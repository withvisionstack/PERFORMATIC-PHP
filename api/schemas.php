<?php
require_once __DIR__ . '/attributes.php';

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
