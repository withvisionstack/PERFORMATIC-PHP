use axum::{
    extract::State,
    http::{Method, StatusCode},
    response::IntoResponse,
    routing::get,
    Json, Router,
};
use base64::{engine::general_purpose::STANDARD as B64, Engine};
use ext_php_rs::embed::Embed;
use serde::Deserialize;
use serde_json::{json, Value};
use std::collections::HashMap;
use std::panic::AssertUnwindSafe;
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::{Duration, Instant};
use tokio::sync::{mpsc, oneshot};
use tower_http::services::ServeDir;

#[derive(Clone, Debug, Deserialize)]
struct ParamDef {
    name: String,
    #[serde(rename = "in")]
    location: String,
    #[serde(rename = "type")]
    r#type: String,
    required: bool,
    description: Option<String>,
}

#[derive(Clone, Debug, Deserialize)]
struct BodyDef {
    schema: String,
    required: bool,
    example: Option<Value>,
}

#[derive(Clone, Debug, Deserialize)]
struct ResponseDef {
    status: u16,
    description: String,
    schema: Option<String>,
    example: Option<Value>,
}

#[derive(Clone, Debug, Deserialize)]
struct RouteDef {
    method: String,
    path: String,
    handler: String,
    summary: Option<String>,
    description: Option<String>,
    #[serde(default)]
    tags: Vec<String>,
    #[serde(default)]
    params: Vec<ParamDef>,
    body: Option<BodyDef>,
    #[serde(default)]
    responses: Vec<ResponseDef>,
    #[serde(default)]
    security: Vec<String>,
}

#[derive(Clone, Debug, Deserialize)]
struct SchemaDef {
    name: String,
    fields: HashMap<String, String>,
    #[serde(default)]
    required: Vec<String>,
}

#[derive(Clone, Debug, Deserialize)]
struct SecuritySchemeDef {
    name: String,
    #[serde(rename = "type")]
    r#type: String,
    scheme: String,
    bearer_format: Option<String>,
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

struct CacheEntry {
    body: String,
    expires_at: Instant,
}

struct AppState {
    routes: Vec<RouteDef>,
    php_tx: mpsc::Sender<Job>,
    cache: Mutex<HashMap<String, CacheEntry>>,
    schemas: Vec<SchemaDef>,
    security_schemes: Vec<SecuritySchemeDef>,
}

/// Quanto tempo o cache dura (em segundos).
const CACHE_TTL_SECS: u64 = 60;

/// Só GET é cacheado, exceto rotas da lista negra.
fn is_cacheable(method: &str, path: &str) -> bool {
    if method != "GET" {
        return false;
    }
    const NEVER_CACHE: &[&str] = &["/me", "/headers", "/diag", "/ini", "/routes", "/ping"];
    !NEVER_CACHE.contains(&path)
}

// ======================= LOGS COLORIDOS =======================
fn hms_now() -> String {
    let now = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs();
    format!(
        "{:02}:{:02}:{:02}",
        (now / 3600) % 24,
        (now / 60) % 60,
        now % 60
    )
}

fn color_method(m: &str) -> String {
    let padded = format!("{:<7}", m);
    let color = match m {
        "GET" => "34",    // azul
        "POST" => "32",   // verde
        "PUT" => "33",    // amarelo
        "PATCH" => "35",  // magenta
        "DELETE" => "31", // vermelho
        _ => "90",        // cinza
    };
    format!("\x1b[{}m{}\x1b[0m", color, padded)
}

fn color_status(s: u16) -> String {
    let color = match s {
        200..=299 => "32",   // verde
        300..=399 => "33",   // amarelo
        400..=499 => "31",   // vermelho
        500..=599 => "1;31", // vermelho negrito
        _ => "0",
    };
    format!("\x1b[{}m{}\x1b[0m", color, s)
}

fn color_size(b: usize) -> String {
    let s = if b < 1024 {
        format!("{}B", b)
    } else if b < 1024 * 1024 {
        format!("{:.1}KB", b as f64 / 1024.0)
    } else {
        format!("{:.1}MB", b as f64 / (1024.0 * 1024.0))
    };
    format!("\x1b[90m{}\x1b[0m", s)
}

fn log_request(method: &str, path: &str, status: u16, elapsed_ms: f64, size: usize) {
    eprintln!(
        "\x1b[90m{}\x1b[0m {} {} {} \x1b[90m{:.2}ms\x1b[0m {}",
        hms_now(),
        color_method(method),
        path,
        color_status(status),
        elapsed_ms,
        color_size(size)
    );
}

fn main() {
    // Caminho ABSOLUTO para o php.ini (evita depender do cwd)
    let cwd = std::env::current_dir().expect("cwd");
    std::env::set_var("PHPRC", cwd.join("php.ini"));
    dotenvy::dotenv().ok();

    // ---------------------------------------------------------------------
    // 1. Ler rotas em uma thread temporária (inicialização única)
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
                    // Detecta multipart/form-data
                    let is_multipart = job.headers.iter().any(|(k, v)| {
                        k.eq_ignore_ascii_case("content-type")
                            && v.to_lowercase().contains("multipart/form-data")
                    });

                    // __files é sempre zerado (evita sobrar arquivo da requisição anterior)
                    let multipart_call = if is_multipart {
                        "multipart_parse(); "
                    } else {
                        ""
                    };

                    let b64_body = B64.encode(&job.body);
                    let b64_query = B64.encode(job.query.as_bytes());
                    let b64_method = B64.encode(job.method.as_bytes());

                    // ZERA $_SERVER inteiro e seta REQUEST_METHOD + headers
                    let headers_php: String = {
                        let mut s = String::from(
                            "$_SERVER = []; $_SERVER['REQUEST_METHOD'] = $GLOBALS['__method']; ",
                        );

                        for (k, v) in &job.headers {
                            // Só letras/números/hífen no nome: evita quebrar o código PHP gerado
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
                            $_GET = []; \
                            parse_str(base64_decode('{b64_query}'), $_GET); \
                            {headers_php} \
                            {multipart_call} \
                            return {handler}({args}); \
                        }})();",
                        b64_body = b64_body,
                        b64_method = b64_method,
                        b64_query = b64_query,
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
            cache: Mutex::new(HashMap::new()),
        });
        let app = Router::new()
            .route("/docs", get(docs_html))
            .route("/docs.json", get(docs_json))
            .nest_service("/assets", ServeDir::new("assets"))
            .fallback(dispatch)
            .with_state(state);

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

