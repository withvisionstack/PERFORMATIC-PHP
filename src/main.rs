mod dispatch;
mod logging;
mod openapi;
mod response;
mod types;

use axum::{routing::get, Router};
use base64::{engine::general_purpose::STANDARD as B64, Engine};
use ext_php_rs::embed::{
    worker_request_shutdown, worker_request_startup, worker_reset_superglobals, Embed,
};
use std::panic::AssertUnwindSafe;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::thread;
use tokio::sync::{mpsc, Notify};
use tower_http::services::ServeDir;

use dispatch::dispatch;
use openapi::{docs_html, docs_json};
use types::{AppState, Job, RouteDef, SchemaDef, SecuritySchemeDef};

/*
   NOTAS PARA USO EM PRODUCAO :

---Tráfego médio: Reciclo (a cada 100k requests)
---Pico de memória
---1 req/s	~28 horas	~35 MB
---10 req/s	~2,8 horas	~90 MB
---100 req/s ~17 minutos ~90 MB

---Base: ~33,5 MB.
---Crescimento: ~0,5 KB por request.
---Pico: ~85–90 MB a 100k requests

---NTS EXT_PHP_RS AGUARDAR PROXIMA VERSAO DA LINGUAGEM OU ATUALIZACOES


---NOTA : 2 workers como taxa minima atrás do Nginx, mesmo com pouco tráfego

*/




/// Marcado pela thread do PHP quando o limite de reciclo é atingido.
/// O servidor então para de aceitar conexões, drena as requisições em andamento
/// e o processo sai com código 0 (o systemd/Docker sobe um novo).
static RECYCLE_REQUESTED: AtomicBool = AtomicBool::new(false);

/// Se a drenagem demorar mais que isso (ex.: cliente keep-alive travado),
/// o processo sai mesmo assim, para a memória não crescer indefinidamente.
const RECYCLE_DRAIN_TIMEOUT_SECS: u64 = 10;



