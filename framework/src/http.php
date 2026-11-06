<?php
function http_parallel(array $urls, int $timeout = 10): array
{
    $mh = curl_multi_init();
    $handles = [];

    foreach ($urls as $i => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    // Loop robusto: só sai quando $running chega a 0
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.1);
        }
        // Se houve erro de multi, registra mas não aborta o loop
        if ($status !== CURLM_OK && $status !== CURLM_CALL_MULTI_PERFORM) {
            error_log("curl_multi_exec status: " . $status);
        }
    } while ($running > 0);

    // Lê os resultados
    $resultados = [];
    foreach ($handles as $i => $ch) {
        $corpo = curl_multi_getcontent($ch);
        $erro  = curl_error($ch);
        $info  = curl_getinfo($ch);

        if ($erro !== '') {
            error_log("curl erro [$i]: $erro (HTTP {$info['http_code']})");
            $resultados[$i] = null;
        } else {
            $resultados[$i] = $corpo;
        }

        curl_multi_remove_handle($mh, $ch);
    }

    curl_multi_close($mh);
    return $resultados;
}

/* ─── Helpers de request ─── */

function body(): array
{
    $raw = $GLOBALS['__body'] ?? '';
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function raw_body(): string
{
    return $GLOBALS['__body'] ?? '';
}

function method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/* ─── Helpers de response ─── */

function http(int $status, array $data): string
{
    $envelope = [
        '__status' => $status,
        '__body'   => $data,
    ];

    if (!empty($GLOBALS['__cookies'])) {
        $envelope['__cookies'] = $GLOBALS['__cookies'];
        $GLOBALS['__cookies'] = [];
    }

    $json = json_encode(
        $envelope,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        error_log('json_encode falhou: ' . json_last_error_msg());
        return json_encode([
            '__status' => 500,
            '__body'   => ['erro' => 'falha ao serializar resposta'],
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    return $json;
}

function json_response(array $data): string
{
    return http(200, $data);
}