/// Extrai (status, body) do envelope PHP.
/// Se não houver envelope, assume status 200 e o valor cru.
fn parse_php_response(s: &str) -> (u16, Option<Value>) {
    match serde_json::from_str::<Value>(s) {
        Ok(Value::Object(mut obj)) if obj.contains_key("__status") => {
            let code = obj
                .remove("__status")
                .and_then(|v| v.as_u64())
                .unwrap_or(200) as u16;
            let body = obj.remove("__body").unwrap_or(Value::Null);
            (code, Some(body))
        }
        Ok(v) => (200, Some(v)),
        Err(_) => (200, None),
    }
}

async fn dispatch(
    State(state): State<Arc<AppState>>,
    method: Method,
    uri: axum::http::Uri,
    headers: axum::http::HeaderMap,
    body: axum::body::Bytes,
) -> impl IntoResponse {
    let started = Instant::now();
    let path = uri.path().to_string();
    let method_str = method.as_str().to_string();
    let query = uri.query().unwrap_or("").to_string();

    // ------------------------------------------------------------------
    // Meta-endpoints de documentação
    // ------------------------------------------------------------------
    if path == "/docs.json" {
        return docs_json(State(state)).await.into_response();
    }
    if path == "/docs" || path == "/docs/" {
        return docs_html().await.into_response();
    }

    // ------------------------------------------------------------------
    // Cache lookup
    // ------------------------------------------------------------------
    let cacheable = is_cacheable(&method_str, &path);
    let cache_key = format!("{}:{}?{}", method_str, path, query);

    if cacheable {
        let cache = state.cache.lock().unwrap();
        if let Some(entry) = cache.get(&cache_key) {
            if entry.expires_at > Instant::now() {
                let (s, b) = parse_php_response(&entry.body);
                let st = StatusCode::from_u16(s).unwrap_or(StatusCode::OK);
                log_request(
                    &method_str,
                    &path,
                    s,
                    started.elapsed().as_secs_f64() * 1000.0,
                    entry.body.len(),
                );
                return match b {
                    Some(v) => (st, Json(v)).into_response(),
                    None => (st, entry.body.clone()).into_response(),
                };
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
        None => {
            let msg = "Rota não encontrada";
            log_request(
                &method_str,
                &path,
                404,
                started.elapsed().as_secs_f64() * 1000.0,
                msg.len(),
            );
            return (StatusCode::NOT_FOUND, msg).into_response();
        }
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

    let (s, b) = parse_php_response(&result);
    let status = StatusCode::from_u16(s).unwrap_or(StatusCode::OK);

    // ------------------------------------------------------------------
    // Cache write (só respostas 2xx)
    // ------------------------------------------------------------------
    if cacheable && status.is_success() {
        let mut cache = state.cache.lock().unwrap();
        cache.insert(
            cache_key,
            CacheEntry {
                body: result.clone(),
                expires_at: Instant::now() + Duration::from_secs(CACHE_TTL_SECS),
            },
        );
    }

    // ------------------------------------------------------------------
    // Resposta final (status + body via envelope)
    // ------------------------------------------------------------------
    log_request(
        &method_str,
        &path,
        s,
        started.elapsed().as_secs_f64() * 1000.0,
        result.len(),
    );

    match b {
        Some(v) => (status, Json(v)).into_response(),
        None => (status, result).into_response(),
    }
}

async fn docs_json(State(state): State<Arc<AppState>>) -> impl IntoResponse {
    Json(build_openapi(
        &state.routes,
        &state.schemas,
        &state.security_schemes,
    ))
}

async fn docs_html() -> impl IntoResponse {
    let html = r#"<!DOCTYPE html>
<html>
<head>
     <title>Performatic PHP Docs</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <link rel="icon" href="/assets/favicon.ico">
</head>
<body>

<div style="padding:20px">
    <img src="/assets/performatic.png" height="110">
</div>

    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        SwaggerUIBundle({
            url: '/docs.json',
            dom_id: '#swagger-ui',
            deepLinking: true,
        });
    </script>
</body>
</html>"#;
    axum::response::Html(html)
}

fn php_type_to_openapi(t: &str) -> Value {
    match t {
        "int" => json!({"type": "integer"}),
        "float" => json!({"type": "number"}),
        "bool" => json!({"type": "boolean"}),
        "string" => json!({"type": "string"}),
        "datetime" => json!({"type": "string", "format": "date-time"}),
        "date" => json!({"type": "string", "format": "date"}),
        "array" => json!({"type": "array", "items": {}}),
        _ => {
            // Tipo customizado → $ref
            if t.chars().next().map(|c| c.is_uppercase()).unwrap_or(false) {
                json!({"$ref": format!("#/components/schemas/{}", t)})
            } else {
                json!({"type": "string"})
            }
        }
    }
}

fn build_openapi(
    routes: &[RouteDef],
    schemas: &[SchemaDef],
    security_schemes: &[SecuritySchemeDef],
) -> Value {
    let mut paths = serde_json::Map::new();

    for route in routes {
        let entry = paths
            .entry(route.path.clone())
            .or_insert_with(|| json!({}));

        let mut operation = json!({
            "summary":     route.summary,
            "description": route.description,
            "operationId": route.handler,
            "tags":        route.tags,
        });

        // Parâmetros
        if !route.params.is_empty() {
            let params: Vec<Value> = route
                .params
                .iter()
                .map(|p| {
                    json!({
                        "name": p.name,
                        "in":   p.location,
                        "required": p.required,
                        "schema": php_type_to_openapi(&p.r#type),
                        "description": p.description,
                    })
                })
                .collect();
            operation["parameters"] = json!(params);
        }

        // RequestBody
        if let Some(body) = &route.body {
            let mut content = json!({
                "schema": {"$ref": format!("#/components/schemas/{}", body.schema)}
            });
            if let Some(ex) = &body.example {
                content["example"] = ex.clone();
            }
            operation["requestBody"] = json!({
                "required": body.required,
                "content": {"application/json": content},
            });
        }

        // Responses
        let mut responses = serde_json::Map::new();
        if route.responses.is_empty() {
            responses.insert("200".to_string(), json!({"description": "Sucesso"}));
        } else {
            for resp in &route.responses {
                let mut r = json!({"description": resp.description});
                if let Some(schema) = &resp.schema {
                    r["content"] = json!({
                        "application/json": {
                            "schema": {"$ref": format!("#/components/schemas/{}", schema)}
                        }
                    });
                }
                if let Some(ex) = &resp.example {
                    if r.get("content").is_some() {
                        r["content"]["application/json"]["example"] = ex.clone();
                    }
                }
                responses.insert(resp.status.to_string(), r);
            }
        }
        // Erros padrão sempre presentes
        if !responses.contains_key("500") {
            responses.insert("500".to_string(), json!({"description": "Erro interno"}));
        }
        operation["responses"] = Value::Object(responses);

        // Security
        if !route.security.is_empty() {
            let arr: Vec<Value> = route
                .security
                .iter()
                .map(|s| json!({ s.clone(): [] }))
                .collect();
            operation["security"] = json!(arr);
        }

        entry[route.method.to_lowercase()] = operation;
    }

    // Components
    let mut comp_schemas = serde_json::Map::new();
    for s in schemas {
        let mut properties = serde_json::Map::new();
        for (name, ty) in &s.fields {
            properties.insert(name.clone(), php_type_to_openapi(ty));
        }
        comp_schemas.insert(
            s.name.clone(),
            json!({
                "type": "object",
                "properties": properties,
                "required": s.required,
            }),
        );
    }

    let mut comp_security = serde_json::Map::new();
    for sec in security_schemes {
        let mut def = json!({
            "type": sec.r#type,
            "scheme": sec.scheme,
        });
        if let Some(bf) = &sec.bearer_format {
            def["bearerFormat"] = json!(bf);
        }
        comp_security.insert(sec.name.clone(), def);
    }

    let mut components = json!({});
    if !comp_schemas.is_empty() {
        components["schemas"] = Value::Object(comp_schemas);
    }
    if !comp_security.is_empty() {
        components["securitySchemes"] = Value::Object(comp_security);
    }

    let mut root = json!({
        "openapi": "3.0.0",
        "info": {
            "title": "Performatic PHP",
            "version": "1.0.0"
        },
        "paths": paths,
    });
    if components
        .as_object()
        .map(|o| !o.is_empty())
        .unwrap_or(false)
    {
        root["components"] = components;
    }
    root
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