pub fn hms_now() -> String {
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

pub fn color_method(m: &str) -> String {
    let padded = format!("{:<7}", m);
    let color = match m {
        "GET"    => "34",
        "POST"   => "32",
        "PUT"    => "33",
        "PATCH"  => "35",
        "DELETE" => "31",
        _        => "90",
    };
    format!("\x1b[{}m{}\x1b[0m", color, padded)
}

pub fn color_status(s: u16) -> String {
    let color = match s {
        200..=299 => "32",
        300..=399 => "33",
        400..=499 => "31",
        500..=599 => "1;31",
        _         => "0",
    };
    format!("\x1b[{}m{}\x1b[0m", color, s)
}

pub fn color_size(b: usize) -> String {
    let s = if b < 1024 {
        format!("{}B", b)
    } else if b < 1024 * 1024 {
        format!("{:.1}KB", b as f64 / 1024.0)
    } else {
        format!("{:.1}MB", b as f64 / (1024.0 * 1024.0))
    };
    format!("\x1b[90m{}\x1b[0m", s)
}

pub fn log_request(method: &str, path: &str, status: u16, elapsed_ms: f64, size: usize) {
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