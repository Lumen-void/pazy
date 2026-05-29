<?php

declare(strict_types=1);

final class Csrf
{
    public static function token(array $config): string
    {
        $key = $config['security']['csrf_key'];

        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(32));
        }

        return $_SESSION[$key];
    }

    public static function verify(array $config, ?string $token): bool
    {
        $key = $config['security']['csrf_key'];
        $stored = $_SESSION[$key] ?? '';

        return is_string($token) && is_string($stored) && hash_equals($stored, $token);
    }
}