fn main() {


  // ─── Hot reload supervisor ───
      let args: Vec<String> = std::env::args().collect();
      let watch = args.iter().any(|a| a == "--watch" || a == "-w");
      if watch && std::env::var("PERFORMATIC_CHILD").is_err() {
          run_supervisor();
          return;
      }
      if std::env::var("PERFORMATIC_CHILD").is_ok() {
          spawn_watcher();
      }
      // ─── fim do hot reload ───


    //let cwd = std::env::current_dir().expect("cwd");
    //std::env::set_var("PHPRC", cwd.join("php.ini"));
    //std::env::set_var("PHP_INI_SCAN_DIR", "");   // ← NOVO: não escaneia conf.d
    dotenvy::dotenv_override().ok();

    // ---------------------------------------------------------------------
    // 1. Ler rotas
    // ---------------------------------------------------------------------
    let (routes, schemas, security_schemes) = {
        let (tx, rx) = std::sync::mpsc::channel();
        thread::Builder::new()
            .stack_size(8 * 1024 * 1024)
            .spawn(move || {
                Embed::run(|| {
                    Embed::run_script("api/routes.php").expect("routes.php");
                    let routes_json = Embed::eval("json_encode(get_routes());")
                        .expect("get_routes")
                        .string()
                        .expect("get_routes string");
                    let schemas_json = Embed::eval("json_encode(get_schemas());")
                        .expect("get_schemas")
                        .string()
                        .expect("get_schemas string");
                    let security_json = Embed::eval("json_encode(get_security_schemes());")
                        .expect("get_security_schemes")
                        .string()
                        .expect("get_security_schemes string");
                    tx.send((routes_json, schemas_json, security_json)).ok();
                });
            })
            .unwrap()
            .join()
            .unwrap();
        let (r, s, sec) = rx.recv().unwrap();
        (
            serde_json::from_str::<Vec<RouteDef>>(&r).expect("json rotas"),
            serde_json::from_str::<Vec<SchemaDef>>(&s).expect("json schemas"),
            serde_json::from_str::<Vec<SecuritySchemeDef>>(&sec).expect("json security"),
        )
    };

    println!(
        " {} rotas, {} schemas, {} schemes",
        routes.len(),
        schemas.len(),
        security_schemes.len()
    );

    // ---------------------------------------------------------------------
    // 2. Worker PHP
    // ---------------------------------------------------------------------
    let (php_tx, mut php_rx) = mpsc::channel::<Job>(10000);

    // Avisa o servidor HTTP que o worker PHP pediu reciclo gracioso
    let recycle_notify = Arc::new(Notify::new());
    let worker_notify = recycle_notify.clone();

    thread::Builder::new()
        .stack_size(8 * 1024 * 1024)
        .spawn(move || {
            Embed::run(AssertUnwindSafe(move || {
                Embed::run_script("api/routes.php").expect("routes.php (worker)");
                eprintln!(" PHP worker pronto");


            let mut count: u64 = 0;
            const RESET_STATE: u64 = 5_000;        // reset in-process a cada 5k
            // O heap do PHP cresce ~0,5 KB/request e o reset in-process não o libera
            // (medido com /__mem). O reciclo de processo é quem limita a memória:
            // 100k requests ≈ +50 MB (RSS ~90–100 MB, bem abaixo do limite de 512 MB).
            const RECYCLE_PROCESS: u64 = 100_000;  // mata processo a cada 100k




                while let Some(job) = php_rx.blocking_recv() {
                    let is_multipart = job.headers.iter().any(|(k, v)| {
                        k.eq_ignore_ascii_case("content-type")
                            && v.to_lowercase().contains("multipart/form-data")
                    });

                    let multipart_call = if is_multipart {
                        "multipart_parse(); "
                    } else {
                        ""
                    };

                    let b64_body = B64.encode(&job.body);
                    let b64_query = B64.encode(job.query.as_bytes());
                    let b64_method = B64.encode(job.method.as_bytes());
                    let b64_cookies = B64.encode(job.cookies.as_bytes());       // ← NOVO

                    let headers_php: String = {
                        let mut s = String::from(
                            "$_SERVER = []; $_SERVER['REQUEST_METHOD'] = $GLOBALS['__method']; ",
                        );
                        for (k, v) in &job.headers {
                            let key: String = k
                                .chars()
                                .filter_map(|c| {
                                    if c.is_ascii_alphanumeric() {
                                        Some(c.to_ascii_uppercase())
                                    } else if c == '-' {
                                        Some('_')
                                    } else {
                                        None
                                    }
                                })
                                .collect();

                            if key.is_empty() {
                                continue;
                            }

                            let b64_val = B64.encode(v.as_bytes());
                            s.push_str(&format!(
                                "$_SERVER['HTTP_{}'] = base64_decode('{}'); ",
                                key, b64_val
                            ));
                        }
                        s
                    };

                   let code = format!(
                       "(function() {{ \
                           $GLOBALS['__body'] = base64_decode('{b64_body}'); \
                           $GLOBALS['__method'] = base64_decode('{b64_method}'); \
                           $GLOBALS['__files'] = []; \
                           $GLOBALS['__cookies'] = []; \
                           $_GET = []; \
                           $_POST = []; \
                           $_COOKIE = []; \
                           $_SERVER = []; \
                           parse_str(base64_decode('{b64_query}'), $_GET); \
                           parse_str(base64_decode('{b64_cookies}'), $_COOKIE); \
                           {headers_php} \
                           {multipart_call} \
                           try {{ \
                               return {handler}({args}); \
                           }} catch (\\PerformaticValidationError $e) {{ \
                               return json_encode([ \
                                   '__status' => 400, \
                                   '__body' => [ \
                                       'erro' => 'validação falhou', \
                                       'campos' => $e->errors, \
                                   ], \
                               ], JSON_UNESCAPED_UNICODE); \
                           }} catch (Throwable $e) {{ \
                               $debug = getenv('APP_ENV') === 'development'; \
                               error_log('Handler error: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine()); \
                               return json_encode([ \
                                   '__status' => 500, \
                                   '__body' => [ \
                                       'erro'  => $debug ? $e->getMessage() : 'erro interno', \
                                       'local' => $debug ? $e->getFile() . ':' . $e->getLine() : null, \
                                       'tipo'  => $debug ? get_class($e) : null, \
                                   ], \
                               ], JSON_UNESCAPED_UNICODE); \
                           }} \
                       }})();",
                       b64_body = b64_body,
                       b64_method = b64_method,
                       b64_query = b64_query,
                       b64_cookies = b64_cookies,
                       headers_php = headers_php,
                       multipart_call = multipart_call,
                       handler = job.handler,
                       args = job.args_php
                   );


                    let result = match Embed::eval(&code) {
                        Ok(z) => match z.string() {
                            Some(s) => Ok(s.to_string()),
                            None => Err(format!("PHP retornou não-string. Zval: {:?}", z)),
                        },
                        Err(e) => Err(format!("Erro no eval: {:?}", e)),
                    };

                    let _ = job.respond_to.send(result);

                    count += 1;
                    if count % 1000 == 0 {
                        eprintln!("COUNT={}", count);
                    }
                    if count % RESET_STATE == 0 {
                        eprintln!("♻️  Worker reset in-process após {} requests", count);
                        // Reset de estado (limpa superglobais, memória PHP)
                        worker_request_shutdown();
                        worker_request_startup().expect("startup");
                        worker_reset_superglobals();
                        if let Ok(z) = Embed::eval("memory_get_usage(true);") {
                            eprintln!("   PHP heap após reset: {:?}", z.long());
                        }

                        // Tenta liberar RSS
                        #[cfg(target_os = "linux")]
                        unsafe {
                            extern "C" {
                                fn malloc_trim(pad: usize) -> i32;
                            }
                            malloc_trim(0);
                        }
                    }

                    // Reciclo gracioso: NÃO mata o processo na hora. Pede ao servidor
                    // para parar de aceitar conexões e terminar as requisições em
                    // andamento (este loop continua atendendo a fila até lá).
                    if count >= RECYCLE_PROCESS && !RECYCLE_REQUESTED.swap(true, Ordering::SeqCst) {
                        eprintln!(
                            "♻️  Reciclo gracioso após {} requests: drenando requisições em andamento...",
                            count
                        );
                        worker_notify.notify_one();

                        // Rede de segurança: se a drenagem travar, sai mesmo assim
                        std::thread::spawn(|| {
                            std::thread::sleep(std::time::Duration::from_secs(
                                RECYCLE_DRAIN_TIMEOUT_SECS,
                            ));
                            eprintln!("♻️  Drenagem excedeu {}s, encerrando", RECYCLE_DRAIN_TIMEOUT_SECS);
                            std::process::exit(0);
                        });
                    }
                }
            }));
        })
        .unwrap();

    // ---------------------------------------------------------------------
    // 3. Servidor Axum
    // ---------------------------------------------------------------------
    let rt = tokio::runtime::Builder::new_multi_thread()
        .worker_threads(8)
        .enable_all()
        .build()
        .unwrap();

    rt.block_on(async move {
        let state = Arc::new(AppState {
            routes,
            schemas,
            security_schemes,
            php_tx,
            cache: std::sync::Mutex::new(std::collections::HashMap::new()),
        });

        let app = Router::new()
            .route("/docs", get(docs_html))
            .route("/docs.json", get(docs_json))
            .nest_service("/assets", ServeDir::new("assets"))
            .fallback(dispatch)
            .with_state(state);

        // Aceita PORT do ambiente (útil para N workers)
        let port: u16 = std::env::var("PORT")
            .unwrap_or_else(|_| "7600".to_string())
            .parse()
            .unwrap_or(7600);

        let listener = tokio::net::TcpListener::bind(format!("0.0.0.0:{}", port))
            .await
            .unwrap();
        println!(" Servidor ouvindo em http://localhost:{}", port);

        axum::serve(listener, app)
            .with_graceful_shutdown(shutdown_signal(recycle_notify))
            .await
            .unwrap();

        println!("\x1b[32m✓ Servidor encerrado graciosamente\x1b[0m");
    });

    // Fim do servidor. Em reciclo gracioso, sai com 0 (systemd/Docker reiniciam).
    // Em modo --watch, o supervisor só reinicia com código 42.
    if RECYCLE_REQUESTED.load(Ordering::SeqCst) {
        eprintln!("♻️  Reciclo concluído, saindo para reiniciar");
        if std::env::var("PERFORMATIC_CHILD").is_ok() {
            std::process::exit(42);
        }
        std::process::exit(0);
    }

    async fn shutdown_signal(recycle: Arc<Notify>) {
        let ctrl_c = async {
            tokio::signal::ctrl_c()
                .await
                .expect("Falha ao instalar handler de Ctrl+C");
        };

        #[cfg(unix)]
        let terminate = async {
            tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())
                .expect("Falha ao instalar handler de SIGTERM")
                .recv()
                .await;
        };

        #[cfg(not(unix))]
        let terminate = std::future::pending::<()>();

        tokio::select! {
            _ = ctrl_c => {},
            _ = terminate => {},
            _ = recycle.notified() => {},
        }

        if !RECYCLE_REQUESTED.load(Ordering::SeqCst) {
            eprintln!("\n\x1b[33m⚠ Sinal de shutdown recebido. Aguardando requisições em andamento...\x1b[0m");
        }
    }



}


