<?php

declare(strict_types=1);

require_once __DIR__ . '/attributes.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/schemas.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/multipart.php';

/*
|--------------------------------------------------------------------------
| Request Helpers
|--------------------------------------------------------------------------
*/

function raw_body(): string
{
    // file_get_contents retorna false (não null) em falha, por isso ?: e não ??
    return $GLOBALS['__body']
        ?? (file_get_contents('php://input') ?: '');
}

function body(): array
{
    $raw = $GLOBALS['__body'] ?? '';
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function query(string $key, mixed $default = null): mixed
{
    return $_GET[$key] ?? $default;
}

function request_header(string $name, ?string $default = null): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    return $_SERVER[$key] ?? $default;
}

function bearer_token(): ?string
{
    $auth = request_header('Authorization', '');

    if ($auth === '' || stripos($auth, 'Bearer ') !== 0) {
        return null;
    }

    $token = trim(substr($auth, 7));

    return $token !== '' ? $token : null;
}

function is_json_request(): bool
{
    return stripos(request_header('Content-Type', ''), 'application/json') !== false;
}

function client_ip(): string
{
    $forwarded = request_header('X-Forwarded-For', '');

    if ($forwarded !== '') {
        // Primeiro IP da lista é o cliente original
        return trim(explode(',', $forwarded)[0]);
    }

    return $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
}

/*
|--------------------------------------------------------------------------
| Response Helpers
|--------------------------------------------------------------------------
*/

/**
 * Resposta com status HTTP explícito (o Rust lê __status e __body).
 *
 *   return http(404, ['erro' => 'não encontrado']);
 *   return http(201, ['id' => 42]);
 */
function http(int $status, array $data): string
{
    $json = json_encode(
        ['__status' => $status, '__body' => $data],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    return $json !== false
        ? $json
        : '{"__status":500,"__body":{"erro":"json_encode falhou"}}';
}

function json_response(array $data): string
{
    return http(200, $data);
}

/*
|--------------------------------------------------------------------------
| Rate limit (APCu se disponível, senão memória do worker)
|--------------------------------------------------------------------------
*/

function rate_limited(string $key, int $limit, int $window = 60): bool
{
    if (function_exists('apcu_inc') && function_exists('apcu_enabled') && apcu_enabled()) {
        apcu_add($key, 0, $window); // cria com TTL só se não existir
        $count = apcu_inc($key);

        return $count !== false && $count > $limit;
    }

    static $local = [];
    $now = time();

    // Limpa janelas expiradas para não crescer sem limite
    if (count($local) > 10000) {
        foreach ($local as $k => $v) {
            if ($v['exp'] <= $now) {
                unset($local[$k]);
            }
        }
    }

    if (!isset($local[$key]) || $local[$key]['exp'] <= $now) {
        $local[$key] = ['n' => 0, 'exp' => $now + $window];
    }

    $local[$key]['n']++;

    return $local[$key]['n'] > $limit;
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
        'routes_mtime' => date('Y-m-d H:i:s', filemtime(__FILE__)),
    ]);
}




/*
|--------------------------------------------------------------------------
| Multipart/data
|--------------------------------------------------------------------------
*/

#[Post('/echo-form', summary: 'Echo de form-data', tags: ['multipart'])]
function echo_form(): string
{
    return http(200, [
        'post'   => $_POST,
        'files'  => array_map(
            fn($f) => ['filename' => $f['filename'], 'size' => $f['size'], 'mime' => $f['mime']],
            files()
        ),
    ]);
}

#[Post('/upload', summary: 'Upload de arquivo', tags: ['multipart'])]
function upload(): string
{
    $info = file_info('avatar');
    if ($info === null) {
        return http(400, ['erro' => 'arquivo avatar obrigatório']);
    }
    if ($info['size'] > 2 * 1024 * 1024) {
        return http(413, ['erro' => 'máximo 2MB']);
    }

    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $path = file_save('avatar', $dir);
    if ($path === null) {
        return http(500, ['erro' => 'falha ao salvar']);
    }

    return http(201, [
        'path'     => basename($path),
        'filename' => $info['filename'],
        'size'     => $info['size'],
        'mime'     => $info['mime'],
    ]);
}

/*
|--------------------------------------------------------------------------
| Headers / Query / Echo
|--------------------------------------------------------------------------
*/

