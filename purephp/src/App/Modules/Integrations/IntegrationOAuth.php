<?php

declare(strict_types=1);

namespace Pazy\Modules\Integrations;

final class IntegrationOAuth
{
    private const PROVIDERS = [
        'slack' => [
            'client_id_key' => 'SLACK_OAUTH_CLIENT_ID',
            'client_secret_key' => 'SLACK_OAUTH_CLIENT_SECRET',
            'scope_key' => 'SLACK_OAUTH_SCOPES',
            'auth_url_key' => 'SLACK_OAUTH_AUTH_URL',
            'token_url_key' => 'SLACK_OAUTH_TOKEN_URL',
            'default_auth_url' => 'https://slack.com/oauth/v2/authorize',
            'default_token_url' => 'https://slack.com/api/oauth.v2.access',
            'default_scopes' => 'incoming-webhook,chat:write,channels:read,users:read',
            'scope_separator' => ',',
            'extra_auth' => [],
        ],
        'zoho' => [
            'client_id_key' => 'ZOHO_OAUTH_CLIENT_ID',
            'client_secret_key' => 'ZOHO_OAUTH_CLIENT_SECRET',
            'scope_key' => 'ZOHO_OAUTH_SCOPES',
            'auth_url_key' => 'ZOHO_OAUTH_AUTH_URL',
            'token_url_key' => 'ZOHO_OAUTH_TOKEN_URL',
            'default_auth_url' => 'https://accounts.zoho.com/oauth/v2/auth',
            'default_token_url' => 'https://accounts.zoho.com/oauth/v2/token',
            'default_scopes' => 'ZohoBooks.fullaccess.all offline_access',
            'scope_separator' => ' ',
            'extra_auth' => ['access_type' => 'offline', 'prompt' => 'consent'],
        ],
        'google_workspace' => [
            'client_id_key' => 'GOOGLE_OAUTH_CLIENT_ID',
            'client_secret_key' => 'GOOGLE_OAUTH_CLIENT_SECRET',
            'scope_key' => 'GOOGLE_OAUTH_SCOPES',
            'auth_url_key' => 'GOOGLE_OAUTH_AUTH_URL',
            'token_url_key' => 'GOOGLE_OAUTH_TOKEN_URL',
            'default_auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'default_token_url' => 'https://oauth2.googleapis.com/token',
            'default_scopes' => 'openid email profile https://www.googleapis.com/auth/admin.directory.user.readonly',
            'scope_separator' => ' ',
            'extra_auth' => [
                'access_type' => 'offline',
                'include_granted_scopes' => 'true',
                'prompt' => 'consent',
            ],
        ],
        'microsoft_ad' => [
            'client_id_key' => 'MICROSOFT_OAUTH_CLIENT_ID',
            'client_secret_key' => 'MICROSOFT_OAUTH_CLIENT_SECRET',
            'scope_key' => 'MICROSOFT_OAUTH_SCOPES',
            'auth_url_key' => 'MICROSOFT_OAUTH_AUTH_URL',
            'token_url_key' => 'MICROSOFT_OAUTH_TOKEN_URL',
            'default_auth_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'default_token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'default_scopes' => 'offline_access User.Read',
            'scope_separator' => ' ',
            'extra_auth' => [],
        ],
    ];

    public static function isSupportedProvider(string $providerKey): bool
    {
        return isset(self::PROVIDERS[$providerKey]);
    }

