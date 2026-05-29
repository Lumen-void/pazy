<?php

declare(strict_types=1);

namespace Pazy\Modules\Integrations;

final class IntegrationRuntimeConfig
{
    private const COMMON_ERP = ['zoho', 'odoo', 'tally', 'oracle_fusion', 'business_central', 'campfire', 'netsuite'];
    private const COMMON_BANK = ['hdfc_bank', 'icici_bank', 'axis_bank', 'kotak_bank'];

    private static ?array $baselineEnv = null;

    public static function fieldsForProvider(string $providerKey): array
    {
        if (in_array($providerKey, self::COMMON_BANK, true)) {
            return [
                ['key' => 'BANK_API_BASE_URL', 'label' => 'Bank API Base URL', 'sensitive' => false, 'placeholder' => 'https://api.bank.example', 'help' => 'Primary payout API endpoint.'],
                ['key' => 'BANK_API_TRANSFER_PATH', 'label' => 'Transfer Path', 'sensitive' => false, 'placeholder' => '/payments/transfer', 'help' => 'Relative transfer path or full URL.', 'default' => '/payments/transfer'],
                ['key' => 'BANK_API_TOKEN', 'label' => 'Bank API Token', 'sensitive' => true, 'placeholder' => 'Bearer token', 'help' => 'Auth token from bank API.'],
                ['key' => 'BANK_API_KEY', 'label' => 'Bank API Key', 'sensitive' => true, 'placeholder' => 'X-API-Key', 'help' => 'Optional API key header value.'],
                ['key' => 'BANK_API_TIMEOUT', 'label' => 'Timeout (seconds)', 'sensitive' => false, 'placeholder' => '25', 'help' => 'HTTP timeout for bank calls.', 'default' => '25'],
            ];
        }

        if ($providerKey === 'mail') {
            return [
                ['key' => 'MAIL_INBOUND_IMAP_HOST', 'label' => 'IMAP Host', 'sensitive' => false, 'placeholder' => 'imap.gmail.com', 'help' => 'Mail server host for invoice inbox.'],
                ['key' => 'MAIL_INBOUND_IMAP_PORT', 'label' => 'IMAP Port', 'sensitive' => false, 'placeholder' => '993', 'help' => 'IMAP server port.', 'default' => '993'],
                ['key' => 'MAIL_INBOUND_IMAP_ENCRYPTION', 'label' => 'Encryption', 'sensitive' => false, 'placeholder' => 'ssl', 'help' => 'ssl/tls/none.', 'default' => 'ssl'],
                ['key' => 'MAIL_INBOUND_IMAP_FOLDER', 'label' => 'Folder', 'sensitive' => false, 'placeholder' => 'INBOX', 'help' => 'Mailbox folder to pull invoices from.', 'default' => 'INBOX'],
                ['key' => 'MAIL_INBOUND_IMAP_USERNAME', 'label' => 'IMAP Username', 'sensitive' => false, 'placeholder' => 'invoices@company.com', 'help' => 'IMAP login username.'],
                ['key' => 'MAIL_INBOUND_IMAP_PASSWORD', 'label' => 'IMAP Password/App Password', 'sensitive' => true, 'placeholder' => '********', 'help' => 'Use app password when available.'],
                ['key' => 'MAIL_INBOUND_IMAP_MOVE_TO', 'label' => 'Move Processed To', 'sensitive' => false, 'placeholder' => 'Processed', 'help' => 'Optional mailbox folder for processed emails.'],
                ['key' => 'MAIL_FROM_ADDRESS', 'label' => 'Outbound From Address', 'sensitive' => false, 'placeholder' => 'finance@company.com', 'help' => 'Fallback sender for email notifications.'],
            ];
        }

        if ($providerKey === 'slack') {
            return [
                ['key' => 'SLACK_WEBHOOK_URL', 'label' => 'Slack Webhook URL', 'sensitive' => true, 'placeholder' => 'https://hooks.slack.com/services/...', 'help' => 'Incoming webhook URL for alerts and approvals.'],
                ['key' => 'SLACK_OAUTH_CLIENT_ID', 'label' => 'Slack OAuth Client ID', 'sensitive' => false, 'placeholder' => '1234567890.1234567890', 'help' => 'Slack app client ID from OAuth settings.'],
                ['key' => 'SLACK_OAUTH_CLIENT_SECRET', 'label' => 'Slack OAuth Client Secret', 'sensitive' => true, 'placeholder' => 'Client secret', 'help' => 'Slack app client secret.'],
                ['key' => 'SLACK_OAUTH_SCOPES', 'label' => 'Slack OAuth Scopes', 'sensitive' => false, 'placeholder' => 'incoming-webhook,chat:write', 'help' => 'Comma-separated Slack scopes.', 'default' => 'incoming-webhook,chat:write,channels:read,users:read'],
                ['key' => 'SLACK_OAUTH_AUTH_URL', 'label' => 'Slack OAuth Auth URL', 'sensitive' => false, 'placeholder' => 'https://slack.com/oauth/v2/authorize', 'help' => 'Slack authorization URL.', 'default' => 'https://slack.com/oauth/v2/authorize'],
                ['key' => 'SLACK_OAUTH_TOKEN_URL', 'label' => 'Slack OAuth Token URL', 'sensitive' => false, 'placeholder' => 'https://slack.com/api/oauth.v2.access', 'help' => 'Slack token exchange URL.', 'default' => 'https://slack.com/api/oauth.v2.access'],
                ['key' => 'MESSAGING_TIMEOUT', 'label' => 'Timeout (seconds)', 'sensitive' => false, 'placeholder' => '25', 'help' => 'Slack request timeout.', 'default' => '25'],
            ];
        }

        if ($providerKey === 'whatsapp') {
            return [
                ['key' => 'WHATSAPP_ACCESS_TOKEN', 'label' => 'WhatsApp Access Token', 'sensitive' => true, 'placeholder' => 'EAA...', 'help' => 'Cloud API access token.'],
                ['key' => 'WHATSAPP_PHONE_NUMBER_ID', 'label' => 'Phone Number ID', 'sensitive' => false, 'placeholder' => '1234567890', 'help' => 'Meta phone number ID for sending messages.'],
                ['key' => 'WHATSAPP_TEST_TO', 'label' => 'Default Test Recipient', 'sensitive' => false, 'placeholder' => '9198xxxxxxxx', 'help' => 'Used when no test recipient is entered.'],
                ['key' => 'MESSAGING_TIMEOUT', 'label' => 'Timeout (seconds)', 'sensitive' => false, 'placeholder' => '25', 'help' => 'WhatsApp request timeout.', 'default' => '25'],
            ];
        }

        if ($providerKey === 'zoho') {
            return [
                ['key' => 'ERP_PROVIDER', 'label' => 'ERP Provider', 'sensitive' => false, 'placeholder' => 'zoho', 'help' => 'Connector identifier.', 'default' => 'zoho'],
                ['key' => 'ZOHO_BOOKS_SYNC_ENDPOINT', 'label' => 'Zoho Sync Endpoint', 'sensitive' => false, 'placeholder' => 'https://api.zoho.com/...', 'help' => 'Voucher sync endpoint for Zoho.'],
                ['key' => 'ZOHO_BOOKS_ACCESS_TOKEN', 'label' => 'Zoho Access Token', 'sensitive' => true, 'placeholder' => 'OAuth token', 'help' => 'Zoho OAuth token.'],
                ['key' => 'ZOHO_OAUTH_CLIENT_ID', 'label' => 'Zoho OAuth Client ID', 'sensitive' => false, 'placeholder' => '1000.XXXXXXXX', 'help' => 'Zoho app client ID.'],
                ['key' => 'ZOHO_OAUTH_CLIENT_SECRET', 'label' => 'Zoho OAuth Client Secret', 'sensitive' => true, 'placeholder' => 'Client secret', 'help' => 'Zoho app client secret.'],
                ['key' => 'ZOHO_OAUTH_SCOPES', 'label' => 'Zoho OAuth Scopes', 'sensitive' => false, 'placeholder' => 'ZohoBooks.fullaccess.all offline_access', 'help' => 'Space-separated Zoho scopes.', 'default' => 'ZohoBooks.fullaccess.all offline_access'],
                ['key' => 'ZOHO_OAUTH_AUTH_URL', 'label' => 'Zoho OAuth Auth URL', 'sensitive' => false, 'placeholder' => 'https://accounts.zoho.com/oauth/v2/auth', 'help' => 'Zoho authorization URL.', 'default' => 'https://accounts.zoho.com/oauth/v2/auth'],
                ['key' => 'ZOHO_OAUTH_TOKEN_URL', 'label' => 'Zoho OAuth Token URL', 'sensitive' => false, 'placeholder' => 'https://accounts.zoho.com/oauth/v2/token', 'help' => 'Zoho token exchange URL.', 'default' => 'https://accounts.zoho.com/oauth/v2/token'],
                ['key' => 'ERP_SYNC_TIMEOUT', 'label' => 'Timeout (seconds)', 'sensitive' => false, 'placeholder' => '25', 'help' => 'ERP sync timeout.', 'default' => '25'],
            ];
        }

        if ($providerKey === 'tally') {
            return [
                ['key' => 'ERP_PROVIDER', 'label' => 'ERP Provider', 'sensitive' => false, 'placeholder' => 'tally', 'help' => 'Connector identifier.', 'default' => 'tally'],
                ['key' => 'TALLY_SYNC_URL', 'label' => 'Tally Sync URL', 'sensitive' => false, 'placeholder' => 'https://tally-sync.company.com/v1/voucher', 'help' => 'Voucher sync endpoint for Tally bridge.'],
                ['key' => 'ERP_SYNC_TOKEN', 'label' => 'Sync Token', 'sensitive' => true, 'placeholder' => 'Bearer token', 'help' => 'Token sent as Authorization header.'],
                ['key' => 'ERP_SYNC_TIMEOUT', 'label' => 'Timeout (seconds)', 'sensitive' => false, 'placeholder' => '25', 'help' => 'ERP sync timeout.', 'default' => '25'],
            ];
        }

        if (in_array($providerKey, ['odoo', 'oracle_fusion', 'business_central', 'campfire', 'netsuite'], true)) {
            return [
                ['key' => 'ERP_PROVIDER', 'label' => 'ERP Provider', 'sensitive' => false, 'placeholder' => $providerKey, 'help' => 'Connector identifier.', 'default' => $providerKey],
                ['key' => 'ERP_SYNC_URL', 'label' => 'ERP Sync URL', 'sensitive' => false, 'placeholder' => 'https://erp-sync.company.com/v1/vouchers', 'help' => 'Generic voucher sync endpoint.'],
                ['key' => 'ERP_SYNC_TOKEN', 'label' => 'ERP Sync Token', 'sensitive' => true, 'placeholder' => 'Bearer token', 'help' => 'Token sent as Authorization header.'],
                ['key' => 'ERP_SYNC_TIMEOUT', 'label' => 'Timeout (seconds)', 'sensitive' => false, 'placeholder' => '25', 'help' => 'ERP sync timeout.', 'default' => '25'],
            ];
        }

        if ($providerKey === 'gstn_portal') {
            return [
                ['key' => 'TAX_API_BASE_URL', 'label' => 'Tax API Base URL', 'sensitive' => false, 'placeholder' => 'https://tax.company.com/reconcile', 'help' => 'Tax reconciliation endpoint.'],
                ['key' => 'TAX_API_TOKEN', 'label' => 'Tax API Token', 'sensitive' => true, 'placeholder' => 'Bearer token', 'help' => 'Auth token for tax API.'],
            ];
        }

        if ($providerKey === 'mca_registry') {
            return [
                ['key' => 'MCA_VERIFICATION_URL', 'label' => 'MCA Verification URL', 'sensitive' => false, 'placeholder' => 'https://mca.company.com/verify', 'help' => 'Entity verification endpoint.'],
                ['key' => 'MCA_API_TOKEN', 'label' => 'MCA API Token', 'sensitive' => true, 'placeholder' => 'Bearer token', 'help' => 'Auth token for MCA endpoint.'],
            ];
        }

        if ($providerKey === 'google_workspace') {
            return [
                ['key' => 'GOOGLE_WORKSPACE_SYNC_URL', 'label' => 'Google Workspace Sync URL', 'sensitive' => false, 'placeholder' => 'https://identity.company.com/google/sync', 'help' => 'Workspace provisioning endpoint.'],
                ['key' => 'IDENTITY_SYNC_TOKEN', 'label' => 'Identity Sync Token', 'sensitive' => true, 'placeholder' => 'Bearer token', 'help' => 'Auth token for identity sync calls.'],
                ['key' => 'GOOGLE_OAUTH_CLIENT_ID', 'label' => 'Google OAuth Client ID', 'sensitive' => false, 'placeholder' => 'xxxx.apps.googleusercontent.com', 'help' => 'Google OAuth client ID.'],
                ['key' => 'GOOGLE_OAUTH_CLIENT_SECRET', 'label' => 'Google OAuth Client Secret', 'sensitive' => true, 'placeholder' => 'Client secret', 'help' => 'Google OAuth client secret.'],
                ['key' => 'GOOGLE_OAUTH_SCOPES', 'label' => 'Google OAuth Scopes', 'sensitive' => false, 'placeholder' => 'openid email profile ...', 'help' => 'Space-separated Google scopes.', 'default' => 'openid email profile https://www.googleapis.com/auth/admin.directory.user.readonly'],
                ['key' => 'GOOGLE_OAUTH_AUTH_URL', 'label' => 'Google OAuth Auth URL', 'sensitive' => false, 'placeholder' => 'https://accounts.google.com/o/oauth2/v2/auth', 'help' => 'Google authorization URL.', 'default' => 'https://accounts.google.com/o/oauth2/v2/auth'],
                ['key' => 'GOOGLE_OAUTH_TOKEN_URL', 'label' => 'Google OAuth Token URL', 'sensitive' => false, 'placeholder' => 'https://oauth2.googleapis.com/token', 'help' => 'Google token exchange URL.', 'default' => 'https://oauth2.googleapis.com/token'],
            ];
        }

        if ($providerKey === 'microsoft_ad') {
            return [
                ['key' => 'MICROSOFT_AD_SYNC_URL', 'label' => 'Microsoft AD Sync URL', 'sensitive' => false, 'placeholder' => 'https://identity.company.com/ad/sync', 'help' => 'Active Directory sync endpoint.'],
                ['key' => 'IDENTITY_SYNC_TOKEN', 'label' => 'Identity Sync Token', 'sensitive' => true, 'placeholder' => 'Bearer token', 'help' => 'Auth token for identity sync calls.'],
                ['key' => 'MICROSOFT_OAUTH_CLIENT_ID', 'label' => 'Microsoft OAuth Client ID', 'sensitive' => false, 'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', 'help' => 'Azure app client ID.'],
                ['key' => 'MICROSOFT_OAUTH_CLIENT_SECRET', 'label' => 'Microsoft OAuth Client Secret', 'sensitive' => true, 'placeholder' => 'Client secret', 'help' => 'Azure app client secret.'],
                ['key' => 'MICROSOFT_OAUTH_SCOPES', 'label' => 'Microsoft OAuth Scopes', 'sensitive' => false, 'placeholder' => 'offline_access User.Read', 'help' => 'Space-separated Microsoft scopes.', 'default' => 'offline_access User.Read'],
                ['key' => 'MICROSOFT_OAUTH_AUTH_URL', 'label' => 'Microsoft OAuth Auth URL', 'sensitive' => false, 'placeholder' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize', 'help' => 'Microsoft authorization URL.', 'default' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize'],
                ['key' => 'MICROSOFT_OAUTH_TOKEN_URL', 'label' => 'Microsoft OAuth Token URL', 'sensitive' => false, 'placeholder' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token', 'help' => 'Microsoft token exchange URL.', 'default' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token'],
            ];
        }

        return [];
    }

