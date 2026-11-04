<?php

declare(strict_types=1);

require_once __DIR__ . '/attributes.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/../app/config.php';

/*
|--------------------------------------------------------------------------
| Request Helpers
|--------------------------------------------------------------------------
*/

function body(): array
{
    $raw = $GLOBALS['__body']
        ?? file_get_contents('php://input')
        ?? '';

    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded)
        ? $decoded
        : [];
}

function raw_body(): string
{
    return $GLOBALS['__body']
        ?? file_get_contents('php://input')
        ?? '';
}

function method(): string
{
    return strtoupper(
        $GLOBALS['__method']
        ?? $_SERVER['REQUEST_METHOD']
        ?? 'GET'
    );
}

function query(string $key, mixed $default = null): mixed
{
    return $_GET[$key] ?? $default;
}

function request_header(
    string $name,
    ?string $default = null
): ?string {
    $key = 'HTTP_' . strtoupper(
            str_replace('-', '_', $name)
        );

    return $_SERVER[$key] ?? $default;
}

function bearer_token(): ?string
{
    $auth = request_header('Authorization', '');

    if ($auth === '') {
        return null;
    }

    if (stripos($auth, 'Bearer ') === 0) {
        return substr($auth, 7);
    }

    return null;
}

function is_json_request(): bool
{
    $contentType = request_header(
        'Content-Type',
        ''
    );

    return stripos(
            $contentType,
            'application/json'
        ) !== false;
}

/*
|--------------------------------------------------------------------------
| Response Helpers
|--------------------------------------------------------------------------
*/

function json_response(array $data): string
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    return $json !== false
        ? $json
        : '{"erro":"json_encode falhou"}';
}

/*
|--------------------------------------------------------------------------
| Diagnóstico
|--------------------------------------------------------------------------
*/

#[Get('/ping')]
function ping(): string
{
    return json_response([
        'pong' => true,
        'ts'   => time(),
    ]);
}

#[Get('/diag')]
function diag(): string
{
    return json_response([
        'php_version' => PHP_VERSION,
        'curl_multi'  => function_exists('curl_multi_init'),
        'json_bool'   => json_encode(['x' => true]),
        'json_int'    => json_encode(['x' => 1]),
        'method'      => method(),
    ]);
}

#[Get('/ini')]
function ini_endpoint(): string
{
    return json_response([
        'loaded_file'  => php_ini_loaded_file(),
        'cwd'          => getcwd(),
        'phprc'        => getenv('PHPRC'),
        'routes_file'  => realpath(__FILE__),
        'routes_mtime' => date(
            'Y-m-d H:i:s',
            filemtime(__FILE__)
        ),
    ]);
}

/*
|--------------------------------------------------------------------------
| Headers
|--------------------------------------------------------------------------
*/

#[Get('/headers')]
function headers_endpoint(): string
{
    return json_response([
        'authorization' => request_header(
            'Authorization',
            'não enviado'
        ),

        'user_agent' => request_header(
            'User-Agent',
            'não enviado'
        ),

        'content_type' => request_header(
            'Content-Type',
            'não enviado'
        ),

        'bearer' => bearer_token(),

        'is_json' => is_json_request(),

        'todos_headers' => array_filter(
            $_SERVER,
            fn ($key) => str_starts_with(
                $key,
                'HTTP_'
            ),
            ARRAY_FILTER_USE_KEY
        ),
    ]);
}

/*
|--------------------------------------------------------------------------
| Query String
|--------------------------------------------------------------------------
*/

#[Get('/buscar')]
function buscar(): string
{
    return json_response([
        'q'            => query('q', ''),
        'page'         => (int) query('page', 1),
        'limit'        => (int) query('limit', 20),
        'todos_params' => $_GET,
    ]);
}

/*
|--------------------------------------------------------------------------
| Echo
|--------------------------------------------------------------------------
*/

#[Post('/echo')]
function echo_endpoint(): string
{
    return json_response([
        'method' => method(),
        'body'   => body(),
        'raw'    => raw_body(),
        'query'  => $_GET,
    ]);
}

/*
|--------------------------------------------------------------------------
| Auth Demo
|--------------------------------------------------------------------------
*/

#[Post('/login')]
function login(): string
{
    $dados = body();

    $user = $dados['user'] ?? '';
    $pass = $dados['pass'] ?? '';

    if (
        $user === 'admin'
        && $pass === 'secret'
    ) {
        return json_response([
            'user'  => $user,
            'token' => 'fake-jwt-' .
                bin2hex(random_bytes(16)),
        ]);
    }

    return json_response([
        'erro' => 'credenciais inválidas'
    ]);
}

#[Get('/me')]
function me(): string
{
    $token = bearer_token();

    if ($token === null) {
        return json_response([
            'erro' => 'Authorization Bearer ausente'
        ]);
    }

    if (!str_starts_with(
        $token,
        'fake-jwt-'
    )) {
        return json_response([
            'erro' => 'token inválido'
        ]);
    }

    return json_response([
        'user' => 'admin',
        'autenticado' => true,
    ]);
}

/*
|--------------------------------------------------------------------------
| Clima
|--------------------------------------------------------------------------
*/

#[Get('/clima')]
function clima_endpoint(): string
{
    $cidades = [
        'São Paulo',
        'Curitiba',
        'Belo Horizonte'
    ];

    $apiKey = getenv('WEATHER_TOKEN') ?: '';

    $apiUrl = getenv('WEATHER_API_URL')
        ?: 'https://api.weatherapi.com/v1/current.json';

    if ($apiKey === '') {
        return json_response([
            'erro' => 'WEATHER_TOKEN não configurado'
        ]);
    }

    $urls = [];

    foreach ($cidades as $cidade) {
        $urls[] = $apiUrl . '?' .
            http_build_query([
                'key'  => $apiKey,
                'q'    => $cidade,
                'lang' => 'pt'
            ]);
    }

    $responses = http_parallel($urls);

    $resultados = [];

    foreach ($cidades as $i => $cidade) {

        $dados = json_decode(
            $responses[$i] ?? '',
            true
        );

        if (!is_array($dados)) {
            $resultados[] = [
                'cidade' => $cidade,
                'erro'   => 'falha na consulta'
            ];
            continue;
        }

        $resultados[] = [
            'cidade'      => $cidade,
            'temperatura' => $dados['current']['temp_c'] ?? null,
            'condicao'    => $dados['current']['condition']['text'] ?? null,
            'umidade'     => $dados['current']['humidity'] ?? null,
        ];
    }

    return json_response([
        'resultados' => $resultados
    ]);
}

/*
|--------------------------------------------------------------------------
| Reflection Routes
|--------------------------------------------------------------------------
*/

function get_routes(): array
{
    $routes = [];

    foreach (get_defined_functions()['user'] as $fn) {

        $ref = new ReflectionFunction($fn);

        foreach ($ref->getAttributes() as $attr) {

            $class = $attr->getName();

            try {
                $short = (
                new ReflectionClass($class)
                )->getShortName();
            } catch (Throwable) {
                continue;
            }

            if (!in_array(
                $short,
                ['Get', 'Post'],
                true
            )) {
                continue;
            }

            $arguments = $attr->getArguments();

            $routes[] = [
                'method' => $short === 'Get'
                    ? 'GET'
                    : 'POST',
                'path' => $arguments[0] ?? '/',
                'handler' => $fn,
            ];
        }
    }

    return $routes;
}

#[Get('/routes')]
function routes_endpoint(): string
{
    $routes = get_routes();

    return json_response([
        'count'  => count($routes),
        'routes' => $routes,
    ]);
}