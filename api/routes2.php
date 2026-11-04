<?php
require_once __DIR__ . '/attributes.php';

// Descomentar apenas o que precisar:
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/cookies.php';

// ─── Suas rotas ───
#[Get('/')]
function home(): string {
    return json_response(['msg' => 'Hello']);
}

#[Post('/usuarios')]
function criar_usuario(): string {
    $dados = body();
    db_exec('INSERT INTO usuarios (nome) VALUES (?)', [$dados['nome']]);
    return http(201, ['id' => (int) db()->lastInsertId()]);
}

// ─── Rotas do framework (obrigatório) ───
function get_routes(): array {
    $routes = [];
    foreach (get_defined_functions()['user'] as $fn) {
        $ref = new ReflectionFunction($fn);
        foreach ($ref->getAttributes() as $attr) {
            $name = (new ReflectionClass($attr->getName()))->getShortName();
            if (in_array($name, ['Get', 'Post', 'Put', 'Patch', 'Delete'])) {
                $routes[] = [
                    'method'  => strtoupper($name),
                    'path'    => $attr->getArguments()[0],
                    'handler' => $fn,
                ];
            }
        }
    }
    return $routes;
}

function get_schemas(): array { return []; }
function get_security_schemes(): array { return []; }
