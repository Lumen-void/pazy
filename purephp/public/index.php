<?php

declare(strict_types=1);

require_once __DIR__.'/../src/bootstrap.php';

apply_security_headers($config);
enforce_https_if_required($config);

$pdo = Database::pdo($config);
$path = request_path($config);

if (str_starts_with($path, '/api/v1')) {
    handle_api_request($pdo, $config, $path);
}

$user = Auth::user();

$pageFromPath = trim($path, '/');
if ($pageFromPath === '' || $pageFromPath === 'index.php') {
    $pageFromPath = null;
}

$page = $_GET['page'] ?? $pageFromPath ?? 'home';
$page = trim((string) $page);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_web_post($pdo, $config, $page);
} else {
    handle_web_get($pdo, $config, $page);
}

[$title, $content] = render_web_page($pdo, $config, $page);
$user = Auth::user();

require __DIR__.'/../templates/layout.php';