    public static function callbackUri(array $config): string
    {
        $path = \base_url($config, 'index.php?page=integrations&oauth=callback');
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $scheme = 'http';
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') {
            $scheme = 'https';
        } elseif ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            $scheme = 'https';
        }

        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($host === '') {
            $host = 'localhost';
        }

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return $scheme.'://'.$host.$path;
    }

    public static function buildAuthorizationUrl(string $providerKey, array $config, array $runtimeEnv, string $state): array
    {
        $settings = self::resolvedSettings($providerKey, $runtimeEnv);
        $redirectUri = self::callbackUri($config);

        if ($settings['client_id'] === '' || $settings['client_secret'] === '') {
            throw new \RuntimeException('Configure OAuth client ID and secret for '.$providerKey.' before connecting.');
        }

        if ($settings['auth_url'] === '') {
            throw new \RuntimeException('OAuth authorization URL is missing for '.$providerKey.'.');
        }

        $params = [
            'response_type' => 'code',
            'client_id' => $settings['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => $settings['scope'],
            'state' => $state,
        ];

        foreach ($settings['extra_auth'] as $key => $value) {
            $params[(string) $key] = (string) $value;
        }

        $url = $settings['auth_url'];
        $separator = str_contains($url, '?') ? '&' : '?';
        $url .= $separator.http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return [
            'url' => $url,
            'redirect_uri' => $redirectUri,
        ];
    }

    public static function exchangeCode(string $providerKey, array $runtimeEnv, string $code, string $redirectUri): array
    {
        $settings = self::resolvedSettings($providerKey, $runtimeEnv);
        if ($settings['token_url'] === '') {
            throw new \RuntimeException('OAuth token URL is missing for '.$providerKey.'.');
        }
        if ($settings['client_id'] === '' || $settings['client_secret'] === '') {
            throw new \RuntimeException('Configure OAuth client ID and secret for '.$providerKey.' before connecting.');
        }

        $response = self::postForm($settings['token_url'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $settings['client_id'],
            'client_secret' => $settings['client_secret'],
            'redirect_uri' => $redirectUri,
        ]);

        $json = $response['json'] ?? [];
        if (! is_array($json)) {
            $json = [];
        }

        if ((int) ($response['status_code'] ?? 500) >= 400) {
            $error = (string) ($json['error_description'] ?? $json['error'] ?? 'Token exchange failed.');
            throw new \RuntimeException($error);
        }

        if ($providerKey === 'slack' && isset($json['ok']) && $json['ok'] !== true) {
            $error = (string) ($json['error'] ?? 'Slack authorization failed.');
            throw new \RuntimeException($error);
        }

        return self::normalizeTokenResult($providerKey, $json);
    }

    private static function normalizeTokenResult(string $providerKey, array $json): array
    {
        $envUpdates = [];
        $connectedAccount = '';

        if ($providerKey === 'slack') {
            $accessToken = trim((string) ($json['access_token'] ?? ''));
            if ($accessToken === '') {
                throw new \RuntimeException('Slack did not return an access token.');
            }

            $envUpdates['SLACK_BOT_TOKEN'] = $accessToken;
            $hook = trim((string) ($json['incoming_webhook']['url'] ?? ''));
            if ($hook !== '') {
                $envUpdates['SLACK_WEBHOOK_URL'] = $hook;
            }
            $connectedAccount = trim((string) ($json['team']['name'] ?? 'Slack Workspace'));
        } elseif ($providerKey === 'zoho') {
            $accessToken = trim((string) ($json['access_token'] ?? ''));
            if ($accessToken === '') {
                throw new \RuntimeException('Zoho did not return an access token.');
            }

            $envUpdates['ERP_PROVIDER'] = 'zoho';
            $envUpdates['ZOHO_BOOKS_ACCESS_TOKEN'] = $accessToken;
            $refreshToken = trim((string) ($json['refresh_token'] ?? ''));
            if ($refreshToken !== '') {
                $envUpdates['ZOHO_BOOKS_REFRESH_TOKEN'] = $refreshToken;
            }
            $apiDomain = trim((string) ($json['api_domain'] ?? ''));
            if ($apiDomain !== '') {
                $envUpdates['ZOHO_BOOKS_SYNC_ENDPOINT'] = rtrim($apiDomain, '/').'/books/v3';
            }
            $connectedAccount = 'Zoho Organization';
        } elseif ($providerKey === 'google_workspace') {
            $accessToken = trim((string) ($json['access_token'] ?? ''));
            if ($accessToken === '') {
                throw new \RuntimeException('Google did not return an access token.');
            }

            $envUpdates['IDENTITY_SYNC_TOKEN'] = $accessToken;
            $refreshToken = trim((string) ($json['refresh_token'] ?? ''));
            if ($refreshToken !== '') {
                $envUpdates['GOOGLE_OAUTH_REFRESH_TOKEN'] = $refreshToken;
            }
            $connectedAccount = 'Google Workspace';
        } elseif ($providerKey === 'microsoft_ad') {
            $accessToken = trim((string) ($json['access_token'] ?? ''));
            if ($accessToken === '') {
                throw new \RuntimeException('Microsoft did not return an access token.');
            }

            $envUpdates['IDENTITY_SYNC_TOKEN'] = $accessToken;
            $refreshToken = trim((string) ($json['refresh_token'] ?? ''));
            if ($refreshToken !== '') {
                $envUpdates['MICROSOFT_OAUTH_REFRESH_TOKEN'] = $refreshToken;
            }
            $connectedAccount = 'Microsoft Directory';
        }

        return [
            'env_updates' => $envUpdates,
            'token_response' => [
                'token_type' => (string) ($json['token_type'] ?? ''),
                'scope' => (string) ($json['scope'] ?? ''),
                'expires_in' => (int) ($json['expires_in'] ?? 0),
            ],
            'connected_account' => $connectedAccount,
        ];
    }

    private static function resolvedSettings(string $providerKey, array $runtimeEnv): array
    {
        if (! isset(self::PROVIDERS[$providerKey])) {
            throw new \RuntimeException('OAuth is not supported for '.$providerKey.'.');
        }

        $provider = self::PROVIDERS[$providerKey];
        $clientId = self::value($runtimeEnv, (string) $provider['client_id_key']);
        $clientSecret = self::value($runtimeEnv, (string) $provider['client_secret_key']);
        $scope = self::value($runtimeEnv, (string) $provider['scope_key'], (string) $provider['default_scopes']);
        $authUrl = self::value($runtimeEnv, (string) $provider['auth_url_key'], (string) $provider['default_auth_url']);
        $tokenUrl = self::value($runtimeEnv, (string) $provider['token_url_key'], (string) $provider['default_token_url']);

        if ((string) $provider['scope_separator'] === ',' && ! str_contains($scope, ',')) {
            $scope = str_replace(' ', ',', $scope);
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => trim($scope),
            'auth_url' => trim($authUrl),
            'token_url' => trim($tokenUrl),
            'extra_auth' => is_array($provider['extra_auth'] ?? null) ? $provider['extra_auth'] : [],
        ];
    }

    private static function value(array $runtimeEnv, string $key, string $default = ''): string
    {
        $fromRuntime = trim((string) ($runtimeEnv[$key] ?? ''));
        if ($fromRuntime !== '') {
            return $fromRuntime;
        }

        $fromEnv = trim((string) getenv($key));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        return trim($default);
    }

    private static function postForm(string $url, array $data): array
    {
        if (! function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is required for OAuth token exchange.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize OAuth HTTP client.');
        }

        $payload = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('OAuth HTTP request failed: '.$error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $body = substr((string) $raw, $headerSize);
        $json = json_decode($body, true);

        return [
            'status_code' => $statusCode,
            'body' => $body,
            'json' => is_array($json) ? $json : null,
        ];
    }
}

