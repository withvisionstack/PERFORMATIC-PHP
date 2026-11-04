mod dispatch;
mod logging;
mod openapi;
mod response;
mod types;

use axum::{routing::get, Router};
use base64::{engine::general_purpose::STANDARD as B64, Engine};
use ext_php_rs::embed::Embed;
use std::panic::AssertUnwindSafe;
use std::sync::Arc;
use std::thread;
use tokio::sync::mpsc;
use tower_http::services::ServeDir;

use dispatch::dispatch;
use openapi::{docs_html, docs_json};
use types::{AppState, Job, RouteDef, SchemaDef, SecuritySchemeDef};



fn main() {
    //let cwd = std::env::current_dir().expect("cwd");
    //std::env::set_var("PHPRC", cwd.join("php.ini"));
    //std::env::set_var("PHP_INI_SCAN_DIR", "");   // ← NOVO: não escaneia conf.d
    dotenvy::dotenv().ok();

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
        "📋 {} rotas, {} schemas, {} schemes",
        routes.len(),
        schemas.len(),
        security_schemes.len()
    );

    // ---------------------------------------------------------------------
    // 2. Worker PHP
    // ---------------------------------------------------------------------
    let (php_tx, mut php_rx) = mpsc::channel::<Job>(10000);

    thread::Builder::new()
        .stack_size(8 * 1024 * 1024)
        .spawn(move || {
            Embed::run(AssertUnwindSafe(move || {
                Embed::run_script("api/routes.php").expect("routes.php (worker)");
                eprintln!("✅ PHP worker pronto");

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
                           return {handler}({args}); \
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
        println!("🚀 Servidor ouvindo em http://localhost:{}", port);

        axum::serve(listener, app)
            .with_graceful_shutdown(shutdown_signal())
            .await
            .unwrap();

        println!("\x1b[32m✓ Servidor encerrado graciosamente\x1b[0m");
    });

    async fn shutdown_signal() {
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
        }

        eprintln!("\n\x1b[33m⚠ Sinal de shutdown recebido. Aguardando requisições em andamento...\x1b[0m");
    }



}
