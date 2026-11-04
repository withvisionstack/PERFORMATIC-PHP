use axum::http::{header, HeaderValue};
use axum::response::Response;
use serde_json::Value;






/// Converte um parâmetro de rota em literal PHP seguro.
pub fn php_literal(p: &str) -> String {
    match p.parse::<i64>() {
        Ok(n) if n.to_string() == p => p.to_string(),
        _ => format!("'{}'", p.replace('\\', "\\\\").replace('\'', "\\'")),
    }
}

/// Extrai (status, body, cookies) do envelope PHP.
pub fn parse_php_response(s: &str) -> (u16, Option<Value>, Vec<String>) {
    match serde_json::from_str::<Value>(s) {
        Ok(Value::Object(mut obj)) if obj.contains_key("__status") => {
            let code = obj
                .remove("__status")
                .and_then(|v| v.as_u64())
                .unwrap_or(200) as u16;
            let body = obj.remove("__body").unwrap_or(Value::Null);
            let cookies = obj
                .remove("__cookies")                    // ← CORRIGIDO (era "headers")
                .and_then(|v| v.as_array().cloned())
                .map(|arr| {
                    arr.iter()
                        .filter_map(|v| v.as_str().map(String::from))
                        .collect()
                })
                .unwrap_or_default();
            (code, Some(body), cookies)
        }
        Ok(v) => (200, Some(v), vec![]),
        Err(_) => (200, None, vec![]),
    }
}

/// Adiciona Set-Cookie na resposta.
pub fn add_cookies(response: &mut Response, cookies: Vec<String>) {
    for cookie in cookies {
        if let Ok(hv) = HeaderValue::from_str(&cookie) {
            response.headers_mut().append(header::SET_COOKIE, hv);
        }
    }
}