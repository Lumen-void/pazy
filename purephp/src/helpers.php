<?php

declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function today_utc(): string
{
    return gmdate('Y-m-d');
}

function money_format_indian(float $value): string
{
    return number_format($value, 2, '.', ',');
}

function base_url(array $config, string $path = ''): string
{
    $base = rtrim((string) $config['app']['base_url'], '/');
    if ($path === '') {
        return $base;
    }

    return $base.'/'.ltrim($path, '/');
}

function request_path(array $config): string
{
    $uriPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $basePath = (string) parse_url((string) $config['app']['base_url'], PHP_URL_PATH);
    $basePath = rtrim($basePath, '/');

    if ($basePath !== '' && str_starts_with($uriPath, $basePath)) {
        $trimmed = substr($uriPath, strlen($basePath));
        return $trimmed === false || $trimmed === '' ? '/' : $trimmed;
    }

    return $uriPath;
}

function redirect_to(string $url): void
{
    header('Location: '.$url);
    exit;
}

function flash_set(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    if (! isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = (string) $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    return $message;
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

function current_company_id(): int
{
    return (int) (Auth::user()['company_id'] ?? 0);
}

function current_user_id(): int
{
    return (int) (Auth::user()['id'] ?? 0);
}

function object_storage_root(array $config): string
{
    $root = (string) ($config['storage']['object_root'] ?? (__DIR__.'/../storage/object'));

    if ($root === '') {
        $root = __DIR__.'/../storage/object';
    }

    if (! str_starts_with($root, '/')) {
        $root = __DIR__.'/../'.ltrim($root, '/');
    }

    return rtrim($root, '/');
}

function ensure_directory(string $path): void
{
    if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
        throw new RuntimeException('Failed to create directory: '.$path);
    }
}

function sanitize_filename(string $name): string
{
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $base ?? '');
    $base = trim((string) $base, '-_');

    if ($base === '') {
        $base = 'document';
    }

    return substr($base, 0, 64);
}

function uploaded_file_extension(string $originalName, string $mimeType): string
{
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension !== '') {
        return preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
    }

    $map = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'application/json' => 'json',
    ];

    return $map[$mimeType] ?? 'bin';
}

function normalize_uploaded_files(array $field): array
{
    if (! isset($field['name'])) {
        return [];
    }

    if (! is_array($field['name'])) {
        return [$field];
    }

    $files = [];
    $count = count($field['name']);
    for ($i = 0; $i < $count; $i++) {
        $files[] = [
            'name' => $field['name'][$i] ?? '',
            'type' => $field['type'][$i] ?? '',
            'tmp_name' => $field['tmp_name'][$i] ?? '',
            'error' => $field['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $field['size'][$i] ?? 0,
        ];
    }

    return $files;
}

function store_uploaded_file(array $config, array $file, int $companyId, string $entityType): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed with code '.$error);
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || ! is_file($tmpName)) {
        throw new RuntimeException('Uploaded temp file missing.');
    }

    $originalName = (string) ($file['name'] ?? 'upload.bin');
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('Uploaded file is empty.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) ($finfo->file($tmpName) ?: 'application/octet-stream');

    $safeEntity = preg_replace('/[^a-z0-9_-]/i', '-', $entityType) ?: 'misc';
    $safeBase = sanitize_filename($originalName);
    $extension = uploaded_file_extension($originalName, $mimeType);

    $datePath = gmdate('Y/m/d');
    $objectKey = sprintf(
        'company/%d/%s/%s/%s_%s.%s',
        $companyId,
        strtolower($safeEntity),
        $datePath,
        gmdate('His'),
        bin2hex(random_bytes(8)).'-'.$safeBase,
        $extension
    );

    $root = object_storage_root($config);
    $destination = $root.'/'.$objectKey;

    ensure_directory(dirname($destination));

    $moved = move_uploaded_file($tmpName, $destination);
    if (! $moved) {
        $moved = rename($tmpName, $destination);
    }
    if (! $moved) {
        throw new RuntimeException('Could not move uploaded file to object storage.');
    }

    return [
        'object_key' => $objectKey,
        'original_name' => $originalName,
        'mime_type' => $mimeType,
        'file_size_bytes' => filesize($destination) ?: $size,
    ];
}

