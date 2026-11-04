<p align="center">
  <img src="logo.png" alt="Performatic PHP" width="100%">
  <img src="performatic.png"  height="50%" width="70%" >
</p>

<h1 align="center">Performatic PHP</h1>

<p align="center">
  Framework minimalista: <strong>PHP procedural</strong> com <strong>motor em Rust</strong>.
  <br>
  Sem FPM. Sem Apache. Sem OOP forçada. Binário único.
</p>

<p align="center">
  <a href="#instalação">Instalação</a> •
  <a href="#primeiros-passos">Primeiros passos</a> •
  <a href="#sintaxe">Sintaxe</a> •
  <a href="#helpers">Helpers</a> •
  <a href="#exemplos">Exemplos</a> •
  <a href="#performance">Performance</a> •
  <a href="#distribuição">Distribuição</a>
</p>

---

## O que é?

Performatic PHP é um **servidor de aplicação** que embute o motor do PHP (via `libphp`) em um binário Rust. Ele substitui Nginx + PHP-FPM por **um único processo** que:

- ✅ Executa PHP **procedural**, sem controllers ou classes obrigatórias
- ✅ Usa **atributos** (`#[Get]`, `#[Post]`) para declarar rotas
- ✅ Mantém o motor PHP **quente** entre requisições (sem bootstrap por request)
- ✅ Suporta `GET`, `POST`, query string, headers, JSON, autenticação Bearer
- ✅ Tem **cache in-memory** configurável por endpoint
- ✅ Entrega **~57.000 req/s** em endpoint trivial (vs ~350 do Laravel)

**Não é** um framework MVC. **Não tem** ORM. **Não tem** container de DI. Você escreve funções PHP e o framework cuida do HTTP.

---

## Instalação

### Requisitos

| Requisito | Versão mínima |
| :--- | :--- |
| Linux | Ubuntu 22.04+, Debian 12+ |
| PHP | 8.4.x com dev headers (`php-dev`) |
| Rust | 1.75+ |
| libclang | `libclang-dev` (para o bindgen) |

### Passo a passo (Ubuntu/Debian)

```bash
# 1. Dependências do sistema
sudo apt update
sudo apt install -y \
  php-dev php8.5-curl php8.5-fpm \
  libclang-dev build-essential pkg-config curl git

# 2. Rust (se não tiver)
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y
source "$HOME/.cargo/env"

# 3. Clone o template
git clone https://github.com/withvisionstack/PERFORMATIC-PHP
cd meu-projeto

# 4. Configure o .env
cp .env.example .env
nano .env

# 5. Compile
cargo build --release

# 6. Rode
./target/release/php-server
```

Acesse `http://localhost:7600/ping` — deve retornar `{"pong":true,"ts":...}`.

### Instalação via Docker

```bash
docker build -t performatic-php .
docker run -p 7600:7600 --env-file .env performatic-php
```

---

## Primeiros passos

### Estrutura do projeto

```
meu-projeto/
├── src/main.rs              ← motor (não editar)
├── Cargo.toml               ← dependências (não editar)
├── php.ini                  ← config do PHP (não editar)
├── .env                     ← ★ SUAS VARIÁVEIS
├── api/
│   ├── attributes.php       ← atributos #[Get], #[Post] (não editar)
│   ├── http.php             ← http_parallel() (não editar)
│   └── routes.php           ← ★ SUAS ROTAS
└── app/
    └── config.php           ← carrega .env (não editar)
```

**Só dois arquivos importam para você**: `api/routes.php` (suas rotas) e `.env` (configuração).

### Sua primeira rota

Edite `api/routes.php`:

```php
<?php
require_once __DIR__ . '/attributes.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/../app/config.php';

#[Get('/ola')]
function ola(): string {
    return json_response(['msg' => 'Olá do Performatic PHP!']);
}
```

Reinicie o servidor (`cargo run --release`) e acesse `http://localhost:7600/ola`.

---

## Sintaxe

### Rotas GET

```php
#[Get('/usuarios')]
function listar_usuarios(): string {
    return json_response(['usuarios' => ['Alice', 'Bob']]);
}
```

### Rotas GET com parâmetros de URL

