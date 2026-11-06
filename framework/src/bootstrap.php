<?php

/**
* Performatic PHP — bootstrap do framework.
* Carrega todos os módulos core e define get_routes/get_schemas/get_security_schemes.
*
* NÃO EDITE. Este arquivo é parte do pacote performatic/framework.
*/

require_once __DIR__ . '/attributes.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/cookies.php';
require_once __DIR__ . '/multipart.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/helpers.php';

// ─── Reflection: o motor Rust chama essas 3 funções no boot ───

function get_routes(): array
{
$routes = [];
foreach (get_defined_functions()['user'] as $fn) {
$ref = new ReflectionFunction($fn);
foreach ($ref->getAttributes() as $attr) {
$short = (new ReflectionClass($attr->getName()))->getShortName();
if (!in_array($short, ['Get', 'Post', 'Put', 'Patch', 'Delete'], true)) {
continue;
}
$args = $attr->getArguments();
$routes[] = [
'method'      => strtoupper($short),
'path'        => $args['path'] ?? $args[0] ?? '/',
'handler'     => $fn,
'summary'     => $args['summary'] ?? $args[1] ?? null,
'description' => $args['description'] ?? $args[2] ?? null,
'tags'        => $args['tags'] ?? $args[3] ?? [],
'params'      => collect_route_meta($ref, 'Param'),
'body'        => collect_body_meta($ref),
'responses'   => collect_route_meta($ref, 'Response'),
'security'    => collect_security_meta($ref),
];
}
}
return $routes;
}

function get_schemas(): array
{
$schemas = [];
foreach (get_defined_functions()['user'] as $fn) {
$ref = new ReflectionFunction($fn);
foreach ($ref->getAttributes() as $attr) {
if ((new ReflectionClass($attr->getName()))->getShortName() !== 'Schema') {
continue;
}
$a = $attr->getArguments();
$schemas[] = [
'name'     => $a['name'] ?? $a[0] ?? '',
'fields'   => $a['fields'] ?? $a[1] ?? [],
'required' => $a['required'] ?? $a[2] ?? [],
];
}
}
return $schemas;
}

function get_security_schemes(): array
{
$schemes = [];
foreach (get_defined_functions()['user'] as $fn) {
$ref = new ReflectionFunction($fn);
foreach ($ref->getAttributes() as $attr) {
if ((new ReflectionClass($attr->getName()))->getShortName() !== 'SecurityScheme') {
continue;
}
$a = $attr->getArguments();
$schemes[] = [
'name'          => $a['name'] ?? $a[0] ?? '',
'type'          => $a['type'] ?? $a[1] ?? 'http',
'scheme'        => $a['scheme'] ?? $a[2] ?? '',
'bearer_format' => $a['bearerFormat'] ?? $a[3] ?? null,
];
}
}
return $schemes;
}

// ─── Helpers internos de reflection ───

function collect_route_meta(ReflectionFunction $ref, string $attrName): array
{
$out = [];
foreach ($ref->getAttributes() as $attr) {
if ((new ReflectionClass($attr->getName()))->getShortName() !== $attrName) {
continue;
}
$a = $attr->getArguments();
if ($attrName === 'Param') {
$out[] = [
'name'        => $a['name'] ?? $a[0] ?? '',
'in'          => $a['in'] ?? $a[1] ?? 'path',
'type'        => $a['type'] ?? $a[2] ?? 'string',
'required'    => $a['required'] ?? $a[3] ?? true,
'description' => $a['description'] ?? $a[4] ?? null,
];
} elseif ($attrName === 'Response') {
$out[] = [
'status'      => $a['status'] ?? $a[0] ?? 200,
'description' => $a['description'] ?? $a[1] ?? '',
'schema'      => $a['schema'] ?? $a[2] ?? null,
'example'     => $a['example'] ?? $a[3] ?? null,
];
}
}
return $out;
}

function collect_body_meta(ReflectionFunction $ref): ?array
{
foreach ($ref->getAttributes() as $attr) {
if ((new ReflectionClass($attr->getName()))->getShortName() !== 'Body') {
continue;
}
$a = $attr->getArguments();
return [
'schema'   => $a['schema'] ?? $a[0] ?? '',
'required' => $a['required'] ?? $a[1] ?? true,
'example'  => $a['example'] ?? $a[2] ?? null,
];
}
return null;
}

function collect_security_meta(ReflectionFunction $ref): array
{
$out = [];
foreach ($ref->getAttributes() as $attr) {
if ((new ReflectionClass($attr->getName()))->getShortName() === 'Security') {
$a = $attr->getArguments();
$out[] = $a['scheme'] ?? $a[0] ?? '';
}
}
return $out;
}