<?php

declare(strict_types=1);

require_once __DIR__ . '/attributes.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/schemas.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/multipart.php';
require_once __DIR__ . '/cookies.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/validation.php';

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

/**
 * IP do cliente.
 *
 * Só confia em headers de proxy se TRUST_PROXY=1 (Nginx/Caddy na frente).
 * Com proxy confiável usa X-Real-IP ou o ÚLTIMO item do X-Forwarded-For
 * (o primeiro é controlado pelo cliente e pode ser forjado).
 * O Rust deve preencher $_SERVER['REMOTE_ADDR'] com o IP do peer.
 */
function client_ip(): string
{
    if (env_bool('TRUST_PROXY')) {
        $real = request_header('X-Real-IP', '');
        if ($real !== '') {
            return trim($real);
        }

        $forwarded = request_header('X-Forwarded-For', '');
        if ($forwarded !== '') {
            $parts = array_map('trim', explode(',', $forwarded));
            return end($parts) ?: 'desconhecido';
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
}

function env_bool(string $name, bool $default = false): bool
{
    $v = getenv($name);

    if ($v === false || $v === '') {
        return $default;
    }

    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
}

/** Lê um campo string do body JSON (ou null se ausente/tipo errado). */
function body_string(array $dados, string $key): ?string
{
    return is_string($dados[$key] ?? null) ? $dados[$key] : null;
}

/*
|--------------------------------------------------------------------------
| Response Helpers
|--------------------------------------------------------------------------
*/

/**
 * Resposta com status HTTP explícito (o Rust lê __status, __body e __cookies).
 *
 *   return http(404, ['erro' => 'não encontrado']);
 *   return http(201, ['id' => 42]);
 */
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
        error_log('json_encode falhou em http(): ' . json_last_error_msg());

        return '{"__status":500,"__body":{"erro":"falha ao serializar resposta"}}';
    }

    return $json;
}

function json_response(array $data): string
{
    return http(200, $data);
}

function unauthorized(): string
{
    return http(401, ['erro' => 'não autenticado']);
}

/** Endpoints de diagnóstico só respondem com APP_DEBUG=1. */
function debug_guard(): ?string
{
    return env_bool('APP_DEBUG')
        ? null
        : http(404, ['erro' => 'não encontrado']);
}

/*
|--------------------------------------------------------------------------
| Banco de dados (processo persistente: reconexão + rollback)
|--------------------------------------------------------------------------
*/

function db(): PDO
{
    if (!isset($GLOBALS['__pdo']) || !($GLOBALS['__pdo'] instanceof PDO)) {
        $GLOBALS['__pdo'] = new PDO(
            getenv('DB_DSN') ?: 'sqlite:/tmp/app.db',
            getenv('DB_USER') ?: '',
            getenv('DB_PASS') ?: '',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]
        );
    }

    return $GLOBALS['__pdo'];
}

function db_connection_lost(PDOException $e): bool
{
    $state = (string) ($e->errorInfo[0] ?? '');
    $msg   = $e->getMessage();

    return in_array($state, ['08000', '08003', '08006', '57P01', '57P02', '57P03'], true)
        || str_contains($msg, 'server closed the connection')
        || str_contains($msg, 'no connection to the server')
        || str_contains($msg, 'could not receive data')
        || str_contains($msg, 'gone away');
}

/**
 * Executa uma query e devolve o statement.
 * Reconecta e tenta de novo UMA vez se a conexão caiu
 * (nunca dentro de transação, para não quebrar a atomicidade).
 */
function db_exec(string $sql, array $params = []): PDOStatement
{
    for ($attempt = 0; ; $attempt++) {
        try {
            $stmt = db()->prepare($sql);

            foreach (array_values($params) as $i => $value) {
                $stmt->bindValue($i + 1, $value, match (true) {
                    is_int($value)  => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    $value === null => PDO::PARAM_NULL,
                    default         => PDO::PARAM_STR,
                });
            }

            $stmt->execute();

            return $stmt;
        } catch (PDOException $e) {
            if ($attempt === 0 && empty($GLOBALS['__in_tx']) && db_connection_lost($e)) {
                $GLOBALS['__pdo'] = null;
                continue;
            }

            throw $e;
        }
    }
}

/** SELECT (ou qualquer query com RETURNING) -> array de linhas. */
function db_query(string $sql, array $params = []): array
{
    return db_exec($sql, $params)->fetchAll();
}