#[Get('/headers')]
function headers_endpoint(): string
{
    return json_response([
        'authorization' => request_header('Authorization', 'não enviado'),
        'user_agent'    => request_header('User-Agent', 'não enviado'),
        'content_type'  => request_header('Content-Type', 'não enviado'),
        'bearer'        => bearer_token(),
        'is_json'       => is_json_request(),
        'todos_headers' => array_filter(
            $_SERVER,
            fn ($key) => str_starts_with($key, 'HTTP_'),
            ARRAY_FILTER_USE_KEY
        ),
    ]);
}

#[Get('/buscar')]
function buscar(): string
{
    $q = query('q', '');

    return json_response([
        'q'            => is_string($q) ? $q : '', // ?q[]=x vira array
        'page'         => max(1, (int) query('page', 1)),
        'limit'        => max(1, min(100, (int) query('limit', 20))),
        'todos_params' => $_GET,
    ]);
}

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

    if (!is_string($user) || !is_string($pass) || $user === '' || $pass === '') {
        return http(400, ['erro' => 'credenciais obrigatórias']);
    }

    if (hash_equals('admin', $user) && hash_equals('secret', $pass)) {
        return http(200, [
            'user'  => $user,
            'token' => 'fake-jwt-' . bin2hex(random_bytes(16)),
        ]);
    }

    return http(401, ['erro' => 'credenciais inválidas']);
}

#[Get('/me')]
function me(): string
{
    $token = bearer_token();

    if ($token === null) {
        return http(401, ['erro' => 'Authorization Bearer ausente']);
    }

    if (!str_starts_with($token, 'fake-jwt-')) {
        return http(401, ['erro' => 'token inválido']);
    }

    return http(200, [
        'user'        => 'admin',
        'autenticado' => true,
    ]);
}

/*
|--------------------------------------------------------------------------
| Recursos (PUT / PATCH / DELETE)
|--------------------------------------------------------------------------
*/

#[Put('/recursos/{id}', summary: 'Atualiza recurso (PUT)', tags: ['recursos'])]
function test_put(int $id): string
{
    return http(200, [
        'method' => method(),
        'id'     => $id,
        'body'   => body(),
    ]);
}

#[Patch('/recursos/{id}', summary: 'Atualiza parcialmente (PATCH)', tags: ['recursos'])]
function test_patch(int $id): string
{
    return http(200, [
        'method' => method(),
        'id'     => $id,
        'body'   => body(),
    ]);
}

#[Delete('/recursos/{id}', summary: 'Remove recurso (DELETE)', tags: ['recursos'])]
function test_delete(int $id): string
{
    return http(200, [
        'method' => method(),
        'id'     => $id,
    ]);
}

/*
|--------------------------------------------------------------------------
| Usuários / Pedidos
|--------------------------------------------------------------------------
*/

#[Get('/usuarios/{id}')]
function mostrar(int $id): string
{
    if ($id <= 0) {
        return http(400, ['erro' => 'id inválido']);
    }

    if ($id === 999) {
        return http(404, ['erro' => 'não encontrado']);
    }

    return http(200, ['id' => $id, 'nome' => 'Alice']);
}


#[Post('/pedido',
    summary: 'Cria pedido',
    description: 'Cria um novo pedido vinculado ao usuário autenticado',
    tags: ['pedidos'])]
#[Body('Pedido', required: true, example: [
    'usuario_id' => 1,
    'itens'      => [['produto_id' => 10, 'qty' => 2]],
    'total'      => 199.9,
])]
#[Response(201, 'Pedido criado', schema: 'Pedido', example: [
    'id' => 1, 'usuario_id' => 1, 'total' => 199.9,
])]
#[Response(400, 'Dados inválidos')]
#[Response(401, 'Não autenticado')]
#[Security('BearerAuth')]
function criar_pedido(): string
{
    $ip = client_ip();

    if (rate_limited("rate:$ip", 100, 60)) {
        return http(429, ['erro' => 'rate limit excedido']);
    }

    return http(201, ['pedido_id' => 123]);
}

/*
|--------------------------------------------------------------------------
| Clima
|--------------------------------------------------------------------------
*/

