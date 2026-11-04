use axum::{
    extract::State,
    http::{Method, StatusCode},
    response::IntoResponse,
    Json, Router,
};
use base64::{engine::general_purpose::STANDARD as B64, Engine};
use ext_php_rs::embed::Embed;
use serde::Deserialize;
use serde_json::Value;
use std::collections::HashMap;
use std::panic::AssertUnwindSafe;
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::{Duration, Instant};
use tokio::sync::{mpsc, oneshot};

#[derive(Clone, Debug, Deserialize)]
struct RouteDef {
    method: String,
    path: String,
    handler: String,
}

struct Job {
    handler: String,
    args_php: String,
    body: Vec<u8>,
    query: String,
    headers: Vec<(String, String)>,
    method: String,
    respond_to: oneshot::Sender<Result<String, String>>,
}

// ← NOVO: entrada de cache
struct CacheEntry {
    body: String,
    expires_at: Instant,
}

struct AppState {
    routes: Vec<RouteDef>,
    php_tx: mpsc::Sender<Job>,
    cache: Mutex<HashMap<String, CacheEntry>>,   // ← NOVO
}

/// Quanto tempo o cache dura (em segundos).
/// Ajuste por endpoint se necessário.
const CACHE_TTL_SECS: u64 = 60;

/// Endpoints que NÃO devem ser cacheados (POST, autenticação, etc).
/// Comparação exata com `path`.
fn is_cacheable(method: &str, path: &str) -> bool {
    if method != "GET" {
        return false;
    }
    // Lista negra: rotas que nunca devem ser cacheadas
    const NEVER_CACHE: &[&str] = &["/me", "/headers", "/diag", "/ini", "/routes", "/ping"];
    !NEVER_CACHE.contains(&path)
}