/** Executa $fn dentro de uma transação, com rollback garantido em erro. */
function db_transaction(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    $GLOBALS['__in_tx'] = true;

    try {
        $result = $fn();
        $pdo->commit();

        return $result;
    } catch (Throwable $e) {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
            $GLOBALS['__pdo'] = null; // conexão inutilizável: descarta
        }

        throw $e;
    } finally {
        $GLOBALS['__in_tx'] = false;
    }
}

/**
 * O Rust deve chamar isto DEPOIS de cada requisição (inclusive após erro),
 * para que nada sobre para a requisição seguinte do mesmo worker.
 */
function __request_cleanup(): void
{
    $pdo = $GLOBALS['__pdo'] ?? null;

    if ($pdo instanceof PDO) {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
            $GLOBALS['__pdo'] = null;
        }
    }

    $GLOBALS['__in_tx']   = false;
    $GLOBALS['__cookies'] = [];
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
| Diagnóstico (somente com APP_DEBUG=1)
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
    if ($blocked = debug_guard()) {
        return $blocked;
    }

    return json_response([
        'php_version' => PHP_VERSION,
        'curl_multi'  => function_exists('curl_multi_init'),
        'json_bool'   => json_encode(['x' => true]),
        'json_int'    => json_encode(['x' => 1]),
        'method'      => method(),
    ]);
}

#[Get('/diag-pdo')]
function diag_pdo(): string
{
    if ($blocked = debug_guard()) {
        return $blocked;
    }

    try {
        $drivers = PDO::getAvailableDrivers();
    } catch (Throwable $e) {
        $drivers = ['erro' => $e->getMessage()];
    }

    return json_response([
        'pdo_loaded'    => extension_loaded('pdo'),
        'pdo_pgsql'     => extension_loaded('pdo_pgsql'),
        'pgsql'         => extension_loaded('pgsql'),
        'drivers'       => $drivers,
        'php_ini'       => php_ini_loaded_file(),
        'scan_dir'      => getenv('PHP_INI_SCAN_DIR') ?: '(default)',
        'extension_dir' => ini_get('extension_dir'),
    ]);
}

#[Get('/diag-db')]
function diag_db(): string
{
    if ($blocked = debug_guard()) {
        return $blocked;
    }

    try {
        $r = db_query('SELECT 1 AS ok');

        return json_response(['conexao' => 'OK', 'resultado' => $r[0] ?? null]);
    } catch (Throwable $e) {
        // Não devolve DSN nem usuário (o DSN pode conter senha)
        return http(500, [
            'erro' => get_class($e),
            'msg'  => $e->getMessage(),
        ]);
    }
}

#[Get('/ini')]
function ini_endpoint(): string
{
    if ($blocked = debug_guard()) {
        return $blocked;
    }

    return json_response([
        'loaded_file'  => php_ini_loaded_file(),
        'cwd'          => getcwd(),
        'phprc'        => getenv('PHPRC'),
        'routes_file'  => realpath(__FILE__),
        'routes_mtime' => date('Y-m-d H:i:s', filemtime(__FILE__)),
    ]);
}

#[Get('/headers')]
function headers_endpoint(): string
{
    if ($blocked = debug_guard()) {
        return $blocked;
    }

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

/*
|--------------------------------------------------------------------------
| Multipart / Upload
|--------------------------------------------------------------------------
*/

#[Post('/echo-form', summary: 'Echo de form-data', tags: ['multipart'])]
function echo_form(): string
{
    return http(200, [
        'post'  => $_POST,
        'files' => array_map(
            fn ($f) => [
                'filename' => $f['filename'],
                'size'     => $f['size'],
                'mime'     => $f['mime'],
            ],
            files()
        ),
    ]);
}

#[Post('/upload', summary: 'Upload de avatar (autenticado)', tags: ['multipart'])]
#[Response(201, 'Salvo')]
#[Response(400, 'Arquivo ausente ou tipo inválido')]
#[Response(401, 'Não autenticado')]
#[Response(413, 'Arquivo grande')]
#[Security('BearerAuth')]
function upload(): string
{
    if (current_user() === null) {
        return unauthorized();
    }

    $info = file_info('avatar');

    if ($info === null) {
        return http(400, ['erro' => 'arquivo avatar obrigatório']);
    }

    // O limite real de body deve ser imposto no Rust (413 antes de ler tudo).
    if ($info['size'] > 2 * 1024 * 1024) {
        return http(413, ['erro' => 'máximo 2MB']);
    }

    // Allowlist de extensão + MIME declarado (o MIME do cliente não é prova;
    // confira também o conteúdo se o arquivo for servido de volta).
    $permitidos = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'gif'  => ['image/gif'],
    ];

    $ext  = strtolower(pathinfo((string) ($info['filename'] ?? ''), PATHINFO_EXTENSION));
    $mime = strtolower((string) ($info['mime'] ?? ''));

    if (!isset($permitidos[$ext]) || !in_array($mime, $permitidos[$ext], true)) {
        return http(400, ['erro' => 'tipo de arquivo não permitido (jpg, png, webp, gif)']);
    }

    $dir = __DIR__ . '/../storage';

    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return http(500, ['erro' => 'falha ao preparar diretório']);
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
| Query / Echo
|--------------------------------------------------------------------------
*/

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
| Auth
|--------------------------------------------------------------------------
*/

#[Post('/login',
    summary: 'Autentica usuário',
    tags: ['auth'])]
