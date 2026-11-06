<?php
/**
 * Rotas do projeto.
 * O core do framework vem do vendor/ (composer autoload).
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Schemas e security do projeto (definidos por você)
require_once __DIR__ . '/schemas.php';
require_once __DIR__ . '/security.php';

// ─── SUAS ROTAS ───

#[Get('/health')]
function health(): string
{
    return json_response(['ok' => true, 'ts' => time()]);
}

#[Post('/produtos',
    summary: 'Cria produto',
    tags: ['produtos'])]
#[Body('ProdutoRequest', example: ['nome' => 'Caneta', 'preco' => 2.50])]
#[Response(201, 'Criado')]
#[Response(400, 'Dados inválidos')]
function criar_produto(): string
{
    $dados = validate_body('ProdutoRequest');
    db_exec(
        'INSERT INTO produtos (nome, preco, estoque) VALUES (?, ?, ?)',
        [$dados['nome'], $dados['preco'], $dados['estoque'] ?? 0]
    );
    return http(201, [
        'id'    => (int) db()->lastInsertId(),
        'nome'  => $dados['nome'],
        'preco' => $dados['preco'],
    ]);
}

#[Get('/produtos', summary: 'Lista produtos', tags: ['produtos'])]
function listar_produtos(): string
{
    $rows = db_query('SELECT id, nome, preco, estoque FROM produtos ORDER BY id DESC LIMIT 20');
    return http(200, [
        'produtos'   => $rows,
        'pagination' => ['total' => count($rows), 'page' => 1, 'limit' => 20],
    ]);
}
