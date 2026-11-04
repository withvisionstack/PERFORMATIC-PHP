use serde::Deserialize;
use serde_json::Value;
use std::collections::HashMap;
use std::time::Instant;
use tokio::sync::{mpsc, oneshot};

#[derive(Clone, Debug, Deserialize)]
pub struct ParamDef {
    pub name: String,
    #[serde(rename = "in")]
    pub location: String,
    #[serde(rename = "type")]
    pub r#type: String,
    pub required: bool,
    pub description: Option<String>,
}

#[derive(Clone, Debug, Deserialize)]
pub struct BodyDef {
    pub schema: String,
    pub required: bool,
    pub example: Option<Value>,
}

#[derive(Clone, Debug, Deserialize)]
pub struct ResponseDef {
    pub status: u16,
    pub description: String,
    pub schema: Option<String>,
    pub example: Option<Value>,
}

#[derive(Clone, Debug, Deserialize)]
pub struct RouteDef {
    pub method: String,
    pub path: String,
    pub handler: String,
    pub summary: Option<String>,
    pub description: Option<String>,
    #[serde(default)]
    pub tags: Vec<String>,
    #[serde(default)]
    pub params: Vec<ParamDef>,
    pub body: Option<BodyDef>,
    #[serde(default)]
    pub responses: Vec<ResponseDef>,
    #[serde(default)]
    pub security: Vec<String>,
}

#[derive(Clone, Debug, Deserialize)]
pub struct SchemaDef {
    pub name: String,
    pub fields: HashMap<String, String>,
    #[serde(default)]
    pub required: Vec<String>,
}

#[derive(Clone, Debug, Deserialize)]
pub struct SecuritySchemeDef {
    pub name: String,
    #[serde(rename = "type")]
    pub r#type: String,
    pub scheme: String,
    pub bearer_format: Option<String>,
}

pub struct Job {
    pub handler: String,
    pub args_php: String,
    pub cookies: String,
    pub body: Vec<u8>,
    pub query: String,
    pub headers: Vec<(String, String)>,
    pub method: String,
    pub respond_to: oneshot::Sender<Result<String, String>>,
}

pub struct CacheEntry {
    pub body: String,
    pub expires_at: Instant,
}

pub struct AppState {
    pub routes: Vec<RouteDef>,
    pub php_tx: mpsc::Sender<Job>,
    pub cache: std::sync::Mutex<HashMap<String, CacheEntry>>,
    pub schemas: Vec<SchemaDef>,
    pub security_schemes: Vec<SecuritySchemeDef>,
}

pub const CACHE_TTL_SECS: u64 = 60;

pub fn is_cacheable(method: &str, path: &str) -> bool {
    if method != "GET" {
        return false;
    }
    const NEVER_CACHE: &[&str] = &["/me", "/headers", "/diag", "/ini", "/routes", "/ping"];
    !NEVER_CACHE.contains(&path)
}