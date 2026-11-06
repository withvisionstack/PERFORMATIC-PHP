<?php

#[Schema('Usuario', [
    'id'        => 'int',
    'nome'      => 'string',
    'email'     => 'string',
    'criado_em' => 'datetime',
], required: ['nome', 'email'])]
function __schema_usuario() {}

#[Schema('Pedido', [
    'id'         => 'int',
    'usuario_id' => 'int',
    'itens'      => 'array',
    'total'      => 'float',
], required: ['usuario_id', 'itens'])]
function __schema_pedido() {}

#[Schema('LoginRequest', [
    'email' => 'string',
    'senha' => 'string',
], required: ['email', 'senha'])]
function __schema_login_request() {}


#[Schema('RegisterRequest', [
    'email' => 'string',
    'senha' => 'string',
], required: ['email', 'senha'])]
function __schema_register_request() {}

#[Schema('ProdutoRequest', [
    'nome'    => 'string|required|min:3|max:200',
    'preco'   => 'float|required|min:0.01',
    'estoque' => 'int|default:0|min:0',
    'categoria' => 'string|in:eletronicos,livros,roupas',
    'ativo'   => 'bool|default:true',
])]
function __schema_produto_request() {}