#[Body('LoginRequest', example: ['email' => 'admin@test.com', 'senha' => '123456'])]
#[Response(200, 'Autenticado', example: ['user_id' => 1, 'token' => 'eyJ...'])]
#[Response(400, 'Campos obrigatórios')]
#[Response(401, 'Credenciais inválidas')]
#[Response(429, 'Muitas tentativas')]
function login(): string
{
    $dados = body();
    $email = strtolower(trim(body_string($dados, 'email') ?? ''));
    $senha = body_string($dados, 'senha') ?? '';

    if ($email === '' || $senha === '') {
        return http(400, ['erro' => 'email e senha obrigatórios']);
    }

    $ip = client_ip();

    if (rate_limited("login:ip:$ip", 20, 60) || rate_limited('login:email:' . sha1($email), 5, 60)) {
        return http(429, ['erro' => 'muitas tentativas, aguarde']);
    }

    $rows = db_query('SELECT id, senha_hash FROM usuarios WHERE email = ?', [$email]);
    $user = $rows[0] ?? null;

    // Sempre roda um password_verify, mesmo sem usuário: evita descobrir
    // quais e-mails existem pela diferença de tempo de resposta.
    static $dummyHash = null;
    $dummyHash ??= password_hash('dummy-password', PASSWORD_BCRYPT);

    $ok = password_verify($senha, $user['senha_hash'] ?? $dummyHash);

    if ($user === null || !$ok) {
        return http(401, ['erro' => 'credenciais inválidas']);
    }

    $token = jwt_encode(['user_id' => (int) $user['id'], 'email' => $email]);

    // Cookie para browser + token no JSON para SPA/mobile.
    // (httponly/samesite/secure precisam ser suportados pelo cookies.php)
    set_cookie('auth_token', $token, [
        'expires'  => time() + 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => env_bool('COOKIE_SECURE'),
    ]);

    return http(200, [
        'user_id' => (int) $user['id'],
        'token'   => $token,
    ]);
}

#[Post('/register',
    summary: 'Cadastro de usuário',
    tags: ['auth'])]
#[Body('RegisterRequest', example: ['email' => 'user@test.com', 'senha' => '123456'])]
#[Response(201, 'Criado')]
#[Response(400, 'Dados inválidos')]
#[Response(409, 'Email já cadastrado')]
function register(): string
{
    $dados = body();
    $email = strtolower(trim(body_string($dados, 'email') ?? ''));
    $senha = body_string($dados, 'senha') ?? '';

    if (
        $email === ''
        || strlen($email) > 254
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    ) {
        return http(400, ['erro' => 'email inválido']);
    }

    // bcrypt ignora tudo após 72 bytes
    if (strlen($senha) < 6 || strlen($senha) > 72) {
        return http(400, ['erro' => 'senha deve ter de 6 a 72 caracteres']);
    }

    if (rate_limited('register:ip:' . client_ip(), 10, 60)) {
        return http(429, ['erro' => 'muitas tentativas, aguarde']);
    }

    try {
        $rows = db_query(
            'INSERT INTO usuarios (email, senha_hash) VALUES (?, ?) RETURNING id',
            [$email, password_hash($senha, PASSWORD_BCRYPT)]
        );

        return http(201, ['id' => (int) $rows[0]['id']]);
    } catch (PDOException $e) {
        // SQLSTATE: 23505 (Postgres), 23000 (MySQL/SQLite)
        if (in_array((string) $e->getCode(), ['23505', '23000'], true)) {
            return http(409, ['erro' => 'email já cadastrado']);
        }

        throw $e;
    }
}