#[Get('/clima')]
function clima_endpoint(): string
{
    $cidades = ['São Paulo', 'Curitiba', 'Belo Horizonte'];

    $apiKey = getenv('WEATHER_TOKEN') ?: '';
    $apiUrl = getenv('WEATHER_API_URL')
        ?: 'https://api.weatherapi.com/v1/current.json';

    if ($apiKey === '') {
        return http(500, ['erro' => 'WEATHER_TOKEN não configurado']);
    }

    $urls = array_map(
        fn (string $cidade) => $apiUrl . '?' . http_build_query([
                'key'  => $apiKey,
                'q'    => $cidade,
                'lang' => 'pt',
            ]),
        $cidades
    );

    $responses = http_parallel($urls);

    $resultados = [];

    foreach ($cidades as $i => $cidade) {
        $dados = json_decode($responses[$i] ?? '', true);

        // A API pode responder JSON de erro (ex.: chave inválida) sem "current"
        if (!is_array($dados) || !isset($dados['current'])) {
            $resultados[] = [
                'cidade' => $cidade,
                'erro'   => 'falha na consulta',
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

    return http(200, ['resultados' => $resultados]);
}

/*
|--------------------------------------------------------------------------
| Reflection helpers (internos, prefixo __ => ignorados como rotas)
|--------------------------------------------------------------------------
*/

/** Nome curto de uma classe de atributo (sem namespace). */
function __attr_short_name(ReflectionAttribute $attr): string
{
    $parts = explode('\\', $attr->getName());

    return end($parts);
}

/** Lê um argumento de atributo por nome ou por posição. */
function __attr_arg(array $args, string $name, int $pos, mixed $default = null): mixed
{
    return $args[$name] ?? $args[$pos] ?? $default;
}

/**
 * Percorre as funções de usuário cujo nome começa com $prefix
 * (ou todas, se $prefix for '') e devolve os atributos com o nome curto
 * pedido, no formato [nome da função, ReflectionAttribute].
 *
 * @return iterable<array{0: string, 1: ReflectionAttribute}>
 */
function __functions_with_attributes(string $prefix = ''): iterable
{
    foreach (get_defined_functions()['user'] as $fn) {
        if ($prefix === '' && str_starts_with($fn, '__')) {
            continue; // pula funções internas (schemas, security, helpers)
        }

        if ($prefix !== '' && !str_starts_with($fn, $prefix)) {
            continue;
        }

        foreach ((new ReflectionFunction($fn))->getAttributes() as $attr) {
            yield [$fn, $attr];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Reflection: Routes / Schemas / Security
|--------------------------------------------------------------------------
*/

function get_routes(): array
{
    $methodMap = [
        'Get'    => 'GET',
        'Post'   => 'POST',
        'Put'    => 'PUT',
        'Patch'  => 'PATCH',
        'Delete' => 'DELETE',
    ];

    $routes = [];
    $seen   = []; // uma rota por função: o primeiro atributo HTTP vence

    foreach (__functions_with_attributes() as [$fn, $attr]) {
        $short = __attr_short_name($attr);

        if (!isset($methodMap[$short]) || isset($seen[$fn])) {
            continue;
        }

        $seen[$fn] = true;
        $a = $attr->getArguments();

        $routes[] = [
            'path'        => __attr_arg($a, 'path', 0, '/'),
            'summary'     => __attr_arg($a, 'summary', 1),
            'description' => __attr_arg($a, 'description', 2),
            'tags'        => __attr_arg($a, 'tags', 3, []),
            'method'      => $methodMap[$short],
            'handler'     => $fn,
            'params'      => [],
            'body'        => null,
            'responses'   => [],
            'security'    => [],
        ];
    }

    return $routes;
}

function get_schemas(): array
{
    $schemas = [];

    foreach (__functions_with_attributes('__schema') as [, $attr]) {
        if (__attr_short_name($attr) !== 'Schema') {
            continue;
        }

        $a = $attr->getArguments();

        $schemas[] = [
            'name'     => __attr_arg($a, 'name', 0, ''),
            'fields'   => __attr_arg($a, 'fields', 1, []),
            'required' => __attr_arg($a, 'required', 2, []),
        ];
    }

    return $schemas;
}

function get_security_schemes(): array
{
    $schemes = [];

    foreach (__functions_with_attributes('__security') as [, $attr]) {
        if (__attr_short_name($attr) !== 'SecurityScheme') {
            continue;
        }

        $a = $attr->getArguments();

        $schemes[] = [
            'name'          => __attr_arg($a, 'name', 0, ''),
            'type'          => __attr_arg($a, 'type', 1, 'http'),
            'scheme'        => __attr_arg($a, 'scheme', 2, ''),
            'bearer_format' => __attr_arg($a, 'bearerFormat', 3),
        ];
    }

    return $schemes;
}

#[Get('/routes')]
function routes_endpoint(): string
{
    $routes = get_routes();

    return http(200, [
        'count'  => count($routes),
        'routes' => $routes,
    ]);
}