function store_raw_file_content(
    array $config,
    string $rawContent,
    string $originalName,
    string $mimeType,
    int $companyId,
    string $entityType
): array {
    if ($rawContent === '') {
        throw new RuntimeException('Raw file content is empty.');
    }

    $safeEntity = preg_replace('/[^a-z0-9_-]/i', '-', $entityType) ?: 'misc';
    $safeBase = sanitize_filename($originalName !== '' ? $originalName : 'upload.bin');
    $extension = uploaded_file_extension($originalName !== '' ? $originalName : 'upload.bin', $mimeType);

    $datePath = gmdate('Y/m/d');
    $objectKey = sprintf(
        'company/%d/%s/%s/%s_%s.%s',
        $companyId,
        strtolower($safeEntity),
        $datePath,
        gmdate('His'),
        bin2hex(random_bytes(8)).'-'.$safeBase,
        $extension
    );

    $root = object_storage_root($config);
    $destination = $root.'/'.$objectKey;
    ensure_directory(dirname($destination));

    $written = file_put_contents($destination, $rawContent);
    if ($written === false || $written <= 0) {
        throw new RuntimeException('Could not write file into object storage.');
    }

    return [
        'object_key' => $objectKey,
        'original_name' => $originalName !== '' ? $originalName : ('document.'.$extension),
        'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
        'file_size_bytes' => filesize($destination) ?: strlen($rawContent),
    ];
}