#[Get('/me',
    summary: 'Retorna usuário autenticado',
    tags: ['auth'])]
#[Response(200, 'OK')]
#[Response(401, 'Não autenticado')]
#[Security('BearerAuth')]
function me(): string
{
    $user = current_user();

    return $user === null ? unauthorized() : http(200, $user);
}

#[Post('/logout', summary: 'Logout', tags: ['auth'])]
function logout(): string
{
    clear_cookie('auth_token');

    return http(200, ['ok' => true]);
}

/*
|--------------------------------------------------------------------------
| Produtos
|--------------------------------------------------------------------------
*/

/**
 * Valida os campos de produto.
 * $parcial = true (PUT): só valida o que veio. false (POST): exige nome e preco.
 *
 * @return array{0: ?array, 1: ?string} [campos, erro]
 */
function __produto_campos(array $d, bool $parcial): array
{
    $campos = [];

    if (!$parcial || array_key_exists('nome', $d)) {
        $nome = is_string($d['nome'] ?? null) ? trim($d['nome']) : '';

        if ($nome === '' || mb_strlen($nome) > 200) {
            return [null, 'nome obrigatório (até 200 caracteres)'];
        }

        $campos['nome'] = $nome;
    }

    if (!$parcial || array_key_exists('preco', $d)) {
        $preco = $d['preco'] ?? null;

        if (!is_int($preco) && !is_float($preco) && !(is_string($preco) && is_numeric($preco))) {
            return [null, 'preco deve ser numérico'];
        }

        $preco = (float) $preco;

        if ($preco <= 0 || $preco > 99999999.99) {
            return [null, 'preco deve ser maior que 0'];
        }

        $campos['preco'] = round($preco, 2);
    }

    if (array_key_exists('estoque', $d) || !$parcial) {
        $estoque = filter_var($d['estoque'] ?? 0, FILTER_VALIDATE_INT);

        if ($estoque === false || $estoque < 0) {
            return [null, 'estoque deve ser inteiro >= 0'];
        }

        $campos['estoque'] = $estoque;
    }

    return [$campos, null];
}

#[Get('/produtos',
    summary: 'Lista produtos (paginado)',
    tags: ['produtos'])]
#[Param('page', 'query', 'int', false, 'Página (default 1)')]
#[Param('limit', 'query', 'int', false, 'Itens por página (1-100, default 20)')]
#[Response(200, 'OK')]
function listar_produtos(): string
{
    $page   = max(1, (int) query('page', 1));
    $limit  = max(1, min(100, (int) query('limit', 20)));
    $offset = ($page - 1) * $limit;

    $total = (int) (db_query('SELECT COUNT(*) AS n FROM produtos')[0]['n'] ?? 0);

    $rows = db_query(
        'SELECT id, nome, preco, estoque FROM produtos ORDER BY id DESC LIMIT ? OFFSET ?',
        [$limit, $offset]
    );

    return http(200, [
        'produtos'   => $rows,
        'pagination' => [
            'page'  => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int) ceil($total / $limit),
        ],
    ]);
}

#[Post('/produtos',
    summary: 'Cria produto',
    tags: ['produtos'])]
#[Body('ProdutoRequest', example: [
    'nome'  => 'Caneta Azul',
    'preco' => 2.50,
    'categoria' => 'livros',
])]
#[Response(201, 'Criado')]
#[Response(400, 'Dados inválidos')]
function criar_produto(): string
{
    $dados = validate_body('ProdutoRequest');  // 400 automático se inválido

    // Aqui $dados já tem tipos corretos: nome=string, preco=float, estoque=int, ativo=bool
    $stmt = db_exec(
        'INSERT INTO produtos (nome, preco, estoque) VALUES (?, ?, ?)',
        [$dados['nome'], $dados['preco'], $dados['estoque']]
    );

    return http(201, [
        'id'    => (int) db()->lastInsertId(),
        'nome'  => $dados['nome'],
        'preco' => $dados['preco'],
    ]);
}

#[Get('/produtos/{id}',
    summary: 'Busca produto',
    tags: ['produtos'])]
#[Param('id', 'path', 'int', true, 'ID')]
#[Response(200, 'OK')]
#[Response(404, 'Não encontrado')]
function buscar_produto(int $id): string
{
    $rows = db_query('SELECT id, nome, preco, estoque FROM produtos WHERE id = ?', [$id]);

    return empty($rows)
        ? http(404, ['erro' => 'não encontrado'])
        : http(200, $rows[0]);
}

