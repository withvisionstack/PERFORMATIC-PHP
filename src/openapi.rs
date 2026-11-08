use axum::{extract::State, response::IntoResponse, Json};
use serde_json::{json, Value};
use std::sync::Arc;

use crate::types::{AppState, RouteDef, SchemaDef, SecuritySchemeDef};

pub async fn docs_json(State(state): State<Arc<AppState>>) -> impl IntoResponse {
    Json(build_openapi(
        &state.routes,
        &state.schemas,
        &state.security_schemes,
    ))
}

pub async fn docs_html() -> impl IntoResponse {
    let html = r#"<!DOCTYPE html>
<html>
<head>
    <title>Performatic PHP Docs</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <link rel="icon" href="/assets/favicon.ico">
</head>
<body>
<div style="padding:20px; display:flex; align-items:center; justify-content:center;">
    <img src="/assets/performatic.png" style="height:300px;">
</div>
<div id="swagger-ui"></div>
<script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
<script>
    SwaggerUIBundle({ url: '/docs.json', dom_id: '#swagger-ui', deepLinking: true });
</script>
</body>
</html>"#;
    axum::response::Html(html)
}

fn php_type_to_openapi(t: &str) -> Value {
    match t {
        "int"      => json!({"type": "integer"}),
        "float"    => json!({"type": "number"}),
        "bool"     => json!({"type": "boolean"}),
        "string"   => json!({"type": "string"}),
        "datetime" => json!({"type": "string", "format": "date-time"}),
        "date"     => json!({"type": "string", "format": "date"}),
        "array"    => json!({"type": "array", "items": {}}),
        _ => {
            if t.chars().next().map(|c| c.is_uppercase()).unwrap_or(false) {
                json!({"$ref": format!("#/components/schemas/{}", t)})
            } else {
                json!({"type": "string"})
            }
        }
    }
}

pub fn build_openapi(
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

        if !route.params.is_empty() {
            let params: Vec<Value> = route.params.iter().map(|p| {
                json!({
                    "name": p.name,
                    "in":   p.location,
                    "required": p.required,
                    "schema": php_type_to_openapi(&p.r#type),
                    "description": p.description,
                })
            }).collect();
            operation["parameters"] = json!(params);
        }

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
        if !responses.contains_key("500") {
            responses.insert("500".to_string(), json!({"description": "Erro interno"}));
        }
        operation["responses"] = Value::Object(responses);

        if !route.security.is_empty() {
            let arr: Vec<Value> = route.security.iter()
                .map(|s| json!({ s.clone(): [] }))
                .collect();
            operation["security"] = json!(arr);
        }

        entry[route.method.to_lowercase()] = operation;
    }

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
            "version": "1.3.0"
        },
        "paths": paths,
    });
    if components.as_object().map(|o| !o.is_empty()).unwrap_or(false) {
        root["components"] = components;
    }
    root
}