use axum::{
    extract::State,
    http::{Method, StatusCode},
    response::IntoResponse,
    Json,
};
use std::sync::Arc;
use std::time::Instant;
use tokio::sync::oneshot;
use serde_json::json;

use crate::logging::log_request;
use crate::openapi::{docs_html, docs_json};
use crate::response::{add_cookies, parse_php_response, php_literal};
use crate::types::{is_cacheable, AppState, CacheEntry, Job, CACHE_TTL_SECS};

pub async fn dispatch(
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

    // Meta-endpoints
    if path == "/docs.json" {
        return docs_json(State(state)).await.into_response();
    }
    if path == "/docs" || path == "/docs/" {
        return docs_html().await.into_response();
    }

    // Cache lookup
    let cacheable = is_cacheable(&method_str, &path);
    let cache_key = format!("{}:{}?{}", method_str, path, query);

    if cacheable {
        let cache = state.cache.lock().unwrap();
        if let Some(entry) = cache.get(&cache_key) {
            if entry.expires_at > Instant::now() {
                let (s, b, cookies) = parse_php_response(&entry.body);    // ← 3 valores
                let st = StatusCode::from_u16(s).unwrap_or(StatusCode::OK);
                log_request(
                    &method_str,
                    &path,
                    s,
                    started.elapsed().as_secs_f64() * 1000.0,
                    entry.body.len(),
                );
                let mut response = match b {
                    Some(v) => (st, Json(v)).into_response(),
                    None => (st, entry.body.clone()).into_response(),
                };
                add_cookies(&mut response, cookies);
                return response;
            }
        }
    }

    // Match rota
    let mut matched = None;
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


        // ------------------------------------------------------------------
        // Security declarativo: #[Security(...)] vira 401 antes de chamar o PHP
        // ------------------------------------------------------------------
         if !route.security.is_empty() {
                let has_auth = headers
                    .get("authorization")
                    .and_then(|v| v.to_str().ok())
                    .map(|v| v.starts_with("Bearer ") && v.len() > 7)
                    .unwrap_or(false);

                let has_cookie = headers
                    .get("cookie")
                    .and_then(|v| v.to_str().ok())
                    .map(|v| v.contains("auth_token="))
                    .unwrap_or(false);

                if !has_auth && !has_cookie {
                    log_request(&method_str, &path, 401,
                        started.elapsed().as_secs_f64() * 1000.0, 0);
                    return (StatusCode::UNAUTHORIZED,
                        Json(json!({"erro": "não autenticado"}))).into_response();
                }
            }


    let args_php = params.iter()
        .map(|p| php_literal(p))
        .collect::<Vec<_>>()
        .join(", ");

    let headers_vec: Vec<(String, String)> = headers
        .iter()
        .filter_map(|(k, v)| {
            v.to_str().ok().map(|v| (k.as_str().to_string(), v.to_string()))
        })
        .collect();

    // ← CORRIGIDO: extrair cookies do HeaderMap
    let cookies_header = headers
        .get("cookie")
        .and_then(|v| v.to_str().ok())
        .unwrap_or("")
        .to_string();

    let (resp_tx, resp_rx) = oneshot::channel();
    if state
        .php_tx
        .send(Job {
            handler: route.handler.clone(),
            args_php,
            body: body.to_vec(),
            query,
            headers: headers_vec,
            cookies: cookies_header,       // ← CORRIGIDO: agora existe
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

    let (s, b, cookies) = parse_php_response(&result);    // ← 3 valores
    let status = StatusCode::from_u16(s).unwrap_or(StatusCode::OK);

    // Cache write (só 2xx)
    if cacheable && status.is_success() {
        let mut cache = state.cache.lock().unwrap();
        cache.insert(
            cache_key,
            CacheEntry {
                body: result.clone(),
                expires_at: Instant::now() + std::time::Duration::from_secs(CACHE_TTL_SECS),
            },
        );
    }

    log_request(
        &method_str,
        &path,
        s,
        started.elapsed().as_secs_f64() * 1000.0,
        result.len(),
    );

    let mut response = match b {
        Some(v) => (status, Json(v)).into_response(),
        None => (status, result).into_response(),
    };
    add_cookies(&mut response, cookies);
    response
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