```php
#[Get('/usuarios/{id}')]
function mostrar_usuario(int $id): string {
    return json_response(['id' => $id, 'nome' => 'Alice']);
}
// GET /usuarios/42 → $id = 42 (int, convertido automaticamente)
```

### Rotas GET com query string

```php
#[Get('/produtos')]
function listar_produtos(): string {
    $page  = (int) query('page', 1);
    $limit = (int) query('limit', 20);
    $busca = query('q', '');

    return json_response([
        'page'  => $page,
        'limit' => $limit,
        'q'     => $busca,
    ]);
}
// GET /produtos?page=2&limit=10&q=notebook
```

### Rotas POST com body JSON

```php
#[Post('/usuarios')]
function criar_usuario(): string {
    $dados = body();

    if (empty($dados['nome'])) {
        return json_response(['erro' => 'nome obrigatório']);
    }

    // INSERT INTO usuarios ...
    return json_response(['id' => 1, 'nome' => $dados['nome']]);
}
// POST /usuarios
// Body: {"nome":"Felipe"}
```

### Rotas com autenticação

```php
#[Post('/pedidos')]
function criar_pedido(): string {
    $token = bearer_token();
    if ($token === null) {
        return json_response(['erro' => 'não autenticado']);
    }

    $dados = body();
    return json_response(['pedido_id' => 123, 'dados' => $dados]);
}
// POST /pedidos
// Header: Authorization: Bearer <token>
```

---

## Helpers

Todos os helpers são **funções PHP procedurais**. Nada de classes, nada de `$this`.

### Leitura de request

| Helper | Retorno | Uso |
| :--- | :--- | :--- |
| `body()` | `array` | Corpo JSON parseado (retorna `[]` se vazio) |
| `raw_body()` | `string` | Corpo cru (útil para XML, form, etc) |
| `method()` | `string` | Método HTTP (`GET`, `POST`, etc) |
| `query($key, $default)` | `mixed` | Parâmetro da query string |
| `header($name, $default)` | `?string` | Header HTTP |
| `bearer_token()` | `?string` | Extrai `Bearer <token>` do `Authorization` |
| `is_json_request()` | `bool` | `Content-Type` contém `application/json` |

### Escrita de response

| Helper | Uso |
| :--- | :--- |
| `json_response($array)` | Serializa array em JSON com flags corretas |

### HTTP externo

| Helper | Uso |
| :--- | :--- |
| `http_parallel($urls, $timeout)` | Dispara N `GET` em paralelo e retorna os corpos |

```php
$respostas = http_parallel([
    'https://api1.com/dados',
    'https://api2.com/dados',
]);
// $respostas[0] = corpo da primeira URL
// $respostas[1] = corpo da segunda
```

---

## Exemplos

### CRUD de usuários

```php
#[Get('/usuarios')]
function listar(): string {
    $page  = (int) query('page', 1);
    $limit = (int) query('limit', 20);

    $pdo = new PDO(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
    $stmt = $pdo->prepare(
        'SELECT * FROM usuarios LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit',  $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
    $stmt->execute();

    return json_response([
        'usuarios' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'page'     => $page,
        'limit'    => $limit,
    ]);
}

#[Get('/usuarios/{id}')]
function mostrar(int $id): string {
    $pdo = new PDO(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
    $stmt->execute([$id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        return json_response(['erro' => 'não encontrado']);
    }
    return json_response($usuario);
}

#[Post('/usuarios')]
function criar(): string {
    $dados = body();

    if (empty($dados['nome']) || empty($dados['email'])) {
        return json_response(['erro' => 'nome e email obrigatórios']);
    }

    $pdo = new PDO(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
    $stmt = $pdo->prepare(
        'INSERT INTO usuarios (nome, email) VALUES (?, ?)'
    );
    $stmt->execute([$dados['nome'], $dados['email']]);

    return json_response([
        'id'   => (int) $pdo->lastInsertId(),
        'nome' => $dados['nome'],
    ]);
}
```

### Login + JWT