function persist_document_metadata(
    PDO $pdo,
    int $companyId,
    string $entityType,
    int $entityId,
    array $storedFile,
    int $uploadedBy
): int {
    $stmt = $pdo->prepare('INSERT INTO documents
        (company_id, entity_type, entity_id, object_key, original_name, mime_type, file_size_bytes, uploaded_by, created_at)
        VALUES
        (:company_id, :entity_type, :entity_id, :object_key, :original_name, :mime_type, :file_size_bytes, :uploaded_by, :created_at)');

    $stmt->execute([
        'company_id' => $companyId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'object_key' => (string) ($storedFile['object_key'] ?? ''),
        'original_name' => (string) ($storedFile['original_name'] ?? 'document'),
        'mime_type' => (string) ($storedFile['mime_type'] ?? 'application/octet-stream'),
        'file_size_bytes' => (int) ($storedFile['file_size_bytes'] ?? 0),
        'uploaded_by' => $uploadedBy,
        'created_at' => now_utc(),
    ]);

    return (int) $pdo->lastInsertId();
}

function log_capture_event(
    PDO $pdo,
    int $companyId,
    string $entityType,
    int $entityId,
    string $sourceChannel,
    ?string $sourceRef,
    array $payload,
    ?int $capturedBy
): int {
    $stmt = $pdo->prepare('INSERT INTO capture_events
        (company_id, entity_type, entity_id, source_channel, source_ref, payload_json, captured_by, created_at)
        VALUES
        (:company_id, :entity_type, :entity_id, :source_channel, :source_ref, :payload_json, :captured_by, :created_at)');

    $stmt->execute([
        'company_id' => $companyId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'source_channel' => $sourceChannel,
        'source_ref' => $sourceRef,
        'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
        'captured_by' => $capturedBy,
        'created_at' => now_utc(),
    ]);

    return (int) $pdo->lastInsertId();
}

function request_client_ip(): string
{
    $candidates = [
        (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
        (string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''),
        (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        $parts = array_map('trim', explode(',', $candidate));
        foreach ($parts as $part) {
            if ($part !== '' && filter_var($part, FILTER_VALIDATE_IP)) {
                return $part;
            }
        }
    }

    return '0.0.0.0';
}

function request_is_secure(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https === 'on' || $https === '1') {
        return true;
    }

    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($proto === 'https') {
        return true;
    }

    $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
    return $port === 443;
}

function enforce_https_if_required(array $config): void
{
    $forceHttps = (bool) (($config['security']['force_https'] ?? false) === true);
    if (! $forceHttps || request_is_secure()) {
        return;
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: https://'.$host.$uri, true, 301);
    exit;
}

function apply_security_headers(array $config): void
{
    if (headers_sent()) {
        return;
    }

    $security = $config['security'] ?? [];
    $xFrame = (string) ($security['x_frame_options'] ?? 'DENY');
    $xContentType = (string) ($security['x_content_type_options'] ?? 'nosniff');
    $referrerPolicy = (string) ($security['referrer_policy'] ?? 'strict-origin-when-cross-origin');
    $permissionsPolicy = (string) ($security['permissions_policy'] ?? 'geolocation=(), microphone=(), camera=()');
    $csp = trim((string) ($security['content_security_policy'] ?? ''));

    header('X-Frame-Options: '.$xFrame);
    header('X-Content-Type-Options: '.$xContentType);
    header('Referrer-Policy: '.$referrerPolicy);
    header('Permissions-Policy: '.$permissionsPolicy);
    header('X-XSS-Protection: 0');
    header('Cross-Origin-Resource-Policy: same-site');
    header('Cross-Origin-Opener-Policy: same-origin');

    if ($csp !== '') {
        header('Content-Security-Policy: '.$csp);
    }

    if (request_is_secure()) {
        $hstsMaxAge = max(0, (int) ($security['hsts_max_age'] ?? 0));
        if ($hstsMaxAge > 0) {
            header('Strict-Transport-Security: max-age='.$hstsMaxAge.'; includeSubDomains');
        }
    }
}

function ensure_runtime_support_tables(PDO $pdo): void
{
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }

    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS request_rate_limits (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            limiter_key VARCHAR(190) NOT NULL,
            window_started_at DATETIME NOT NULL,
            hit_count INT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_request_rate_limits_key (limiter_key),
            KEY idx_request_rate_limits_expires (expires_at)
        ) ENGINE=InnoDB');
    } catch (Throwable $ignored) {
        // If CREATE privileges are unavailable, limiter falls back to fail-open mode.
    }

    $bootstrapped = true;
}

function rate_limit_allow(PDO $pdo, string $limiterKey, int $maxHits, int $windowSeconds): array
{
    $maxHits = max(1, $maxHits);
    $windowSeconds = max(1, $windowSeconds);
    $nowTs = time();
    $now = gmdate('Y-m-d H:i:s', $nowTs);
    $expiresAtTs = $nowTs + $windowSeconds;
    $expiresAt = gmdate('Y-m-d H:i:s', $expiresAtTs);

    ensure_runtime_support_tables($pdo);

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM request_rate_limits WHERE expires_at < :now')
            ->execute(['now' => $now]);

        $select = $pdo->prepare('SELECT id, hit_count, window_started_at, expires_at
                                 FROM request_rate_limits
                                 WHERE limiter_key = :limiter_key
                                 LIMIT 1
                                 FOR UPDATE');
        $select->execute(['limiter_key' => $limiterKey]);
        $row = $select->fetch();

        $hitCount = 1;
        if (! $row) {
            $pdo->prepare('INSERT INTO request_rate_limits
                (limiter_key, window_started_at, hit_count, expires_at, created_at, updated_at)
                VALUES
                (:limiter_key, :window_started_at, :hit_count, :expires_at, :created_at, :updated_at)')
                ->execute([
                    'limiter_key' => $limiterKey,
                    'window_started_at' => $now,
                    'hit_count' => 1,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
        } else {
            $windowStartedTs = strtotime((string) $row['window_started_at']) ?: $nowTs;
            $currentExpiresTs = strtotime((string) $row['expires_at']) ?: $expiresAtTs;
            $windowExpired = $currentExpiresTs <= $nowTs || ($windowStartedTs + $windowSeconds) <= $nowTs;

            if ($windowExpired) {
                $hitCount = 1;
                $expiresAtTs = $nowTs + $windowSeconds;
                $expiresAt = gmdate('Y-m-d H:i:s', $expiresAtTs);
                $pdo->prepare('UPDATE request_rate_limits
                               SET window_started_at = :window_started_at,
                                   hit_count = :hit_count,
                                   expires_at = :expires_at,
                                   updated_at = :updated_at
                               WHERE id = :id')
                    ->execute([
                        'window_started_at' => $now,
                        'hit_count' => $hitCount,
                        'expires_at' => $expiresAt,
                        'updated_at' => $now,
                        'id' => (int) $row['id'],
                    ]);
            } else {
                $hitCount = (int) $row['hit_count'] + 1;
                $expiresAtTs = $currentExpiresTs;
                $expiresAt = gmdate('Y-m-d H:i:s', $expiresAtTs);
                $pdo->prepare('UPDATE request_rate_limits
                               SET hit_count = :hit_count,
                                   updated_at = :updated_at
                               WHERE id = :id')
                    ->execute([
                        'hit_count' => $hitCount,
                        'updated_at' => $now,
                        'id' => (int) $row['id'],
                    ]);
            }
        }

        $pdo->commit();

        $remaining = max(0, $maxHits - $hitCount);
        $allowed = $hitCount <= $maxHits;
        $retryAfter = $allowed ? 0 : max(1, $expiresAtTs - $nowTs);

        return [
            'allowed' => $allowed,
            'limit' => $maxHits,
            'remaining' => $remaining,
            'retry_after' => $retryAfter,
            'window_seconds' => $windowSeconds,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Fail open to avoid availability impact if limiter storage is unavailable.
        return [
            'allowed' => true,
            'limit' => $maxHits,
            'remaining' => $maxHits,
            'retry_after' => 0,
            'window_seconds' => $windowSeconds,
        ];
    }
}