fn main() {
    // Caminho ABSOLUTO para o php.ini (evita depender do cwd)
    let cwd = std::env::current_dir().expect("cwd");
    std::env::set_var("PHPRC", cwd.join("php.ini"));
    dotenvy::dotenv().ok();

    // ---------------------------------------------------------------------
    // 1. Ler rotas em uma thread temporária (inicialização única)
    // ---------------------------------------------------------------------
    let routes = {
        let (tx, rx) = std::sync::mpsc::channel();
        thread::Builder::new()
            .stack_size(8 * 1024 * 1024)
            .spawn(move || {
                Embed::run(|| {
                    Embed::run_script("api/routes.php").expect("routes.php (startup)");
                    let json = Embed::eval("json_encode(get_routes());")
                        .expect("json_encode(get_routes)")
                        .string()
                        .expect("json_encode(get_routes) não retornou string");
                    let routes: Vec<RouteDef> =
                        serde_json::from_str(&json).expect("json inválido");
                    tx.send(routes).ok();
                });
            })
            .unwrap()
            .join()
            .unwrap();
        rx.recv().unwrap()
    };

    println!("📋 {} rotas carregadas:", routes.len());
    for r in &routes {
        println!("   {} {}", r.method, r.path);
    }

    // ---------------------------------------------------------------------
    // 2. Worker PHP único com canal async
    // ---------------------------------------------------------------------
    let (php_tx, mut php_rx) = mpsc::channel::<Job>(10000);

    thread::Builder::new()
        .stack_size(8 * 1024 * 1024)
        .spawn(move || {
            Embed::run(AssertUnwindSafe(move || {
                Embed::run_script("api/routes.php").expect("routes.php (worker)");
                eprintln!("✅ PHP worker pronto");

                while let Some(job) = php_rx.blocking_recv() {
                    // ZERA $_SERVER inteiro e seta REQUEST_METHOD + headers
                    let headers_php: String = {
                        let mut s = format!(
                            "$_SERVER = []; $_SERVER['REQUEST_METHOD'] = '{}'; ",
                            job.method
                        );
                        for (k, v) in &job.headers {
                            let key = k.to_uppercase().replace('-', "_");
                            let b64_val = B64.encode(v.as_bytes());
                            s.push_str(&format!(
                                "$_SERVER['HTTP_{}'] = base64_decode('{}'); ",
                                key, b64_val
                            ));
                        }
                        s
                    };

                    let b64_query = B64.encode(job.query.as_bytes());

                    let code = if job.body.is_empty() {
                        format!(
                            "(function() {{ \
                                $_GET = []; \
                                parse_str(base64_decode('{b64_query}'), $_GET); \
                                {headers_php} \
                                return {handler}({args}); \
                            }})();",
                            b64_query = b64_query,
                            headers_php = headers_php,
                            handler = job.handler,
                            args = job.args_php
                        )
                    } else {
                        let b64_body = B64.encode(&job.body);
                        format!(
                            "(function() {{ \
                                $GLOBALS['__body'] = base64_decode('{b64_body}'); \
                                $GLOBALS['__method'] = '{method}'; \
                                $_GET = []; \
                                parse_str(base64_decode('{b64_query}'), $_GET); \
                                {headers_php} \
                                return {handler}({args}); \
                            }})();",
                            b64_body = b64_body,
                            b64_query = b64_query,
                            method = job.method,
                            headers_php = headers_php,
                            handler = job.handler,
                            args = job.args_php
                        )
                    };

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
        .worker_threads(8)   // subimos de 4 para 8 para melhor accept loop
        .enable_all()
        .build()
        .unwrap();

    rt.block_on(async move {
        let state = Arc::new(AppState {
            routes,
            php_tx,
            cache: Mutex::new(HashMap::new()),   // ← NOVO
        });
        let app = Router::new().fallback(dispatch).with_state(state);

        let listener = tokio::net::TcpListener::bind("0.0.0.0:7600")
            .await
            .unwrap();
        println!("🚀 Servidor ouvindo em http://localhost:7600");
        axum::serve(listener, app).await.unwrap();
    });
}

/// Converte um parâmetro de rota em literal PHP seguro.
fn php_literal(p: &str) -> String {
    match p.parse::<i64>() {
        Ok(n) if n.to_string() == p => p.to_string(),
        _ => format!("'{}'", p.replace('\\', "\\\\").replace('\'', "\\'")),
    }
}

async fn dispatch(
    State(state): State<Arc<AppState>>,
    method: Method,
    uri: axum::http::Uri,
    headers: axum::http::HeaderMap,
    body: axum::body::Bytes,
) -> impl IntoResponse {
    let path = uri.path().to_string();
    let method_str = method.as_str().to_string();
    let query = uri.query().unwrap_or("").to_string();

    // ------------------------------------------------------------------
    // ← NOVO: Verificar cache antes de chamar o PHP
    // ------------------------------------------------------------------
    let cache_key = format!("{}:{}?{}", method_str, path, query);

    if is_cacheable(&method_str, &path) {
        let cache = state.cache.lock().unwrap();
        if let Some(entry) = cache.get(&cache_key) {
            if entry.expires_at > Instant::now() {
                if let Ok(v) = serde_json::from_str::<Value>(&entry.body) {
                    return (StatusCode::OK, Json(v)).into_response();
                }
                return (StatusCode::OK, entry.body.clone()).into_response();
            }
        }
    }

    // ------------------------------------------------------------------
    // Encontrar a rota
    // ------------------------------------------------------------------
    let mut matched: Option<(&RouteDef, Vec<String>)> = None;
    for route in &state.routes {
        if route.method != method_str {
            continue;
        }
        if let Some(params) = match_path(&route.path, &path) {
            matched = Some((route, params));
            break;
        }
    }

    let (route, params) = match matched {
        Some(m) => m,
        None => return (StatusCode::NOT_FOUND, "Rota não encontrada").into_response(),
    };

    let args_php = params
        .iter()
        .map(|p| php_literal(p))
        .collect::<Vec<_>>()
        .join(", ");

    let headers_vec: Vec<(String, String)> = headers
        .iter()
        .filter_map(|(k, v)| {
            v.to_str()
                .ok()
                .map(|v| (k.as_str().to_string(), v.to_string()))
        })
        .collect();

    let (resp_tx, resp_rx) = oneshot::channel();
    if state
        .php_tx
        .send(Job {
            handler: route.handler.clone(),
            args_php,
            body: body.to_vec(),
            query,
            headers: headers_vec,
            method: method_str.clone(),
            respond_to: resp_tx,
        })
        .await
        .is_err()
    {
        return (StatusCode::INTERNAL_SERVER_ERROR, "PHP worker indisponível").into_response();
    }

    let result = match resp_rx.await {
        Ok(Ok(s)) if s.is_empty() => {
            return (
                StatusCode::INTERNAL_SERVER_ERROR,
                "PHP retornou vazio (erro fatal — ver log do servidor)",
            )
                .into_response();
        }
        Ok(Ok(s)) => s,
        Ok(Err(e)) => {
            return (StatusCode::INTERNAL_SERVER_ERROR, format!("Erro PHP: {}", e)).into_response();
        }
        Err(_) => {
            return (StatusCode::INTERNAL_SERVER_ERROR, "Worker caiu").into_response();
        }
    };

    // ------------------------------------------------------------------
    // ← NOVO: Guardar no cache após resposta bem-sucedida
    // ------------------------------------------------------------------
    if is_cacheable(&method_str, &path) && !result.is_empty() {
        let mut cache = state.cache.lock().unwrap();
        cache.insert(
            cache_key,
            CacheEntry {
                body: result.clone(),
                expires_at: Instant::now() + Duration::from_secs(CACHE_TTL_SECS),
            },
        );
    }

    if let Ok(v) = serde_json::from_str::<Value>(&result) {
        (StatusCode::OK, Json(v)).into_response()
    } else {
        (StatusCode::OK, result).into_response()
    }
}

fn match_path(pattern: &str, actual: &str) -> Option<Vec<String>> {
    let pat: Vec<&str> = pattern.split('/').filter(|s| !s.is_empty()).collect();
    let act: Vec<&str> = actual.split('/').filter(|s| !s.is_empty()).collect();
    if pat.len() != act.len() {
        return None;
    }
    let mut params = Vec::new();
    for (p, a) in pat.iter().zip(act.iter()) {
        if p.starts_with('{') && p.ends_with('}') {
            params.push(a.to_string());
        } else if p != a {
            return None;
        }
    }
    Some(params)
}