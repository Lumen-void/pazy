<?php

declare(strict_types=1);

function bootstrap_load_env_file(string $path): void
{
    if (! is_file($path) || ! is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (! is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim((string) $parts[0]);
        $value = trim((string) $parts[1]);
        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) !== false) {
            continue;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

bootstrap_load_env_file(__DIR__.'/../.env');

$config = require __DIR__.'/config.php';

$logDirectory = __DIR__.'/../logs';
if (! is_dir($logDirectory)) {
    @mkdir($logDirectory, 0775, true);
}

ini_set('log_errors', '1');
ini_set('error_log', $logDirectory.'/app-error.log');
ini_set('display_errors', ((bool) ($config['app']['debug'] ?? false)) ? '1' : '0');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (! empty($config['security']['session_name'])) {
        session_name($config['security']['session_name']);
    }
    session_start();
}

if (ini_get('date.timezone') === '') {
    date_default_timezone_set($config['app']['timezone']);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Pazy\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__.'/App/'.str_replace('\\', '/', $relative).'.php';

    if (is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Database.php';
require_once __DIR__.'/Auth.php';
require_once __DIR__.'/Csrf.php';
require_once __DIR__.'/api.php';
require_once __DIR__.'/web.php';
