<p align="center">
  <img src="assets/logo.png" alt="Performatic PHP" width="360">
</p>

<h1 align="center">Performatic PHP</h1>

<p align="center">
  <strong>API em PHP procedural. Motor em Rust. Sem Laravel.</strong>
  <br>
  <code>#[Get('/users')]</code> · <code>body()</code> · <code>db_query()</code> · <code>http(404, ...)</code>
</p>

<p align="center">
  <a href="LICENSE"><img alt="Licença MIT" src="https://img.shields.io/badge/licen%C3%A7a-MIT-blue.svg"></a>
  <img alt="PHP 8.5" src="https://img.shields.io/badge/PHP-8.5-777BB4.svg">
  <img alt="Rust" src="https://img.shields.io/badge/motor-Rust-orange.svg">
  <img alt="PostgreSQL" src="https://img.shields.io/badge/PostgreSQL-16-336791.svg">
</p>

<p align="center">
  OpenAPI automático · JWT · PostgreSQL · Cache in-memory · Upload multipart · HTTP paralelo · CLI
</p>

<p align="center">
  <img src="assets/performatic.png" alt="Performatic PHP em ação" width="720">
</p>

---

## Sumário

- [Por que existe](#por-que-existe)
- [Como funciona](#como-funciona)
- [Instalação](#instalação)
- [Sua primeira rota](#sua-primeira-rota)
- [⚠️ Segurança e estado do worker](#️-segurança-e-estado-do-worker)
- [Referência](#referência)
- [OpenAPI automático](#openapi-automático)
- [CLI](#cli)
- [Estrutura do projeto](#estrutura-do-projeto)
- [Pacotes recomendados](#pacotes-recomendados)
- [Migrations](#migrations)
- [Deploy em produção](#deploy-em-produção)
- [Performance](#performance)
- [Cache in-memory](#cache-in-memory)
- [Troubleshooting](#troubleshooting)
- [Contribuindo](#contribuindo)

---

## Por que existe

Você já sabe PHP. Você já sabe SQL. Não precisa aprender Eloquent, Blade, Artisan, Facades ou container de DI para criar uma API.

**Performatic PHP** é PHP procedural puro com um motor em Rust. Você escreve funções; o framework cuida do HTTP.

```php
#[Get('/usuarios/{id}')]
function buscar(int $id): string
{
    $user = db_query('SELECT * FROM usuarios WHERE id = ?', [$id]);
    return empty($user) ? http(404, ['erro' => 'não encontrado']) : http(200, $user[0]);
}
```

Isso é tudo. Swagger em `/docs`, JWT, cache e upload — sem framework pesado.

**Use quando** você quer uma API JSON rápida, com poucas dependências e código fácil de ler.
**Talvez não seja a melhor escolha quando** você precisa de ORM, renderização de views, filas ou um ecossistema de pacotes de framework completo.

---

## Como funciona

```
Cliente ──► Nginx ──► Worker (Rust) ──► PHP embed ──► sua função #[Get(...)]
                          │                               │
                          ├─ roteamento, cache, JWT       └─ db_query(), http(), body()
                          └─ multipart, HTTP paralelo
```

O Rust recebe a conexão, resolve a rota a partir dos atributos PHP e executa sua função dentro de um PHP embutido, evitando o custo de inicializar o PHP a cada request.

> Como o worker é **persistente**, leia a seção [Segurança e estado do worker](#️-segurança-e-estado-do-worker) antes de ir para produção.

---

## Instalação

### Docker (recomendado)

```bash
git clone https://github.com/withvisionstack/PERFORMATIC-PHP meu-projeto
cd meu-projeto
cp .env.example .env
docker compose up -d --build
```

Teste:

```bash
curl http://localhost:8000/ping
# {"pong":true,"ts":...}
```

Swagger: <http://localhost:8000/docs>

### VPS (Ubuntu/Debian, sem Docker)

```bash
git clone https://github.com/withvisionstack/PERFORMATIC-PHP meu-projeto
cd meu-projeto
./install.sh
cargo run --release
```

O `install.sh` instala PHP, Rust e dependências do sistema, cria o `.env` e compila.

### Requisitos (instalação manual)

- Linux (Ubuntu 22.04+ ou Debian 12+)
- Rust (versão indicada no `Cargo.toml`)
- PHP 8.5 com `php-dev`, `php-curl` e `php-pgsql` (ou `php-mysql`)
- `libclang-dev`, `build-essential`, `pkg-config`
- Windows e oficialmente não suportado somente via WSL2/Ubuntu 

---

## Sua primeira rota

**1. Crie `api/routes/produtos.php`:**

```php
<?php

#[Get('/produtos', summary: 'Lista produtos', tags: ['produtos'])]
function listar_produtos(): string
{
    $produtos = db_query('SELECT id, nome, preco FROM produtos LIMIT 20');
    return http(200, ['produtos' => $produtos, 'total' => count($produtos)]);
}
```

**2. Registre em `api/routes.php`:**

```php
require_once __DIR__ . '/routes/produtos.php';
```

**3. Crie a tabela:**

```bash
docker compose exec postgres psql -U performatic -d performatic -c "
CREATE TABLE produtos (
    id SERIAL PRIMARY KEY,
    nome TEXT NOT NULL,
    preco NUMERIC(10,2) NOT NULL
);
INSERT INTO produtos (nome, preco) VALUES ('Caneta', 2.50), ('Caderno', 15.00);
"
```

**4. Teste:**

```bash
curl http://localhost:8000/produtos | jq
# {"produtos":[{"id":1,"nome":"Caneta","preco":"2.50"},{"id":2,"nome":"Caderno","preco":"15.00"}],"total":2}
```

Pronto. O Swagger em `/docs` já documenta a rota automaticamente.

---

## ⚠️ Segurança e estado do worker

O worker é **persistente**: o PHP não reinicia a cada request. Isso é o que dá a performance, mas muda as regras do jogo em relação ao PHP-FPM.

| O que | Comportamento entre requests |
| :--- | :--- |
| Superglobais (`$_GET`, `$_POST`, `$_SERVER`...) | ✅ Resetadas a cada request |
| Variáveis `static` dentro de funções | ⚠️ **Persistem** |
| `$GLOBALS` customizados | ⚠️ **Persistem** |
| Conexões PDO | ⚠️ **Persistem** (reaproveitadas) |

**Regras de ouro**

- ❌ **Nunca** guarde dados de usuário em `static` ou `$GLOBALS`: o próximo request pode lê-los (vazamento entre usuários).
  Use **JWT** (`current_user()`) ou o **banco**.
- ✅ Cache compartilhado de propósito é ok, mas só com dados **não sensíveis**, e use o [cache in-memory](#cache-in-memory) do framework em vez de improvisar.
- ✅ Sempre finalize transações (`commit`/`rollBack`): uma transação aberta vaza para o próximo request na mesma conexão.
- ✅ Valide e limite uploads (tamanho, tipo) e use prepared statements em todo SQL.
- ✅ Em produção: segredo JWT forte no `.env` e HTTPS.

```php
// ❌ Errado: o valor sobrevive ao request e vaza para outro usuário
function usuario_atual() {
    static $user = null;
    return $user ??= current_user();
}

// ✅ Certo: leia de novo a cada request
function usuario_atual() {
    return current_user();
}
```

---

## Referência

### Roteamento

```php
#[Get('/caminho')]       // GET
#[Post('/caminho')]      // POST
#[Put('/caminho')]       // PUT
#[Patch('/caminho')]     // PATCH
#[Delete('/caminho')]    // DELETE

#[Get('/usuarios/{id}')] // parâmetro de URL, já tipado
function buscar(int $id): string { /* $id chega como int */ }
```

### Leitura de request

| Helper | Retorno | Exemplo |
| :--- | :--- | :--- |
| `body()` | `array` | `$dados = body();` |
| `raw_body()` | `string` | `$xml = raw_body();` |
| `method()` | `string` | `if (method() === 'POST')` |
| `query($key, $default)` | `mixed` | `$page = (int) query('page', 1);` |
| `header($name, $default)` | `?string` | `header('User-Agent')` |
| `bearer_token()` | `?string` | `$token = bearer_token();` |
| `current_user()` | `?array` | `$user = current_user();` |

### Escrita de response

| Helper | Status | Uso |
| :--- | :--- | :--- |
| `json_response($array)` | 200 | `return json_response([...]);` |
| `http($status, $array)` | customizado | `return http(201, ['id' => 1]);` |

Erros seguem o mesmo padrão: `return http(400, ['erro' => 'inválido']);`

Status comuns: `200`, `201`, `204`, `400`, `401`, `403`, `404`, `409`, `422`, `429`, `500`.

### Banco de dados

Sempre use prepared statements (`?`) — nunca concatene entrada do usuário no SQL.

```php
// SELECT (retorna array)
$rows = db_query('SELECT * FROM produtos WHERE preco > ?', [100]);

// INSERT
$pdo  = db();
$stmt = $pdo->prepare('INSERT INTO produtos (nome, preco) VALUES (?, ?)');
$stmt->execute(['Caneta', 2.50]);
$id = (int) $pdo->lastInsertId();

// UPDATE
$pdo->prepare('UPDATE produtos SET preco = ? WHERE id = ?')->execute([3.00, $id]);

// DELETE
$pdo->prepare('DELETE FROM produtos WHERE id = ?')->execute([$id]);

// Transação
$pdo->beginTransaction();
try {
    // ...
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
```

### Autenticação JWT

```php
#[Post('/login', tags: ['auth'])]
#[Body('LoginRequest', example: ['email' => 'user@test.com', 'senha' => '123456'])]
#[Response(200, 'Autenticado')]
#[Response(401, 'Credenciais inválidas')]
function login(): string
{
    $dados = body();
    $stmt = db()->prepare('SELECT id, senha_hash FROM usuarios WHERE email = ?');
    $stmt->execute([$dados['email'] ?? '']);
    $user = $stmt->fetch();

    if (!$user || !password_verify($dados['senha'] ?? '', $user['senha_hash'])) {
        return http(401, ['erro' => 'credenciais inválidas']);
    }

    $token = jwt_encode(['user_id' => $user['id'], 'email' => $dados['email']]);
    set_cookie('auth_token', $token, ['expires' => time() + 3600]);

    return http(200, ['token' => $token]);
}

#[Get('/me', tags: ['auth'])]
#[Security('BearerAuth')]
#[Response(200, 'OK')]
#[Response(401, 'Não autenticado')]
function me(): string
{
    $user = current_user(); // funciona com Bearer OU cookie
    return $user === null ? http(401, ['erro' => 'não autenticado']) : http(200, $user);
}
```

**SPA / mobile** (header Bearer):

```javascript
fetch('/me', { headers: { Authorization: `Bearer ${token}` } })
```

**Navegador** (cookie HttpOnly, inacessível ao JS):

```javascript
fetch('/me', { credentials: 'include' })
```

### Upload de arquivos

```php
#[Post('/upload', tags: ['arquivos'])]
#[Response(201, 'Salvo')]
#[Response(413, 'Arquivo grande')]
function upload(): string
{
    $info = file_info('arquivo');
    if ($info === null) return http(400, ['erro' => 'arquivo obrigatório']);
    if ($info['size'] > 2 * 1024 * 1024) return http(413, ['erro' => 'máx 2MB']);

    $path = file_save('arquivo', __DIR__ . '/../storage');
    return http(201, ['path' => basename($path), 'size' => $info['size']]);
}
```

```bash
curl -X POST http://localhost:8000/upload -F "arquivo=@foto.jpg"
```

### HTTP paralelo (APIs externas)

```php
#[Get('/clima')]
function clima(): string
{
    $urls = [
        'https://api.clima.com/sao-paulo',
        'https://api.clima.com/curitiba',
    ];
    $respostas = http_parallel($urls); // dispara as duas em paralelo
    return http(200, ['dados' => array_map('json_decode', $respostas)]);
}
```

---

## OpenAPI automático

Todo endpoint anotado aparece no Swagger em `/docs`.

<p align="center">
  <img src="Performatic_PHP_generation_doc_API.png" alt="Documentação da API gerada automaticamente" width="720">
</p>

```php
#[Post('/produtos',
    summary: 'Cria produto',
    description: 'Adiciona um produto ao catálogo',
    tags: ['produtos'])]
#[Body('ProdutoRequest', example: ['nome' => 'Caneta', 'preco' => 2.50])]
#[Response(201, 'Criado', schema: 'Produto')]
#[Response(400, 'Dados inválidos')]
#[Security('BearerAuth')]
function criar(): string { /* ... */ }
```

**Schemas** em `api/schemas.php`:

```php
#[Schema('ProdutoRequest', [
    'nome'  => 'string',
    'preco' => 'float',
], required: ['nome', 'preco'])]
function __schema_produto_request() {}

#[Schema('Produto', [
    'id'    => 'int',
    'nome'  => 'string',
    'preco' => 'float',
])]
function __schema_produto() {}
```

**Security schemes** em `api/security.php`:

```php
#[SecurityScheme('BearerAuth', 'http', 'bearer', 'JWT')]
function __security_bearer() {}
```

Abra <http://localhost:8000/docs> para a Swagger UI interativa, com os botões **Authorize** e **Try it out**.

---

## CLI

<p align="center">
  <img src="CLI_Application.png" alt="Performatic PHP CLI" width="720">
</p>

<!-- Descreva aqui os comandos da CLI (ex.: criar projeto, gerar rota, rodar migrations). -->

---

## Estrutura do projeto

```
meu-projeto/
├── src/                  ← motor Rust (não editar)
├── api/
│   ├── routes/           ← suas rotas, um arquivo por domínio
│   │   ├── auth.php
│   │   ├── usuarios.php
│   │   ├── produtos.php
│   │   └── pedidos.php
│   ├── routes.php        ← entrypoint (só requires)
│   ├── schemas.php       ← schemas OpenAPI
│   ├── security.php      ← schemes de autenticação
│   ├── attributes.php    ← #[Get], #[Post]... (não editar)
│   ├── http.php          ← http_parallel (não editar)
│   ├── cookies.php       ← set_cookie (não editar)
│   ├── jwt.php           ← jwt_encode/decode (não editar)
│   ├── multipart.php     ← upload (não editar)
│   └── db.php            ← helpers PDO (não editar)
├── app/config.php        ← carrega .env (não editar)
├── db/init.sql           ← schema inicial
├── storage/              ← uploads (ignorado pelo git)
├── nginx/nginx.conf
├── .env / .env.example
├── docker-compose.yml
├── Dockerfile
├── Cargo.toml
└── composer.json
```

**Divida por domínio** quando passar de ~20 rotas. Cada arquivo em `api/routes/` é independente.

---

## Pacotes recomendados

| Pacote | Uso | Instalação |
| :--- | :--- | :--- |
| `firebase/php-jwt` | JWT (já incluído) | `composer require firebase/php-jwt` |
| `robmorgan/phinx` | Migrations | `composer require robmorgan/phinx` |
| `monolog/monolog` | Logs estruturados | `composer require monolog/monolog` |
| `ramsey/uuid` | UUIDs | `composer require ramsey/uuid` |
| `guzzlehttp/guzzle` | Cliente HTTP (alternativa ao `http_parallel`) | `composer require guzzlehttp/guzzle` |
| `stripe/stripe-php` | Pagamentos | `composer require stripe/stripe-php` |
| `phpmailer/phpmailer` | E-mail SMTP | `composer require phpmailer/phpmailer` |

**Evite** `illuminate/database` (Eloquent), `doctrine/orm`, `symfony/console` e `laravel/framework`: trazem peso e um paradigma OOP que contradiz a proposta do framework.

---

## Migrations

Usamos o [Phinx](https://phinx.org):

```bash
composer require robmorgan/phinx
vendor/bin/phinx init
vendor/bin/phinx create CreateProdutos
```

`db/migrations/20260101000000_create_produtos.php`:

```php
<?php
use Phinx\Migration\AbstractMigration;

class CreateProdutos extends AbstractMigration
{
    public function change(): void
    {
        $this->table('produtos')
            ->addColumn('nome', 'string', ['limit' => 200])
            ->addColumn('preco', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addTimestamps()
            ->create();
    }
}
```

```bash
vendor/bin/phinx migrate
```

---

## Deploy em produção

### Docker + Nginx

```bash
# 1 worker
docker compose up -d --build

# 4 workers (o Nginx balanceia)
docker compose up -d --scale api=4
```

O `nginx/nginx.conf` já usa `least_conn` e `resolver 127.0.0.11` (DNS do Docker), então escala sem configuração extra.

### Quantos workers?

Regra prática: **N = número de núcleos da CPU**. Acima disso não há ganho de throughput, só contenção.

| Servidor | Workers | RAM estimada | Throughput estimado |
| :--- | :--- | :--- | :--- |
| 2 vCPU / 2 GB | 2 | ~100 MB | ~2–5k req/s |
| 4 vCPU / 4 GB | 4 | ~200 MB | ~5–10k req/s |
| 8 vCPU / 8 GB | 8 | ~400 MB | ~10–20k req/s |

### systemd (sem Docker)

`/etc/systemd/system/performatic@.service`:

```ini
[Unit]
Description=Performatic PHP Worker %i
After=network.target postgresql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/api
EnvironmentFile=/var/www/api/.env
Environment="PORT=760%i"
ExecStart=/var/www/api/target/release/php-server
Restart=always
RestartSec=2
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now performatic@{0..3}
```

### HTTPS com Certbot

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d api.seudominio.com
```

---

## Performance

Benchmarks em VPS de 4 vCPU / 4 GB, Docker Compose (Nginx + PostgreSQL 16), medidos com [`wrk`](https://github.com/wg/wrk).

### Motor puro (host, sem Docker e sem Nginx)

| Endpoint | Throughput | Latência p50 |
| :--- | :--- | :--- |
| `GET /ping` (trivial) | **~55.000 req/s** | < 5 ms |
| `GET /produtos` (DB) | ~2.000 req/s | ~2 ms |
| `POST /login` (bcrypt) | ~80 req/s | ~12 ms |

### Docker + Nginx + 1 worker

| Endpoint | Throughput | Latência p50 | Latência p99 |
| :--- | :--- | :--- | :--- |
| `GET /health` | ~1.000 req/s | 44 ms | 790 ms |
| `GET /produtos` (DB) | ~300 req/s | 156 ms | 1,21 s |
| `POST /produtos` (DB + validação) | ~300 req/s | 133 ms | 403 ms |

O overhead de ~5× em relação ao host vem da bridge do Docker e dos dois saltos de rede. A latência alta reflete o **modelo serial de um único worker**: cada request espera a anterior terminar. Escalar workers resolve isso.

### Escalando com N workers

```bash
docker compose up -d --scale api=4
```

| Setup | `GET /health` | `GET /produtos` |
| :--- | :--- | :--- |
| 1 worker | ~1.000 req/s | ~300 req/s |
| 4 workers | ~4.000 req/s | ~1.200 req/s |

O Nginx faz load balancing (`least_conn`). O ganho é próximo de linear até saturar a CPU.

### Comparação com Laravel + FPM (mesmo hardware)

| Endpoint | Performatic | Laravel | Ganho |
| :--- | :--- | :--- | :--- |
| `/ping` | 3.500 req/s | 350 req/s | ~10× |
| `/produtos` (DB) | 400 req/s | 150 req/s | ~2,7× |
| `/login` | 80 req/s | 40 req/s | ~2× |

> O gargalo real costuma ser o banco, não o framework: o `eval` do PHP leva ~17 µs, enquanto uma query no PostgreSQL leva de 1 a 50 ms. Benchmarks variam com hardware e configuração — reproduza no seu ambiente.

### Reproduzindo os testes

```bash
# 1 worker
wrk -t4 -c100 -d15s --latency http://localhost:8000/health

# 4 workers
docker compose up -d --scale api=4 && sleep 15
docker compose ps | grep api | wc -l        # esperado: 4
wrk -t4 -c100 -d15s --latency http://localhost:8000/health

# volta para 1 worker (dev)
docker compose up -d --scale api=1
```

---

## Cache in-memory

- Apenas `GET` é cacheado (nunca `POST`, `PUT`, `PATCH` ou `DELETE`).
- TTL padrão: 60 s, configurável com `CACHE_TTL_SECS` no `.env`.
- Rotas excluídas: `/me`, `/headers`, `/diag`, `/ini`, `/routes`, `/ping`.
- Cache hit responde em **< 5 ms** (~3.500 req/s no cenário `GET /produtos`).

---

## Troubleshooting

<details>
<summary><code>AddrInUse</code> na porta 7600</summary>

```bash
sudo pkill -9 -f php-server
sudo fuser -k 7600/tcp
```
</details>

<details>
<summary><code>vendor/autoload.php not found</code> no Docker</summary>

O `composer install` falhou durante o build. Confirme:

```bash
docker compose build --no-cache api 2>&1 | grep "composer OK"
```
</details>

<details>
<summary><code>curl.so not loaded</code> no embed</summary>

Adicione ao `php.ini`:

```ini
extension=curl
extension=pdo_pgsql
```
</details>

<details>
<summary><code>libphp.so not found</code></summary>

O `ext-php-rs` procura em `/usr/lib/libphp.so`. Confirme:

```bash
docker compose exec api ls -la /usr/lib/libphp*.so*
```
</details>

<details>
<summary><code>/login</code> retorna 100% non-2xx no <code>wrk</code></summary>

Bug conhecido: `wrk -s /dev/stdin` não envia o `body` corretamente. Salve o script em arquivo:

```bash
cat > /tmp/login.lua <<'EOF'
wrk.method = "POST"
wrk.body   = '{"email":"admin@test.com","senha":"123456"}'
wrk.headers["Content-Type"] = "application/json"
EOF

wrk -t4 -c50 -d15s -s /tmp/login.lua http://localhost:8000/login
```
</details>

---

## Contribuindo

PRs são bem-vindos. Abra uma issue antes de implementar features grandes.

### Nota sobre o build Docker

O `Dockerfile` copia `framework/` duas vezes:

1. **No builder**, antes do `composer install` — o `composer.json` usa um repositório `path` apontando para `./framework`.
2. **No runtime** — o `vendor` cria um symlink relativo para `../../framework/`, então o destino precisa existir.

Quando o framework for publicado no Packagist (v1.2), remova:

- `COPY framework ./framework` (builder) e `COPY --from=builder /app/framework ./framework` (runtime)
- o bloco `repositories` do `composer.json`
- a entrada `"performatic/framework": "dev-main"` do `composer.lock`

---

## Licença

MIT. Veja [LICENSE](LICENSE).

<p align="center">
  <sub>Feito para quem quer criar APIs em PHP sem framework pesado.</sub>
</p>