```php
#[Post('/login')]
function login(): string {
    $dados = body();
    $email = $dados['email'] ?? '';
    $senha = $dados['senha'] ?? '';

    $pdo = new PDO(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
    $stmt->execute([$email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
        return json_response(['erro' => 'credenciais inválidas']);
    }

    // Gere seu JWT aqui (use firebase/php-jwt ou similar)
    $token = 'jwt-' . bin2hex(random_bytes(32));

    return json_response([
        'token' => $token,
        'user'  => $usuario['nome'],
    ]);
}

#[Get('/me')]
function me(): string {
    $token = bearer_token();
    if ($token === null) {
        return json_response(['erro' => 'não autenticado']);
    }

    // Valide o JWT aqui
    return json_response(['user' => 'Felipe']);
}
```

### Proxy para API externa com cache

```php
#[Get('/github/{user}')]
function github_user(string $user): string {
    // O cache do Rust já cobre isso: GET /github/felipedev fica 60s em cache
    $respostas = http_parallel([
        "https://api.github.com/users/{$user}"
    ]);

    $dados = json_decode($respostas[0] ?? '', true);
    if (!is_array($dados)) {
        return json_response(['erro' => 'falha na consulta']);
    }

    return json_response([
        'login' => $dados['login'] ?? null,
        'name'  => $dados['name'] ?? null,
        'repos' => $dados['public_repos'] ?? 0,
    ]);
}
```

---

## Performance

Benchmarks em VPS de 4 vCPU / 4 GB, Ubuntu 22.04:

| Endpoint | Performatic PHP (1 worker) | PHP-FPM | Laravel + FPM |
| :--- | :--- | :--- | :--- |
| `/ping` (trivial) | **57.000 req/s** | ~2.000 req/s | ~350 req/s |
| `/echo` (JSON pequeno) | **55.000 req/s** | ~1.800 req/s | ~300 req/s |
| `/clima` (I/O 200ms, com cache) | **~50.000 req/s** | ~1.500 req/s | ~300 req/s |
| `/clima` (I/O 200ms, sem cache) | ~8 req/s (limitado pela API externa) | ~5 req/s | ~2 req/s |

**Ganho médio**: **~25× mais rápido** que PHP-FPM, **~150× mais rápido** que Laravel.

### Escalando horizontalmente

Para > 57k req/s em endpoint trivial, rode **N processos** + Nginx:

```nginx
upstream performatic {
    least_conn;
    server 127.0.0.1:7600;
    server 127.0.0.1:7601;
    server 127.0.0.1:7602;
    server 127.0.0.1:7603;
}

server {
    listen 8000;
    location / {
        proxy_pass http://performatic;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
    }
}
```

Cada worker consome ~40 MB de RAM. Sweet spot: `N = núcleos_da_CPU`.

---

## Distribuição

### Opção A: Template git (recomendado)

Cada novo projeto é um `git clone` do template:

```bash
git clone https://github.com/withvisionstack/PERFORMATIC-PHP
cd meu-projeto
./install.sh
```

### Opção B: Docker

```bash
docker build -t performatic-php .
docker run -d -p 7600:7600 --env-file .env --name api performatic-php
```

### Opção C: Binário pré-compilado

Compile uma vez e distribua o binário:

```bash
cargo build --release
strip target/release/php-server
# 8-12 MB
```

O usuário só precisa do binário + `api/` + `app/` + `php.ini` + `.env`.

---

## Limitações

| Recurso | Status |
| :--- | :--- |
| `GET`, `POST` | ✅ |
| `PUT`, `PATCH`, `DELETE` | ⚠️ Requer adicionar em `attributes.php` |
| Query string | ✅ |
| Headers | ✅ |
| Body JSON | ✅ |
| Autenticação Bearer | ✅ |
| `multipart/form-data` (upload) | ❌ Ainda não suportado |
| Sessões PHP (`session_start`) | ❌ Use JWT |
| Status HTTP customizado (401, 404) | ⚠️ Ainda não implementado |
| Múltiplos workers no mesmo processo | ❌ Use N processos + Nginx |

---

## Licença

MIT. Veja `LICENSE` para detalhes.

---

## Contribuindo

PRs são bem-vindos. Abra uma issue para discutir features grandes antes de implementar.
