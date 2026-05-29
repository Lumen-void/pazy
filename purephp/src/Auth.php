<?php

declare(strict_types=1);

final class Auth
{
    public static function user(): ?array
    {
        return $_SESSION['auth_user'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function login(array $user, array $memberships): void
    {
        if ($memberships === []) {
            throw new RuntimeException('No active company membership found.');
        }

        $active = $memberships[0];

        $_SESSION['auth_user'] = [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'company_id' => (int) $active['company_id'],
            'company_name' => (string) $active['company_name'],
            'organization_id' => (int) $active['organization_id'],
            'role' => (string) $active['role_name'],
            'permissions' => self::normalizePermissions($active['permissions_json'] ?? '[]'),
            'memberships' => array_map(static function (array $membership): array {
                return [
                    'company_id' => (int) $membership['company_id'],
                    'company_name' => (string) $membership['company_name'],
                    'role_name' => (string) $membership['role_name'],
                    'permissions' => self::normalizePermissions($membership['permissions_json'] ?? '[]'),
                ];
            }, $memberships),
        ];

        session_regenerate_id(true);
    }

    public static function switchCompany(int $companyId): bool
    {
        $user = self::user();
        if (! $user) {
            return false;
        }

        foreach ($user['memberships'] as $membership) {
            if ((int) $membership['company_id'] === $companyId) {
                $_SESSION['auth_user']['company_id'] = $companyId;
                $_SESSION['auth_user']['company_name'] = (string) $membership['company_name'];
                $_SESSION['auth_user']['role'] = (string) $membership['role_name'];
                $_SESSION['auth_user']['permissions'] = $membership['permissions'];
                return true;
            }
        }

        return false;
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        if (! $user) {
            return false;
        }

        $permissions = $user['permissions'] ?? [];
        if (in_array('*', $permissions, true)) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }

    public static function requirePermission(array $config, string $permission): void
    {
        if (! self::can($permission)) {
            flash_set('error', 'Permission denied for '.$permission.'.');
            redirect_to(base_url($config, 'index.php?page=dashboard'));
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function requireLogin(array $config): void
    {
        if (! self::check()) {
            redirect_to(base_url($config, 'index.php?page=login'));
        }
    }

    public static function issueApiToken(PDO $pdo, array $user, array $config, string $tokenName = 'default'): string
    {
        $token = bin2hex(random_bytes(24));
        $hash = hash('sha256', $token);

        $expiresAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $expiresAt->modify('+'.max(1, (int) $config['security']['api_token_ttl_days']).' days');

        $stmt = $pdo->prepare('INSERT INTO api_tokens
            (user_id, company_id, token_name, token_hash, expires_at, created_at)
            VALUES
            (:user_id, :company_id, :token_name, :token_hash, :expires_at, :created_at)');
        $stmt->execute([
            'user_id' => (int) $user['id'],
            'company_id' => (int) $user['company_id'],
            'token_name' => $tokenName,
            'token_hash' => $hash,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'created_at' => now_utc(),
        ]);

        return $token;
    }

    public static function userFromBearerToken(PDO $pdo, ?string $token): ?array
    {
        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        $hash = hash('sha256', trim($token));

        $stmt = $pdo->prepare('SELECT t.user_id, t.company_id, u.name, u.email, u.status
                               FROM api_tokens t
                               JOIN users u ON u.id = t.user_id
                               WHERE t.token_hash = :token_hash
                                 AND t.revoked_at IS NULL
                                 AND (t.expires_at IS NULL OR t.expires_at >= :now_utc)
                               LIMIT 1');
        $stmt->execute([
            'token_hash' => $hash,
            'now_utc' => now_utc(),
        ]);
        $row = $stmt->fetch();

        if (! $row || $row['status'] !== 'active') {
            return null;
        }

        $touch = $pdo->prepare('UPDATE api_tokens SET last_used_at = :last_used_at WHERE token_hash = :token_hash');
        $touch->execute(['last_used_at' => now_utc(), 'token_hash' => $hash]);

        return [
            'id' => (int) $row['user_id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'company_id' => (int) $row['company_id'],
        ];
    }

    public static function bearerTokenFromRequest(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if ((! is_string($header) || trim($header) === '') && function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strtolower((string) $name) === 'authorization') {
                        $header = (string) $value;
                        break;
                    }
                }
            }
        }

        if (! is_string($header) || $header === '') {
            return null;
        }

        if (! preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            return null;
        }

        return trim((string) ($matches[1] ?? ''));
    }

    private static function normalizePermissions(string $permissionsJson): array
    {
        $decoded = json_decode($permissionsJson, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded)));
    }
}
