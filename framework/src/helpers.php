<?php

function body_string(string $key, ?string $default = null): ?string
{
$v = body()[$key] ?? null;
return is_string($v) ? $v : $default;
}

function body_int(string $key, int $default = 0): int
{
$v = body()[$key] ?? null;
return is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : $default;
}

function body_float(string $key, float $default = 0.0): float
{
$v = body()[$key] ?? null;
return is_numeric($v) ? (float) $v : $default;
}

function body_array(string $key): array
{
$v = body()[$key] ?? null;
return is_array($v) ? $v : [];
}