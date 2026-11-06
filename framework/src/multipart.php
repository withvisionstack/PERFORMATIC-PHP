<?php
/**
 * Parser de multipart/form-data.
 * Popula $_POST (campos) e $GLOBALS['__files'] (arquivos).
 * Chamado automaticamente pelo worker Rust quando Content-Type é multipart.
 */
function multipart_parse(): void
{
    $contentType = $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (!preg_match('/boundary=(.+)$/i', $contentType, $m)) {
        return;
    }
    $boundary = trim($m[1], '"');
    $raw = $GLOBALS['__body'] ?? '';
    if ($raw === '') {
        return;
    }

    // Sempre limpa (worker é persistente)
    $_POST = [];
    $GLOBALS['__files'] = [];

    $delimiter = '--' . $boundary;
    $parts = explode($delimiter, $raw);

    foreach ($parts as $part) {
        // Ignora o prefixo e o sufixo (--\r\n no final)
        $part = ltrim($part, "\r\n");
        if ($part === '' || $part === "--\r\n" || $part === '--') {
            continue;
        }

        // Separa headers do conteúdo
        $split = strpos($part, "\r\n\r\n");
        if ($split === false) {
            continue;
        }
        $headers = substr($part, 0, $split);
        $body = substr($part, $split + 4);
        // Remove o \r\n final do conteúdo (se houver)
        $body = preg_replace('/\r\n$/', '', $body);

        // Extrai name="..."
        if (!preg_match('/name="([^"]+)"/i', $headers, $nameMatch)) {
            continue;
        }
        $name = $nameMatch[1];

        // Detecta arquivo (tem filename="...")
        if (preg_match('/filename="([^"]*)"/i', $headers, $fileMatch)) {
            $filename = $fileMatch[1];
            $mime = 'application/octet-stream';
            if (preg_match('/Content-Type:\s*(.+)/i', $headers, $mimeMatch)) {
                $mime = trim($mimeMatch[1]);
            }
            $GLOBALS['__files'][$name] = [
                'filename' => $filename,
                'mime'     => $mime,
                'size'     => strlen($body),
                'content'  => $body,   // binário cru (será salvo ou base64)
            ];
        } else {
            // Campo de texto normal
            $_POST[$name] = $body;
        }
    }
}

/* ---------- Helpers de leitura ---------- */

function post(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}

function files(): array
{
    return $GLOBALS['__files'] ?? [];
}

function file_info(string $name): ?array
{
    $f = $GLOBALS['__files'][$name] ?? null;
    if ($f === null) {
        return null;
    }
    return [
        'filename' => $f['filename'],
        'mime'     => $f['mime'],
        'size'     => $f['size'],
    ];
}

function file_content(string $name): ?string
{
    return $GLOBALS['__files'][$name]['content'] ?? null;
}

function file_save(string $name, string $destDir): ?string
{
    $f = $GLOBALS['__files'][$name] ?? null;
    if ($f === null) {
        return null;
    }
    // Sanitiza o nome (evita path traversal)
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $f['filename']);
    $dest = rtrim($destDir, '/') . '/' . uniqid('', true) . '_' . $safe;
    if (file_put_contents($dest, $f['content']) === false) {
        return null;
    }
    return $dest;
}
