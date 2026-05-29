<?php

declare(strict_types=1);

use Pazy\Integrations\ProviderRegistry;
use Pazy\Integrations\Support\HttpClient;
use Pazy\Modules\Approvals\ApprovalEngine;
use Pazy\Modules\Audit\AuditService;
use Pazy\Modules\Expenses\ExpensePolicyEngine;
use Pazy\Modules\Integrations\IntegrationJobWorker;
use Pazy\Modules\Integrations\IntegrationOAuth;
use Pazy\Modules\Integrations\IntegrationRuntimeConfig;
use Pazy\Modules\Integrations\MailInboxPuller;
use Pazy\Modules\Payments\PaymentEngine;
use Pazy\Modules\Payments\PaymentBatchEngine;
use Pazy\Modules\Procurement\MatchingEngine;
use Pazy\Modules\Tax\TaxReconciliationEngine;

function web_public_pages(): array
{
    return ['home', 'about', 'features', 'pricing', 'contact', 'login'];
}

function web_is_public_page(string $page): bool
{
    return in_array($page, web_public_pages(), true);
}

function web_ensure_optional_tables(PDO $pdo): void
{
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }

    $requiredTables = [
        'company_integrations',
        'corporate_cards',
        'credit_lines',
        'upi_wallets',
        'request_rate_limits',
    ];

    $missing = [];
    $check = $pdo->prepare('SELECT COUNT(*)
                            FROM information_schema.tables
                            WHERE table_schema = DATABASE()
                              AND table_name = :table_name');
    foreach ($requiredTables as $tableName) {
        $check->execute(['table_name' => $tableName]);
        $exists = (int) ($check->fetchColumn() ?: 0) > 0;
        if (! $exists) {
            $missing[] = $tableName;
        }
    }

    if ($missing !== []) {
        try {
            if (in_array('company_integrations', $missing, true)) {
                $pdo->exec('CREATE TABLE IF NOT EXISTS company_integrations (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    company_id BIGINT UNSIGNED NOT NULL,
                    provider_key VARCHAR(80) NOT NULL,
                    provider_name VARCHAR(120) NOT NULL,
                    category VARCHAR(40) NOT NULL,
                    status VARCHAR(30) NOT NULL DEFAULT "disabled",
                    connection_meta_json JSON NULL,
                    connected_at DATETIME NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uq_company_provider (company_id, provider_key),
                    KEY idx_company_integrations_status (company_id, status),
                    CONSTRAINT fk_company_integrations_company FOREIGN KEY (company_id) REFERENCES companies(id)
                ) ENGINE=InnoDB');
            }

            if (in_array('corporate_cards', $missing, true)) {
                $pdo->exec('CREATE TABLE IF NOT EXISTS corporate_cards (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    company_id BIGINT UNSIGNED NOT NULL,
                    card_name VARCHAR(120) NOT NULL,
                    card_type VARCHAR(20) NOT NULL DEFAULT "virtual",
                    assigned_user_id BIGINT UNSIGNED NULL,
                    last4 CHAR(4) NOT NULL,
                    spending_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
                    mcc_controls_json JSON NULL,
                    receipt_required TINYINT(1) NOT NULL DEFAULT 1,
                    status VARCHAR(30) NOT NULL DEFAULT "active",
                    created_by BIGINT UNSIGNED NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    KEY idx_cards_company_status (company_id, status),
                    CONSTRAINT fk_cards_company FOREIGN KEY (company_id) REFERENCES companies(id),
                    CONSTRAINT fk_cards_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id),
                    CONSTRAINT fk_cards_created_by FOREIGN KEY (created_by) REFERENCES users(id)
                ) ENGINE=InnoDB');
            }

            if (in_array('credit_lines', $missing, true)) {
                $pdo->exec('CREATE TABLE IF NOT EXISTS credit_lines (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    company_id BIGINT UNSIGNED NOT NULL,
                    provider_name VARCHAR(120) NOT NULL,
                    sanctioned_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
                    utilized_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                    available_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                    interest_rate_apr DECIMAL(6,2) NOT NULL DEFAULT 0,
                    status VARCHAR(30) NOT NULL DEFAULT "active",
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uq_credit_line_company (company_id),
                    CONSTRAINT fk_credit_line_company FOREIGN KEY (company_id) REFERENCES companies(id)
                ) ENGINE=InnoDB');
            }

            if (in_array('upi_wallets', $missing, true)) {
                $pdo->exec('CREATE TABLE IF NOT EXISTS upi_wallets (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    company_id BIGINT UNSIGNED NOT NULL,
                    virtual_account VARCHAR(120) NOT NULL,
                    daily_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
                    monthly_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
                    used_today DECIMAL(14,2) NOT NULL DEFAULT 0,
                    used_month DECIMAL(14,2) NOT NULL DEFAULT 0,
                    status VARCHAR(30) NOT NULL DEFAULT "active",
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uq_upi_wallet_company (company_id),
                    CONSTRAINT fk_upi_wallet_company FOREIGN KEY (company_id) REFERENCES companies(id)
                ) ENGINE=InnoDB');
            }

            if (in_array('request_rate_limits', $missing, true)) {
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
            }
        } catch (Throwable $ignored) {
            // If DB user lacks CREATE privileges, features will remain hidden until schema is migrated.
        }
    }

    $bootstrapped = true;
}

function web_integration_catalog(): array
{
    return [
        [
            'provider_key' => 'mail',
            'provider_name' => 'Mail',
            'category' => 'capture',
            'default_status' => 'active',
            'description' => "Organization's unique email forwarding address to capture invoices.",
            'connect_url' => 'https://workspace.google.com/gmail/',
        ],
        [
            'provider_key' => 'slack',
            'provider_name' => 'Slack',
            'category' => 'communication',
            'default_status' => 'disabled',
            'description' => 'Connect Slack to raise invoices, manage approvals, and receive alerts.',
            'connect_url' => 'https://slack.com/apps',
        ],
        [
            'provider_key' => 'zoho',
            'provider_name' => 'Zoho',
            'category' => 'erp',
            'default_status' => 'active',
            'description' => 'Connect Zoho Books and sync accounting directly.',
            'connect_url' => 'https://www.zoho.com/books/',
        ],
        [
            'provider_key' => 'odoo',
            'provider_name' => 'Odoo',
            'category' => 'erp',
            'default_status' => 'disabled',
            'description' => 'Sync purchase orders and GRNs into the spend workflow.',
            'connect_url' => 'https://www.odoo.com/',
        ],
        [
            'provider_key' => 'tally',
            'provider_name' => 'Tally',
            'category' => 'erp',
            'default_status' => 'disabled',
            'description' => 'Sync accounting data directly to Tally ledgers.',
            'connect_url' => 'https://tallysolutions.com/',
        ],
        [
            'provider_key' => 'whatsapp',
            'provider_name' => 'WhatsApp',
            'category' => 'capture',
            'default_status' => 'disabled',
            'description' => 'Allow employees to submit invoices over WhatsApp.',
            'connect_url' => 'https://developers.facebook.com/docs/whatsapp/',
        ],
        [
            'provider_key' => 'google_workspace',
            'provider_name' => 'Google Workspace',
            'category' => 'identity',
            'default_status' => 'disabled',
            'description' => 'Sync users and provisioning from Google Workspace.',
            'connect_url' => 'https://workspace.google.com/',
        ],
        [
            'provider_key' => 'microsoft_ad',
            'provider_name' => 'Microsoft Active Directory',
            'category' => 'identity',
            'default_status' => 'disabled',
            'description' => 'Sync users and groups from Microsoft Active Directory.',
            'connect_url' => 'https://www.microsoft.com/microsoft-365',
        ],
        [
            'provider_key' => 'oracle_fusion',
            'provider_name' => 'Oracle Fusion Cloud',
            'category' => 'erp',
            'default_status' => 'disabled',
            'description' => 'Connect Oracle Fusion Cloud and sync accounting.',
            'connect_url' => 'https://www.oracle.com/erp/',
        ],
        [
            'provider_key' => 'business_central',
            'provider_name' => 'Microsoft Business Central 365',
            'category' => 'erp',
            'default_status' => 'disabled',
            'description' => 'Connect Dynamics BC 365 and sync journals.',
            'connect_url' => 'https://dynamics.microsoft.com/business-central/',
        ],
        [
            'provider_key' => 'campfire',
            'provider_name' => 'Campfire',
            'category' => 'erp',
            'default_status' => 'disabled',
            'description' => 'Connect Campfire and synchronize accounting data.',
            'connect_url' => 'https://www.campfiresoftware.com/',
        ],
        [
            'provider_key' => 'netsuite',
            'provider_name' => 'Oracle NetSuite',
            'category' => 'erp',
            'default_status' => 'disabled',
            'description' => 'Connect NetSuite and automate accounting sync.',
            'connect_url' => 'https://www.netsuite.com/',
        ],
        [
            'provider_key' => 'hdfc_bank',
            'provider_name' => 'HDFC Bank API',
            'category' => 'banking',
            'default_status' => 'disabled',
            'description' => 'Connect HDFC payment rails for single and bulk payouts.',
            'connect_url' => 'https://www.hdfcbank.com/',
        ],
        [
            'provider_key' => 'icici_bank',
            'provider_name' => 'ICICI Bank API',
            'category' => 'banking',
            'default_status' => 'disabled',
            'description' => 'Connect ICICI payment APIs for secure transfer orchestration.',
            'connect_url' => 'https://www.icicibank.com/',
        ],
        [
            'provider_key' => 'axis_bank',
            'provider_name' => 'Axis Bank API',
            'category' => 'banking',
            'default_status' => 'disabled',
            'description' => 'Connect Axis Bank rails with maker-checker payout controls.',
            'connect_url' => 'https://www.axisbank.com/',
        ],
        [
            'provider_key' => 'kotak_bank',
            'provider_name' => 'Kotak Bank API',
            'category' => 'banking',
            'default_status' => 'disabled',
            'description' => 'Connect Kotak APIs for vendor and employee payout execution.',
            'connect_url' => 'https://www.kotak.com/',
        ],
        [
            'provider_key' => 'gstn_portal',
            'provider_name' => 'GSTN Reconciliation',
            'category' => 'compliance',
            'default_status' => 'disabled',
            'description' => 'Link GST portal reconciliation feeds to release and hold decisions.',
            'connect_url' => 'https://www.gst.gov.in/',
        ],
        [
            'provider_key' => 'mca_registry',
            'provider_name' => 'MCA Validation',
            'category' => 'compliance',
            'default_status' => 'disabled',
            'description' => 'Validate entity details against MCA registry checks.',
            'connect_url' => 'https://www.mca.gov.in/',
        ],
    ];
}

function web_has_env(string $key): bool
{
    return trim((string) getenv($key)) !== '';
}

function web_integration_config_fields(string $providerKey): array
{
    return IntegrationRuntimeConfig::fieldsForProvider($providerKey);
}

function web_apply_runtime_env_for_company(PDO $pdo, int $companyId, bool $activeOnly = true, ?string $providerFilter = null): int
{
    return IntegrationRuntimeConfig::applyForCompany($pdo, $companyId, $activeOnly, $providerFilter);
}

function web_mask_secret_value(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    $length = strlen($trimmed);
    if ($length <= 4) {
        return str_repeat('*', $length);
    }

    return str_repeat('*', max(4, $length - 4)).substr($trimmed, -4);
}

function web_integration_icon_url(string $providerKey): string
{
    $domain = match ($providerKey) {
        'mail' => 'gmail.com',
        'slack' => 'slack.com',
        'zoho' => 'zoho.com',
        'odoo' => 'odoo.com',
        'tally' => 'tallysolutions.com',
        'whatsapp' => 'whatsapp.com',
        'google_workspace' => 'workspace.google.com',
        'microsoft_ad', 'business_central' => 'microsoft.com',
        'oracle_fusion' => 'oracle.com',
        'campfire' => 'basecamp.com',
        'netsuite' => 'netsuite.com',
        'hdfc_bank' => 'hdfcbank.com',
        'icici_bank' => 'icicibank.com',
        'axis_bank' => 'axisbank.com',
        'kotak_bank' => 'kotak.com',
        'gstn_portal' => 'gst.gov.in',
        'mca_registry' => 'mca.gov.in',
        default => '',
    };

    return $domain === '' ? '' : 'https://www.google.com/s2/favicons?domain='.$domain.'&sz=64';
}

function web_integration_icon_label(string $providerKey, string $providerName): string
{
    $label = match ($providerKey) {
        'google_workspace' => 'G',
        'microsoft_ad' => 'MS',
        'business_central' => 'BC',
        'gstn_portal' => 'GST',
        'mca_registry' => 'MCA',
        default => '',
    };

    if ($label !== '') {
        return $label;
    }

    $letters = preg_replace('/[^A-Za-z]/', '', $providerName);
    return strtoupper(substr($letters !== null && $letters !== '' ? $letters : 'AP', 0, 2));
}

function web_integration_runtime_state(string $providerKey): array
{
    $erpEnabled = web_has_env('ERP_SYNC_URL') || web_has_env('ZOHO_BOOKS_SYNC_ENDPOINT') || web_has_env('TALLY_SYNC_URL');
    $bankEnabled = web_has_env('BANK_API_BASE_URL');
    $googleEnabled = web_has_env('GOOGLE_WORKSPACE_SYNC_URL');
    $adEnabled = web_has_env('MICROSOFT_AD_SYNC_URL');
    $taxEnabled = web_has_env('TAX_API_BASE_URL');
    $mcaEnabled = web_has_env('MCA_VERIFICATION_URL');

    return match ($providerKey) {
        'mail' => (
            function_exists('imap_open') &&
            web_has_env('MAIL_INBOUND_IMAP_HOST') &&
            web_has_env('MAIL_INBOUND_IMAP_USERNAME') &&
            web_has_env('MAIL_INBOUND_IMAP_PASSWORD')
        )
            ? ['mode' => 'live', 'label' => 'Live', 'hint' => 'IMAP is configured.']
            : (! function_exists('imap_open')
                ? ['mode' => 'stub', 'label' => 'Stub', 'hint' => 'Enable PHP IMAP extension.']
                : ['mode' => 'stub', 'label' => 'Stub', 'hint' => 'Configure IMAP credentials in Integrations page or .env.']),
        'slack' => web_has_env('SLACK_WEBHOOK_URL')
            ? ['mode' => 'live', 'label' => 'Live', 'hint' => 'Using Slack webhook.']
            : ['mode' => 'stub', 'label' => 'Stub', 'hint' => 'Configure Slack webhook in Integrations page or .env.'],
        'whatsapp' => (web_has_env('WHATSAPP_ACCESS_TOKEN') && web_has_env('WHATSAPP_PHONE_NUMBER_ID'))
            ? ['mode' => 'live', 'label' => 'Live', 'hint' => 'Using WhatsApp Cloud API.']
            : ['mode' => 'stub', 'label' => 'Stub', 'hint' => 'Configure WhatsApp credentials in Integrations page or .env.'],
        'google_workspace' => $googleEnabled
            ? ['mode' => 'live', 'label' => 'Live', 'hint' => 'Google Workspace sync endpoint is configured.']
            : ['mode' => 'stub', 'label' => 'Stub', 'hint' => 'Configure Google sync URL in Integrations page or .env.'],
        'microsoft_ad' => $adEnabled
            ? ['mode' => 'live', 'label' => 'Live', 'hint' => 'Microsoft AD sync endpoint is configured.']
            : ['mode' => 'stub', 'label' => 'Stub', 'hint' => 'Configure Microsoft AD sync URL in Integrations page or .env.'],
        'zoho' => (web_has_env('ZOHO_BOOKS_SYNC_ENDPOINT') || web_has_env('ERP_SYNC_URL'))
            ? ['mode' => 'live', 'label' => 'Live', 'hint' => 'Voucher sync endpoint configured.']
            : ['mode' => 'stub', 'label' => 'Stub', 'hint' => 'Configure ERP sync endpoint in Integrations page or .env.'],
        'tally' => (web_has_env('TALLY_SYNC_URL') || web_has_env('ERP_SYNC_URL'))
            ? ['mode' => 'live', 'label' => 'Live', 'hint' => 'Voucher sync endpoint configured.']
            : ['mode' => 'stub', 'label' => 'Stub', 'hint' => 'Configure Tally/ERP sync URL in Integrations page or .env.'],
        'odoo', 'oracle_fusion', 'business_central', 'campfire', 'netsuite' => $erpEnabled
            ? ['mode' => 'live', 'label' => 'Live', 'hint' => 'Using generic ERP sync endpoint.']
            : ['mode' => 'stub', 'label' => 'Stub', 'hint' => 'Configure ERP sync endpoint in Integrations page or .env.'],
        'hdfc_bank', 'icici_bank', 'axis_bank', 'kotak_bank' => $bankEnabled
            ? ['mode' => 'live', 'label' => 'Live', 'hint' => 'Bank connector is configured.']
            : ['mode' => 'stub', 'label' => 'Stub', 'hint' => 'Configure bank credentials in Integrations page or .env.'],
        'gstn_portal' => $taxEnabled
            ? ['mode' => 'live', 'label' => 'Live', 'hint' => 'Tax reconciliation endpoint is configured.']
            : ['mode' => 'stub', 'label' => 'Stub', 'hint' => 'Configure tax API endpoint in Integrations page or .env.'],
        'mca_registry' => $mcaEnabled
            ? ['mode' => 'live', 'label' => 'Live', 'hint' => 'MCA verification endpoint is configured.']
            : ['mode' => 'stub', 'label' => 'Stub', 'hint' => 'Configure MCA endpoint in Integrations page or .env.'],
        default => ['mode' => 'stub', 'label' => 'Stub', 'hint' => 'Connector is currently available as a stub in this build.'],
    };
}

function web_generic_probe(string $url, ?string $token = null): array
{
    $headers = [];
    $trimmedToken = trim((string) $token);
    if ($trimmedToken !== '') {
        $headers['Authorization'] = 'Bearer '.$trimmedToken;
    }

    $response = HttpClient::request('GET', $url, $headers, null, 15);
    if ($response['status_code'] < 200 || $response['status_code'] >= 400) {
        throw new RuntimeException('Probe failed: HTTP '.$response['status_code']);
    }

    return [
        'provider' => 'endpoint-probe',
        'status' => 'connected',
        'http_status' => $response['status_code'],
        'endpoint' => $url,
    ];
}

function web_sample_invoice_for_erp_test(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare('SELECT i.id, i.company_id, i.vendor_id, i.invoice_number, i.invoice_date, i.due_date,
                                  i.subtotal_amount, i.tax_amount, i.total_amount, i.po_id, i.grn_id, i.source_channel,
                                  i.currency_code, v.name AS vendor_name, v.tax_id AS vendor_tax_id
                           FROM invoices i
                           JOIN vendors v ON v.id = i.vendor_id
                           WHERE i.company_id = :company_id
                           ORDER BY i.id DESC
                           LIMIT 1');
    $stmt->execute(['company_id' => $companyId]);
    $invoice = $stmt->fetch();
    if (is_array($invoice)) {
        return $invoice;
    }

    return [
        'id' => 0,
        'company_id' => $companyId,
        'vendor_id' => null,
        'invoice_number' => 'TEST-ERP-'.gmdate('YmdHis'),
        'invoice_date' => today_utc(),
        'due_date' => today_utc(),
        'subtotal_amount' => 100,
        'tax_amount' => 18,
        'total_amount' => 118,
        'po_id' => null,
        'grn_id' => null,
        'source_channel' => 'web',
        'currency_code' => 'INR',
        'vendor_name' => 'ERP Test Vendor',
        'vendor_tax_id' => null,
    ];
}

function web_run_integration_test(PDO $pdo, int $companyId, string $providerKey, ?string $recipient): array
{
    $recipient = trim((string) $recipient);
    web_apply_runtime_env_for_company($pdo, $companyId, false, $providerKey);

    if ($providerKey === 'mail') {
        $result = MailInboxPuller::probe();

        if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $emailTest = ProviderRegistry::messaging()->send(
                'email',
                $recipient,
                'Pazy Integration Test',
                'Mail connector test at '.now_utc().' UTC'
            );
            $result['outbound_email'] = $emailTest;
        }

        return $result;
    }

    if ($providerKey === 'slack') {
        return ProviderRegistry::messaging()->send(
            'slack',
            $recipient !== '' ? $recipient : 'slack-webhook',
            'Pazy Integration Test',
            'Slack connector test at '.now_utc().' UTC'
        );
    }

    if ($providerKey === 'whatsapp') {
        $to = $recipient !== '' ? $recipient : trim((string) getenv('WHATSAPP_TEST_TO'));
        if ($to === '') {
            throw new RuntimeException('Provide a recipient number or set WHATSAPP_TEST_TO.');
        }

        return ProviderRegistry::messaging()->send(
            'whatsapp',
            $to,
            'Pazy Integration Test',
            'WhatsApp connector test at '.now_utc().' UTC'
        );
    }

    if ($providerKey === 'google_workspace') {
        $endpoint = trim((string) getenv('GOOGLE_WORKSPACE_SYNC_URL'));
        if ($endpoint === '') {
            throw new RuntimeException('Set GOOGLE_WORKSPACE_SYNC_URL first.');
        }

        return web_generic_probe($endpoint, (string) getenv('IDENTITY_SYNC_TOKEN'));
    }

    if ($providerKey === 'microsoft_ad') {
        $endpoint = trim((string) getenv('MICROSOFT_AD_SYNC_URL'));
        if ($endpoint === '') {
            throw new RuntimeException('Set MICROSOFT_AD_SYNC_URL first.');
        }

        return web_generic_probe($endpoint, (string) getenv('IDENTITY_SYNC_TOKEN'));
    }

    if (in_array($providerKey, ['zoho', 'odoo', 'tally', 'oracle_fusion', 'business_central', 'campfire', 'netsuite'], true)) {
        $invoice = web_sample_invoice_for_erp_test($pdo, $companyId);
        return ProviderRegistry::erp()->syncVoucher($invoice);
    }

    if (in_array($providerKey, ['hdfc_bank', 'icici_bank', 'axis_bank', 'kotak_bank'], true)) {
        if (! web_has_env('BANK_API_BASE_URL')) {
            throw new RuntimeException('Set BANK_API_BASE_URL first.');
        }

        return [
            'provider' => 'bank-connector',
            'status' => 'configured',
            'bank' => $providerKey,
            'message' => 'Bank integration is configured. Use payment workflows for end-to-end validation.',
        ];
    }

    if ($providerKey === 'gstn_portal') {
        $endpoint = trim((string) getenv('TAX_API_BASE_URL'));
        if ($endpoint === '') {
            throw new RuntimeException('Set TAX_API_BASE_URL first.');
        }

        return web_generic_probe($endpoint, (string) getenv('TAX_API_TOKEN'));
    }

    if ($providerKey === 'mca_registry') {
        $endpoint = trim((string) getenv('MCA_VERIFICATION_URL'));
        if ($endpoint === '') {
            throw new RuntimeException('Set MCA_VERIFICATION_URL first.');
        }

        return web_generic_probe($endpoint, (string) getenv('MCA_API_TOKEN'));
    }

    return [
        'provider' => 'stub-'.$providerKey,
        'status' => 'not_implemented',
        'message' => 'This connector is represented in UI but has no live adapter in this build.',
    ];
}