#[Put('/produtos/{id}',
    summary: 'Atualiza produto (campos enviados)',
    tags: ['produtos'])]
#[Param('id', 'path', 'int', true, 'ID')]
#[Body('ProdutoRequest')]
#[Response(200, 'OK')]
#[Response(400, 'Dados inválidos')]
#[Response(401, 'Não autenticado')]
#[Response(404, 'Não encontrado')]
#[Security('BearerAuth')]
function atualizar_produto(int $id): string
{
    if (current_user() === null) {
        return unauthorized();
    }

    [$campos, $erro] = __produto_campos(body(), true);

    if ($campos === null) {
        return http(400, ['erro' => $erro]);
    }

    if ($campos === []) {
        return http(400, ['erro' => 'nenhum campo para atualizar']);
    }

    // Os nomes de coluna vêm de __produto_campos (allowlist fixa), nunca do cliente
    $set = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($campos)));

    $rows = db_query(
        "UPDATE produtos SET $set WHERE id = ? RETURNING id, nome, preco, estoque",
        [...array_values($campos), $id]
    );

    return empty($rows)
        ? http(404, ['erro' => 'não encontrado'])
        : http(200, $rows[0]);
}

#[Delete('/produtos/{id}',
    summary: 'Remove produto',
    tags: ['produtos'])]
#[Param('id', 'path', 'int', true, 'ID')]
#[Response(200, 'OK')]
#[Response(401, 'Não autenticado')]
#[Response(404, 'Não encontrado')]
#[Security('BearerAuth')]
function remover_produto(int $id): string
{
    if (current_user() === null) {
        return unauthorized();
    }

    $rows = db_query('DELETE FROM produtos WHERE id = ? RETURNING id', [$id]);

    return empty($rows)
        ? http(404, ['erro' => 'não encontrado'])
        : http(200, ['ok' => true]);
}

/*
|--------------------------------------------------------------------------
| Recursos (teste de métodos PUT / PATCH / DELETE)
|--------------------------------------------------------------------------
*/

#[Put('/recursos/{id}', summary: 'Atualiza recurso (PUT)', tags: ['recursos'])]
function test_put(int $id): string
{
    return http(200, ['method' => method(), 'id' => $id, 'body' => body()]);
}

#[Patch('/recursos/{id}', summary: 'Atualiza parcialmente (PATCH)', tags: ['recursos'])]
function test_patch(int $id): string
{
    return http(200, ['method' => method(), 'id' => $id, 'body' => body()]);
}

#[Delete('/recursos/{id}', summary: 'Remove recurso (DELETE)', tags: ['recursos'])]
function test_delete(int $id): string
{
    return http(200, ['method' => method(), 'id' => $id]);
}

/*
|--------------------------------------------------------------------------
| Usuários / Pedidos (exemplos)
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
    'itens' => [['produto_id' => 10, 'qty' => 2]],
])]
#[Response(201, 'Pedido criado', schema: 'Pedido', example: [
    'pedido_id' => 1,
])]
#[Response(400, 'Dados inválidos')]
#[Response(401, 'Não autenticado')]
#[Response(429, 'Rate limit excedido')]
#[Security('BearerAuth')]
function criar_pedido(): string
{
    $user = current_user();

    if ($user === null) {
        return unauthorized();
    }

    if (rate_limited('pedido:ip:' . client_ip(), 100, 60)) {
        return http(429, ['erro' => 'rate limit excedido']);
    }

    $itens = body()['itens'] ?? null;

    if (!is_array($itens) || $itens === [] || count($itens) > 100) {
        return http(400, ['erro' => 'itens obrigatório (1 a 100 itens)']);
    }

    foreach ($itens as $item) {
        $produtoId = is_array($item) ? filter_var($item['produto_id'] ?? null, FILTER_VALIDATE_INT) : false;
        $qty       = is_array($item) ? filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT) : false;

        if ($produtoId === false || $produtoId <= 0 || $qty === false || $qty <= 0) {
            return http(400, ['erro' => 'cada item precisa de produto_id e qty inteiros > 0']);
        }
    }

    // TODO: persistir o pedido (use db_transaction) e devolver o id real.
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
 * (ou todas as públicas, se $prefix for '') e devolve [nome da função, atributo].
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
| Reflection: Routes / Schemas / Security (rodam só no boot)
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
    if ($blocked = debug_guard()) {
        return $blocked;
    }

    $routes = get_routes();

    return http(200, [
        'count'  => count($routes),
        'routes' => $routes,
    ]);
}