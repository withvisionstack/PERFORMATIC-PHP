<?php
/**
 * Helpers de cookie. O Rust escreve os Set-Cookie na resposta final.
 * Sem session_start(). Stateless por design.
 */

function set_cookie(string $name, string $value, array $opts = []): void
{
    $defaults = [
        'expires'   => 0,
        'path'      => '/',
        'domain'    => '',
        'secure'    => false,    // false em dev (localhost sem HTTPS)
        'http_only' => true,
        'same_site' => 'Lax',
    ];
    $o = array_merge($defaults, $opts);

    $parts = [urlencode($name) . '=' . urlencode($value)];

    if ($o['expires'] > 0) {
        $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', $o['expires']);
        $parts[] = 'Max-Age=' . max(0, $o['expires'] - time());
    }
    if ($o['path'] !== '')      $parts[] = 'Path=' . $o['path'];
    if ($o['domain'] !== '')    $parts[] = 'Domain=' . $o['domain'];
    if ($o['secure'])           $parts[] = 'Secure';
    if ($o['http_only'])        $parts[] = 'HttpOnly';
    if ($o['same_site'] !== '') $parts[] = 'SameSite=' . $o['same_site'];

    $GLOBALS['__cookies'][] = implode('; ', $parts);
}

function cookie(string $name, ?string $default = null): ?string
{
    return isset($_COOKIE[$name]) ? $_COOKIE[$name] : $default;
}

function clear_cookie(string $name, string $path = '/'): void
{
    set_cookie($name, '', [
        'expires'   => time() - 3600,
        'path'      => $path,
        'secure'    => false,
        'http_only' => true,
    ]);
}