function web_sync_company_integrations(PDO $pdo, int $companyId): void
{
    $catalog = web_integration_catalog();
    $companyCodeStmt = $pdo->prepare('SELECT code FROM companies WHERE id = :id LIMIT 1');
    $companyCodeStmt->execute(['id' => $companyId]);
    $companyCode = strtolower(str_replace([' ', '_'], '', (string) ($companyCodeStmt->fetchColumn() ?: 'company')));
    $mailAlias = $companyCode.'@invoices.pazy.local';

    $insert = $pdo->prepare('INSERT INTO company_integrations
        (company_id, provider_key, provider_name, category, status, connection_meta_json, connected_at, created_at, updated_at)
        VALUES
        (:company_id, :provider_key, :provider_name, :category, :status, :connection_meta_json, :connected_at, :created_at, :updated_at)');
    $exists = $pdo->prepare('SELECT id FROM company_integrations WHERE company_id = :company_id AND provider_key = :provider_key LIMIT 1');

    foreach ($catalog as $provider) {
        $providerKey = (string) $provider['provider_key'];
        $exists->execute([
            'company_id' => $companyId,
            'provider_key' => $providerKey,
        ]);

        if ($exists->fetch()) {
            continue;
        }

        $meta = [
            'description' => (string) $provider['description'],
        ];
        if ($providerKey === 'mail') {
            $meta['invoice_forwarding_alias'] = $mailAlias;
        }

        $status = (string) ($provider['default_status'] ?? 'disabled');
        $insert->execute([
            'company_id' => $companyId,
            'provider_key' => $providerKey,
            'provider_name' => (string) $provider['provider_name'],
            'category' => (string) $provider['category'],
            'status' => $status,
            'connection_meta_json' => json_encode($meta, JSON_THROW_ON_ERROR),
            'connected_at' => $status === 'active' ? now_utc() : null,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
    }
}

function web_sync_org_integrations(PDO $pdo, int $organizationId): int
{
    $companyStmt = $pdo->prepare('SELECT id
                                  FROM companies
                                  WHERE organization_id = :organization_id');
    $companyStmt->execute(['organization_id' => $organizationId]);
    $companyIds = array_map(static fn ($value): int => (int) $value, $companyStmt->fetchAll(PDO::FETCH_COLUMN));

    if ($companyIds === []) {
        return 0;
    }

    $updateStmt = $pdo->prepare('UPDATE company_integrations
                                 SET status = "active",
                                     connected_at = COALESCE(connected_at, :connected_at),
                                     updated_at = :updated_at
                                 WHERE company_id = :company_id');

    foreach ($companyIds as $companyId) {
        web_sync_company_integrations($pdo, $companyId);
        $updateStmt->execute([
            'connected_at' => now_utc(),
            'updated_at' => now_utc(),
            'company_id' => $companyId,
        ]);
    }

    return count($companyIds);
}

function handle_web_get(PDO $pdo, array $config, string $currentPage): void
{
    if ($currentPage !== 'integrations') {
        return;
    }

    if (! Auth::check()) {
        return;
    }

    if ((string) ($_GET['oauth'] ?? '') !== 'callback') {
        return;
    }

    Auth::requirePermission($config, 'integrations.manage');

    $state = trim((string) ($_GET['state'] ?? ''));
    $code = trim((string) ($_GET['code'] ?? ''));
    $oauthError = trim((string) ($_GET['error'] ?? ''));
    $oauthErrorDescription = trim((string) ($_GET['error_description'] ?? ''));
    $sessionStates = $_SESSION['integration_oauth_states'] ?? [];

    if (! is_array($sessionStates)) {
        $sessionStates = [];
    }

    if ($state === '' || ! isset($sessionStates[$state]) || ! is_array($sessionStates[$state])) {
        flash_set('error', 'OAuth state is invalid or expired. Start connect again.');
        redirect_to(base_url($config, 'index.php?page=integrations'));
    }

    $stateData = $sessionStates[$state];
    unset($sessionStates[$state]);
    $_SESSION['integration_oauth_states'] = $sessionStates;

    $providerKey = trim((string) ($stateData['provider_key'] ?? ''));
    $companyId = max(1, (int) ($stateData['company_id'] ?? 0));
    $userId = max(1, (int) ($stateData['user_id'] ?? 0));
    $createdAt = (int) ($stateData['created_at'] ?? 0);
    $currentUser = Auth::user();
    $activeUserId = (int) ($currentUser['id'] ?? 0);

    if ($providerKey === '' || ! IntegrationOAuth::isSupportedProvider($providerKey)) {
        flash_set('error', 'Unsupported OAuth provider callback.');
        redirect_to(base_url($config, 'index.php?page=integrations'));
    }

    if ($userId !== $activeUserId) {
        flash_set('error', 'OAuth callback user mismatch. Retry from this account.');
        redirect_to(base_url($config, 'index.php?page=integrations'));
    }

    if ($createdAt <= 0 || (time() - $createdAt) > 900) {
        flash_set('error', 'OAuth session expired. Please connect again.');
        redirect_to(base_url($config, 'index.php?page=integrations'));
    }

    web_sync_company_integrations($pdo, $companyId);

    $fetch = $pdo->prepare('SELECT id, provider_name, connection_meta_json
                            FROM company_integrations
                            WHERE company_id = :company_id
                              AND provider_key = :provider_key
                            LIMIT 1');
    $fetch->execute([
        'company_id' => $companyId,
        'provider_key' => $providerKey,
    ]);
    $row = $fetch->fetch();
    if (! $row) {
        flash_set('error', 'Integration record not found for OAuth callback.');
        redirect_to(base_url($config, 'index.php?page=integrations'));
    }

    $meta = json_decode((string) ($row['connection_meta_json'] ?? '{}'), true);
    if (! is_array($meta)) {
        $meta = [];
    }
    $existingEnv = IntegrationRuntimeConfig::readEnvFromMeta($meta);

    if ($oauthError !== '') {
        $message = $oauthErrorDescription !== '' ? $oauthErrorDescription : $oauthError;
        $meta['oauth'] = [
            'connected' => false,
            'provider_key' => $providerKey,
            'last_error' => $message,
            'last_callback_at' => now_utc(),
            'last_callback_by' => $userId,
        ];
        $pdo->prepare('UPDATE company_integrations
                       SET connection_meta_json = :connection_meta_json,
                           updated_at = :updated_at
                       WHERE id = :id')
            ->execute([
                'connection_meta_json' => json_encode($meta, JSON_THROW_ON_ERROR),
                'updated_at' => now_utc(),
                'id' => (int) $row['id'],
            ]);

        AuditService::log($pdo, $companyId, $userId, 'integration.oauth.callback_error', 'integration', (int) $row['id'], [
            'provider_key' => $providerKey,
            'error' => $message,
        ]);

        flash_set('error', (($row['provider_name'] ?? $providerKey).' OAuth failed: '.$message));
        redirect_to(base_url($config, 'index.php?page=integrations'));
    }

    if ($code === '') {
        flash_set('error', 'Missing OAuth authorization code.');
        redirect_to(base_url($config, 'index.php?page=integrations'));
    }

    try {
        $exchange = IntegrationOAuth::exchangeCode($providerKey, $existingEnv, $code, IntegrationOAuth::callbackUri($config));
        $envUpdates = is_array($exchange['env_updates'] ?? null) ? $exchange['env_updates'] : [];
        $nextEnv = $existingEnv;
        foreach ($envUpdates as $key => $value) {
            $k = trim((string) $key);
            if ($k === '') {
                continue;
            }
            $nextEnv[$k] = trim((string) $value);
        }

        $meta = IntegrationRuntimeConfig::writeEnvToMeta($meta, $nextEnv);
        $meta['oauth'] = [
            'connected' => true,
            'provider_key' => $providerKey,
            'connected_at' => now_utc(),
            'connected_by' => $userId,
            'connected_account' => (string) ($exchange['connected_account'] ?? ''),
            'last_error' => null,
            'token_meta' => $exchange['token_response'] ?? [],
        ];

        $pdo->prepare('UPDATE company_integrations
                       SET status = "active",
                           connected_at = :connected_at,
                           connection_meta_json = :connection_meta_json,
                           updated_at = :updated_at
                       WHERE id = :id')
            ->execute([
                'connected_at' => now_utc(),
                'connection_meta_json' => json_encode($meta, JSON_THROW_ON_ERROR),
                'updated_at' => now_utc(),
                'id' => (int) $row['id'],
            ]);

        web_apply_runtime_env_for_company($pdo, $companyId, false, $providerKey);

        AuditService::log($pdo, $companyId, $userId, 'integration.oauth.connected', 'integration', (int) $row['id'], [
            'provider_key' => $providerKey,
            'connected_account' => (string) ($exchange['connected_account'] ?? ''),
            'updated_keys' => array_keys($envUpdates),
        ]);

        flash_set('success', (($row['provider_name'] ?? $providerKey).' connected successfully via OAuth.'));
        redirect_to(base_url($config, 'index.php?page=integrations'));
    } catch (Throwable $e) {
        $meta['oauth'] = [
            'connected' => false,
            'provider_key' => $providerKey,
            'last_error' => mb_substr($e->getMessage(), 0, 500),
            'last_callback_at' => now_utc(),
            'last_callback_by' => $userId,
        ];
        $pdo->prepare('UPDATE company_integrations
                       SET connection_meta_json = :connection_meta_json,
                           updated_at = :updated_at
                       WHERE id = :id')
            ->execute([
                'connection_meta_json' => json_encode($meta, JSON_THROW_ON_ERROR),
                'updated_at' => now_utc(),
                'id' => (int) $row['id'],
            ]);

        AuditService::log($pdo, $companyId, $userId, 'integration.oauth.connect_failed', 'integration', (int) $row['id'], [
            'provider_key' => $providerKey,
            'error' => $e->getMessage(),
        ]);

        flash_set('error', (($row['provider_name'] ?? $providerKey).' OAuth failed: '.$e->getMessage()));
        redirect_to(base_url($config, 'index.php?page=integrations'));
    }
}

function handle_web_post(PDO $pdo, array $config, string $currentPage): void
{
    $postedToken = $_POST['_csrf'] ?? null;
    if (! Csrf::verify($config, is_string($postedToken) ? $postedToken : null)) {
        flash_set('error', 'Security token mismatch. Please refresh and retry.');
        redirect_to(base_url($config, 'index.php?page='.$currentPage));
    }

    $action = (string) ($_POST['action'] ?? '');
    ensure_runtime_support_tables($pdo);

    $rateCfg = $config['security']['rate_limits'] ?? [];
    $authPerMinute = max(1, (int) ($rateCfg['auth_per_minute'] ?? 15));
    $webPostPerMinute = max(1, (int) ($rateCfg['web_post_per_minute'] ?? 120));
    $ip = request_client_ip();

    $rateKey = 'web:post:'.$ip.':'.$action;
    $rateLimit = $webPostPerMinute;
    if ($action === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $rateKey = 'web:auth:'.$ip.':'.$email;
        $rateLimit = $authPerMinute;
    } elseif ($action === 'submit_public_contact') {
        $rateKey = 'web:contact:'.$ip;
        $rateLimit = max(10, (int) floor($webPostPerMinute / 2));
    }

    $rate = rate_limit_allow($pdo, $rateKey, $rateLimit, 60);
    if (($rate['allowed'] ?? true) !== true) {
        $retry = max(1, (int) ($rate['retry_after'] ?? 60));
        flash_set('error', 'Too many requests. Please retry in '.$retry.' seconds.');
        redirect_to(base_url($config, 'index.php?page='.$currentPage));
    }

    if ($action === 'login') {
        web_action_login($pdo, $config);
    }

    if ($action === 'logout') {
        Auth::logout();
        flash_set('success', 'Logged out.');
        redirect_to(base_url($config, 'index.php?page=home'));
    }

    if ($action === 'submit_public_contact') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $company = trim((string) ($_POST['company'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));

        if ($name === '' || $email === '' || $message === '') {
            flash_set('error', 'Name, email, and message are required.');
            redirect_to(base_url($config, 'index.php?page=contact'));
        }

        $entry = [
            'name' => $name,
            'email' => $email,
            'company' => $company,
            'message' => $message,
            'submitted_at' => now_utc(),
            'source_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ];

        $logFile = __DIR__.'/../storage/public_contact.log';
        $line = json_encode($entry, JSON_THROW_ON_ERROR).PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

        flash_set('success', 'Thanks. Your message has been received by our team.');
        redirect_to(base_url($config, 'index.php?page=contact'));
    }

    if ($action === 'switch_company') {
        Auth::requireLogin($config);

        $companyId = (int) ($_POST['company_id'] ?? 0);
        if (! Auth::switchCompany($companyId)) {
            flash_set('error', 'Company switch denied.');
        } else {
            flash_set('success', 'Company context switched.');
        }

        redirect_to(base_url($config, 'index.php?page=dashboard'));
    }

    Auth::requireLogin($config);

    $companyId = current_company_id();
    $userId = current_user_id();

    web_ensure_optional_tables($pdo);
    if ($companyId > 0) {
        web_sync_company_integrations($pdo, $companyId);
        web_apply_runtime_env_for_company($pdo, $companyId, true, null);
    }

    if ($action === 'create_vendor') {
        Auth::requirePermission($config, 'vendors.manage');

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            flash_set('error', 'Vendor name is required.');
            redirect_to(base_url($config, 'index.php?page=vendors'));
        }

        $taxId = trim((string) ($_POST['tax_id'] ?? ''));
        $verification = ProviderRegistry::identity()->verifyTaxIdentity($taxId);

        $insert = $pdo->prepare('INSERT INTO vendors
            (company_id, name, email, phone, tax_id, bank_account_masked, compliance_score, status, created_at, updated_at)
            VALUES
            (:company_id, :name, :email, :phone, :tax_id, :bank_account_masked, :compliance_score, :status, :created_at, :updated_at)');
        $insert->execute([
            'company_id' => $companyId,
            'name' => $name,
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'tax_id' => $taxId,
            'bank_account_masked' => trim((string) ($_POST['bank_account_masked'] ?? '')),
            'compliance_score' => (int) ($verification['score'] ?? 50),
            'status' => 'active',
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);

        $vendorId = (int) $pdo->lastInsertId();
        AuditService::log($pdo, $companyId, $userId, 'vendor.created', 'vendor', $vendorId, ['tax_valid' => (bool) ($verification['valid'] ?? false)]);

        flash_set('success', 'Vendor created and verified.');
        redirect_to(base_url($config, 'index.php?page=vendors'));
    }

    if ($action === 'create_po') {
        Auth::requirePermission($config, 'procurement.manage');

        $vendorId = (int) ($_POST['vendor_id'] ?? 0);
        $poNumber = trim((string) ($_POST['po_number'] ?? ''));
        $amount = (float) ($_POST['total_amount'] ?? 0);

        if ($vendorId <= 0 || $poNumber === '' || $amount <= 0) {
            flash_set('error', 'PO number, vendor, and amount are required.');
            redirect_to(base_url($config, 'index.php?page=procurement'));
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO purchase_orders
                (company_id, vendor_id, po_number, po_date, total_amount, status, requester_user_id, created_at, updated_at)
                VALUES
                (:company_id, :vendor_id, :po_number, :po_date, :total_amount, :status, :requester_user_id, :created_at, :updated_at)');
            $insert->execute([
                'company_id' => $companyId,
                'vendor_id' => $vendorId,
                'po_number' => $poNumber,
                'po_date' => (string) ($_POST['po_date'] ?? today_utc()),
                'total_amount' => $amount,
                'status' => 'submitted',
                'requester_user_id' => $userId,
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);

            $poId = (int) $pdo->lastInsertId();

            ApprovalEngine::createFlow(
                $pdo,
                $companyId,
                'po',
                $poId,
                $amount,
                $userId,
                trim((string) ($_POST['department_code'] ?? '')) ?: null,
                $vendorId
            );

            AuditService::log($pdo, $companyId, $userId, 'po.created', 'po', $poId, ['amount' => $amount]);
            $pdo->commit();

            flash_set('success', 'Purchase order submitted for approval.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Failed to create PO: '.$e->getMessage());
        }

        redirect_to(base_url($config, 'index.php?page=procurement'));
    }

    if ($action === 'create_grn') {
        Auth::requirePermission($config, 'procurement.manage');

        $poId = (int) ($_POST['po_id'] ?? 0);
        $grnNumber = trim((string) ($_POST['grn_number'] ?? ''));
        if ($poId <= 0 || $grnNumber === '') {
            flash_set('error', 'PO and GRN number are required.');
            redirect_to(base_url($config, 'index.php?page=procurement'));
        }

        $insert = $pdo->prepare('INSERT INTO goods_receipts
            (company_id, po_id, grn_number, received_date, status, receiver_user_id, created_at, updated_at)
            VALUES
            (:company_id, :po_id, :grn_number, :received_date, :status, :receiver_user_id, :created_at, :updated_at)');
        $insert->execute([
            'company_id' => $companyId,
            'po_id' => $poId,
            'grn_number' => $grnNumber,
            'received_date' => (string) ($_POST['received_date'] ?? today_utc()),
            'status' => 'received',
            'receiver_user_id' => $userId,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);

        $grnId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE purchase_orders SET status = "partially_received", updated_at = :updated_at WHERE id = :id AND company_id = :company_id')
            ->execute(['updated_at' => now_utc(), 'id' => $poId, 'company_id' => $companyId]);

        AuditService::log($pdo, $companyId, $userId, 'grn.created', 'grn', $grnId, ['po_id' => $poId]);

        flash_set('success', 'GRN recorded.');
        redirect_to(base_url($config, 'index.php?page=procurement'));
    }

    if ($action === 'create_invoice') {
        Auth::requirePermission($config, 'invoices.manage');

        $vendorId = (int) ($_POST['vendor_id'] ?? 0);
        $invoiceNumber = trim((string) ($_POST['invoice_number'] ?? ''));
        $totalAmount = (float) ($_POST['total_amount'] ?? 0);
        $sourceChannel = strtolower(trim((string) ($_POST['source_channel'] ?? 'web')));
        $sourceRef = trim((string) ($_POST['source_ref'] ?? ''));

        if ($vendorId <= 0 || $invoiceNumber === '' || $totalAmount <= 0) {
            flash_set('error', 'Invoice number, vendor, and amount are required.');
            redirect_to(base_url($config, 'index.php?page=invoices'));
        }

        $pdo->beginTransaction();
        try {
            $storedInvoiceDocument = null;
            if (isset($_FILES['invoice_file']) && (int) ($_FILES['invoice_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $storedInvoiceDocument = store_uploaded_file($config, (array) $_FILES['invoice_file'], $companyId, 'invoice');
            }

            $ocrPath = trim((string) ($_POST['document_path'] ?? ''));
            if ($storedInvoiceDocument !== null) {
                $ocrPath = (string) $storedInvoiceDocument['object_key'];
            }

            $ocr = ProviderRegistry::ocr()->extractInvoice($ocrPath);

            $insert = $pdo->prepare('INSERT INTO invoices
                (company_id, vendor_id, po_id, grn_id, invoice_number, invoice_date, due_date, subtotal_amount, tax_amount, total_amount, source_channel, extracted_data_json, status, created_by, created_at, updated_at)
                VALUES
                (:company_id, :vendor_id, :po_id, :grn_id, :invoice_number, :invoice_date, :due_date, :subtotal_amount, :tax_amount, :total_amount, :source_channel, :extracted_data_json, :status, :created_by, :created_at, :updated_at)');
            $insert->execute([
                'company_id' => $companyId,
                'vendor_id' => $vendorId,
                'po_id' => (int) ($_POST['po_id'] ?? 0) ?: null,
                'grn_id' => (int) ($_POST['grn_id'] ?? 0) ?: null,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => (string) ($_POST['invoice_date'] ?? today_utc()),
                'due_date' => (string) ($_POST['due_date'] ?? today_utc()),
                'subtotal_amount' => (float) ($_POST['subtotal_amount'] ?? $totalAmount),
                'tax_amount' => (float) ($_POST['tax_amount'] ?? 0),
                'total_amount' => $totalAmount,
                'source_channel' => $sourceChannel,
                'extracted_data_json' => json_encode($ocr, JSON_THROW_ON_ERROR),
                'status' => 'captured',
                'created_by' => $userId,
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);

            $invoiceId = (int) $pdo->lastInsertId();
            log_capture_event($pdo, $companyId, 'invoice', $invoiceId, $sourceChannel, $sourceRef !== '' ? $sourceRef : null, [
                'invoice_number' => $invoiceNumber,
                'po_id' => (int) ($_POST['po_id'] ?? 0) ?: null,
                'grn_id' => (int) ($_POST['grn_id'] ?? 0) ?: null,
            ], $userId);

            if ($storedInvoiceDocument !== null) {
                $documentId = persist_document_metadata($pdo, $companyId, 'invoice', $invoiceId, $storedInvoiceDocument, $userId);

                $job = $pdo->prepare('INSERT INTO integration_jobs
                    (company_id, provider, job_type, status, payload_json, attempts, run_at, created_at, updated_at)
                    VALUES
                    (:company_id, :provider, :job_type, :status, :payload_json, :attempts, :run_at, :created_at, :updated_at)');
                $job->execute([
                    'company_id' => $companyId,
                    'provider' => 'ocr_stub',
                    'job_type' => 'invoice.extract',
                    'status' => 'queued',
                    'payload_json' => json_encode([
                        'invoice_id' => $invoiceId,
                        'document_id' => $documentId,
                        'document_key' => $storedInvoiceDocument['object_key'],
                    ], JSON_THROW_ON_ERROR),
                    'attempts' => 0,
                    'run_at' => now_utc(),
                    'created_at' => now_utc(),
                    'updated_at' => now_utc(),
                ]);
            }

            $match = MatchingEngine::evaluateInvoice($pdo, $config, $companyId, $invoiceId, $userId);

            if ($match['status'] === 'matched') {
                $pdo->prepare('UPDATE invoices SET status = "pending_approval", updated_at = :updated_at WHERE id = :id')
                    ->execute(['updated_at' => now_utc(), 'id' => $invoiceId]);

                ApprovalEngine::createFlow(
                    $pdo,
                    $companyId,
                    'invoice',
                    $invoiceId,
                    $totalAmount,
                    $userId,
                    trim((string) ($_POST['department_code'] ?? '')) ?: null,
                    $vendorId
                );
            }

            AuditService::log($pdo, $companyId, $userId, 'invoice.captured', 'invoice', $invoiceId, $match);

            $pdo->commit();
            flash_set('success', $match['status'] === 'matched' ? 'Invoice matched and routed for approval.' : 'Invoice captured with matching exception.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Invoice capture failed: '.$e->getMessage());
        }

        redirect_to(base_url($config, 'index.php?page=invoices'));
    }

    if ($action === 'resolve_exception') {
        Auth::requirePermission($config, 'invoices.manage');
        $returnPage = trim((string) ($_POST['return_page'] ?? 'invoices')) ?: 'invoices';

        $exceptionId = (int) ($_POST['exception_id'] ?? 0);
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);

        if ($exceptionId > 0 && $invoiceId > 0) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE matching_exceptions
                               SET status = "resolved",
                                   resolution_note = :resolution_note,
                                   resolved_by = :resolved_by,
                                   resolved_at = :resolved_at,
                                   updated_at = :updated_at
                               WHERE id = :id AND company_id = :company_id')
                    ->execute([
                        'resolution_note' => trim((string) ($_POST['resolution_note'] ?? 'resolved manually')),
                        'resolved_by' => $userId,
                        'resolved_at' => now_utc(),
                        'updated_at' => now_utc(),
                        'id' => $exceptionId,
                        'company_id' => $companyId,
                    ]);

                $invoiceMetaStmt = $pdo->prepare('SELECT total_amount, vendor_id FROM invoices WHERE id = :id AND company_id = :company_id LIMIT 1');
                $invoiceMetaStmt->execute(['id' => $invoiceId, 'company_id' => $companyId]);
                $invoiceMeta = $invoiceMetaStmt->fetch();

                if ($invoiceMeta) {
                    $pdo->prepare('UPDATE invoices SET status = "pending_approval", exception_reason = NULL, updated_at = :updated_at WHERE id = :id')
                        ->execute(['updated_at' => now_utc(), 'id' => $invoiceId]);

                    ApprovalEngine::createFlow(
                        $pdo,
                        $companyId,
                        'invoice',
                        $invoiceId,
                        (float) $invoiceMeta['total_amount'],
                        $userId,
                        null,
                        (int) $invoiceMeta['vendor_id']
                    );
                }

                AuditService::log($pdo, $companyId, $userId, 'invoice.exception.resolved', 'invoice', $invoiceId, ['exception_id' => $exceptionId]);
                $pdo->commit();
                flash_set('success', 'Exception resolved and invoice routed for approval.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('error', 'Failed to resolve exception: '.$e->getMessage());
            }
        }

        redirect_to(base_url($config, 'index.php?page='.$returnPage));
    }

    if ($action === 'rematch_invoice') {
        Auth::requirePermission($config, 'invoices.manage');

        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            flash_set('error', 'Invoice is required for rematching.');
            redirect_to(base_url($config, 'index.php?page=matching'));
        }

        $poId = (int) ($_POST['po_id'] ?? 0);
        $grnId = (int) ($_POST['grn_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE invoices
                           SET po_id = :po_id,
                               grn_id = :grn_id,
                               status = "captured",
                               exception_reason = NULL,
                               updated_at = :updated_at
                           WHERE id = :id AND company_id = :company_id')
                ->execute([
                    'po_id' => $poId > 0 ? $poId : null,
                    'grn_id' => $grnId > 0 ? $grnId : null,
                    'updated_at' => now_utc(),
                    'id' => $invoiceId,
                    'company_id' => $companyId,
                ]);

            $match = MatchingEngine::evaluateInvoice($pdo, $config, $companyId, $invoiceId, $userId);

            if (($match['status'] ?? '') === 'matched') {
                $invoiceMeta = $pdo->prepare('SELECT total_amount, vendor_id FROM invoices WHERE id = :id AND company_id = :company_id LIMIT 1');
                $invoiceMeta->execute(['id' => $invoiceId, 'company_id' => $companyId]);
                $meta = $invoiceMeta->fetch();
                if ($meta) {
                    $pdo->prepare('UPDATE invoices SET status = "pending_approval", updated_at = :updated_at WHERE id = :id')
                        ->execute([
                            'updated_at' => now_utc(),
                            'id' => $invoiceId,
                        ]);
                    ApprovalEngine::createFlow(
                        $pdo,
                        $companyId,
                        'invoice',
                        $invoiceId,
                        (float) $meta['total_amount'],
                        $userId,
                        null,
                        (int) $meta['vendor_id']
                    );
                }
            }

            AuditService::log($pdo, $companyId, $userId, 'invoice.rematched', 'invoice', $invoiceId, $match);
            $pdo->commit();
            flash_set('success', ($match['status'] ?? '') === 'matched' ? 'Invoice matched and routed for approval.' : 'Rematch attempted; invoice still in exception.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Rematch failed: '.$e->getMessage());
        }

        redirect_to(base_url($config, 'index.php?page=matching'));
    }

    if ($action === 'create_expense') {
        Auth::requirePermission($config, 'expenses.manage');

        $baseAmount = (float) ($_POST['amount'] ?? 0);
        $category = trim((string) ($_POST['category'] ?? ''));
        $distanceKmRaw = trim((string) ($_POST['distance_km'] ?? ''));
        $mileageRateRaw = trim((string) ($_POST['mileage_rate'] ?? ''));
        $distanceKm = $distanceKmRaw !== '' ? max(0, (float) $distanceKmRaw) : null;
        $mileageRate = $mileageRateRaw !== '' ? max(0, (float) $mileageRateRaw) : null;
        $mileageAmount = ($distanceKm !== null && $mileageRate !== null && $distanceKm > 0 && $mileageRate > 0)
            ? round($distanceKm * $mileageRate, 2)
            : null;
        $amount = round($baseAmount + (float) ($mileageAmount ?? 0), 2);
        $sourceChannel = strtolower(trim((string) ($_POST['source_channel'] ?? 'web')));
        $sourceRef = trim((string) ($_POST['source_ref'] ?? ''));

        if ($amount <= 0 || $category === '') {
            flash_set('error', 'Expense category and amount are required.');
            redirect_to(base_url($config, 'index.php?page=expenses'));
        }

        $pdo->beginTransaction();
        try {
            $uploadedFiles = isset($_FILES['expense_files']) ? normalize_uploaded_files((array) $_FILES['expense_files']) : [];
            $successfulUploads = 0;
            foreach ($uploadedFiles as $uploadedFile) {
                if ((int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $successfulUploads++;
                }
            }

            $manualProofCount = max(0, (int) ($_POST['proof_count'] ?? 0));
            $proofCount = max($manualProofCount, $successfulUploads);

            $insert = $pdo->prepare('INSERT INTO expense_claims
                (company_id, user_id, category, department_code, source_channel, description, expense_date, start_location, end_location, distance_km, mileage_rate, mileage_amount, amount, currency_code, proof_count, status, created_at, updated_at)
                VALUES
                (:company_id, :user_id, :category, :department_code, :source_channel, :description, :expense_date, :start_location, :end_location, :distance_km, :mileage_rate, :mileage_amount, :amount, :currency_code, :proof_count, :status, :created_at, :updated_at)');
            $insert->execute([
                'company_id' => $companyId,
                'user_id' => $userId,
                'category' => $category,
                'department_code' => trim((string) ($_POST['department_code'] ?? 'GEN')),
                'source_channel' => $sourceChannel,
                'description' => trim((string) ($_POST['description'] ?? '')),
                'expense_date' => (string) ($_POST['expense_date'] ?? today_utc()),
                'start_location' => trim((string) ($_POST['start_location'] ?? '')) ?: null,
                'end_location' => trim((string) ($_POST['end_location'] ?? '')) ?: null,
                'distance_km' => $distanceKm,
                'mileage_rate' => $mileageRate,
                'mileage_amount' => $mileageAmount,
                'amount' => $amount,
                'currency_code' => strtoupper((string) ($_POST['currency_code'] ?? $config['app']['currency'])),
                'proof_count' => $proofCount,
                'status' => 'submitted',
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);

            $claimId = (int) $pdo->lastInsertId();
            log_capture_event($pdo, $companyId, 'expense', $claimId, $sourceChannel, $sourceRef !== '' ? $sourceRef : null, [
                'category' => $category,
                'amount' => $amount,
                'mileage_amount' => $mileageAmount,
            ], $userId);

            foreach ($uploadedFiles as $uploadedFile) {
                if ((int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }

                $storedFile = store_uploaded_file($config, $uploadedFile, $companyId, 'expense');
                $documentId = persist_document_metadata($pdo, $companyId, 'expense', $claimId, $storedFile, $userId);

                $pdo->prepare('INSERT INTO expense_attachments (claim_id, document_id, created_at)
                               VALUES (:claim_id, :document_id, :created_at)')
                    ->execute([
                        'claim_id' => $claimId,
                        'document_id' => $documentId,
                        'created_at' => now_utc(),
                    ]);
            }

            $evaluation = ExpensePolicyEngine::evaluate($pdo, $companyId, $claimId);

            if ($evaluation['status'] !== 'policy_flagged') {
                ApprovalEngine::createFlow(
                    $pdo,
                    $companyId,
                    'expense',
                    $claimId,
                    $amount,
                    $userId,
                    trim((string) ($_POST['department_code'] ?? '')) ?: null,
                    null
                );
            }

            AuditService::log($pdo, $companyId, $userId, 'expense.created', 'expense', $claimId, $evaluation);
            $pdo->commit();

            if ($evaluation['status'] === 'policy_flagged') {
                flash_set('error', 'Expense submitted but flagged by policy checks.');
            } else {
                flash_set('success', 'Expense submitted for approval.');
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Expense submission failed: '.$e->getMessage());
        }

        redirect_to(base_url($config, 'index.php?page=expenses'));
    }

    if ($action === 'create_approval_policy_rule') {
        Auth::requirePermission($config, 'approvals.decide');

        $entityType = trim((string) ($_POST['entity_type'] ?? ''));
        $approverUserId = (int) ($_POST['approver_user_id'] ?? 0);
        $levelOrder = max(1, (int) ($_POST['level_order'] ?? 1));

        if ($entityType === '' || $approverUserId <= 0) {
            flash_set('error', 'Entity type and approver are required for policy rule.');
            redirect_to(base_url($config, 'index.php?page=approvals'));
        }

        $stmt = $pdo->prepare('INSERT INTO approval_policy_rules
            (company_id, entity_type, level_order, approver_user_id, min_amount, max_amount, department_code, vendor_id, sla_hours, reminder_channels_json, created_at, updated_at)
            VALUES
            (:company_id, :entity_type, :level_order, :approver_user_id, :min_amount, :max_amount, :department_code, :vendor_id, :sla_hours, :reminder_channels_json, :created_at, :updated_at)');
        $stmt->execute([
            'company_id' => $companyId,
            'entity_type' => $entityType,
            'level_order' => $levelOrder,
            'approver_user_id' => $approverUserId,
            'min_amount' => (float) ($_POST['min_amount'] ?? 0),
            'max_amount' => (float) ($_POST['max_amount'] ?? 9999999999.99),
            'department_code' => trim((string) ($_POST['department_code'] ?? '')) ?: null,
            'vendor_id' => (int) ($_POST['vendor_id'] ?? 0) ?: null,
            'sla_hours' => max(1, (int) ($_POST['sla_hours'] ?? 24)),
            'reminder_channels_json' => json_encode(array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['reminder_channels'] ?? 'email'))))), JSON_THROW_ON_ERROR),
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);

        AuditService::log($pdo, $companyId, $userId, 'approval.policy_rule.created', 'approval_policy_rule', (int) $pdo->lastInsertId(), [
            'entity_type' => $entityType,
            'level_order' => $levelOrder,
        ]);

        flash_set('success', 'Approval policy rule created.');
        redirect_to(base_url($config, 'index.php?page=approvals'));
    }

    if ($action === 'escalate_overdue_approvals') {
        Auth::requirePermission($config, 'approvals.decide');

        $pending = $pdo->prepare('SELECT a.id, a.entity_type, a.entity_id, a.approver_user_id
                                  FROM approvals a
                                  WHERE a.company_id = :company_id
                                    AND a.status = "pending"
                                    AND a.due_at IS NOT NULL
                                    AND a.due_at <= :now_at
                                    AND a.escalated_at IS NULL
                                  ORDER BY a.id ASC
                                  LIMIT 200');
        $pending->execute([
            'company_id' => $companyId,
            'now_at' => now_utc(),
        ]);
        $rows = $pending->fetchAll();

        $count = 0;
        foreach ($rows as $row) {
            $message = sprintf(
                'Approval #%d (%s #%d) is overdue and requires immediate action.',
                (int) $row['id'],
                (string) $row['entity_type'],
                (int) $row['entity_id']
            );

            $pdo->prepare('INSERT INTO notifications
                           (company_id, user_id, channel, subject, message_text, status, created_at, sent_at)
                           VALUES
                           (:company_id, :user_id, :channel, :subject, :message_text, :status, :created_at, :sent_at)')
                ->execute([
                    'company_id' => $companyId,
                    'user_id' => (int) $row['approver_user_id'],
                    'channel' => 'email',
                    'subject' => 'Approval Escalation',
                    'message_text' => $message,
                    'status' => 'queued',
                    'created_at' => now_utc(),
                    'sent_at' => null,
                ]);
            $notificationId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO integration_jobs
                           (company_id, provider, job_type, status, payload_json, attempts, run_at, created_at, updated_at)
                           VALUES
                           (:company_id, :provider, :job_type, :status, :payload_json, :attempts, :run_at, :created_at, :updated_at)')
                ->execute([
                    'company_id' => $companyId,
                    'provider' => 'messaging_stub',
                    'job_type' => 'notification.dispatch',
                    'status' => 'queued',
                    'payload_json' => json_encode([
                        'notification_id' => $notificationId,
                    ], JSON_THROW_ON_ERROR),
                    'attempts' => 0,
                    'run_at' => now_utc(),
                    'created_at' => now_utc(),
                    'updated_at' => now_utc(),
                ]);

            $pdo->prepare('UPDATE approvals
                           SET escalated_at = :escalated_at,
                               due_at = :next_due_at,
                               updated_at = :updated_at
                           WHERE id = :id AND company_id = :company_id')
                ->execute([
                    'escalated_at' => now_utc(),
                    'next_due_at' => gmdate('Y-m-d H:i:s', time() + (6 * 3600)),
                    'updated_at' => now_utc(),
                    'id' => (int) $row['id'],
                    'company_id' => $companyId,
                ]);
            $count++;
        }

        AuditService::log($pdo, $companyId, $userId, 'approval.escalation.run', 'approval', null, ['count' => $count]);
        flash_set('success', 'Escalation run queued reminders for '.$count.' overdue approvals.');
        redirect_to(base_url($config, 'index.php?page=approvals'));
    }

    if ($action === 'approve' || $action === 'reject') {
        Auth::requirePermission($config, 'approvals.decide');

        $approvalId = (int) ($_POST['approval_id'] ?? 0);
        $decision = $action === 'approve' ? 'approved' : 'rejected';

        if ($approvalId > 0) {
            $pdo->beginTransaction();
            try {
                $result = ApprovalEngine::decide(
                    $pdo,
                    $companyId,
                    $approvalId,
                    $userId,
                    $decision,
                    trim((string) ($_POST['decision_note'] ?? ''))
                );

                api_apply_final_decision_to_entity($pdo, $result);

                AuditService::log($pdo, $companyId, $userId, 'approval.'.$decision, 'approval', $approvalId, $result);
                $pdo->commit();
                flash_set('success', 'Approval '.$decision.'.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('error', 'Approval failed: '.$e->getMessage());
            }
        }

        redirect_to(base_url($config, 'index.php?page=approvals'));
    }

    if ($action === 'create_payment') {
        Auth::requirePermission($config, 'payments.manage');

        $sourceId = (int) ($_POST['source_id'] ?? 0);
        $payeeId = (int) ($_POST['payee_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $idempotencyKey = trim((string) ($_POST['idempotency_key'] ?? ''));
        $scheduledForRaw = trim((string) ($_POST['scheduled_for'] ?? ''));
        $scheduledFor = $scheduledForRaw !== '' ? str_replace('T', ' ', $scheduledForRaw).':00' : '';

        if ($sourceId <= 0 || $payeeId <= 0 || $amount <= 0) {
            flash_set('error', 'Source invoice, payee and amount are required.');
            redirect_to(base_url($config, 'index.php?page=payments'));
        }

        if ($idempotencyKey === '') {
            $idempotencyKey = bin2hex(random_bytes(12));
        }

        if (! api_register_idempotency($pdo, $companyId, 'payment.create', $idempotencyKey, $_POST)) {
            flash_set('error', 'Duplicate idempotency key.');
            redirect_to(base_url($config, 'index.php?page=payments'));
        }

        $taxCheck = $pdo->prepare('SELECT recommendation
                                   FROM tax_reconciliations
                                   WHERE company_id = :company_id AND invoice_id = :invoice_id
                                   ORDER BY id DESC
                                   LIMIT 1');
        $taxCheck->execute(['company_id' => $companyId, 'invoice_id' => $sourceId]);
        $recommendation = (string) ($taxCheck->fetchColumn() ?: 'release');
        $status = $recommendation === 'hold' ? 'blocked' : 'pending_approval';

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO payments
                (company_id, source_type, source_id, payee_type, payee_id, amount, currency_code, payment_mode, status, idempotency_key, maker_user_id, scheduled_for, created_at, updated_at)
                VALUES
                (:company_id, :source_type, :source_id, :payee_type, :payee_id, :amount, :currency_code, :payment_mode, :status, :idempotency_key, :maker_user_id, :scheduled_for, :created_at, :updated_at)');
            $insert->execute([
                'company_id' => $companyId,
                'source_type' => 'invoice',
                'source_id' => $sourceId,
                'payee_type' => 'vendor',
                'payee_id' => $payeeId,
                'amount' => $amount,
                'currency_code' => strtoupper((string) ($_POST['currency_code'] ?? $config['app']['currency'])),
                'payment_mode' => strtoupper((string) ($_POST['payment_mode'] ?? 'NEFT')),
                'status' => $status,
                'idempotency_key' => $idempotencyKey,
                'maker_user_id' => $userId,
                'scheduled_for' => $scheduledFor !== '' ? $scheduledFor : null,
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);

            $paymentId = (int) $pdo->lastInsertId();

            if ($status === 'pending_approval') {
                ApprovalEngine::createFlow($pdo, $companyId, 'payment', $paymentId, $amount, $userId, null, null);
            }

            AuditService::log($pdo, $companyId, $userId, 'payment.created', 'payment', $paymentId, ['status' => $status]);
            $pdo->commit();

            if ($status === 'blocked') {
                flash_set('error', 'Payment blocked by tax reconciliation hold decision.');
            } else {
                flash_set('success', 'Payment created and routed for approval.');
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Payment creation failed: '.$e->getMessage());
        }

        redirect_to(base_url($config, 'index.php?page=payments'));
    }

    if ($action === 'create_payment_batch') {
        Auth::requirePermission($config, 'payments.manage');
        $returnPage = trim((string) ($_POST['redirect_page'] ?? 'payments'));
        if (! in_array($returnPage, ['payments', 'bulk-payout'], true)) {
            $returnPage = 'payments';
        }

        $invoiceIds = $_POST['invoice_ids'] ?? [];
        if (! is_array($invoiceIds)) {
            $invoiceIds = [];
        }

        $paymentMode = strtoupper(trim((string) ($_POST['payment_mode'] ?? 'NEFT')));
        $scheduledForRaw = trim((string) ($_POST['scheduled_for'] ?? ''));
        $scheduledFor = $scheduledForRaw !== '' ? str_replace('T', ' ', $scheduledForRaw).':00' : '';
        $currencyCode = strtoupper(trim((string) ($_POST['currency_code'] ?? $config['app']['currency'])));

        $pdo->beginTransaction();
        try {
            $batch = PaymentBatchEngine::createFromApprovedInvoices(
                $pdo,
                $companyId,
                $userId,
                $invoiceIds,
                $paymentMode,
                $currencyCode,
                $scheduledFor !== '' ? $scheduledFor : null
            );
            AuditService::log($pdo, $companyId, $userId, 'payment.batch.created', 'payment_batch', (int) $batch['batch_id'], $batch);
            $pdo->commit();
            flash_set('success', sprintf('Payment batch %s created with %d items.', (string) $batch['batch_code'], (int) $batch['total_items']));
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Batch creation failed: '.$e->getMessage());
        }

        redirect_to(base_url($config, 'index.php?page='.$returnPage));
    }

    if ($action === 'dispatch_payment_batch') {
        Auth::requirePermission($config, 'payments.manage');
        $returnPage = trim((string) ($_POST['redirect_page'] ?? 'payments'));
        if (! in_array($returnPage, ['payments', 'bulk-payout'], true)) {
            $returnPage = 'payments';
        }

        $batchId = (int) ($_POST['batch_id'] ?? 0);
        if ($batchId <= 0) {
            flash_set('error', 'Batch ID is required.');
            redirect_to(base_url($config, 'index.php?page='.$returnPage));
        }

        try {
            $result = PaymentBatchEngine::queueApprovedPayments($pdo, $companyId, $batchId, $userId);
            AuditService::log($pdo, $companyId, $userId, 'payment.batch.dispatched', 'payment_batch', $batchId, $result);
            flash_set('success', 'Dispatched '.$result['queued_jobs'].' approved payments from batch #'.$batchId.'.');
        } catch (Throwable $e) {
            flash_set('error', 'Batch dispatch failed: '.$e->getMessage());
        }

        redirect_to(base_url($config, 'index.php?page='.$returnPage));
    }

    if ($action === 'execute_payment') {
        Auth::requirePermission($config, 'payments.manage');

        $paymentId = (int) ($_POST['payment_id'] ?? 0);
        if ($paymentId > 0) {
            try {
                $result = PaymentEngine::execute($pdo, $companyId, $paymentId, $userId);
                AuditService::log($pdo, $companyId, $userId, 'payment.executed', 'payment', $paymentId, $result);
                flash_set('success', 'Payment completed. UTR: '.$result['utr_reference']);
            } catch (Throwable $e) {
                flash_set('error', 'Payment execution failed: '.$e->getMessage());
            }
        }

        redirect_to(base_url($config, 'index.php?page=payments'));
    }

    if ($action === 'inbox_bulk_action') {
        Auth::requireLogin($config);

        $bulkAction = trim((string) ($_POST['bulk_action'] ?? ''));
        $selectedApprovals = isset($_POST['selected_approvals']) && is_array($_POST['selected_approvals']) ? array_map('intval', $_POST['selected_approvals']) : [];
        $selectedExceptions = isset($_POST['selected_exceptions']) && is_array($_POST['selected_exceptions']) ? array_map('intval', $_POST['selected_exceptions']) : [];
        $selectedPayments = isset($_POST['selected_payments']) && is_array($_POST['selected_payments']) ? array_map('intval', $_POST['selected_payments']) : [];
        $selectedTaxRuns = isset($_POST['selected_tax_runs']) && is_array($_POST['selected_tax_runs']) ? array_map('intval', $_POST['selected_tax_runs']) : [];

        $count = 0;
        $pdo->beginTransaction();
        try {
            if ($bulkAction === 'approve' || $bulkAction === 'reject') {
                Auth::requirePermission($config, 'approvals.decide');
                foreach ($selectedApprovals as $approvalId) {
                    if ($approvalId <= 0) {
                        continue;
                    }
                    try {
                        $result = ApprovalEngine::decide(
                            $pdo,
                            $companyId,
                            $approvalId,
                            $userId,
                            $bulkAction === 'approve' ? 'approved' : 'rejected',
                            'Bulk action from operations inbox'
                        );
                        api_apply_final_decision_to_entity($pdo, $result);
                        $count++;
                    } catch (Throwable $ignored) {
                        continue;
                    }
                }
            } elseif ($bulkAction === 'resolve_exceptions') {
                Auth::requirePermission($config, 'invoices.manage');
                $invoiceMetaStmt = $pdo->prepare('SELECT total_amount, vendor_id FROM invoices WHERE id = :id AND company_id = :company_id LIMIT 1');
                foreach ($selectedExceptions as $exceptionId) {
                    if ($exceptionId <= 0) {
                        continue;
                    }

                    $exceptionStmt = $pdo->prepare('SELECT invoice_id FROM matching_exceptions WHERE id = :id AND company_id = :company_id AND status = "open" LIMIT 1');
                    $exceptionStmt->execute(['id' => $exceptionId, 'company_id' => $companyId]);
                    $invoiceId = (int) ($exceptionStmt->fetchColumn() ?: 0);
                    if ($invoiceId <= 0) {
                        continue;
                    }

                    $pdo->prepare('UPDATE matching_exceptions
                                   SET status = "resolved",
                                       resolution_note = :note,
                                       resolved_by = :resolved_by,
                                       resolved_at = :resolved_at,
                                       updated_at = :updated_at
                                   WHERE id = :id AND company_id = :company_id')
                        ->execute([
                            'note' => 'Bulk-resolved from operations inbox',
                            'resolved_by' => $userId,
                            'resolved_at' => now_utc(),
                            'updated_at' => now_utc(),
                            'id' => $exceptionId,
                            'company_id' => $companyId,
                        ]);

                    $invoiceMetaStmt->execute(['id' => $invoiceId, 'company_id' => $companyId]);
                    $invoiceMeta = $invoiceMetaStmt->fetch();
                    if ($invoiceMeta) {
                        $pdo->prepare('UPDATE invoices SET status = "pending_approval", exception_reason = NULL, updated_at = :updated_at WHERE id = :id')
                            ->execute(['updated_at' => now_utc(), 'id' => $invoiceId]);
                        ApprovalEngine::createFlow(
                            $pdo,
                            $companyId,
                            'invoice',
                            $invoiceId,
                            (float) $invoiceMeta['total_amount'],
                            $userId,
                            null,
                            (int) $invoiceMeta['vendor_id']
                        );
                    }
                    $count++;
                }
            } elseif ($bulkAction === 'dispatch_payments') {
                Auth::requirePermission($config, 'payments.manage');
                foreach ($selectedPayments as $paymentId) {
                    if ($paymentId <= 0) {
                        continue;
                    }
                    $paymentStmt = $pdo->prepare('SELECT id FROM payments WHERE id = :id AND company_id = :company_id AND status = "approved" LIMIT 1');
                    $paymentStmt->execute(['id' => $paymentId, 'company_id' => $companyId]);
                    if (! $paymentStmt->fetch()) {
                        continue;
                    }

                    $pdo->prepare('INSERT INTO integration_jobs
                                   (company_id, provider, job_type, status, payload_json, attempts, run_at, created_at, updated_at)
                                   VALUES
                                   (:company_id, :provider, :job_type, :status, :payload_json, :attempts, :run_at, :created_at, :updated_at)')
                        ->execute([
                            'company_id' => $companyId,
                            'provider' => 'bank_stub',
                            'job_type' => 'payment.dispatch',
                            'status' => 'queued',
                            'payload_json' => json_encode(['payment_id' => $paymentId], JSON_THROW_ON_ERROR),
                            'attempts' => 0,
                            'run_at' => now_utc(),
                            'created_at' => now_utc(),
                            'updated_at' => now_utc(),
                        ]);
                    $count++;
                }
            } elseif ($bulkAction === 'release_tax_holds') {
                Auth::requirePermission($config, 'tax.manage');
                foreach ($selectedTaxRuns as $taxId) {
                    if ($taxId <= 0) {
                        continue;
                    }
                    $update = $pdo->prepare('UPDATE tax_reconciliations
                                             SET recommendation = "release",
                                                 hold_reason = NULL,
                                                 decision_status = "release"
                                             WHERE id = :id AND company_id = :company_id');
                    $update->execute(['id' => $taxId, 'company_id' => $companyId]);
                    if ((int) $update->rowCount() > 0) {
                        $count++;
                    }
                }
            }

            AuditService::log($pdo, $companyId, $userId, 'inbox.bulk_action', 'inbox', null, [
                'action' => $bulkAction,
                'count' => $count,
            ]);
            $pdo->commit();
            flash_set('success', 'Bulk action applied to '.$count.' records.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Bulk action failed: '.$e->getMessage());
        }

        redirect_to(base_url($config, 'index.php?page=inbox'));
    }

    if ($action === 'run_tax_reconciliation') {
        Auth::requirePermission($config, 'tax.manage');

        try {
            $count = TaxReconciliationEngine::run($pdo, $companyId, $userId);
            AuditService::log($pdo, $companyId, $userId, 'tax.reconciliation.run', 'tax_reconciliation', null, ['count' => $count]);
            flash_set('success', 'Reconciliation run completed for '.$count.' invoices.');
        } catch (Throwable $e) {
            flash_set('error', 'Tax run failed: '.$e->getMessage());
        }

        redirect_to(base_url($config, 'index.php?page=tax'));
    }

    if ($action === 'queue_notification') {
        Auth::requirePermission($config, 'notifications.manage');

        $channel = trim((string) ($_POST['channel'] ?? 'email'));
        $subject = trim((string) ($_POST['subject'] ?? 'Notification'));
        $message = trim((string) ($_POST['message_text'] ?? ''));
        if ($message === '') {
            flash_set('error', 'Notification message is required.');
            redirect_to(base_url($config, 'index.php?page=notifications'));
        }

        $stmt = $pdo->prepare('INSERT INTO notifications
            (company_id, user_id, channel, subject, message_text, status, created_at, sent_at)
            VALUES
            (:company_id, :user_id, :channel, :subject, :message_text, :status, :created_at, :sent_at)');
        $stmt->execute([
            'company_id' => $companyId,
            'user_id' => (int) ($_POST['user_id'] ?? $userId),
            'channel' => $channel,
            'subject' => $subject,
            'message_text' => $message,
            'status' => 'queued',
            'created_at' => now_utc(),
            'sent_at' => null,
        ]);
        $notificationId = (int) $pdo->lastInsertId();

        $job = $pdo->prepare('INSERT INTO integration_jobs
            (company_id, provider, job_type, status, payload_json, attempts, run_at, created_at, updated_at)
            VALUES
            (:company_id, :provider, :job_type, :status, :payload_json, :attempts, :run_at, :created_at, :updated_at)');
        $job->execute([
            'company_id' => $companyId,
            'provider' => 'messaging_stub',
            'job_type' => 'notification.dispatch',
            'status' => 'queued',
            'payload_json' => json_encode([
                'notification_id' => $notificationId,
                'channel' => $channel,
                'subject' => $subject,
                'message_text' => $message,
            ], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'run_at' => now_utc(),
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);

        AuditService::log($pdo, $companyId, $userId, 'notification.queued', 'notification', $notificationId, ['channel' => $channel]);
        flash_set('success', 'Notification queued.');
        redirect_to(base_url($config, 'index.php?page=notifications'));
    }

    if ($action === 'run_integration_worker') {
        Auth::requirePermission($config, 'integrations.manage');

        $limit = max(1, min(200, (int) ($_POST['limit'] ?? 20)));
        $summary = IntegrationJobWorker::runDueJobs($pdo, $config, $companyId, $userId, $limit);

        flash_set(
            'success',
            sprintf(
                'Worker run: picked %d, completed %d, retrying %d, dead-letter %d.',
                (int) $summary['picked'],
                (int) $summary['completed'],
                (int) $summary['retrying'],
                (int) $summary['dead_letter']
            )
        );

        redirect_to(base_url($config, 'index.php?page=integrations'));
    }

    if ($action === 'link_all_integrations') {
        Auth::requirePermission($config, 'integrations.manage');

        $stmt = $pdo->prepare('UPDATE company_integrations
                               SET status = "active",
                                   connected_at = COALESCE(connected_at, :connected_at),
                                   updated_at = :updated_at
                               WHERE company_id = :company_id');
        $stmt->execute([
            'connected_at' => now_utc(),
            'updated_at' => now_utc(),
            'company_id' => $companyId,
        ]);

        AuditService::log($pdo, $companyId, $userId, 'integration.connection.bulk_linked', 'integration', null, [
            'updated_rows' => (int) $stmt->rowCount(),
        ]);

        flash_set('success', 'All available apps are now linked for this company.');
        redirect_to(base_url($config, 'index.php?page=integrations'));
    }

    if ($action === 'link_all_integrations_org') {
        Auth::requirePermission($config, 'organizations.manage');

        $organizationId = (int) (Auth::user()['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            flash_set('error', 'Organization context is required for org-level linking.');
            redirect_to(base_url($config, 'index.php?page=integrations'));
        }

        $companyCount = web_sync_org_integrations($pdo, $organizationId);
        AuditService::log($pdo, $companyId, $userId, 'integration.connection.org_bulk_linked', 'organization', $organizationId, [
            'organization_id' => $organizationId,
            'company_count' => $companyCount,
        ]);

        flash_set('success', 'All available apps are now linked for every company in your organization.');
        redirect_to(base_url($config, 'index.php?page=integrations'));
    }

    if ($action === 'queue_mail_inbox_pull') {
        Auth::requirePermission($config, 'integrations.manage');

        $limit = max(1, min(50, (int) ($_POST['limit'] ?? 10)));
        $vendorId = max(0, (int) ($_POST['vendor_id'] ?? 0));
        $defaultAmount = max(1.0, (float) ($_POST['default_total_amount'] ?? 100.0));
        $markAsSeen = ((int) ($_POST['mark_as_seen'] ?? 1)) === 1 ? 1 : 0;
        $moveToFolder = trim((string) ($_POST['move_to_folder'] ?? ''));

        $payload = [
            'company_id' => $companyId,
            'limit' => $limit,
            'vendor_id' => $vendorId > 0 ? $vendorId : null,
            'default_total_amount' => $defaultAmount,
            'mark_as_seen' => $markAsSeen,
            'move_to_folder' => $moveToFolder !== '' ? $moveToFolder : null,
        ];

        $pdo->prepare('INSERT INTO integration_jobs
            (company_id, provider, job_type, status, payload_json, attempts, run_at, created_at, updated_at)
            VALUES
            (:company_id, :provider, :job_type, :status, :payload_json, :attempts, :run_at, :created_at, :updated_at)')
            ->execute([
                'company_id' => $companyId,
                'provider' => 'mail_inbound',
                'job_type' => 'mail.inbox.pull',
                'status' => 'queued',
                'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
                'attempts' => 0,
                'run_at' => now_utc(),
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);

        $summary = IntegrationJobWorker::runDueJobs($pdo, $config, $companyId, $userId, 10);
        flash_set(
            'success',
            sprintf(
                'Mail inbox pull queued. Worker completed %d, retrying %d, dead-letter %d.',
                (int) $summary['completed'],
                (int) $summary['retrying'],
                (int) $summary['dead_letter']
            )
        );
        redirect_to(base_url($config, 'index.php?page=integrations'));
    }

    if ($action === 'start_integration_oauth') {
        Auth::requirePermission($config, 'integrations.manage');

        $providerKey = trim((string) ($_POST['provider_key'] ?? ''));
        if ($providerKey === '' || ! IntegrationOAuth::isSupportedProvider($providerKey)) {
            flash_set('error', 'OAuth connect is not available for this provider.');
            redirect_to(base_url($config, 'index.php?page=integrations'));
        }

        web_sync_company_integrations($pdo, $companyId);

        $fetch = $pdo->prepare('SELECT id, provider_name, connection_meta_json
                                FROM company_integrations
                                WHERE company_id = :company_id
                                  AND provider_key = :provider_key
                                LIMIT 1');
        $fetch->execute([
            'company_id' => $companyId,
            'provider_key' => $providerKey,
        ]);
        $row = $fetch->fetch();
        if (! $row) {
            flash_set('error', 'Integration record not found for this company.');
            redirect_to(base_url($config, 'index.php?page=integrations'));
        }

        $meta = json_decode((string) ($row['connection_meta_json'] ?? '{}'), true);
        if (! is_array($meta)) {
            $meta = [];
        }
        $runtimeEnv = IntegrationRuntimeConfig::readEnvFromMeta($meta);

        $state = bin2hex(random_bytes(24));
        if (! isset($_SESSION['integration_oauth_states']) || ! is_array($_SESSION['integration_oauth_states'])) {
            $_SESSION['integration_oauth_states'] = [];
        }
        $_SESSION['integration_oauth_states'][$state] = [
            'provider_key' => $providerKey,
            'company_id' => $companyId,
            'user_id' => $userId,
            'created_at' => time(),
        ];

        try {
            $authPayload = IntegrationOAuth::buildAuthorizationUrl($providerKey, $config, $runtimeEnv, $state);
            $meta['oauth'] = [
                'connect_pending' => true,
                'provider_key' => $providerKey,
                'last_connect_attempt_at' => now_utc(),
                'last_connect_attempt_by' => $userId,
                'last_error' => null,
            ];

            $pdo->prepare('UPDATE company_integrations
                           SET connection_meta_json = :connection_meta_json,
                               updated_at = :updated_at
                           WHERE id = :id')
                ->execute([
                    'connection_meta_json' => json_encode($meta, JSON_THROW_ON_ERROR),
                    'updated_at' => now_utc(),
                    'id' => (int) $row['id'],
                ]);

            AuditService::log($pdo, $companyId, $userId, 'integration.oauth.connect_started', 'integration', (int) $row['id'], [
                'provider_key' => $providerKey,
                'redirect_uri' => (string) ($authPayload['redirect_uri'] ?? ''),
            ]);

            redirect_to((string) $authPayload['url']);
        } catch (Throwable $e) {
            unset($_SESSION['integration_oauth_states'][$state]);

            $meta['oauth'] = [
                'connect_pending' => false,
                'provider_key' => $providerKey,
                'last_connect_attempt_at' => now_utc(),
                'last_connect_attempt_by' => $userId,
                'last_error' => mb_substr($e->getMessage(), 0, 500),
            ];

            $pdo->prepare('UPDATE company_integrations
                           SET connection_meta_json = :connection_meta_json,
                               updated_at = :updated_at
                           WHERE id = :id')
                ->execute([
                    'connection_meta_json' => json_encode($meta, JSON_THROW_ON_ERROR),
                    'updated_at' => now_utc(),
                    'id' => (int) $row['id'],
                ]);

            AuditService::log($pdo, $companyId, $userId, 'integration.oauth.connect_start_failed', 'integration', (int) $row['id'], [
                'provider_key' => $providerKey,
                'error' => $e->getMessage(),
            ]);

            flash_set('error', ($row['provider_name'] ?? $providerKey).' needs one-time admin setup before account connection.');
            redirect_to(base_url($config, 'index.php?page=integrations&provider='.$providerKey.'#provider-setup'));
        }
    }

    if ($action === 'save_integration_credentials') {
        Auth::requirePermission($config, 'integrations.manage');

        $providerKey = trim((string) ($_POST['provider_key'] ?? ''));
        $catalogMap = [];
        foreach (web_integration_catalog() as $provider) {
            $catalogMap[(string) $provider['provider_key']] = $provider;
        }

        if ($providerKey === '' || ! isset($catalogMap[$providerKey])) {
            flash_set('error', 'Unknown integration provider.');
            redirect_to(base_url($config, 'index.php?page=integrations'));
        }

        $fetch = $pdo->prepare('SELECT id, connection_meta_json
                                FROM company_integrations
                                WHERE company_id = :company_id
                                  AND provider_key = :provider_key
                                LIMIT 1');
        $fetch->execute([
            'company_id' => $companyId,
            'provider_key' => $providerKey,
        ]);
        $row = $fetch->fetch();

        if (! $row) {
            flash_set('error', 'Integration record not found for this company.');
            redirect_to(base_url($config, 'index.php?page=integrations'));
        }

        $meta = json_decode((string) ($row['connection_meta_json'] ?? '{}'), true);
        if (! is_array($meta)) {
            $meta = [];
        }

        $existing = IntegrationRuntimeConfig::readEnvFromMeta($meta);
        $fields = web_integration_config_fields($providerKey);
        $postedConfig = $_POST['cfg'] ?? [];
        if (! is_array($postedConfig)) {
            $postedConfig = [];
        }

        $nextEnv = $existing;
        foreach ($fields as $field) {
            $key = trim((string) ($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $isSensitive = (bool) ($field['sensitive'] ?? false);
            $defaultValue = isset($field['default']) ? trim((string) $field['default']) : '';
            $incoming = trim((string) ($postedConfig[$key] ?? ''));

            if ($incoming === '') {
                if ($isSensitive && isset($existing[$key]) && trim((string) $existing[$key]) !== '') {
                    continue;
                }

                if (! $isSensitive && $defaultValue !== '' && (! isset($existing[$key]) || trim((string) $existing[$key]) === '')) {
                    $nextEnv[$key] = $defaultValue;
                    continue;
                }

                $nextEnv[$key] = '';
                continue;
            }

            $nextEnv[$key] = $incoming;
        }

        $meta = IntegrationRuntimeConfig::writeEnvToMeta($meta, $nextEnv);
        $meta['config_updated_at'] = now_utc();
        $meta['config_updated_by'] = $userId;

        $pdo->prepare('UPDATE company_integrations
                       SET connection_meta_json = :connection_meta_json,
                           updated_at = :updated_at
                       WHERE id = :id')
            ->execute([
                'connection_meta_json' => json_encode($meta, JSON_THROW_ON_ERROR),
                'updated_at' => now_utc(),
                'id' => (int) $row['id'],
            ]);

        web_apply_runtime_env_for_company($pdo, $companyId, false, $providerKey);

        $savedCount = 0;
        foreach ($nextEnv as $value) {
            if (trim((string) $value) !== '') {
                $savedCount++;
            }
        }

        AuditService::log($pdo, $companyId, $userId, 'integration.config.saved', 'integration', (int) $row['id'], [
            'provider_key' => $providerKey,
            'saved_keys' => $meta['configured_keys'] ?? [],
            'saved_count' => $savedCount,
        ]);

        flash_set('success', ($catalogMap[$providerKey]['provider_name'] ?? $providerKey).' credentials saved in app vault.');
        redirect_to(base_url($config, 'index.php?page=integrations'));
    }

    if ($action === 'test_integration_connection') {
        Auth::requirePermission($config, 'integrations.manage');

        $providerKey = trim((string) ($_POST['provider_key'] ?? ''));
        $testRecipient = trim((string) ($_POST['test_recipient'] ?? ''));

        $catalogMap = [];
        foreach (web_integration_catalog() as $provider) {
            $catalogMap[(string) $provider['provider_key']] = $provider;
        }

        if ($providerKey === '' || ! isset($catalogMap[$providerKey])) {
            flash_set('error', 'Unknown integration provider.');
            redirect_to(base_url($config, 'index.php?page=integrations'));
        }

        $fetch = $pdo->prepare('SELECT id, connection_meta_json
                                FROM company_integrations
                                WHERE company_id = :company_id
                                  AND provider_key = :provider_key
                                LIMIT 1');
        $fetch->execute([
            'company_id' => $companyId,
            'provider_key' => $providerKey,
        ]);
        $row = $fetch->fetch();

        if (! $row) {
            flash_set('error', 'Integration record not found for this company.');
            redirect_to(base_url($config, 'index.php?page=integrations'));
        }

        $meta = json_decode((string) ($row['connection_meta_json'] ?? '{}'), true);
        if (! is_array($meta)) {
            $meta = [];
        }

        try {
            $result = web_run_integration_test($pdo, $companyId, $providerKey, $testRecipient);
            $providerName = (string) ($catalogMap[$providerKey]['provider_name'] ?? $providerKey);
            $runtime = web_integration_runtime_state($providerKey);

            $meta['last_test_status'] = 'passed';
            $meta['last_tested_at'] = now_utc();
            $meta['last_test_by'] = $userId;
            $meta['last_test_summary'] = mb_substr(json_encode($result, JSON_THROW_ON_ERROR), 0, 1000);

            $pdo->prepare('UPDATE company_integrations
                           SET connection_meta_json = :connection_meta_json,
                               updated_at = :updated_at
                           WHERE id = :id')
                ->execute([
                    'connection_meta_json' => json_encode($meta, JSON_THROW_ON_ERROR),
                    'updated_at' => now_utc(),
                    'id' => (int) $row['id'],
                ]);

            AuditService::log($pdo, $companyId, $userId, 'integration.connection.tested', 'integration', (int) $row['id'], [
                'provider_key' => $providerKey,
                'runtime_mode' => $runtime['mode'],
                'result' => $result,
            ]);

            flash_set('success', $providerName.' test completed ('.$runtime['label'].').');
        } catch (Throwable $e) {
            $meta['last_test_status'] = 'failed';
            $meta['last_tested_at'] = now_utc();
            $meta['last_test_by'] = $userId;
            $meta['last_test_summary'] = mb_substr($e->getMessage(), 0, 1000);

            $pdo->prepare('UPDATE company_integrations
                           SET connection_meta_json = :connection_meta_json,
                               updated_at = :updated_at
                           WHERE id = :id')
                ->execute([
                    'connection_meta_json' => json_encode($meta, JSON_THROW_ON_ERROR),
                    'updated_at' => now_utc(),
                    'id' => (int) $row['id'],
                ]);

            AuditService::log($pdo, $companyId, $userId, 'integration.connection.test_failed', 'integration', (int) $row['id'], [
                'provider_key' => $providerKey,
                'error' => $e->getMessage(),
            ]);

            flash_set('error', ($catalogMap[$providerKey]['provider_name'] ?? $providerKey).' test failed: '.$e->getMessage());
        }

        redirect_to(base_url($config, 'index.php?page=integrations'));
    }

    if ($action === 'toggle_integration_connection') {
        Auth::requirePermission($config, 'integrations.manage');

        $providerKey = trim((string) ($_POST['provider_key'] ?? ''));
        $desiredStatus = trim((string) ($_POST['desired_status'] ?? 'active'));
        $desiredStatus = in_array($desiredStatus, ['active', 'disabled'], true) ? $desiredStatus : 'disabled';

        $catalogMap = [];
        foreach (web_integration_catalog() as $provider) {
            $catalogMap[(string) $provider['provider_key']] = $provider;
        }

        if ($providerKey === '' || ! isset($catalogMap[$providerKey])) {
            flash_set('error', 'Unknown integration provider.');
            redirect_to(base_url($config, 'index.php?page=integrations'));
        }

        $fetch = $pdo->prepare('SELECT id, connection_meta_json
                                FROM company_integrations
                                WHERE company_id = :company_id
                                  AND provider_key = :provider_key
                                LIMIT 1');
        $fetch->execute([
            'company_id' => $companyId,
            'provider_key' => $providerKey,
        ]);
        $row = $fetch->fetch();

        if (! $row) {
            flash_set('error', 'Integration record not found for this company.');
            redirect_to(base_url($config, 'index.php?page=integrations'));
        }

        $meta = json_decode((string) ($row['connection_meta_json'] ?? '{}'), true);
        if (! is_array($meta)) {
            $meta = [];
        }
        $meta['last_action_by'] = $userId;
        $meta['last_action_at'] = now_utc();
        $meta['last_action'] = $desiredStatus === 'active' ? 'connected' : 'disabled';

        $update = $pdo->prepare('UPDATE company_integrations
                                 SET status = :status,
                                     connected_at = :connected_at,
                                     connection_meta_json = :connection_meta_json,
                                     updated_at = :updated_at
                                 WHERE id = :id');
        $update->execute([
            'status' => $desiredStatus,
            'connected_at' => $desiredStatus === 'active' ? now_utc() : null,
            'connection_meta_json' => json_encode($meta, JSON_THROW_ON_ERROR),
            'updated_at' => now_utc(),
            'id' => (int) $row['id'],
        ]);

        AuditService::log($pdo, $companyId, $userId, 'integration.connection.'.$desiredStatus, 'integration', (int) $row['id'], [
            'provider_key' => $providerKey,
        ]);

        flash_set('success', $catalogMap[$providerKey]['provider_name'].' is now '.($desiredStatus === 'active' ? 'active' : 'disabled').'.');
        redirect_to(base_url($config, 'index.php?page=integrations'));
    }

    if ($action === 'create_corporate_card') {
        Auth::requirePermission($config, 'payments.manage');

        $cardName = trim((string) ($_POST['card_name'] ?? ''));
        $cardType = trim((string) ($_POST['card_type'] ?? 'virtual'));
        $assignedUserId = (int) ($_POST['assigned_user_id'] ?? 0);
        $spendingLimit = round((float) ($_POST['spending_limit'] ?? 0), 2);
        $mccControls = trim((string) ($_POST['mcc_controls'] ?? ''));
        $receiptRequired = (int) ($_POST['receipt_required'] ?? 1) === 1 ? 1 : 0;

        if ($cardName === '' || $spendingLimit <= 0) {
            flash_set('error', 'Card name and spending limit are required.');
            redirect_to(base_url($config, 'index.php?page=cards'));
        }

        if (! in_array($cardType, ['virtual', 'physical'], true)) {
            $cardType = 'virtual';
        }

        $assignedUserIdOrNull = $assignedUserId > 0 ? $assignedUserId : null;
        if ($assignedUserIdOrNull !== null) {
            $member = $pdo->prepare('SELECT id
                                     FROM company_user
                                     WHERE company_id = :company_id
                                       AND user_id = :user_id
                                       AND status = "active"
                                     LIMIT 1');
            $member->execute([
                'company_id' => $companyId,
                'user_id' => $assignedUserIdOrNull,
            ]);
            if (! $member->fetch()) {
                flash_set('error', 'Assigned user is not active in this company.');
                redirect_to(base_url($config, 'index.php?page=cards'));
            }
        }

        $mccList = array_values(array_filter(array_map(
            static fn (string $value): string => strtoupper(trim($value)),
            explode(',', $mccControls)
        )));

        $insert = $pdo->prepare('INSERT INTO corporate_cards
            (company_id, card_name, card_type, assigned_user_id, last4, spending_limit, mcc_controls_json, receipt_required, status, created_by, created_at, updated_at)
            VALUES
            (:company_id, :card_name, :card_type, :assigned_user_id, :last4, :spending_limit, :mcc_controls_json, :receipt_required, :status, :created_by, :created_at, :updated_at)');
        $insert->execute([
            'company_id' => $companyId,
            'card_name' => $cardName,
            'card_type' => $cardType,
            'assigned_user_id' => $assignedUserIdOrNull,
            'last4' => str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
            'spending_limit' => $spendingLimit,
            'mcc_controls_json' => json_encode($mccList, JSON_THROW_ON_ERROR),
            'receipt_required' => $receiptRequired,
            'status' => 'active',
            'created_by' => $userId,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);

        $cardId = (int) $pdo->lastInsertId();
        AuditService::log($pdo, $companyId, $userId, 'cards.issued', 'card', $cardId, [
            'card_type' => $cardType,
            'assigned_user_id' => $assignedUserIdOrNull,
            'spending_limit' => $spendingLimit,
        ]);

        flash_set('success', 'Corporate card issued.');
        redirect_to(base_url($config, 'index.php?page=cards'));
    }

    if ($action === 'save_credit_line') {
        Auth::requirePermission($config, 'payments.manage');

        $providerName = trim((string) ($_POST['provider_name'] ?? 'Working Capital Partner'));
        $sanctionedLimit = round((float) ($_POST['sanctioned_limit'] ?? 0), 2);
        $utilizedAmount = round((float) ($_POST['utilized_amount'] ?? 0), 2);
        $interestRate = round((float) ($_POST['interest_rate_apr'] ?? 0), 2);
        $status = trim((string) ($_POST['status'] ?? 'active'));
        $status = in_array($status, ['active', 'paused', 'closed'], true) ? $status : 'active';

        if ($sanctionedLimit <= 0 || $utilizedAmount < 0 || $utilizedAmount > $sanctionedLimit) {
            flash_set('error', 'Credit line amounts are invalid.');
            redirect_to(base_url($config, 'index.php?page=credit-line'));
        }

        $availableAmount = round($sanctionedLimit - $utilizedAmount, 2);

        $exists = $pdo->prepare('SELECT id
                                 FROM credit_lines
                                 WHERE company_id = :company_id
                                 LIMIT 1');
        $exists->execute(['company_id' => $companyId]);
        $lineId = $exists->fetchColumn();

        if ($lineId !== false) {
            $update = $pdo->prepare('UPDATE credit_lines
                                     SET provider_name = :provider_name,
                                         sanctioned_limit = :sanctioned_limit,
                                         utilized_amount = :utilized_amount,
                                         available_amount = :available_amount,
                                         interest_rate_apr = :interest_rate_apr,
                                         status = :status,
                                         updated_at = :updated_at
                                     WHERE id = :id');
            $update->execute([
                'provider_name' => $providerName,
                'sanctioned_limit' => $sanctionedLimit,
                'utilized_amount' => $utilizedAmount,
                'available_amount' => $availableAmount,
                'interest_rate_apr' => $interestRate,
                'status' => $status,
                'updated_at' => now_utc(),
                'id' => (int) $lineId,
            ]);
        } else {
            $insert = $pdo->prepare('INSERT INTO credit_lines
                (company_id, provider_name, sanctioned_limit, utilized_amount, available_amount, interest_rate_apr, status, created_at, updated_at)
                VALUES
                (:company_id, :provider_name, :sanctioned_limit, :utilized_amount, :available_amount, :interest_rate_apr, :status, :created_at, :updated_at)');
            $insert->execute([
                'company_id' => $companyId,
                'provider_name' => $providerName,
                'sanctioned_limit' => $sanctionedLimit,
                'utilized_amount' => $utilizedAmount,
                'available_amount' => $availableAmount,
                'interest_rate_apr' => $interestRate,
                'status' => $status,
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);
            $lineId = (int) $pdo->lastInsertId();
        }

        AuditService::log($pdo, $companyId, $userId, 'credit_line.saved', 'credit_line', (int) $lineId, [
            'provider_name' => $providerName,
            'sanctioned_limit' => $sanctionedLimit,
            'utilized_amount' => $utilizedAmount,
        ]);

        flash_set('success', 'Credit line updated.');
        redirect_to(base_url($config, 'index.php?page=credit-line'));
    }

    if ($action === 'save_upi_wallet') {
        Auth::requirePermission($config, 'payments.manage');

        $virtualAccount = trim((string) ($_POST['virtual_account'] ?? ''));
        $dailyLimit = round((float) ($_POST['daily_limit'] ?? 0), 2);
        $monthlyLimit = round((float) ($_POST['monthly_limit'] ?? 0), 2);
        $status = trim((string) ($_POST['status'] ?? 'active'));
        $status = in_array($status, ['active', 'paused'], true) ? $status : 'active';

        if ($virtualAccount === '' || $dailyLimit <= 0 || $monthlyLimit <= 0 || $dailyLimit > $monthlyLimit) {
            flash_set('error', 'UPI wallet details are invalid.');
            redirect_to(base_url($config, 'index.php?page=upi'));
        }

        $exists = $pdo->prepare('SELECT id
                                 FROM upi_wallets
                                 WHERE company_id = :company_id
                                 LIMIT 1');
        $exists->execute(['company_id' => $companyId]);
        $wallet = $exists->fetch();

        if ($wallet) {
            $update = $pdo->prepare('UPDATE upi_wallets
                                     SET virtual_account = :virtual_account,
                                         daily_limit = :daily_limit,
                                         monthly_limit = :monthly_limit,
                                         status = :status,
                                         updated_at = :updated_at
                                     WHERE id = :id');
            $update->execute([
                'virtual_account' => $virtualAccount,
                'daily_limit' => $dailyLimit,
                'monthly_limit' => $monthlyLimit,
                'status' => $status,
                'updated_at' => now_utc(),
                'id' => (int) $wallet['id'],
            ]);
            $walletId = (int) $wallet['id'];
        } else {
            $insert = $pdo->prepare('INSERT INTO upi_wallets
                (company_id, virtual_account, daily_limit, monthly_limit, used_today, used_month, status, created_at, updated_at)
                VALUES
                (:company_id, :virtual_account, :daily_limit, :monthly_limit, :used_today, :used_month, :status, :created_at, :updated_at)');
            $insert->execute([
                'company_id' => $companyId,
                'virtual_account' => $virtualAccount,
                'daily_limit' => $dailyLimit,
                'monthly_limit' => $monthlyLimit,
                'used_today' => 0,
                'used_month' => 0,
                'status' => $status,
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);
            $walletId = (int) $pdo->lastInsertId();
        }

        AuditService::log($pdo, $companyId, $userId, 'upi.wallet.saved', 'upi_wallet', $walletId, [
            'virtual_account' => $virtualAccount,
            'daily_limit' => $dailyLimit,
            'monthly_limit' => $monthlyLimit,
        ]);

        flash_set('success', 'UPI wallet updated.');
        redirect_to(base_url($config, 'index.php?page=upi'));
    }

    flash_set('error', 'Unknown action.');
    redirect_to(base_url($config, 'index.php?page='.$currentPage));
}

function web_action_login(PDO $pdo, array $config): void
{
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $userStmt = $pdo->prepare('SELECT id, name, email, password_hash, status
                               FROM users
                               WHERE email = :email
                               LIMIT 1');
    $userStmt->execute(['email' => $email]);
    $baseUser = $userStmt->fetch();

    if (! $baseUser || $baseUser['status'] !== 'active' || ! password_verify($password, (string) $baseUser['password_hash'])) {
        flash_set('error', 'Invalid credentials.');
        redirect_to(base_url($config, 'index.php?page=login'));
    }

    $membershipStmt = $pdo->prepare('SELECT cu.company_id, c.name AS company_name, c.organization_id,
                                            r.name AS role_name, r.permissions_json
                                     FROM company_user cu
                                     JOIN companies c ON c.id = cu.company_id
                                     JOIN roles r ON r.id = cu.role_id
                                     WHERE cu.user_id = :user_id
                                       AND cu.status = "active"
                                       AND c.status = "active"
                                     ORDER BY cu.company_id ASC');
    $membershipStmt->execute(['user_id' => (int) $baseUser['id']]);
    $memberships = $membershipStmt->fetchAll();

    if ($memberships === []) {
        flash_set('error', 'No active company membership found.');
        redirect_to(base_url($config, 'index.php?page=login'));
    }

    $sessionUser = [
        'id' => (int) $baseUser['id'],
        'name' => (string) $baseUser['name'],
        'email' => (string) $baseUser['email'],
        'company_id' => (int) $memberships[0]['company_id'],
        'role_name' => (string) $memberships[0]['role_name'],
        'permissions_json' => (string) ($memberships[0]['permissions_json'] ?? '[]'),
    ];

    Auth::login($sessionUser, $memberships);

    AuditService::log($pdo, (int) $memberships[0]['company_id'], (int) $baseUser['id'], 'auth.login', 'user', (int) $baseUser['id'], [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);

    flash_set('success', 'Welcome back, '.$baseUser['name'].'.');
    redirect_to(base_url($config, 'index.php?page=dashboard'));
}

function render_web_page(PDO $pdo, array $config, string $page): array
{
    if (! Auth::check() && ! web_is_public_page($page)) {
        redirect_to(base_url($config, 'index.php?page=login'));
    }

    if (Auth::check()) {
        web_ensure_optional_tables($pdo);
        $activeCompanyId = (int) (Auth::user()['company_id'] ?? 0);
        if ($activeCompanyId > 0) {
            web_sync_company_integrations($pdo, $activeCompanyId);
            web_apply_runtime_env_for_company($pdo, $activeCompanyId, true, null);
        }
    }

    if ($page === 'reimbursements') {
        $page = 'expenses';
    }

    $user = Auth::user();

    ob_start();
    $title = ucfirst($page);

    if ($page === 'home') {
        $title = 'Home';
        ?>
        <section class="home-hero">
            <div class="site-container home-hero-grid">
                <div class="home-hero-copy">
                    <div class="eyebrow">Finance Automation Platform</div>
                    <h1>A Modern Spend Platform for AP, Procurement, Reimbursements, and Payouts</h1>
                    <p class="home-lead">Move from manual finance ops to a connected workflow where capture, approval, compliance, and payment execution happen in one place.</p>
                    <div class="hero-actions">
                        <?php if ($user): ?>
                            <a class="hero-link" href="<?= e(base_url($config, 'index.php?page=dashboard')) ?>">Open Dashboard</a>
                        <?php else: ?>
                            <a class="hero-link" href="<?= e(base_url($config, 'index.php?page=login')) ?>">Get Started</a>
                        <?php endif; ?>
                        <a class="hero-link ghost" href="<?= e(base_url($config, 'index.php?page=features')) ?>">Explore Features</a>
                    </div>
                    <div class="home-trust">
                        <span>Supports: OCR Capture</span>
                        <span>3-Way Matching</span>
                        <span>Maker-Checker Controls</span>
                        <span>Bank API Dispatch</span>
                    </div>
                </div>
                <aside class="home-hero-visual">
                    <div class="home-visual-card glow-card" data-glow>
                        <h3>One Flow, End to End</h3>
                        <ul class="home-checks">
                            <li>Capture invoices from web, email, and chat channels</li>
                            <li>Auto-match PO and GRN with exceptions queued for action</li>
                            <li>Run policy approvals and tax release/hold decisions</li>
                            <li>Execute payouts with callback and audit tracking</li>
                        </ul>
                        <a class="hero-link ghost" href="<?= e(base_url($config, 'index.php?page=contact')) ?>">Book a Consultation</a>
                    </div>
                </aside>
            </div>
        </section>

        <section class="home-modules">
            <div class="site-container home-modules-grid">
                <article class="home-module glow-card" data-glow>
                    <h3>Accounts Payable</h3>
                    <p>Automate invoice intake, data extraction, approvals, and payable tracking in real time.</p>
                </article>
                <article class="home-module glow-card" data-glow>
                    <h3>Procurement Controls</h3>
                    <p>Issue POs, record GRNs, and run mismatch detection with exception-driven workflows.</p>
                </article>
                <article class="home-module glow-card" data-glow>
                    <h3>Payments & Reconciliation</h3>
                    <p>Execute single or batch payouts with maker-checker policies and status callbacks.</p>
                </article>
            </div>
        </section>

        <section class="home-flow">
            <div class="site-container">
                <div class="section-headline">
                    <div class="eyebrow">How It Works</div>
                    <h2>From invoice capture to payout, in five clear stages</h2>
                </div>
                <div class="home-flow-grid">
                    <article class="glow-card" data-glow><span>01</span><h4>Capture</h4><p>Bring invoices and receipts from web, email, and chat channels.</p></article>
                    <article class="glow-card" data-glow><span>02</span><h4>Match</h4><p>Validate invoice values against PO and GRN references.</p></article>
                    <article class="glow-card" data-glow><span>03</span><h4>Approve</h4><p>Route by amount, department, and vendor policy with maker-checker.</p></article>
                    <article class="glow-card" data-glow><span>04</span><h4>Reconcile</h4><p>Apply tax and compliance checks to classify release or hold.</p></article>
                    <article class="glow-card" data-glow><span>05</span><h4>Pay</h4><p>Dispatch payments and track callbacks with complete audit history.</p></article>
                </div>
            </div>
        </section>

        <section class="home-cta">
            <div class="site-container home-cta-wrap">
                <div>
                    <h3>Ready to streamline spend operations?</h3>
                    <p>See how your current AP and reimbursement process maps into this workflow.</p>
                </div>
                <div class="cta-inline">
                    <a class="hero-link" href="<?= e(base_url($config, 'index.php?page=pricing')) ?>">View Pricing</a>
                    <a class="hero-link ghost" href="<?= e(base_url($config, 'index.php?page=contact')) ?>">Talk to Team</a>
                </div>
            </div>
        </section>
        <?php
    } elseif ($page === 'about') {
        $title = 'About';
        ?>
        <section class="about-hero">
            <div class="site-container about-hero-grid">
                <div>
                    <div class="eyebrow">About</div>
                    <h1>Built for finance teams that need precision, speed, and governed execution</h1>
                    <p class="muted">We centralize AP, procurement, reimbursements, tax decisions, and payout operations into a single operational platform designed for control-first teams.</p>
                </div>
                <div class="about-stats">
                    <article><strong>10+</strong><span>core finance modules</span></article>
                    <article><strong>100%</strong><span>transactional critical writes</span></article>
                    <article><strong>24x7</strong><span>queue-backed integration jobs</span></article>
                    <article><strong>Multi-company</strong><span>strict entity-level controls</span></article>
                </div>
            </div>
        </section>

        <section class="about-values">
            <div class="site-container">
                <div class="section-headline">
                    <div class="eyebrow">Core Values</div>
                    <h2>How we design finance systems</h2>
                </div>
                <div class="about-values-grid">
                    <article class="about-value glow-card" data-glow>
                        <h3>Reliability by Design</h3>
                        <p>Idempotent commands, retries, dead-letter recovery, and explicit state transitions across every finance entity.</p>
                    </article>
                    <article class="about-value glow-card" data-glow>
                        <h3>Governance First</h3>
                        <p>Role-based access controls, maker-checker safeguards, and immutable audit events with full action history.</p>
                    </article>
                    <article class="about-value glow-card" data-glow>
                        <h3>Composable Integrations</h3>
                        <p>Provider contracts for OCR, ERP, tax portals, banks, and messaging so dependencies remain swappable.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="about-principles">
            <div class="site-container">
                <div class="section-headline">
                    <div class="eyebrow">Operating Principles</div>
                    <h2>How we structure finance automation</h2>
                </div>
                <div class="about-principles-grid">
                    <article class="glow-card" data-glow>
                        <h4>Single Source of Truth</h4>
                        <p>All approvals, payment actions, and reconciliation decisions are persisted with immutable event logs.</p>
                    </article>
                    <article class="glow-card" data-glow>
                        <h4>Exception-Led Workflow</h4>
                        <p>Teams operate from prioritized queues for mismatches, policy breaks, and tax holds before payout release.</p>
                    </article>
                    <article class="glow-card" data-glow>
                        <h4>Multi-Entity Isolation</h4>
                        <p>All transactions and settings are company-scoped with strict access filtering and context-bound controls.</p>
                    </article>
                </div>
            </div>
        </section>
        <?php
    } elseif ($page === 'features') {
        $title = 'Features';
        ?>
        <section class="features-hero">
            <div class="site-container">
                <div class="eyebrow">Platform Modules</div>
                <h1>Everything from capture to reconciliation, connected end to end</h1>
                <p class="muted">Each module is independently scalable, while workflows stay connected so approvals, tax decisions, and payout execution move in sync.</p>
            </div>
        </section>

        <section class="features-modules">
            <div class="site-container features-modules-grid">
                <article class="feature-module glow-card" data-glow><h3>Vendor Lifecycle</h3><p>Master records, onboarding docs, tax identity checks, and compliance scoring.</p></article>
                <article class="feature-module glow-card" data-glow><h3>Procurement Guardrails</h3><p>PO creation, GRN capture, and configurable matching tolerance with exception queues.</p></article>
                <article class="feature-module glow-card" data-glow><h3>Invoice Operations</h3><p>OCR extraction, validation, approval routing, and dispatch-ready states.</p></article>
                <article class="feature-module glow-card" data-glow><h3>Reimbursements</h3><p>Policy checks, category limits, duplicate detection, and payout linking.</p></article>
                <article class="feature-module glow-card" data-glow><h3>Payment Rails</h3><p>Single and bulk transfers, callback reconciliation, and maker-checker controls.</p></article>
                <article class="feature-module glow-card" data-glow><h3>Tax Decisions</h3><p>Release/hold outcomes with reason codes and payment blocking downstream.</p></article>
            </div>
        </section>

        <section class="features-bands">
            <div class="site-container feature-bands">
                <div><strong>Capture Layer</strong><span>Web + Email + Chat + Mobile channels</span></div>
                <div><strong>Control Layer</strong><span>Policies + Approvals + Maker-Checker</span></div>
                <div><strong>Execution Layer</strong><span>Single + Batch + Callback settlement</span></div>
                <div><strong>Compliance Layer</strong><span>Tax status + Vendor score + Audit events</span></div>
            </div>
        </section>

        <section class="features-integrations">
            <div class="site-container features-integrations-grid">
                <article class="glow-card" data-glow>
                    <h4>Inbound Integrations</h4>
                    <p>Signed webhooks with idempotency keys protect every event ingested from external providers.</p>
                </article>
                <article class="glow-card" data-glow>
                    <h4>Outbound Integrations</h4>
                    <p>Queued adapters for OCR, banking, ERP, messaging, and tax providers with retries and dead-letter fallback.</p>
                </article>
            </div>
        </section>
        <?php
    } elseif ($page === 'pricing') {
        $title = 'Pricing';
        ?>
        <section class="pricing-hero">
            <div class="site-container">
                <div class="eyebrow">Pricing</div>
                <h1>Plans that scale with your finance complexity</h1>
                <p class="muted">All plans include multi-company support, immutable audit events, and workflow orchestration. Use the billing toggle for quick estimate comparisons.</p>
                <div class="pricing-toggle top-space">
                    <button type="button" class="plan-toggle is-active" data-plan-cycle="monthly">Monthly</button>
                    <button type="button" class="plan-toggle" data-plan-cycle="annual">Annual (save 18%)</button>
                </div>
            </div>
        </section>

        <section class="pricing-plans">
            <div class="site-container grid cols-3 gap-lg pricing-grid">
                <article class="pricing-card glow-card" data-glow>
                    <h3>Starter</h3>
                    <p class="muted">Core AP, reimbursements, and approvals.</p>
                    <p class="pricing-amount"><span class="currency">INR</span> <strong class="price-value" data-monthly="39000" data-annual="31980">39,000</strong> <span class="cycle">/mo</span></p>
                    <ul class="muted compact-list">
                        <li>Invoice capture and approvals</li>
                        <li>Expense claims and policy checks</li>
                        <li>Dashboard and standard reports</li>
                    </ul>
                    <strong>Contact Sales</strong>
                </article>
                <article class="pricing-card featured glow-card" data-glow>
                    <h3>Growth</h3>
                    <p class="muted">Adds procurement, batch payouts, and tax cockpit.</p>
                    <p class="pricing-amount"><span class="currency">INR</span> <strong class="price-value" data-monthly="79000" data-annual="64780">79,000</strong> <span class="cycle">/mo</span></p>
                    <ul class="muted compact-list">
                        <li>PO and GRN matching controls</li>
                        <li>Batch payment orchestration</li>
                        <li>Release and hold decisioning</li>
                    </ul>
                    <strong>Contact Sales</strong>
                </article>
                <article class="pricing-card glow-card" data-glow>
                    <h3>Enterprise</h3>
                    <p class="muted">Advanced controls, integration scale, and custom workflows.</p>
                    <p class="pricing-amount"><span class="currency">INR</span> <strong class="price-value" data-monthly="149000" data-annual="122180">149,000</strong> <span class="cycle">/mo</span></p>
                    <ul class="muted compact-list">
                        <li>Custom adapter and workflow layer</li>
                        <li>Enhanced governance model</li>
                        <li>Priority rollout and support</li>
                    </ul>
                    <strong>Contact Sales</strong>
                </article>
            </div>
        </section>

        <section class="pricing-notes">
            <div class="site-container pricing-notes-grid">
                <article class="glow-card" data-glow>
                    <h4>Onboarding</h4>
                    <p>Structured implementation with workflow mapping, role setup, and integration validation.</p>
                </article>
                <article class="glow-card" data-glow>
                    <h4>Support</h4>
                    <p>Email and guided support included. Dedicated rollout and response SLAs on higher plans.</p>
                </article>
                <article class="glow-card" data-glow>
                    <h4>Security</h4>
                    <p>Role-based controls, maker-checker approvals, and audit events are available across all tiers.</p>
                </article>
            </div>
        </section>
        <?php
    } elseif ($page === 'contact') {
        $title = 'Contact';
        ?>
        <section class="contact-hero">
            <div class="site-container">
                <div class="eyebrow">Contact</div>
                <h1>Talk to the solution engineering team</h1>
                <p class="muted">Share your AP volume, current ERP stack, compliance needs, and payout rails. We will map a rollout plan around your process.</p>
            </div>
        </section>

        <section class="contact-main">
            <div class="site-container contact-main-grid">
                <article class="contact-panel glow-card" data-glow>
                    <h3>Reach Us</h3>
                    <p>Email: sales@pazy.local</p>
                    <p>Support: support@pazy.local</p>
                    <p>Coverage: Mon to Fri, 9:00 to 18:00 IST</p>
                    <div class="contact-meta">
                        <span>Response SLA</span>
                        <strong>within 1 business day</strong>
                    </div>
                </article>
                <article class="contact-panel glow-card" data-glow>
                    <h3>Send Message</h3>
                    <form method="post" action="<?= e(base_url($config, 'index.php?page=contact')) ?>">
                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                        <input type="hidden" name="action" value="submit_public_contact">
                        <input type="text" name="name" placeholder="Your name" required>
                        <input type="email" name="email" placeholder="Work email" required>
                        <input type="text" name="company" placeholder="Company">
                        <textarea name="message" rows="4" placeholder="Tell us what you need" required></textarea>
                        <button type="submit">Send Message</button>
                    </form>
                </article>
            </div>
        </section>

        <section class="contact-checklist">
            <div class="site-container">
                <h3>Implementation Discovery Checklist</h3>
                <div class="contact-check-grid">
                    <article class="contact-check glow-card" data-glow>
                        <h4>Current State</h4>
                        <p>How many invoices, claims, and payouts move through your workflow each month?</p>
                    </article>
                    <article class="contact-check glow-card" data-glow>
                        <h4>Integrations</h4>
                        <p>Which ERP systems, bank rails, and communication channels are mandatory for launch?</p>
                    </article>
                    <article class="contact-check glow-card" data-glow>
                        <h4>Controls</h4>
                        <p>What approval thresholds, maker-checker constraints, and tax hold conditions are required?</p>
                    </article>
                </div>
            </div>
        </section>
        <?php
    } elseif ($page === 'login') {
        $title = 'Sign In';
        ?>
        <section class="hero card slim">
            <div class="eyebrow">Finance Command Center</div>
            <h2>Sign In</h2>
            <p class="muted">Use your seeded account or API token after login.</p>
            <form method="post" action="<?= e(base_url($config, 'index.php?page=login')) ?>">
                <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                <input type="hidden" name="action" value="login">
                <label>Email</label>
                <input type="email" name="email" required placeholder="admin@pazy.local">
                <label>Password</label>
                <input type="password" name="password" required placeholder="password1234">
                <button type="submit">Sign In</button>
            </form>
        </section>
        <?php
    } elseif ($page === 'dashboard') {
        $companyId = (int) ($user['company_id'] ?? 0);

        $summary = [
            'vendors' => (int) $pdo->query('SELECT COUNT(*) FROM vendors WHERE company_id = '.(int) $companyId)->fetchColumn(),
            'po' => (int) $pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE company_id = '.(int) $companyId)->fetchColumn(),
            'invoices' => (int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE company_id = '.(int) $companyId)->fetchColumn(),
            'exceptions' => (int) $pdo->query('SELECT COUNT(*) FROM matching_exceptions WHERE company_id = '.(int) $companyId.' AND status = "open"')->fetchColumn(),
            'approvals' => (int) $pdo->query('SELECT COUNT(*) FROM approvals WHERE company_id = '.(int) $companyId.' AND status = "pending"')->fetchColumn(),
            'payments' => (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE company_id = '.(int) $companyId)->fetchColumn(),
            'expenses' => (int) $pdo->query('SELECT COUNT(*) FROM expense_claims WHERE company_id = '.(int) $companyId)->fetchColumn(),
            'tax_holds' => (int) $pdo->query('SELECT COUNT(*) FROM tax_reconciliations WHERE company_id = '.(int) $companyId.' AND recommendation = "hold"')->fetchColumn(),
        ];

        $recentAudit = $pdo->prepare('SELECT action_key, entity_type, entity_id, created_at
                                      FROM audit_events
                                      WHERE company_id = :company_id
                                      ORDER BY id DESC
                                      LIMIT 8');
        $recentAudit->execute(['company_id' => $companyId]);

        $captureMix = $pdo->prepare('SELECT source_channel, COUNT(*) AS total
                                     FROM capture_events
                                     WHERE company_id = :company_id
                                     GROUP BY source_channel
                                     ORDER BY total DESC
                                     LIMIT 10');
        $captureMix->execute(['company_id' => $companyId]);
        ?>
        <div class="page-panel dashboard-panel">
            <section class="section-head">
                <h2>Dashboard</h2>
                <?php $dashboardCompanyLabel = trim((string) preg_replace('/\b(?:Demo|UAE|India)\b\s*/i', '', (string) ($user['company_name'] ?? ''))); ?>
                <p class="muted">Multi-module overview for <?= e($dashboardCompanyLabel !== '' ? $dashboardCompanyLabel : ((string) ($user['company_name'] ?? ''))) ?></p>
            </section>
            <div class="kpis">
                <article class="kpi"><span>Vendors</span><strong><?= e((string) $summary['vendors']) ?></strong></article>
                <article class="kpi"><span>POs</span><strong><?= e((string) $summary['po']) ?></strong></article>
                <article class="kpi"><span>Invoices</span><strong><?= e((string) $summary['invoices']) ?></strong></article>
                <article class="kpi danger"><span>Exceptions</span><strong><?= e((string) $summary['exceptions']) ?></strong></article>
                <article class="kpi"><span>Pending Approvals</span><strong><?= e((string) $summary['approvals']) ?></strong></article>
                <article class="kpi"><span>Payments</span><strong><?= e((string) $summary['payments']) ?></strong></article>
                <article class="kpi"><span>Expenses</span><strong><?= e((string) $summary['expenses']) ?></strong></article>
                <article class="kpi warning"><span>Tax Holds</span><strong><?= e((string) $summary['tax_holds']) ?></strong></article>
            </div>

            <div class="grid cols-2 gap-lg top-space">
                <section class="card">
                    <h3>Workflow Progress</h3>
                    <div class="meter"><span style="width: <?= e((string) min(100, max(0, ($summary['invoices'] > 0 ? (int) (($summary['invoices'] - $summary['exceptions']) * 100 / $summary['invoices']) : 100)))) ?>%"></span></div>
                    <p class="muted">Invoice match health</p>
                    <div class="meter"><span style="width: <?= e((string) min(100, max(0, ($summary['payments'] > 0 ? (int) (($summary['payments'] - $summary['tax_holds']) * 100 / $summary['payments']) : 80)))) ?>%"></span></div>
                    <p class="muted">Payment readiness after tax checks</p>
                    <div class="quick-links top-space">
                        <a href="<?= e(base_url($config, 'index.php?page=inbox')) ?>">Open Inbox</a>
                        <a href="<?= e(base_url($config, 'index.php?page=matching')) ?>">Matching Workspace</a>
                        <a href="<?= e(base_url($config, 'index.php?page=payments')) ?>">Payment Command Center</a>
                    </div>
                </section>

                <section class="card">
                    <h3>Recent Audit Events</h3>
                    <table>
                        <thead><tr><th>Action</th><th>Entity</th><th>At (UTC)</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentAudit as $row): ?>
                            <tr>
                                <td><?= e($row['action_key']) ?></td>
                                <td><?= e($row['entity_type'].' #'.$row['entity_id']) ?></td>
                                <td><?= e($row['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            </div>

            <section class="card top-space">
                <h3>Capture Channel Mix</h3>
                <table>
                    <thead><tr><th>Channel</th><th>Events</th></tr></thead>
                    <tbody>
                    <?php foreach ($captureMix as $mix): ?>
                        <tr>
                            <td><?= e($mix['source_channel']) ?></td>
                            <td><?= e((string) $mix['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
        <?php
    } elseif ($page === 'explore') {
        $title = 'Explore More';
        $companyId = (int) ($user['company_id'] ?? 0);

        $integrationCounts = $pdo->prepare('SELECT
            SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN status = "disabled" THEN 1 ELSE 0 END) AS disabled_count
            FROM company_integrations
            WHERE company_id = :company_id');
        $integrationCounts->execute(['company_id' => $companyId]);
        $counts = $integrationCounts->fetch() ?: ['active_count' => 0, 'disabled_count' => 0];
        ?>
        <div class="page-panel">
            <section class="section-head">
                <h2>Explore More</h2>
                <p class="muted">Discover product modules and jump directly into each workflow.</p>
            </section>

            <div class="explore-grid top-space">
                <article class="explore-card glow-card" data-glow>
                    <div class="explore-icon">BK</div>
                    <h3>Connected Banking</h3>
                    <p>Simplify banking with one platform for all payments, maker-checker, and reconciliation.</p>
                    <a href="<?= e(base_url($config, 'index.php?page=connected-banking')) ?>">Learn More ↗</a>
                </article>

                <article class="explore-card glow-card" data-glow>
                    <div class="explore-icon">CD</div>
                    <h3>Cards</h3>
                    <p>Issue virtual or physical corporate cards with limits, MCC controls, and mandatory receipts.</p>
                    <a href="<?= e(base_url($config, 'index.php?page=cards')) ?>">Learn More ↗</a>
                </article>

                <article class="explore-card glow-card" data-glow>
                    <div class="explore-icon">RB</div>
                    <h3>Reimbursements</h3>
                    <p>Submit claims, run policy checks, and release approved payouts to employees quickly.</p>
                    <a href="<?= e(base_url($config, 'index.php?page=expenses')) ?>">Learn More ↗</a>
                </article>

                <article class="explore-card glow-card" data-glow>
                    <div class="explore-icon">PR</div>
                    <h3>Procurement</h3>
                    <p>Smart procurement with PO and GRN lifecycle tightly connected to AP invoice management.</p>
                    <a href="<?= e(base_url($config, 'index.php?page=procurement')) ?>">Learn More ↗</a>
                </article>

                <article class="explore-card glow-card" data-glow>
                    <div class="explore-icon">CL</div>
                    <h3>Credit Line</h3>
                    <p>Track sanctioned limits, utilization, and available working capital in one place.</p>
                    <a href="<?= e(base_url($config, 'index.php?page=credit-line')) ?>">Learn More ↗</a>
                </article>

                <article class="explore-card glow-card" data-glow>
                    <div class="explore-icon">UP</div>
                    <h3>UPI</h3>
                    <p>Manage petty cash and vendor UPI payouts with wallet limits and spend visibility.</p>
                    <a href="<?= e(base_url($config, 'index.php?page=upi')) ?>">Learn More ↗</a>
                </article>

                <article class="explore-card glow-card" data-glow>
                    <div class="explore-icon">BP</div>
                    <h3>Bulk Payout</h3>
                    <p>Send payments to multiple vendors and employees in one go through batch command center.</p>
                    <a href="<?= e(base_url($config, 'index.php?page=bulk-payout')) ?>">Learn More ↗</a>
                </article>

                <article class="explore-card glow-card" data-glow>
                    <div class="explore-icon">IN</div>
                    <h3>Integrations</h3>
                    <p><?= e((string) ($counts['active_count'] ?? 0)) ?> active connections and <?= e((string) ($counts['disabled_count'] ?? 0)) ?> available adapters.</p>
                    <a href="<?= e(base_url($config, 'index.php?page=integrations')) ?>">Learn More ↗</a>
                </article>
            </div>
        </div>
        <?php
    } elseif ($page === 'inbox') {
        $companyId = (int) ($user['company_id'] ?? 0);

        $pendingApprovals = $pdo->prepare('SELECT a.id, a.entity_type, a.entity_id, a.level_order, a.due_at, approver.name AS approver_name
                                           FROM approvals a
                                           JOIN users approver ON approver.id = a.approver_user_id
                                           WHERE a.company_id = :company_id
                                             AND a.status = "pending"
                                           ORDER BY a.due_at IS NULL ASC, a.due_at ASC, a.id DESC
                                           LIMIT 100');
        $pendingApprovals->execute(['company_id' => $companyId]);

        $exceptions = $pdo->prepare('SELECT me.id, me.invoice_id, me.reason_code, me.created_at
                                     FROM matching_exceptions me
                                     WHERE me.company_id = :company_id
                                       AND me.status = "open"
                                     ORDER BY me.id DESC
                                     LIMIT 100');
        $exceptions->execute(['company_id' => $companyId]);

        $approvedPayments = $pdo->prepare('SELECT p.id, p.amount, p.currency_code, p.payment_mode, p.scheduled_for, v.name AS vendor_name
                                           FROM payments p
                                           LEFT JOIN vendors v ON v.id = p.payee_id
                                           WHERE p.company_id = :company_id
                                             AND p.status = "approved"
                                           ORDER BY p.scheduled_for IS NULL ASC, p.scheduled_for ASC, p.id DESC
                                           LIMIT 100');
        $approvedPayments->execute(['company_id' => $companyId]);

        $taxHolds = $pdo->prepare('SELECT tr.id, tr.invoice_id, tr.hold_reason, tr.tax_period, v.name AS vendor_name
                                   FROM tax_reconciliations tr
                                   JOIN invoices i ON i.id = tr.invoice_id
                                   JOIN vendors v ON v.id = i.vendor_id
                                   WHERE tr.company_id = :company_id
                                     AND tr.recommendation = "hold"
                                   ORDER BY tr.id DESC
                                   LIMIT 100');
        $taxHolds->execute(['company_id' => $companyId]);
        ?>
        <div class="page-panel inbox-panel">
            <section class="section-head">
                <h2>Operations Inbox</h2>
                <p class="muted">Single queue for approvals, matching exceptions, payable dispatches, and tax holds.</p>
            </section>
            <form method="post" action="<?= e(base_url($config, 'index.php?page=inbox')) ?>">
                <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                <input type="hidden" name="action" value="inbox_bulk_action">

                <div class="inline-form top-space">
                    <select name="bulk_action" required>
                        <option value="">Bulk action</option>
                        <option value="approve">Approve selected approvals</option>
                        <option value="reject">Reject selected approvals</option>
                        <option value="resolve_exceptions">Resolve selected exceptions</option>
                        <option value="dispatch_payments">Dispatch selected payments</option>
                        <option value="release_tax_holds">Release selected tax holds</option>
                    </select>
                    <button type="submit">Apply Bulk Action</button>
                </div>

                <div class="grid cols-2 gap-lg top-space">
                    <section class="card">
                        <h3>Pending Approvals</h3>
                        <table>
                            <thead><tr><th>Select</th><th>Approval</th><th>Approver</th><th>Due (UTC)</th></tr></thead>
                            <tbody>
                            <?php foreach ($pendingApprovals as $approval): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_approvals[]" value="<?= e((string) $approval['id']) ?>"></td>
                                    <td>#<?= e((string) $approval['id']) ?> · <?= e($approval['entity_type'].' #'.$approval['entity_id']) ?></td>
                                    <td><?= e($approval['approver_name']) ?></td>
                                    <td><?= e($approval['due_at'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>

                    <section class="card">
                        <h3>Matching Exceptions</h3>
                        <table>
                            <thead><tr><th>Select</th><th>Invoice</th><th>Reason</th><th>Created</th></tr></thead>
                            <tbody>
                            <?php foreach ($exceptions as $exception): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_exceptions[]" value="<?= e((string) $exception['id']) ?>"></td>
                                    <td>#<?= e((string) $exception['invoice_id']) ?></td>
                                    <td><?= e($exception['reason_code']) ?></td>
                                    <td><?= e($exception['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                </div>

                <div class="grid cols-2 gap-lg top-space">
                    <section class="card">
                        <h3>Approved Payments (Ready)</h3>
                        <table>
                            <thead><tr><th>Select</th><th>ID</th><th>Vendor</th><th>Amount</th><th>Schedule</th></tr></thead>
                            <tbody>
                            <?php foreach ($approvedPayments as $payment): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_payments[]" value="<?= e((string) $payment['id']) ?>"></td>
                                    <td>#<?= e((string) $payment['id']) ?></td>
                                    <td><?= e($payment['vendor_name'] ?? '-') ?></td>
                                    <td><?= e(money_format_indian((float) $payment['amount']).' '.$payment['currency_code']) ?></td>
                                    <td><?= e($payment['scheduled_for'] ?? 'Immediate') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>

                    <section class="card">
                        <h3>Tax Holds</h3>
                        <table>
                            <thead><tr><th>Select</th><th>Invoice</th><th>Vendor</th><th>Reason</th></tr></thead>
                            <tbody>
                            <?php foreach ($taxHolds as $hold): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_tax_runs[]" value="<?= e((string) $hold['id']) ?>"></td>
                                    <td>#<?= e((string) $hold['invoice_id']) ?></td>
                                    <td><?= e($hold['vendor_name']) ?></td>
                                    <td><?= e($hold['hold_reason'] ?? 'manual_review') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                </div>
            </form>
        </div>
        <?php
    } elseif ($page === 'vendors') {
        $companyId = (int) ($user['company_id'] ?? 0);
        $vendors = $pdo->prepare('SELECT id, name, email, phone, tax_id, compliance_score, status, created_at
                                  FROM vendors
                                  WHERE company_id = :company_id
                                  ORDER BY id DESC');
        $vendors->execute(['company_id' => $companyId]);
        ?>
        <div class="grid cols-2 gap-lg">
            <section class="card">
                <h2>Vendor Onboarding</h2>
                <p class="muted">Digital KYC with tax identity validation and compliance scoring.</p>
                <form method="post" action="<?= e(base_url($config, 'index.php?page=vendors')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                    <input type="hidden" name="action" value="create_vendor">
                    <input type="text" name="name" placeholder="Vendor name" required>
                    <input type="email" name="email" placeholder="vendor@example.com">
                    <input type="text" name="phone" placeholder="Phone">
                    <input type="text" name="tax_id" placeholder="GSTIN / PAN">
                    <input type="text" name="bank_account_masked" placeholder="Bank account masked">
                    <button type="submit">Create Vendor</button>
                </form>
            </section>

            <section class="card">
                <h2>Vendor Master</h2>
                <table>
                    <thead><tr><th>Name</th><th>Tax ID</th><th>Score</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($vendors as $row): ?>
                        <tr>
                            <td><?= e($row['name']) ?></td>
                            <td><?= e($row['tax_id'] ?? '-') ?></td>
                            <td><?= e((string) $row['compliance_score']) ?></td>
                            <td><span class="badge"><?= e($row['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
        <?php
    } elseif ($page === 'matching') {
        $companyId = (int) ($user['company_id'] ?? 0);

        $poOptions = $pdo->prepare('SELECT id, po_number FROM purchase_orders WHERE company_id = :company_id ORDER BY id DESC');
        $poOptions->execute(['company_id' => $companyId]);
        $allPo = $poOptions->fetchAll();

        $grnOptions = $pdo->prepare('SELECT id, grn_number FROM goods_receipts WHERE company_id = :company_id ORDER BY id DESC');
        $grnOptions->execute(['company_id' => $companyId]);
        $allGrn = $grnOptions->fetchAll();

        $rows = $pdo->prepare('SELECT i.id, i.invoice_number, i.po_id, i.grn_id, i.total_amount, i.status, i.exception_reason,
                                      po.po_number, grn.grn_number, v.name AS vendor_name
                               FROM invoices i
                               JOIN vendors v ON v.id = i.vendor_id
                               LEFT JOIN purchase_orders po ON po.id = i.po_id
                               LEFT JOIN goods_receipts grn ON grn.id = i.grn_id
                               WHERE i.company_id = :company_id
                                 AND i.status IN ("captured", "ocr_processed", "matched", "exception")
                               ORDER BY i.id DESC
                               LIMIT 200');
        $rows->execute(['company_id' => $companyId]);
        ?>
        <section class="card">
            <h2>3-Way Matching Workspace</h2>
            <p class="muted">Link PO and GRN, run rematch, and auto-route matched invoices into approvals.</p>
            <table>
                <thead><tr><th>Invoice</th><th>Vendor</th><th>Total</th><th>Status</th><th>PO</th><th>GRN</th><th>Rematch</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['invoice_number']) ?><br><span class="muted"><?= e($row['exception_reason'] ?? '-') ?></span></td>
                        <td><?= e($row['vendor_name']) ?></td>
                        <td><?= e(money_format_indian((float) $row['total_amount'])) ?></td>
                        <td><span class="badge"><?= e($row['status']) ?></span></td>
                        <td><?= e($row['po_number'] ?? '-') ?></td>
                        <td><?= e($row['grn_number'] ?? '-') ?></td>
                        <td>
                            <form method="post" action="<?= e(base_url($config, 'index.php?page=matching')) ?>" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                                <input type="hidden" name="action" value="rematch_invoice">
                                <input type="hidden" name="invoice_id" value="<?= e((string) $row['id']) ?>">
                                <select name="po_id">
                                    <option value="">PO</option>
                                    <?php foreach ($allPo as $po): ?>
                                        <option value="<?= e((string) $po['id']) ?>" <?= (int) $po['id'] === (int) $row['po_id'] ? 'selected' : '' ?>><?= e($po['po_number']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="grn_id">
                                    <option value="">GRN</option>
                                    <?php foreach ($allGrn as $grn): ?>
                                        <option value="<?= e((string) $grn['id']) ?>" <?= (int) $grn['id'] === (int) $row['grn_id'] ? 'selected' : '' ?>><?= e($grn['grn_number']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit">Run</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php
    } elseif ($page === 'procurement') {
        $companyId = (int) ($user['company_id'] ?? 0);

        $vendorsStmt = $pdo->prepare('SELECT id, name FROM vendors WHERE company_id = :company_id ORDER BY name ASC');
        $vendorsStmt->execute(['company_id' => $companyId]);
        $vendors = $vendorsStmt->fetchAll();

        $poStmt = $pdo->prepare('SELECT po.id, po.po_number, po.po_date, po.total_amount, po.status, v.name AS vendor_name
                                 FROM purchase_orders po
                                 JOIN vendors v ON v.id = po.vendor_id
                                 WHERE po.company_id = :company_id
                                 ORDER BY po.id DESC');
        $poStmt->execute(['company_id' => $companyId]);
        $poRows = $poStmt->fetchAll();

        $grnRows = $pdo->prepare('SELECT g.id, g.grn_number, g.received_date, g.status, po.po_number
                                  FROM goods_receipts g
                                  JOIN purchase_orders po ON po.id = g.po_id
                                  WHERE g.company_id = :company_id
                                  ORDER BY g.id DESC');
        $grnRows->execute(['company_id' => $companyId]);

        $exceptions = $pdo->prepare('SELECT me.id, me.invoice_id, me.reason_code, me.status, me.created_at
                                     FROM matching_exceptions me
                                     WHERE me.company_id = :company_id
                                     ORDER BY me.id DESC');
        $exceptions->execute(['company_id' => $companyId]);
        ?>
        <div class="grid cols-2 gap-lg">
            <section class="card">
                <h2>Create Purchase Order</h2>
                <form method="post" action="<?= e(base_url($config, 'index.php?page=procurement')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                    <input type="hidden" name="action" value="create_po">
                    <select name="vendor_id" required>
                        <option value="">Select Vendor</option>
                        <?php foreach ($vendors as $vendor): ?>
                            <option value="<?= e((string) $vendor['id']) ?>"><?= e($vendor['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="po_number" placeholder="PO Number" required>
                    <input type="date" name="po_date" value="<?= e(today_utc()) ?>" required>
                    <input type="text" name="department_code" placeholder="Department code (e.g. OPS)">
                    <input type="number" step="0.01" name="total_amount" placeholder="Total amount" required>
                    <button type="submit">Submit PO</button>
                </form>
            </section>

            <section class="card">
                <h2>Create GRN</h2>
                <form method="post" action="<?= e(base_url($config, 'index.php?page=procurement')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                    <input type="hidden" name="action" value="create_grn">
                    <select name="po_id" required>
                        <option value="">Select PO</option>
                        <?php foreach ($poRows as $po): ?>
                            <option value="<?= e((string) $po['id']) ?>"><?= e($po['po_number'].' - '.$po['vendor_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="grn_number" placeholder="GRN Number" required>
                    <input type="date" name="received_date" value="<?= e(today_utc()) ?>" required>
                    <button type="submit">Record GRN</button>
                </form>
            </section>
        </div>

        <div class="grid cols-2 gap-lg top-space">
            <section class="card">
                <h3>Purchase Orders</h3>
                <table>
                    <thead><tr><th>PO</th><th>Vendor</th><th>Amount</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($poRows as $po): ?>
                        <tr>
                            <td><?= e($po['po_number']) ?></td>
                            <td><?= e($po['vendor_name']) ?></td>
                            <td><?= e(money_format_indian((float) $po['total_amount'])) ?></td>
                            <td><span class="badge"><?= e($po['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h3>GRN Register</h3>
                <table>
                    <thead><tr><th>GRN</th><th>PO</th><th>Date</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($grnRows as $grn): ?>
                        <tr>
                            <td><?= e($grn['grn_number']) ?></td>
                            <td><?= e($grn['po_number']) ?></td>
                            <td><?= e($grn['received_date']) ?></td>
                            <td><span class="badge"><?= e($grn['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>

        <section class="card top-space">
            <h3>Matching Exceptions Queue</h3>
            <table>
                <thead><tr><th>Invoice</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($exceptions as $exception): ?>
                    <tr>
                        <td>#<?= e((string) $exception['invoice_id']) ?></td>
                        <td><?= e($exception['reason_code']) ?></td>
                        <td><?= e($exception['status']) ?></td>
                        <td>
                            <?php if ($exception['status'] === 'open'): ?>
                                <form method="post" action="<?= e(base_url($config, 'index.php?page=procurement')) ?>" class="inline-form">
                                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                                    <input type="hidden" name="action" value="resolve_exception">
                                    <input type="hidden" name="exception_id" value="<?= e((string) $exception['id']) ?>">
                                    <input type="hidden" name="invoice_id" value="<?= e((string) $exception['invoice_id']) ?>">
                                    <input type="hidden" name="return_page" value="procurement">
                                    <input type="text" name="resolution_note" placeholder="Resolution note">
                                    <button type="submit">Resolve</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php
    } elseif ($page === 'invoices') {
        $companyId = (int) ($user['company_id'] ?? 0);

        $vendorsStmt = $pdo->prepare('SELECT id, name FROM vendors WHERE company_id = :company_id ORDER BY name ASC');
        $vendorsStmt->execute(['company_id' => $companyId]);
        $vendors = $vendorsStmt->fetchAll();

        $poStmt = $pdo->prepare('SELECT id, po_number FROM purchase_orders WHERE company_id = :company_id ORDER BY id DESC');
        $poStmt->execute(['company_id' => $companyId]);
        $purchaseOrders = $poStmt->fetchAll();

        $grnStmt = $pdo->prepare('SELECT id, grn_number FROM goods_receipts WHERE company_id = :company_id ORDER BY id DESC');
        $grnStmt->execute(['company_id' => $companyId]);
        $grns = $grnStmt->fetchAll();

        $invoicesStmt = $pdo->prepare('SELECT i.id, i.invoice_number, i.invoice_date, i.due_date, i.total_amount, i.source_channel, i.status, i.exception_reason, v.name AS vendor_name
                                       FROM invoices i
                                       JOIN vendors v ON v.id = i.vendor_id
                                       WHERE i.company_id = :company_id
                                       ORDER BY i.id DESC');
        $invoicesStmt->execute(['company_id' => $companyId]);
        ?>
        <div class="grid cols-2 gap-lg">
            <section class="card">
                <h2>Capture Invoice</h2>
                <form method="post" enctype="multipart/form-data" action="<?= e(base_url($config, 'index.php?page=invoices')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                    <input type="hidden" name="action" value="create_invoice">
                    <select name="vendor_id" required>
                        <option value="">Vendor</option>
                        <?php foreach ($vendors as $vendor): ?>
                            <option value="<?= e((string) $vendor['id']) ?>"><?= e($vendor['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="po_id">
                        <option value="">PO (optional)</option>
                        <?php foreach ($purchaseOrders as $po): ?>
                            <option value="<?= e((string) $po['id']) ?>"><?= e($po['po_number']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="grn_id">
                        <option value="">GRN (optional)</option>
                        <?php foreach ($grns as $grn): ?>
                            <option value="<?= e((string) $grn['id']) ?>"><?= e($grn['grn_number']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="invoice_number" placeholder="Invoice number" required>
                    <input type="date" name="invoice_date" value="<?= e(today_utc()) ?>" required>
                    <input type="date" name="due_date" value="<?= e(today_utc()) ?>" required>
                    <select name="source_channel" required>
                        <option value="web">Captured via Web</option>
                        <option value="email">Captured via Email</option>
                        <option value="slack">Captured via Slack</option>
                        <option value="whatsapp">Captured via WhatsApp</option>
                        <option value="mobile">Captured via Mobile</option>
                    </select>
                    <input type="text" name="source_ref" placeholder="Source ref (email/thread/phone)">
                    <input type="text" name="department_code" placeholder="Department code">
                    <label>Upload Invoice</label>
                    <input type="file" name="invoice_file" accept=".pdf,.png,.jpg,.jpeg,.webp">
                    <input type="text" name="document_path" placeholder="Existing object key (optional)">
                    <input type="number" step="0.01" name="subtotal_amount" placeholder="Subtotal" required>
                    <input type="number" step="0.01" name="tax_amount" placeholder="Tax" required>
                    <input type="number" step="0.01" name="total_amount" placeholder="Total" required>
                    <button type="submit">Capture</button>
                </form>
            </section>

            <section class="card">
                <h2>Invoices</h2>
                <table>
                    <thead><tr><th>#</th><th>Vendor</th><th>Channel</th><th>Total</th><th>Status</th><th>Exception</th></tr></thead>
                    <tbody>
                    <?php foreach ($invoicesStmt as $invoice): ?>
                        <tr>
                            <td><?= e($invoice['invoice_number']) ?></td>
                            <td><?= e($invoice['vendor_name']) ?></td>
                            <td><?= e($invoice['source_channel'] ?? 'web') ?></td>
                            <td><?= e(money_format_indian((float) $invoice['total_amount'])) ?></td>
                            <td><span class="badge"><?= e($invoice['status']) ?></span></td>
                            <td><?= e($invoice['exception_reason'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
        <?php
    } elseif ($page === 'expenses') {
        $companyId = (int) ($user['company_id'] ?? 0);

        $claims = $pdo->prepare('SELECT id, category, department_code, source_channel, expense_date, distance_km, mileage_amount, amount, currency_code, status, policy_flags_json
                                 FROM expense_claims
                                 WHERE company_id = :company_id
                                 ORDER BY id DESC');
        $claims->execute(['company_id' => $companyId]);
        ?>
        <div class="grid cols-2 gap-lg">
            <section class="card">
                <h2>Submit Expense</h2>
                <p class="muted">Policy engine validates limits, duplicates, and proof requirements.</p>
                <form method="post" enctype="multipart/form-data" action="<?= e(base_url($config, 'index.php?page=expenses')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                    <input type="hidden" name="action" value="create_expense">
                    <input type="text" name="category" placeholder="Category (Travel, Meals...)" required>
                    <input type="text" name="department_code" placeholder="Department" value="OPS">
                    <input type="date" name="expense_date" value="<?= e(today_utc()) ?>" required>
                    <select name="source_channel" required>
                        <option value="web">Submitted via Web</option>
                        <option value="mobile">Submitted via Mobile</option>
                        <option value="email">Submitted via Email</option>
                        <option value="slack">Submitted via Slack</option>
                        <option value="whatsapp">Submitted via WhatsApp</option>
                    </select>
                    <input type="text" name="source_ref" placeholder="Source ref (optional)">
                    <input type="number" step="0.01" name="amount" placeholder="Amount" required>
                    <input type="text" name="currency_code" value="<?= e((string) $config['app']['currency']) ?>" required>
                    <label>Mileage (optional)</label>
                    <input type="text" name="start_location" placeholder="Start location">
                    <input type="text" name="end_location" placeholder="End location">
                    <div class="inline-form">
                        <input type="number" step="0.01" min="0" id="distance_km" name="distance_km" placeholder="Distance (KM)">
                        <input type="number" step="0.01" min="0" id="mileage_rate" name="mileage_rate" placeholder="Rate/KM">
                        <input type="text" id="mileage_amount_preview" placeholder="Mileage total" readonly>
                    </div>
                    <label>Upload Receipts</label>
                    <input type="file" id="expense_files" name="expense_files[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp">
                    <input type="number" id="proof_count" name="proof_count" min="0" value="0" required>
                    <textarea name="description" rows="4" placeholder="Expense description"></textarea>
                    <button type="submit">Submit Claim</button>
                </form>
            </section>

            <section class="card">
                <h2>Claims Queue</h2>
                <table>
                    <thead><tr><th>ID</th><th>Category</th><th>Channel</th><th>Mileage</th><th>Amount</th><th>Status</th><th>Flags</th></tr></thead>
                    <tbody>
                    <?php foreach ($claims as $claim): ?>
                        <tr>
                            <td>#<?= e((string) $claim['id']) ?></td>
                            <td><?= e($claim['category']) ?></td>
                            <td><?= e($claim['source_channel'] ?? 'web') ?></td>
                            <td><?= e($claim['mileage_amount'] !== null ? money_format_indian((float) $claim['mileage_amount']) : '-') ?></td>
                            <td><?= e(money_format_indian((float) $claim['amount']).' '.$claim['currency_code']) ?></td>
                            <td><span class="badge"><?= e($claim['status']) ?></span></td>
                            <td><?= e($claim['policy_flags_json'] ?: '[]') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
        <?php
    } elseif ($page === 'approvals') {
        $companyId = (int) ($user['company_id'] ?? 0);

        $approvals = $pdo->prepare('SELECT a.id, a.entity_type, a.entity_id, a.level_order, a.status, a.decision_note,
                                           a.due_at, a.escalated_at,
                                           requester.name AS requester_name,
                                           approver.name AS approver_name
                                    FROM approvals a
                                    JOIN users requester ON requester.id = a.requested_by
                                    JOIN users approver ON approver.id = a.approver_user_id
                                    WHERE a.company_id = :company_id
                                    ORDER BY a.id DESC');
        $approvals->execute(['company_id' => $companyId]);

        $rules = $pdo->prepare('SELECT apr.id, apr.entity_type, apr.level_order, apr.min_amount, apr.max_amount,
                                       apr.department_code, apr.sla_hours, apr.reminder_channels_json,
                                       u.name AS approver_name, v.name AS vendor_name
                                FROM approval_policy_rules apr
                                JOIN users u ON u.id = apr.approver_user_id
                                LEFT JOIN vendors v ON v.id = apr.vendor_id
                                WHERE apr.company_id = :company_id
                                ORDER BY apr.entity_type ASC, apr.level_order ASC');
        $rules->execute(['company_id' => $companyId]);

        $usersStmt = $pdo->prepare('SELECT u.id, u.name
                                    FROM company_user cu
                                    JOIN users u ON u.id = cu.user_id
                                    WHERE cu.company_id = :company_id AND cu.status = "active"
                                    ORDER BY u.name ASC');
        $usersStmt->execute(['company_id' => $companyId]);
        $approverUsers = $usersStmt->fetchAll();

        $vendorStmt = $pdo->prepare('SELECT id, name FROM vendors WHERE company_id = :company_id ORDER BY name ASC');
        $vendorStmt->execute(['company_id' => $companyId]);
        $vendors = $vendorStmt->fetchAll();
        ?>
        <div class="grid cols-2 gap-lg">
            <section class="card">
                <h2>Approval Work Queue</h2>
                <form method="post" action="<?= e(base_url($config, 'index.php?page=approvals')) ?>" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                    <input type="hidden" name="action" value="escalate_overdue_approvals">
                    <button type="submit">Run Escalation + Reminders</button>
                </form>
                <table class="top-space">
                    <thead><tr><th>ID</th><th>Entity</th><th>Requester</th><th>Approver</th><th>Due</th><th>Status</th><th>Decision</th></tr></thead>
                    <tbody>
                    <?php foreach ($approvals as $row): ?>
                        <tr>
                            <td><?= e((string) $row['id']) ?></td>
                            <td><?= e($row['entity_type'].' #'.$row['entity_id']) ?></td>
                            <td><?= e($row['requester_name']) ?></td>
                            <td><?= e($row['approver_name']) ?></td>
                            <td><?= e($row['due_at'] ?? '-') ?></td>
                            <td><span class="badge"><?= e($row['status']) ?></span></td>
                            <td>
                                <?php if ($row['status'] === 'pending'): ?>
                                    <form method="post" action="<?= e(base_url($config, 'index.php?page=approvals')) ?>" class="inline-form">
                                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                                        <input type="hidden" name="approval_id" value="<?= e((string) $row['id']) ?>">
                                        <input type="text" name="decision_note" placeholder="Decision note">
                                        <button type="submit" name="action" value="approve">Approve</button>
                                        <button type="submit" name="action" value="reject" class="danger" data-confirm="Reject this request?">Reject</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted"><?= e($row['decision_note'] ?? '-') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h2>Policy Builder</h2>
                <form method="post" action="<?= e(base_url($config, 'index.php?page=approvals')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                    <input type="hidden" name="action" value="create_approval_policy_rule">
                    <select name="entity_type" required>
                        <option value="invoice">Invoice</option>
                        <option value="po">Purchase Order</option>
                        <option value="expense">Expense</option>
                        <option value="payment">Payment</option>
                    </select>
                    <input type="number" name="level_order" min="1" value="1" required>
                    <select name="approver_user_id" required>
                        <option value="">Approver</option>
                        <?php foreach ($approverUsers as $approver): ?>
                            <option value="<?= e((string) $approver['id']) ?>"><?= e($approver['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="inline-form">
                        <input type="number" step="0.01" name="min_amount" placeholder="Min amount" value="0">
                        <input type="number" step="0.01" name="max_amount" placeholder="Max amount" value="9999999999.99">
                    </div>
                    <input type="text" name="department_code" placeholder="Department filter (optional)">
                    <select name="vendor_id">
                        <option value="">Vendor filter (optional)</option>
                        <?php foreach ($vendors as $vendor): ?>
                            <option value="<?= e((string) $vendor['id']) ?>"><?= e($vendor['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" min="1" name="sla_hours" value="24" placeholder="SLA hours">
                    <input type="text" name="reminder_channels" value="email,slack" placeholder="Reminder channels CSV">
                    <button type="submit">Add Rule</button>
                </form>
            </section>
        </div>

        <section class="card top-space">
            <h3>Policy Rules</h3>
            <table>
                <thead><tr><th>Entity</th><th>Level</th><th>Approver</th><th>Amount Range</th><th>Department</th><th>Vendor</th><th>SLA(H)</th><th>Reminders</th></tr></thead>
                <tbody>
                <?php foreach ($rules as $rule): ?>
                    <tr>
                        <td><?= e($rule['entity_type']) ?></td>
                        <td><?= e((string) $rule['level_order']) ?></td>
                        <td><?= e($rule['approver_name']) ?></td>
                        <td><?= e(money_format_indian((float) $rule['min_amount']).' - '.money_format_indian((float) $rule['max_amount'])) ?></td>
                        <td><?= e($rule['department_code'] ?? '-') ?></td>
                        <td><?= e($rule['vendor_name'] ?? '-') ?></td>
                        <td><?= e((string) $rule['sla_hours']) ?></td>
                        <td><?= e((string) ($rule['reminder_channels_json'] ?? '[]')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php
    } elseif ($page === 'payments') {
        $companyId = (int) ($user['company_id'] ?? 0);

        $approvedInvoices = $pdo->prepare('SELECT i.id, i.invoice_number, i.total_amount, v.id AS vendor_id, v.name AS vendor_name
                                           FROM invoices i
                                           JOIN vendors v ON v.id = i.vendor_id
                                           WHERE i.company_id = :company_id
                                             AND i.status = "approved"
                                           ORDER BY i.id DESC');
        $approvedInvoices->execute(['company_id' => $companyId]);

        $payments = $pdo->prepare('SELECT p.id, p.source_id, p.payee_id, p.amount, p.currency_code, p.payment_mode, p.status, p.utr_reference,
                                          p.scheduled_for,
                                          v.name AS vendor_name
                                   FROM payments p
                                   LEFT JOIN vendors v ON v.id = p.payee_id
                                   WHERE p.company_id = :company_id
                                   ORDER BY p.id DESC');
        $payments->execute(['company_id' => $companyId]);

        $batches = $pdo->prepare('SELECT b.id, b.batch_code, b.payment_mode, b.scheduled_for, b.status, b.total_items, b.total_amount,
                                         SUM(CASE WHEN bi.status = "dispatched" THEN 1 ELSE 0 END) AS dispatched_items
                                  FROM payment_batches b
                                  LEFT JOIN payment_batch_items bi ON bi.batch_id = b.id
                                  WHERE b.company_id = :company_id
                                  GROUP BY b.id, b.batch_code, b.payment_mode, b.scheduled_for, b.status, b.total_items, b.total_amount
                                  ORDER BY b.id DESC
                                  LIMIT 100');
        $batches->execute(['company_id' => $companyId]);
        ?>
        <div class="page-panel payments-panel">
        <div class="grid cols-2 gap-lg">
            <section class="card">
                <h2>Create Payment Instruction</h2>
                <p class="muted">Idempotent command + maker-checker enforced + tax hold checks.</p>
                <form method="post" action="<?= e(base_url($config, 'index.php?page=payments')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                    <input type="hidden" name="action" value="create_payment">
                    <select name="source_id" id="source_invoice" required>
                        <option value="">Approved invoice</option>
                        <?php foreach ($approvedInvoices as $invoice): ?>
                            <option value="<?= e((string) $invoice['id']) ?>" data-vendor-id="<?= e((string) $invoice['vendor_id']) ?>" data-amount="<?= e((string) $invoice['total_amount']) ?>">
                                <?= e($invoice['invoice_number'].' | '.$invoice['vendor_name'].' | '.money_format_indian((float) $invoice['total_amount'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="payee_id" id="payee_id" placeholder="Vendor ID" required>
                    <input type="number" step="0.01" name="amount" id="payment_amount" placeholder="Amount" required>
                    <input type="text" name="currency_code" value="<?= e((string) $config['app']['currency']) ?>" required>
                    <select name="payment_mode" required>
                        <option value="NEFT">NEFT</option>
                        <option value="RTGS">RTGS</option>
                        <option value="IMPS">IMPS</option>
                        <option value="UPI">UPI</option>
                    </select>
                    <input type="datetime-local" name="scheduled_for" placeholder="Schedule (optional)">
                    <input type="text" name="idempotency_key" placeholder="Optional idempotency key">
                    <button type="submit">Create Payment</button>
                </form>
            </section>

            <section class="card">
                <h2>Create Payment Batch</h2>
                <p class="muted">Select multiple approved invoices and create a single execution batch.</p>
                <form method="post" action="<?= e(base_url($config, 'index.php?page=payments')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                    <input type="hidden" name="action" value="create_payment_batch">
                    <select name="invoice_ids[]" multiple size="6" required>
                        <?php foreach ($approvedInvoices as $invoice): ?>
                            <option value="<?= e((string) $invoice['id']) ?>">
                                <?= e($invoice['invoice_number'].' | '.$invoice['vendor_name'].' | '.money_format_indian((float) $invoice['total_amount'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="payment_mode" required>
                        <option value="NEFT">NEFT</option>
                        <option value="RTGS">RTGS</option>
                        <option value="IMPS">IMPS</option>
                        <option value="UPI">UPI</option>
                    </select>
                    <input type="text" name="currency_code" value="<?= e((string) $config['app']['currency']) ?>" required>
                    <input type="datetime-local" name="scheduled_for" placeholder="Schedule (optional)">
                    <button type="submit">Create Batch</button>
                </form>
            </section>
        </div>

        <div class="grid cols-2 gap-lg top-space">
            <section class="card">
                <h2>Payment Queue</h2>
                <table>
                    <thead><tr><th>ID</th><th>Vendor</th><th>Amount</th><th>Status</th><th>Schedule</th><th>UTR</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td>#<?= e((string) $payment['id']) ?></td>
                            <td><?= e($payment['vendor_name'] ?? ('Vendor #'.$payment['payee_id'])) ?></td>
                            <td><?= e(money_format_indian((float) $payment['amount']).' '.$payment['currency_code']) ?></td>
                            <td><span class="badge"><?= e($payment['status']) ?></span></td>
                            <td><?= e($payment['scheduled_for'] ?? 'Immediate') ?></td>
                            <td><?= e($payment['utr_reference'] ?? '-') ?></td>
                            <td>
                                <?php if ($payment['status'] === 'approved'): ?>
                                    <form method="post" action="<?= e(base_url($config, 'index.php?page=payments')) ?>">
                                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                                        <input type="hidden" name="action" value="execute_payment">
                                        <input type="hidden" name="payment_id" value="<?= e((string) $payment['id']) ?>">
                                        <button type="submit">Execute</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h2>Batch Command Center</h2>
                <table>
                    <thead><tr><th>Batch</th><th>Mode</th><th>Items</th><th>Dispatched</th><th>Total</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($batches as $batch): ?>
                        <tr>
                            <td><?= e($batch['batch_code']) ?></td>
                            <td><?= e($batch['payment_mode']) ?></td>
                            <td><?= e((string) $batch['total_items']) ?></td>
                            <td><?= e((string) ($batch['dispatched_items'] ?? 0)) ?></td>
                            <td><?= e(money_format_indian((float) $batch['total_amount'])) ?></td>
                            <td><span class="badge"><?= e($batch['status']) ?></span></td>
                            <td>
                                <form method="post" action="<?= e(base_url($config, 'index.php?page=payments')) ?>">
                                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                                    <input type="hidden" name="action" value="dispatch_payment_batch">
                                    <input type="hidden" name="batch_id" value="<?= e((string) $batch['id']) ?>">
                                    <button type="submit">Dispatch</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
        </div>
        <?php
    } elseif ($page === 'connected-banking') {
        $title = 'Connected Banking';
        $companyId = (int) ($user['company_id'] ?? 0);

        $accounts = $pdo->prepare('SELECT id, bank_name, account_number_masked, balance, status
                                   FROM company_accounts
                                   WHERE company_id = :company_id
                                   ORDER BY id ASC');
        $accounts->execute(['company_id' => $companyId]);

        $statusSplit = $pdo->prepare('SELECT status, COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount
                                      FROM payments
                                      WHERE company_id = :company_id
                                      GROUP BY status
                                      ORDER BY total DESC');
        $statusSplit->execute(['company_id' => $companyId]);

        $paymentModes = $pdo->prepare('SELECT payment_mode, COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount
                                       FROM payments
                                       WHERE company_id = :company_id
                                       GROUP BY payment_mode
                                       ORDER BY amount DESC');
        $paymentModes->execute(['company_id' => $companyId]);
        ?>
        <div class="page-panel">
            <section class="section-head">
                <h2>Connected Banking</h2>
                <p class="muted">Manage company bank accounts and monitor payout throughput across all rails.</p>
            </section>

            <div class="grid cols-2 gap-lg top-space">
                <section class="card">
                    <h3>Company Accounts</h3>
                    <table>
                        <thead><tr><th>Bank</th><th>Account</th><th>Balance</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <tr>
                                <td><?= e($account['bank_name']) ?></td>
                                <td><?= e($account['account_number_masked']) ?></td>
                                <td><?= e(money_format_indian((float) $account['balance'])) ?></td>
                                <td><span class="badge"><?= e($account['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <section class="card">
                    <h3>Payment Rail Mix</h3>
                    <table>
                        <thead><tr><th>Mode</th><th>Count</th><th>Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($paymentModes as $mode): ?>
                            <tr>
                                <td><?= e($mode['payment_mode']) ?></td>
                                <td><?= e((string) $mode['total']) ?></td>
                                <td><?= e(money_format_indian((float) $mode['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <h3 class="top-space">Status Overview</h3>
                    <table>
                        <thead><tr><th>Status</th><th>Count</th><th>Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($statusSplit as $status): ?>
                            <tr>
                                <td><?= e($status['status']) ?></td>
                                <td><?= e((string) $status['total']) ?></td>
                                <td><?= e(money_format_indian((float) $status['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
        <?php
    } elseif ($page === 'cards') {
        $title = 'Cards';
        $companyId = (int) ($user['company_id'] ?? 0);

        $usersStmt = $pdo->prepare('SELECT u.id, u.name
                                    FROM company_user cu
                                    JOIN users u ON u.id = cu.user_id
                                    WHERE cu.company_id = :company_id
                                      AND cu.status = "active"
                                    ORDER BY u.name ASC');
        $usersStmt->execute(['company_id' => $companyId]);
        $members = $usersStmt->fetchAll();

        $cards = $pdo->prepare('SELECT c.id, c.card_name, c.card_type, c.last4, c.spending_limit, c.receipt_required, c.status, c.mcc_controls_json, c.created_at,
                                       u.name AS assigned_user_name
                                FROM corporate_cards c
                                LEFT JOIN users u ON u.id = c.assigned_user_id
                                WHERE c.company_id = :company_id
                                ORDER BY c.id DESC
                                LIMIT 200');
        $cards->execute(['company_id' => $companyId]);
        ?>
        <div class="page-panel">
            <section class="section-head">
                <h2>Corporate Cards</h2>
                <p class="muted">Issue unlimited virtual or physical cards with spend limits and MCC-level controls.</p>
            </section>

            <div class="grid cols-2 gap-lg top-space">
                <section class="card">
                    <h3>Issue Card</h3>
                    <form method="post" action="<?= e(base_url($config, 'index.php?page=cards')) ?>">
                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                        <input type="hidden" name="action" value="create_corporate_card">
                        <input type="text" name="card_name" placeholder="Card name (e.g. Sales Travel Card)" required>
                        <select name="card_type" required>
                            <option value="virtual">Virtual</option>
                            <option value="physical">Physical</option>
                        </select>
                        <select name="assigned_user_id">
                            <option value="">Assign user (optional)</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?= e((string) $member['id']) ?>"><?= e($member['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" step="0.01" min="0" name="spending_limit" placeholder="Spending limit" required>
                        <input type="text" name="mcc_controls" placeholder="MCC controls CSV (e.g. 3000,4111,5812)">
                        <select name="receipt_required">
                            <option value="1">Mandatory receipt uploads</option>
                            <option value="0">Receipt optional</option>
                        </select>
                        <button type="submit">Issue Card</button>
                    </form>
                </section>

                <section class="card">
                    <h3>Issued Cards</h3>
                    <table>
                        <thead><tr><th>Card</th><th>Assigned</th><th>Type</th><th>Limit</th><th>MCC</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($cards as $card): ?>
                            <tr>
                                <td><?= e($card['card_name'].' ••••'.$card['last4']) ?></td>
                                <td><?= e($card['assigned_user_name'] ?? 'Unassigned') ?></td>
                                <td><?= e($card['card_type']) ?></td>
                                <td><?= e(money_format_indian((float) $card['spending_limit'])) ?></td>
                                <td><?= e((string) ($card['mcc_controls_json'] ?? '[]')) ?></td>
                                <td><span class="badge"><?= e($card['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
        <?php
    } elseif ($page === 'credit-line') {
        $title = 'Credit Line';
        $companyId = (int) ($user['company_id'] ?? 0);

        $lineStmt = $pdo->prepare('SELECT *
                                   FROM credit_lines
                                   WHERE company_id = :company_id
                                   LIMIT 1');
        $lineStmt->execute(['company_id' => $companyId]);
        $line = $lineStmt->fetch() ?: [
            'provider_name' => 'Working Capital Partner',
            'sanctioned_limit' => 0,
            'utilized_amount' => 0,
            'available_amount' => 0,
            'interest_rate_apr' => 0,
            'status' => 'active',
        ];

        $utilization = ((float) $line['sanctioned_limit']) > 0
            ? min(100, max(0, (int) round(((float) $line['utilized_amount'] / (float) $line['sanctioned_limit']) * 100)))
            : 0;
        ?>
        <div class="page-panel">
            <section class="section-head">
                <h2>Credit Line</h2>
                <p class="muted">Track sanctioned working capital, utilization, and instantly available limits.</p>
            </section>

            <div class="grid cols-2 gap-lg top-space">
                <section class="card">
                    <h3>Facility Snapshot</h3>
                    <div class="kpis">
                        <article class="kpi"><span>Sanctioned</span><strong><?= e(money_format_indian((float) $line['sanctioned_limit'])) ?></strong></article>
                        <article class="kpi warning"><span>Utilized</span><strong><?= e(money_format_indian((float) $line['utilized_amount'])) ?></strong></article>
                        <article class="kpi"><span>Available</span><strong><?= e(money_format_indian((float) $line['available_amount'])) ?></strong></article>
                        <article class="kpi"><span>APR</span><strong><?= e((string) $line['interest_rate_apr']) ?>%</strong></article>
                    </div>
                    <div class="top-space">
                        <p class="muted">Utilization: <?= e((string) $utilization) ?>%</p>
                        <div class="meter"><span style="width: <?= e((string) $utilization) ?>%"></span></div>
                    </div>
                </section>

                <section class="card">
                    <h3>Update Credit Facility</h3>
                    <form method="post" action="<?= e(base_url($config, 'index.php?page=credit-line')) ?>">
                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                        <input type="hidden" name="action" value="save_credit_line">
                        <input type="text" name="provider_name" value="<?= e((string) $line['provider_name']) ?>" placeholder="Provider name" required>
                        <input type="number" step="0.01" min="0" name="sanctioned_limit" value="<?= e((string) $line['sanctioned_limit']) ?>" placeholder="Sanctioned limit" required>
                        <input type="number" step="0.01" min="0" name="utilized_amount" value="<?= e((string) $line['utilized_amount']) ?>" placeholder="Utilized amount" required>
                        <input type="number" step="0.01" min="0" name="interest_rate_apr" value="<?= e((string) $line['interest_rate_apr']) ?>" placeholder="Interest APR %" required>
                        <select name="status">
                            <option value="active" <?= $line['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="paused" <?= $line['status'] === 'paused' ? 'selected' : '' ?>>Paused</option>
                            <option value="closed" <?= $line['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                        <button type="submit">Save Credit Line</button>
                    </form>
                </section>
            </div>
        </div>
        <?php
    } elseif ($page === 'upi') {
        $title = 'UPI';
        $companyId = (int) ($user['company_id'] ?? 0);

        $walletStmt = $pdo->prepare('SELECT *
                                     FROM upi_wallets
                                     WHERE company_id = :company_id
                                     LIMIT 1');
        $walletStmt->execute(['company_id' => $companyId]);
        $wallet = $walletStmt->fetch() ?: [
            'virtual_account' => '',
            'daily_limit' => 0,
            'monthly_limit' => 0,
            'used_today' => 0,
            'used_month' => 0,
            'status' => 'active',
        ];

        $upiSpend = $pdo->prepare('SELECT COUNT(*) AS total_txns, COALESCE(SUM(amount), 0) AS total_amount
                                   FROM payments
                                   WHERE company_id = :company_id
                                     AND payment_mode = "UPI"');
        $upiSpend->execute(['company_id' => $companyId]);
        $upiStats = $upiSpend->fetch() ?: ['total_txns' => 0, 'total_amount' => 0];
        ?>
        <div class="page-panel">
            <section class="section-head">
                <h2>UPI Wallet</h2>
                <p class="muted">Use one virtual account for petty cash and vendor payouts with enforceable limits.</p>
            </section>

            <div class="grid cols-2 gap-lg top-space">
                <section class="card">
                    <h3>Wallet Controls</h3>
                    <form method="post" action="<?= e(base_url($config, 'index.php?page=upi')) ?>">
                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                        <input type="hidden" name="action" value="save_upi_wallet">
                        <input type="text" name="virtual_account" value="<?= e((string) $wallet['virtual_account']) ?>" placeholder="Virtual account (e.g. acme@upi)" required>
                        <input type="number" step="0.01" min="0" name="daily_limit" value="<?= e((string) $wallet['daily_limit']) ?>" placeholder="Daily limit" required>
                        <input type="number" step="0.01" min="0" name="monthly_limit" value="<?= e((string) $wallet['monthly_limit']) ?>" placeholder="Monthly limit" required>
                        <select name="status">
                            <option value="active" <?= $wallet['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="paused" <?= $wallet['status'] === 'paused' ? 'selected' : '' ?>>Paused</option>
                        </select>
                        <button type="submit">Save Wallet</button>
                    </form>
                </section>

                <section class="card">
                    <h3>UPI Activity</h3>
                    <div class="kpis">
                        <article class="kpi"><span>Total UPI Txns</span><strong><?= e((string) $upiStats['total_txns']) ?></strong></article>
                        <article class="kpi"><span>Total UPI Amount</span><strong><?= e(money_format_indian((float) $upiStats['total_amount'])) ?></strong></article>
                        <article class="kpi warning"><span>Used Today</span><strong><?= e(money_format_indian((float) $wallet['used_today'])) ?></strong></article>
                        <article class="kpi"><span>Used Month</span><strong><?= e(money_format_indian((float) $wallet['used_month'])) ?></strong></article>
                    </div>
                    <p class="muted top-space">Mandatory documentation for each UPI transfer can be enforced from policy workflows.</p>
                </section>
            </div>
        </div>
        <?php
    } elseif ($page === 'bulk-payout') {
        $title = 'Bulk Payout';
        $companyId = (int) ($user['company_id'] ?? 0);

        $approvedInvoices = $pdo->prepare('SELECT i.id, i.invoice_number, i.total_amount, v.name AS vendor_name
                                           FROM invoices i
                                           JOIN vendors v ON v.id = i.vendor_id
                                           WHERE i.company_id = :company_id
                                             AND i.status = "approved"
                                           ORDER BY i.id DESC');
        $approvedInvoices->execute(['company_id' => $companyId]);

        $batches = $pdo->prepare('SELECT b.id, b.batch_code, b.payment_mode, b.status, b.total_items, b.total_amount, b.scheduled_for,
                                         SUM(CASE WHEN bi.status = "dispatched" THEN 1 ELSE 0 END) AS dispatched_items
                                  FROM payment_batches b
                                  LEFT JOIN payment_batch_items bi ON bi.batch_id = b.id
                                  WHERE b.company_id = :company_id
                                  GROUP BY b.id, b.batch_code, b.payment_mode, b.status, b.total_items, b.total_amount, b.scheduled_for
                                  ORDER BY b.id DESC
                                  LIMIT 100');
        $batches->execute(['company_id' => $companyId]);
        ?>
        <div class="page-panel">
            <section class="section-head">
                <h2>Bulk Payout</h2>
                <p class="muted">Create and dispatch payout batches to multiple vendors with maker-checker controls.</p>
            </section>

            <div class="grid cols-2 gap-lg top-space">
                <section class="card">
                    <h3>Create Batch</h3>
                    <form method="post" action="<?= e(base_url($config, 'index.php?page=bulk-payout')) ?>">
                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                        <input type="hidden" name="action" value="create_payment_batch">
                        <input type="hidden" name="redirect_page" value="bulk-payout">
                        <select name="invoice_ids[]" multiple size="8" required>
                            <?php foreach ($approvedInvoices as $invoice): ?>
                                <option value="<?= e((string) $invoice['id']) ?>">
                                    <?= e($invoice['invoice_number'].' | '.$invoice['vendor_name'].' | '.money_format_indian((float) $invoice['total_amount'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="payment_mode" required>
                            <option value="NEFT">NEFT</option>
                            <option value="RTGS">RTGS</option>
                            <option value="IMPS">IMPS</option>
                            <option value="UPI">UPI</option>
                        </select>
                        <input type="text" name="currency_code" value="<?= e((string) $config['app']['currency']) ?>" required>
                        <input type="datetime-local" name="scheduled_for" placeholder="Schedule (optional)">
                        <button type="submit">Create Batch</button>
                    </form>
                </section>

                <section class="card">
                    <h3>Batch Queue</h3>
                    <table>
                        <thead><tr><th>Batch</th><th>Mode</th><th>Items</th><th>Dispatched</th><th>Total</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($batches as $batch): ?>
                            <tr>
                                <td><?= e($batch['batch_code']) ?></td>
                                <td><?= e($batch['payment_mode']) ?></td>
                                <td><?= e((string) $batch['total_items']) ?></td>
                                <td><?= e((string) ($batch['dispatched_items'] ?? 0)) ?></td>
                                <td><?= e(money_format_indian((float) $batch['total_amount'])) ?></td>
                                <td><span class="badge"><?= e($batch['status']) ?></span></td>
                                <td>
                                    <form method="post" action="<?= e(base_url($config, 'index.php?page=bulk-payout')) ?>">
                                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                                        <input type="hidden" name="action" value="dispatch_payment_batch">
                                        <input type="hidden" name="redirect_page" value="bulk-payout">
                                        <input type="hidden" name="batch_id" value="<?= e((string) $batch['id']) ?>">
                                        <button type="submit">Dispatch</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
        <?php
    } elseif ($page === 'tax') {
        $companyId = (int) ($user['company_id'] ?? 0);

        $runs = $pdo->prepare('SELECT tr.id, tr.invoice_id, tr.match_status, tr.recommendation, tr.hold_reason, tr.tax_period, tr.run_at, v.name AS vendor_name, v.compliance_score
                               FROM tax_reconciliations tr
                               JOIN invoices i ON i.id = tr.invoice_id
                               JOIN vendors v ON v.id = i.vendor_id
                               WHERE tr.company_id = :company_id
                               ORDER BY tr.id DESC
                               LIMIT 200');
        $runs->execute(['company_id' => $companyId]);

        $vendorRisk = $pdo->prepare('SELECT v.name,
                                            v.compliance_score,
                                            SUM(CASE WHEN tr.recommendation = "hold" THEN 1 ELSE 0 END) AS hold_count,
                                            COUNT(tr.id) AS total_runs
                                     FROM vendors v
                                     LEFT JOIN invoices i ON i.vendor_id = v.id AND i.company_id = v.company_id
                                     LEFT JOIN tax_reconciliations tr ON tr.invoice_id = i.id AND tr.company_id = v.company_id
                                     WHERE v.company_id = :company_id
                                     GROUP BY v.id, v.name, v.compliance_score
                                     ORDER BY hold_count DESC, v.compliance_score ASC
                                     LIMIT 10');
        $vendorRisk->execute(['company_id' => $companyId]);

        $periodTrend = $pdo->prepare('SELECT tax_period,
                                             SUM(CASE WHEN recommendation = "release" THEN 1 ELSE 0 END) AS release_count,
                                             SUM(CASE WHEN recommendation = "hold" THEN 1 ELSE 0 END) AS hold_count
                                      FROM tax_reconciliations
                                      WHERE company_id = :company_id
                                      GROUP BY tax_period
                                      ORDER BY tax_period DESC
                                      LIMIT 12');
        $periodTrend->execute(['company_id' => $companyId]);
        ?>
        <div class="grid cols-2 gap-lg">
            <section class="card">
                <h2>Tax Reconciliation</h2>
                <p class="muted">Pluggable tax engine with release/hold decisions and payment blocking input.</p>
                <form method="post" action="<?= e(base_url($config, 'index.php?page=tax')) ?>" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                    <input type="hidden" name="action" value="run_tax_reconciliation">
                    <button type="submit">Run Reconciliation</button>
                </form>

                <table class="top-space">
                    <thead><tr><th>ID</th><th>Invoice</th><th>Vendor</th><th>Match</th><th>Decision</th><th>Reason</th><th>Run At</th></tr></thead>
                    <tbody>
                    <?php foreach ($runs as $row): ?>
                        <tr>
                            <td>#<?= e((string) $row['id']) ?></td>
                            <td>#<?= e((string) $row['invoice_id']) ?></td>
                            <td><?= e($row['vendor_name']) ?></td>
                            <td><?= e($row['match_status']) ?></td>
                            <td><span class="badge <?= e($row['recommendation'] === 'hold' ? 'danger' : 'success') ?>"><?= e($row['recommendation']) ?></span></td>
                            <td><?= e($row['hold_reason'] ?? '-') ?></td>
                            <td><?= e($row['run_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h2>Vendor Compliance Cockpit</h2>
                <table>
                    <thead><tr><th>Vendor</th><th>Score</th><th>Hold Runs</th><th>Total Runs</th></tr></thead>
                    <tbody>
                    <?php foreach ($vendorRisk as $risk): ?>
                        <tr>
                            <td><?= e($risk['name']) ?></td>
                            <td><?= e((string) $risk['compliance_score']) ?></td>
                            <td><?= e((string) $risk['hold_count']) ?></td>
                            <td><?= e((string) $risk['total_runs']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h3 class="top-space">Period Trend</h3>
                <table>
                    <thead><tr><th>Period</th><th>Release</th><th>Hold</th></tr></thead>
                    <tbody>
                    <?php foreach ($periodTrend as $trend): ?>
                        <tr>
                            <td><?= e($trend['tax_period']) ?></td>
                            <td><?= e((string) $trend['release_count']) ?></td>
                            <td><?= e((string) $trend['hold_count']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
        <?php
    } elseif ($page === 'notifications') {
        $companyId = (int) ($user['company_id'] ?? 0);

        $notifications = $pdo->prepare('SELECT id, channel, subject, message_text, status, created_at
                                        FROM notifications
                                        WHERE company_id = :company_id
                                        ORDER BY id DESC
                                        LIMIT 200');
        $notifications->execute(['company_id' => $companyId]);
        ?>
        <div class="grid cols-2 gap-lg">
            <section class="card">
                <h2>Queue Notification</h2>
                <form method="post" action="<?= e(base_url($config, 'index.php?page=notifications')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                    <input type="hidden" name="action" value="queue_notification">
                    <select name="channel" required>
                        <option value="email">Email</option>
                        <option value="slack">Slack</option>
                        <option value="whatsapp">WhatsApp</option>
                    </select>
                    <input type="text" name="subject" placeholder="Subject" required>
                    <textarea name="message_text" rows="4" placeholder="Message" required></textarea>
                    <button type="submit">Queue</button>
                </form>
            </section>

            <section class="card">
                <h2>Outbox</h2>
                <table>
                    <thead><tr><th>Channel</th><th>Subject</th><th>Status</th><th>Created</th></tr></thead>
                    <tbody>
                    <?php foreach ($notifications as $notification): ?>
                        <tr>
                            <td><?= e($notification['channel']) ?></td>
                            <td><?= e($notification['subject'] ?? '-') ?></td>
                            <td><?= e($notification['status']) ?></td>
                            <td><?= e($notification['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
        <?php
    } elseif ($page === 'integrations') {
        $title = 'Settings & Integrations';
        $companyId = (int) ($user['company_id'] ?? 0);

        $catalog = web_integration_catalog();
        $rowsStmt = $pdo->prepare('SELECT id, provider_key, provider_name, category, status, connection_meta_json, connected_at, updated_at
                                   FROM company_integrations
                                   WHERE company_id = :company_id');
        $rowsStmt->execute(['company_id' => $companyId]);
        $integrationRows = $rowsStmt->fetchAll();

        $integrationByKey = [];
        foreach ($integrationRows as $row) {
            $integrationByKey[(string) $row['provider_key']] = $row;
        }

        $providers = [];
        foreach ($catalog as $provider) {
            $key = (string) $provider['provider_key'];
            $row = $integrationByKey[$key] ?? null;
            $meta = json_decode((string) ($row['connection_meta_json'] ?? '{}'), true);
            if (! is_array($meta)) {
                $meta = [];
            }
            $runtime = web_integration_runtime_state($key);
            $storedConfig = IntegrationRuntimeConfig::readEnvFromMeta($meta);
            $fields = web_integration_config_fields($key);
            $configFields = [];
            $configuredCount = 0;
            $oauthMeta = is_array($meta['oauth'] ?? null) ? $meta['oauth'] : [];
            $oauthSupported = IntegrationOAuth::isSupportedProvider($key);
            $oauthConnected = (bool) ($oauthMeta['connected'] ?? false);
            $displayStatus = (string) ($row['status'] ?? $provider['default_status']);
            if ($oauthSupported) {
                $displayStatus = $oauthConnected ? 'active' : 'disabled';
            }

            foreach ($fields as $field) {
                $envKey = trim((string) ($field['key'] ?? ''));
                if ($envKey === '') {
                    continue;
                }

                $isSensitive = (bool) ($field['sensitive'] ?? false);
                $savedValue = trim((string) ($storedConfig[$envKey] ?? ''));
                if ($savedValue !== '') {
                    $configuredCount++;
                }

                $prefill = $savedValue;
                if ($prefill === '' && ! $isSensitive) {
                    $prefill = trim((string) ($field['default'] ?? ''));
                }

                $configFields[] = [
                    'key' => $envKey,
                    'label' => (string) ($field['label'] ?? $envKey),
                    'help' => (string) ($field['help'] ?? ''),
                    'placeholder' => (string) ($field['placeholder'] ?? ''),
                    'sensitive' => $isSensitive,
                    'value' => $isSensitive ? '' : $prefill,
                    'has_value' => $savedValue !== '',
                    'masked_value' => $isSensitive ? web_mask_secret_value($savedValue) : $savedValue,
                ];
            }

            $providers[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : 0,
                'provider_key' => $key,
                'provider_name' => (string) $provider['provider_name'],
                'category' => (string) $provider['category'],
                'connect_url' => (string) ($provider['connect_url'] ?? ''),
                'icon_url' => web_integration_icon_url($key),
                'icon_label' => web_integration_icon_label($key, (string) $provider['provider_name']),
                'description' => (string) ($meta['description'] ?? $provider['description']),
                'status' => $displayStatus,
                'connected_at' => $row['connected_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
                'invoice_forwarding_alias' => $meta['invoice_forwarding_alias'] ?? null,
                'runtime_mode' => (string) ($runtime['mode'] ?? 'stub'),
                'runtime_label' => (string) ($runtime['label'] ?? 'Stub'),
                'runtime_hint' => (string) ($runtime['hint'] ?? ''),
                'last_test_status' => (string) ($meta['last_test_status'] ?? ''),
                'last_tested_at' => (string) ($meta['last_tested_at'] ?? ''),
                'last_test_summary' => (string) ($meta['last_test_summary'] ?? ''),
                'config_fields' => $configFields,
                'configured_count' => $configuredCount,
                'config_total' => count($configFields),
                'config_updated_at' => (string) ($meta['config_updated_at'] ?? ''),
                'oauth_supported' => $oauthSupported,
                'oauth_connected' => $oauthConnected,
                'oauth_connected_account' => (string) ($oauthMeta['connected_account'] ?? ''),
                'oauth_last_error' => (string) ($oauthMeta['last_error'] ?? ''),
            ];
        }

        $selectedProviderKey = trim((string) ($_GET['provider'] ?? ''));
        $selectedProvider = null;
        if ($selectedProviderKey !== '') {
            foreach ($providers as $provider) {
                if ((string) $provider['provider_key'] === $selectedProviderKey) {
                    $selectedProvider = $provider;
                    break;
                }
            }
        }
        if ($selectedProvider === null && $providers !== []) {
            $selectedProvider = $providers[0];
            $selectedProviderKey = (string) $selectedProvider['provider_key'];
        }
        $oauthCallbackUri = IntegrationOAuth::callbackUri($config);

        $jobs = $pdo->prepare('SELECT id, provider, job_type, status, attempts, run_at, updated_at, last_error
                               FROM integration_jobs
                               WHERE company_id = :company_id OR company_id IS NULL
                               ORDER BY id DESC
                               LIMIT 100');
        $jobs->execute(['company_id' => $companyId]);

        $webhooks = $pdo->query('SELECT id, provider, event_name, status, received_at, processed_at
                                 FROM webhook_events
                                 ORDER BY id DESC
                                 LIMIT 60');
        $captureEvents = $pdo->prepare('SELECT id, entity_type, entity_id, source_channel, source_ref, created_at
                                        FROM capture_events
                                        WHERE company_id = :company_id
                                        ORDER BY id DESC
                                        LIMIT 60');
        $captureEvents->execute(['company_id' => $companyId]);
        ?>
        <div class="page-panel settings-page">
            <section class="section-head">
                <h2>Settings - Integrations</h2>
                <p class="muted">Connect capture, ERP, identity, and communication providers for your company workflow.</p>
            </section>

            <div class="settings-shell top-space">
                <aside class="settings-nav card">
                    <a href="<?= e(base_url($config, 'index.php?page=dashboard')) ?>" class="settings-back">← Back</a>
                    <nav>
                        <a href="<?= e(base_url($config, 'index.php?page=integrations')) ?>" class="active">Integration</a>
                        <a href="<?= e(base_url($config, 'index.php?page=vendors')) ?>">Entities</a>
                        <a href="<?= e(base_url($config, 'index.php?page=approvals')) ?>">Company Policies</a>
                        <a href="<?= e(base_url($config, 'index.php?page=tax')) ?>">Taxation</a>
                        <a href="<?= e(base_url($config, 'index.php?page=notifications')) ?>">Alerts</a>
                        <a href="<?= e(base_url($config, 'index.php?page=reports')) ?>">Configurations</a>
                    </nav>
                </aside>

                <div class="settings-content">
                    <section id="provider-setup" class="card">
                        <h3><?= e($selectedProvider !== null ? 'Connect '.$selectedProvider['provider_name'] : 'Connect Provider') ?></h3>
                        <p class="muted">Connect with your provider account. Admin setup is only needed once if this provider requires app credentials.</p>
                        <?php if ($selectedProvider !== null): ?>
                            <div class="integration-provider-head top-space">
                                <div class="integration-provider-icon provider-icon-<?= e($selectedProvider['provider_key']) ?>" aria-label="<?= e($selectedProvider['provider_name']) ?> icon">
                                    <?php if ($selectedProvider['icon_url'] !== ''): ?>
                                        <img src="<?= e($selectedProvider['icon_url']) ?>" alt="" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false">
                                        <span hidden><?= e((string) $selectedProvider['icon_label']) ?></span>
                                    <?php else: ?>
                                        <span><?= e((string) $selectedProvider['icon_label']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h4><?= e($selectedProvider['provider_name']) ?></h4>
                                    <div class="integration-badge-row">
                                        <span class="badge <?= e($selectedProvider['status'] === 'active' ? 'success' : '') ?>"><?= e($selectedProvider['status'] === 'active' ? 'Active' : 'Disabled') ?></span>
                                        <?php if ($selectedProvider['oauth_supported']): ?>
                                            <span class="badge <?= e($selectedProvider['oauth_connected'] ? 'success' : 'danger') ?>"><?= e($selectedProvider['oauth_connected'] ? 'Connected' : 'Not Connected') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="integration-setup-actions top-space">
                                <?php if ($selectedProvider['oauth_supported']): ?>
                                    <form method="post" action="<?= e(base_url($config, 'index.php?page=integrations')) ?>" class="integration-action-form">
                                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                                        <input type="hidden" name="action" value="start_integration_oauth">
                                        <input type="hidden" name="provider_key" value="<?= e($selectedProvider['provider_key']) ?>">
                                        <button type="submit"><?= e($selectedProvider['oauth_connected'] ? 'Reconnect '.$selectedProvider['provider_name'] : 'Connect '.$selectedProvider['provider_name']) ?></button>
                                    </form>
                                <?php elseif ($selectedProvider['connect_url'] !== ''): ?>
                                    <a class="button-link" href="<?= e($selectedProvider['connect_url']) ?>" target="_blank" rel="noopener noreferrer">Open <?= e($selectedProvider['provider_name']) ?> Console</a>
                                <?php endif; ?>
                            </div>

                            <details class="integration-admin-setup top-space">
                                <summary>Admin setup</summary>
                                <div class="integration-admin-body">
                                    <?php if ($selectedProvider['oauth_supported']): ?>
                                        <div class="integration-inline-meta">
                                            <span>OAuth Callback URL</span>
                                            <strong><code class="integration-callback-code"><?= e($oauthCallbackUri) ?></code></strong>
                                            <small>Add this redirect URI once in your <?= e($selectedProvider['provider_name']) ?> app settings.</small>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($selectedProvider['connect_url'] !== ''): ?>
                                        <div class="integration-inline-meta">
                                            <span>Provider Console</span>
                                            <strong><a class="integration-external-link" href="<?= e($selectedProvider['connect_url']) ?>" target="_blank" rel="noopener noreferrer">Open <?= e($selectedProvider['provider_name']) ?> ↗</a></strong>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($selectedProvider['config_total'] > 0): ?>
                                        <section class="integration-config-panel">
                                    <div class="integration-inline-meta">
                                        <span>In-App Credential Vault</span>
                                        <strong><?= e((string) $selectedProvider['configured_count']) ?> / <?= e((string) $selectedProvider['config_total']) ?> keys configured</strong>
                                        <?php if ($selectedProvider['config_updated_at'] !== ''): ?>
                                            <small>Updated at <?= e($selectedProvider['config_updated_at']) ?> UTC</small>
                                        <?php endif; ?>
                                    </div>
                                    <form method="post" action="<?= e(base_url($config, 'index.php?page=integrations&provider='.$selectedProvider['provider_key'].'#provider-setup')) ?>" class="integration-action-form integration-config-form">
                                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                                        <input type="hidden" name="action" value="save_integration_credentials">
                                        <input type="hidden" name="provider_key" value="<?= e($selectedProvider['provider_key']) ?>">
                                        <?php foreach ($selectedProvider['config_fields'] as $field): ?>
                                            <div class="integration-config-field">
                                                <label><?= e((string) $field['label']) ?><?php if ($field['sensitive']): ?> <span class="badge">Secret</span><?php endif; ?></label>
                                                <input
                                                    type="<?= e($field['sensitive'] ? 'password' : 'text') ?>"
                                                    name="cfg[<?= e((string) $field['key']) ?>]"
                                                    value="<?= e((string) $field['value']) ?>"
                                                    placeholder="<?= e($field['placeholder'] !== '' ? (string) $field['placeholder'] : (string) $field['key']) ?>"
                                                    autocomplete="<?= e($field['sensitive'] ? 'new-password' : 'off') ?>"
                                                >
                                                <?php if ($field['sensitive'] && $field['has_value']): ?>
                                                    <small>Saved: <?= e((string) $field['masked_value']) ?>. Leave blank to keep current secret.</small>
                                                <?php elseif ($field['help'] !== ''): ?>
                                                    <small><?= e((string) $field['help']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <button type="submit" class="ghost">Save Credentials</button>
                                    </form>
                                        </section>
                                    <?php else: ?>
                                        <div class="integration-inline-meta">
                                            <span>Credential Vault</span>
                                            <strong>No manual keys required for this connector.</strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </details>
                        <?php else: ?>
                            <div class="integration-inline-meta top-space">
                                <span>Provider Setup</span>
                                <strong>Select a provider from Manage to configure.</strong>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="card">
                        <h3>Provider Marketplace</h3>
                        <div class="integration-provider-grid top-space">
                            <?php if ($providers === []): ?>
                                <article class="integration-provider-card glow-card is-disabled" data-glow>
                                    <div class="integration-provider-head">
                                        <div class="integration-provider-icon">!</div>
                                        <div>
                                            <h4>No Integrations Loaded</h4>
                                            <div class="integration-badge-row">
                                                <span class="badge danger">Catalog Missing</span>
                                            </div>
                                        </div>
                                    </div>
                                    <p>Integration providers were not loaded for this company. Click the button below to sync and activate the full catalog.</p>
                                    <form method="post" action="<?= e(base_url($config, 'index.php?page=integrations')) ?>" class="integration-action-form">
                                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                                        <input type="hidden" name="action" value="link_all_integrations">
                                        <button type="submit">Load Integration Catalog</button>
                                    </form>
                                </article>
                            <?php endif; ?>
                            <?php foreach ($providers as $provider): ?>
                                <article id="integration-<?= e($provider['provider_key']) ?>" class="integration-provider-card glow-card <?= e($provider['status'] === 'active' ? 'is-active' : 'is-disabled') ?>" data-glow>
                                    <div class="integration-provider-head">
                                        <div class="integration-provider-icon provider-icon-<?= e($provider['provider_key']) ?>" aria-label="<?= e($provider['provider_name']) ?> icon">
                                            <?php if ($provider['icon_url'] !== ''): ?>
                                                <img src="<?= e($provider['icon_url']) ?>" alt="" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false">
                                                <span hidden><?= e((string) $provider['icon_label']) ?></span>
                                            <?php else: ?>
                                                <span><?= e((string) $provider['icon_label']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <h4><?= e($provider['provider_name']) ?></h4>
                                            <div class="integration-badge-row">
                                                <span class="badge <?= e($provider['status'] === 'active' ? 'success' : '') ?>"><?= e($provider['status'] === 'active' ? 'Active' : 'Disabled') ?></span>
                                                <?php if ($provider['oauth_supported']): ?>
                                                    <span class="badge <?= e($provider['oauth_connected'] ? 'success' : 'danger') ?>"><?= e($provider['oauth_connected'] ? 'Connected' : 'Not Connected') ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <p><?= e($provider['description']) ?></p>
                                    <div class="integration-card-actions">
                                        <div class="integration-action-row">
                                            <?php if ($provider['oauth_supported']): ?>
                                                <form method="post" action="<?= e(base_url($config, 'index.php?page=integrations')) ?>" class="integration-action-form">
                                                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                                                    <input type="hidden" name="action" value="start_integration_oauth">
                                                    <input type="hidden" name="provider_key" value="<?= e($provider['provider_key']) ?>">
                                                    <button type="submit"><?= e($provider['oauth_connected'] ? 'Reconnect' : 'Connect') ?></button>
                                                </form>
                                            <?php elseif ($provider['connect_url'] !== ''): ?>
                                                <a class="button-link" href="<?= e($provider['connect_url']) ?>" target="_blank" rel="noopener noreferrer">Connect</a>
                                            <?php else: ?>
                                                <button type="button" class="ghost" disabled>Connect</button>
                                            <?php endif; ?>
                                            <a class="button-link ghost" href="<?= e(base_url($config, 'index.php?page=integrations&provider='.$provider['provider_key'].'#provider-setup')) ?>">Manage</a>
                                        </div>
                                    </div>
                                    <details class="integration-advanced">
                                        <summary>Advanced</summary>
                                        <div class="integration-advanced-body">
                                            <?php if ($provider['runtime_hint'] !== ''): ?>
                                                <p class="muted"><?= e($provider['runtime_hint']) ?></p>
                                            <?php endif; ?>
                                            <?php if ($provider['connect_url'] !== ''): ?>
                                                <a class="integration-external-link" href="<?= e($provider['connect_url']) ?>" target="_blank" rel="noopener noreferrer">Open <?= e($provider['provider_name']) ?> ↗</a>
                                            <?php endif; ?>
                                            <?php if ($provider['provider_key'] === 'mail' && is_string($provider['invoice_forwarding_alias']) && $provider['invoice_forwarding_alias'] !== ''): ?>
                                                <div class="integration-inline-meta">
                                                    <span>Invoice Alias</span>
                                                    <strong><?= e($provider['invoice_forwarding_alias']) ?></strong>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($provider['status'] === 'active' && is_string($provider['connected_at']) && $provider['connected_at'] !== ''): ?>
                                                <div class="integration-inline-meta">
                                                    <span>Connected At (UTC)</span>
                                                    <strong><?= e($provider['connected_at']) ?></strong>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($provider['oauth_supported'] && $provider['oauth_connected_account'] !== ''): ?>
                                                <div class="integration-inline-meta">
                                                    <span>Connected Account</span>
                                                    <strong><?= e($provider['oauth_connected_account']) ?></strong>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($provider['oauth_supported'] && $provider['oauth_last_error'] !== ''): ?>
                                                <div class="integration-inline-meta">
                                                    <span>OAuth Error</span>
                                                    <strong><?= e($provider['oauth_last_error']) ?></strong>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($provider['last_test_status'] !== '' && $provider['last_tested_at'] !== ''): ?>
                                                <div class="integration-inline-meta">
                                                    <span>Last Test (UTC)</span>
                                                    <strong><?= e($provider['last_tested_at']) ?> - <?= e($provider['last_test_status']) ?></strong>
                                                    <?php if ($provider['last_test_summary'] !== ''): ?>
                                                        <small><?= e($provider['last_test_summary']) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <form method="post" action="<?= e(base_url($config, 'index.php?page=integrations')) ?>" class="integration-action-form">
                                                <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                                                <input type="hidden" name="action" value="test_integration_connection">
                                                <input type="hidden" name="provider_key" value="<?= e($provider['provider_key']) ?>">
                                                <?php if (in_array($provider['provider_key'], ['mail', 'slack', 'whatsapp'], true)): ?>
                                                    <input type="text" name="test_recipient" placeholder="<?= e($provider['provider_key'] === 'whatsapp' ? 'Phone (optional for mail/slack)' : 'Recipient (optional)') ?>">
                                                <?php endif; ?>
                                                <button type="submit" class="ghost">Test Connection</button>
                                            </form>
                                        </div>
                                    </details>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="card top-space">
                        <h3>Integration Operations</h3>
                        <form method="post" action="<?= e(base_url($config, 'index.php?page=integrations')) ?>" class="inline-form">
                            <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                            <input type="hidden" name="action" value="link_all_integrations">
                            <button type="submit">Link All Apps</button>
                        </form>
                        <?php if (Auth::can('organizations.manage')): ?>
                            <form method="post" action="<?= e(base_url($config, 'index.php?page=integrations')) ?>" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                                <input type="hidden" name="action" value="link_all_integrations_org">
                                <button type="submit">Link All Apps (All Companies)</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= e(base_url($config, 'index.php?page=integrations')) ?>" class="inline-form">
                            <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                            <input type="hidden" name="action" value="run_integration_worker">
                            <input type="number" name="limit" min="1" max="200" value="20">
                            <button type="submit">Run Worker</button>
                        </form>
                        <form method="post" action="<?= e(base_url($config, 'index.php?page=integrations')) ?>" class="inline-form top-space">
                            <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                            <input type="hidden" name="action" value="queue_mail_inbox_pull">
                            <input type="number" name="limit" min="1" max="50" value="10" placeholder="Inbox messages">
                            <input type="number" name="vendor_id" min="0" placeholder="Default vendor ID (optional)">
                            <input type="number" step="0.01" name="default_total_amount" value="100.00" placeholder="Fallback amount">
                            <input type="text" name="move_to_folder" placeholder="Move processed to folder (optional)">
                            <label class="inline-check">
                                <input type="checkbox" name="mark_as_seen" value="1" checked>
                                <span>Mark as seen</span>
                            </label>
                            <button type="submit">Pull Mail Inbox</button>
                        </form>
                        <table class="top-space">
                            <thead><tr><th>Provider</th><th>Job</th><th>Status</th><th>Attempts</th><th>Run At</th><th>Error</th></tr></thead>
                            <tbody>
                            <?php foreach ($jobs as $job): ?>
                                <tr>
                                    <td><?= e($job['provider']) ?></td>
                                    <td><?= e($job['job_type']) ?></td>
                                    <td><?= e($job['status']) ?></td>
                                    <td><?= e((string) $job['attempts']) ?></td>
                                    <td><?= e($job['run_at']) ?></td>
                                    <td><?= e((string) ($job['last_error'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>

                    <div class="grid cols-2 gap-lg top-space">
                        <section class="card">
                            <h3>Inbound Webhooks</h3>
                            <p class="muted">Endpoint: <code>/api/v1/webhooks/{provider}/{event}</code> with <code>Idempotency-Key</code> and <code>X-Signature</code>.</p>
                            <table>
                                <thead><tr><th>Provider</th><th>Event</th><th>Status</th><th>Received</th></tr></thead>
                                <tbody>
                                <?php foreach ($webhooks as $hook): ?>
                                    <tr>
                                        <td><?= e($hook['provider']) ?></td>
                                        <td><?= e($hook['event_name']) ?></td>
                                        <td><?= e($hook['status']) ?></td>
                                        <td><?= e($hook['received_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </section>

                        <section class="card">
                            <h3>Recent Capture Events</h3>
                            <table>
                                <thead><tr><th>Entity</th><th>Channel</th><th>Source</th><th>Created</th></tr></thead>
                                <tbody>
                                <?php foreach ($captureEvents as $event): ?>
                                    <tr>
                                        <td><?= e($event['entity_type'].' #'.$event['entity_id']) ?></td>
                                        <td><?= e($event['source_channel']) ?></td>
                                        <td><?= e($event['source_ref'] ?? '-') ?></td>
                                        <td><?= e($event['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </section>
                    </div>
                </div>
            </div>
        </div>
        <?php
    } elseif ($page === 'reports') {
        $companyId = (int) ($user['company_id'] ?? 0);

        $vendorSpend = $pdo->prepare('SELECT v.name AS vendor_name, COALESCE(SUM(i.total_amount), 0) AS total_spend
                                      FROM vendors v
                                      LEFT JOIN invoices i ON i.vendor_id = v.id AND i.company_id = v.company_id
                                      WHERE v.company_id = :company_id
                                      GROUP BY v.name
                                      ORDER BY total_spend DESC');
        $vendorSpend->execute(['company_id' => $companyId]);

        $invoiceStatus = $pdo->prepare('SELECT status, COUNT(*) AS total
                                        FROM invoices
                                        WHERE company_id = :company_id
                                        GROUP BY status
                                        ORDER BY total DESC');
        $invoiceStatus->execute(['company_id' => $companyId]);

        $paymentStatus = $pdo->prepare('SELECT status, COUNT(*) AS total
                                        FROM payments
                                        WHERE company_id = :company_id
                                        GROUP BY status
                                        ORDER BY total DESC');
        $paymentStatus->execute(['company_id' => $companyId]);
        ?>
        <div class="grid cols-3 gap-lg">
            <section class="card">
                <h3>Vendor Spend</h3>
                <table>
                    <thead><tr><th>Vendor</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($vendorSpend as $row): ?>
                        <tr>
                            <td><?= e($row['vendor_name']) ?></td>
                            <td><?= e(money_format_indian((float) $row['total_spend'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h3>Invoice Status</h3>
                <table>
                    <thead><tr><th>Status</th><th>Count</th></tr></thead>
                    <tbody>
                    <?php foreach ($invoiceStatus as $row): ?>
                        <tr>
                            <td><?= e($row['status']) ?></td>
                            <td><?= e((string) $row['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h3>Payment Status</h3>
                <table>
                    <thead><tr><th>Status</th><th>Count</th></tr></thead>
                    <tbody>
                    <?php foreach ($paymentStatus as $row): ?>
                        <tr>
                            <td><?= e($row['status']) ?></td>
                            <td><?= e((string) $row['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
        <?php
    } elseif ($page === 'audit') {
        $companyId = (int) ($user['company_id'] ?? 0);

        $events = $pdo->prepare('SELECT id, action_key, entity_type, entity_id, metadata_json, created_at
                                 FROM audit_events
                                 WHERE company_id = :company_id
                                 ORDER BY id DESC
                                 LIMIT 300');
        $events->execute(['company_id' => $companyId]);
        ?>
        <section class="card">
            <h2>Audit Timeline</h2>
            <table>
                <thead><tr><th>ID</th><th>Action</th><th>Entity</th><th>Metadata</th><th>At (UTC)</th></tr></thead>
                <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td>#<?= e((string) $event['id']) ?></td>
                        <td><?= e($event['action_key']) ?></td>
                        <td><?= e($event['entity_type'].' #'.$event['entity_id']) ?></td>
                        <td><code><?= e((string) ($event['metadata_json'] ?? '{}')) ?></code></td>
                        <td><?= e($event['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php
    } else {
        $title = 'Not Found';
        ?>
        <section class="card">
            <h2>Page Not Found</h2>
            <p class="muted">This module route does not exist.</p>
        </section>
        <?php
    }

    $content = (string) ob_get_clean();
    return [$title, $content];
}
