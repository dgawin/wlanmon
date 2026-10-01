<?php
declare(strict_types=1);

/**
 * Liest den Authorization-Header robust aus - je nach Apache/PHP-Setup
 * (mod_php vs. PHP-FPM+Proxy) landet er an unterschiedlichen Stellen.
 * Die .htaccess reicht ihn zusätzlich explizit als HTTP_AUTHORIZATION
 * durch (siehe public/.htaccess), das deckt die meisten Fälle ab.
 */
function get_authorization_header(): ?string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                return $value;
            }
        }
    }
    return null;
}

function extract_bearer_token(): ?string
{
    $header = get_authorization_header();
    if ($header === null || stripos($header, 'Bearer ') !== 0) {
        return null;
    }
    return trim(substr($header, 7));
}

function hash_api_key(string $key): string
{
    return hash('sha256', $key);
}

function verify_api_key(string $token, string $storedHash): bool
{
    return hash_equals($storedHash, hash_api_key($token));
}