    public static function allKnownKeys(): array
    {
        $keys = [];
        $providerKeys = [
            'mail', 'slack', 'whatsapp', 'zoho', 'odoo', 'tally', 'oracle_fusion',
            'business_central', 'campfire', 'netsuite', 'hdfc_bank', 'icici_bank',
            'axis_bank', 'kotak_bank', 'gstn_portal', 'mca_registry',
            'google_workspace', 'microsoft_ad',
        ];

        foreach ($providerKeys as $providerKey) {
            foreach (self::fieldsForProvider($providerKey) as $field) {
                $key = (string) ($field['key'] ?? '');
                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    public static function readEnvFromMeta(array $meta): array
    {
        $encrypted = $meta['runtime_env_encrypted'] ?? [];
        if (! is_array($encrypted)) {
            $encrypted = [];
        }

        $values = self::decryptMap($encrypted);
        if ($values !== []) {
            return $values;
        }

        $legacy = $meta['runtime_env'] ?? [];
        if (! is_array($legacy)) {
            return [];
        }

        $out = [];
        foreach ($legacy as $key => $value) {
            $k = trim((string) $key);
            if ($k === '') {
                continue;
            }
            $out[$k] = trim((string) $value);
        }

        return $out;
    }

    public static function writeEnvToMeta(array $meta, array $plainEnv): array
    {
        $clean = [];
        foreach ($plainEnv as $key => $value) {
            $k = trim((string) $key);
            if ($k === '') {
                continue;
            }

            $clean[$k] = trim((string) $value);
        }

        $meta['runtime_env_encrypted'] = self::encryptMap($clean);
        unset($meta['runtime_env']);

        $configured = [];
        foreach ($clean as $key => $value) {
            if ($value !== '') {
                $configured[] = $key;
            }
        }
        $meta['configured_keys'] = $configured;

        return $meta;
    }

    public static function applyForCompany(\PDO $pdo, int $companyId, bool $activeOnly = true, ?string $providerFilter = null): int
    {
        $companyId = max(1, $companyId);
        self::resetKnownEnvToBaseline();

        $sql = 'SELECT provider_key, status, connection_meta_json
                FROM company_integrations
                WHERE company_id = :company_id';
        $params = ['company_id' => $companyId];

        if ($activeOnly) {
            $sql .= ' AND status = "active"';
        }

        if (is_string($providerFilter) && trim($providerFilter) !== '') {
            $sql .= ' AND provider_key = :provider_key';
            $params['provider_key'] = trim($providerFilter);
        }

        $sql .= ' ORDER BY id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $applied = 0;
        foreach ($rows as $row) {
            $providerKey = (string) ($row['provider_key'] ?? '');
            $meta = json_decode((string) ($row['connection_meta_json'] ?? '{}'), true);
            if (! is_array($meta)) {
                $meta = [];
            }

            $runtimeEnv = self::readEnvFromMeta($meta);
            if ($runtimeEnv === []) {
                continue;
            }

            foreach ($runtimeEnv as $key => $value) {
                if ($value === '') {
                    continue;
                }

                self::setEnv($key, $value);
                $applied++;
            }

            if (! isset($runtimeEnv['ERP_PROVIDER']) && in_array($providerKey, self::COMMON_ERP, true)) {
                self::setEnv('ERP_PROVIDER', $providerKey);
            }
        }

        return $applied;
    }

    public static function resetToBaseline(): void
    {
        self::resetKnownEnvToBaseline();
    }

    private static function setEnv(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private static function unsetEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    private static function resetKnownEnvToBaseline(): void
    {
        if (self::$baselineEnv === null) {
            self::$baselineEnv = [];
            foreach (self::allKnownKeys() as $key) {
                $existing = getenv($key);
                self::$baselineEnv[$key] = $existing === false ? null : (string) $existing;
            }
        }

        foreach (self::allKnownKeys() as $key) {
            $base = self::$baselineEnv[$key] ?? null;
            if ($base === null) {
                self::unsetEnv($key);
            } else {
                self::setEnv($key, $base);
            }
        }
    }

    private static function keyPath(): string
    {
        return __DIR__.'/../../../../storage/integration-runtime.key';
    }

    private static function secretKey(): string
    {
        $path = self::keyPath();
        if (is_file($path)) {
            $stored = trim((string) file_get_contents($path));
            if ($stored !== '') {
                $decoded = base64_decode($stored, true);
                if ($decoded !== false && strlen($decoded) === 32) {
                    return $decoded;
                }
            }
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $key = random_bytes(32);
        file_put_contents($path, base64_encode($key), LOCK_EX);
        @chmod($path, 0600);

        return $key;
    }

    private static function encryptValue(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $key = self::secretKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if (! is_string($cipher) || $cipher === '') {
            throw new \RuntimeException('Failed to encrypt integration config value.');
        }

        return 'enc:v1:'.base64_encode($iv.$tag.$cipher);
    }

    private static function decryptValue(string $payload): string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return '';
        }

        if (! str_starts_with($payload, 'enc:v1:')) {
            return $payload;
        }

        $raw = base64_decode(substr($payload, 7), true);
        if (! is_string($raw) || strlen($raw) < 29) {
            return '';
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::secretKey(), OPENSSL_RAW_DATA, $iv, $tag, '');
        if (! is_string($plain)) {
            return '';
        }

        return $plain;
    }

    private static function encryptMap(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $k = trim((string) $key);
            if ($k === '') {
                continue;
            }
            $out[$k] = self::encryptValue(trim((string) $value));
        }

        return $out;
    }

    private static function decryptMap(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $k = trim((string) $key);
            if ($k === '') {
                continue;
            }

            $out[$k] = self::decryptValue((string) $value);
        }

        return $out;
    }
}