fn run_supervisor() {
    let exe = std::env::current_exe().expect("current_exe");
    loop {
        eprintln!("\x1b[33m Iniciando servidor...\x1b[0m");
        let mut child = std::process::Command::new(&exe)
            .env("PERFORMATIC_CHILD", "1")
            .spawn()
            .expect("spawn child");
        let status = child.wait().expect("wait child");

        if status.code() == Some(42) {
            eprintln!("\x1b[33m PHP alterado, reiniciando...\x1b[0m\n");
            std::thread::sleep(std::time::Duration::from_millis(200));
            continue;
        }
        if !status.success() {
            eprintln!("\x1b[31m✗ Servidor morreu com código {:?}, saindo\x1b[0m", status.code());
        }
        break;
    }
}

fn spawn_watcher() {
    use notify::{recommended_watcher, RecursiveMode, Watcher};

    std::thread::spawn(|| {
        let (tx, rx) = std::sync::mpsc::channel();

        // notify 6.x: recommended_watcher recebe um CALLBACK, não (tx, duration)
        let mut w = match recommended_watcher(move |res| {
            let _ = tx.send(res);
        }) {
            Ok(w) => w,
            Err(_) => return,
        };

        if w.watch(std::path::Path::new("api"), RecursiveMode::Recursive).is_err() {
            return;
        }

        for res in rx {
            if let Ok(event) = res {
                if event.paths.iter().any(|p| {
                    p.extension().map_or(false, |e| e == "php")
                }) {
                    std::process::exit(42);
                }
            }
        }
    });
}