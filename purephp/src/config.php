<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => getenv('APP_NAME') ?: 'Pazy Plain',
        'base_url' => getenv('APP_BASE_URL') ?: '/pazy/purephp/public',
        'env' => getenv('APP_ENV') ?: 'local',
        'debug' => filter_var(getenv('APP_DEBUG') ?: '1', FILTER_VALIDATE_BOOL),
        'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Kolkata',
        'currency' => getenv('APP_CURRENCY') ?: 'INR',
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'pazy_plain',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name' => 'pazy_plain_session',
        'csrf_key' => '_csrf_token',
        'api_token_ttl_days' => (int) (getenv('API_TOKEN_TTL_DAYS') ?: 30),
        'force_https' => filter_var(getenv('APP_FORCE_HTTPS') ?: '0', FILTER_VALIDATE_BOOL),
        'hsts_max_age' => (int) (getenv('SECURITY_HSTS_MAX_AGE') ?: 31536000),
        'x_frame_options' => getenv('SECURITY_X_FRAME_OPTIONS') ?: 'DENY',
        'x_content_type_options' => getenv('SECURITY_X_CONTENT_TYPE_OPTIONS') ?: 'nosniff',
        'referrer_policy' => getenv('SECURITY_REFERRER_POLICY') ?: 'strict-origin-when-cross-origin',
        'permissions_policy' => getenv('SECURITY_PERMISSIONS_POLICY') ?: 'geolocation=(), microphone=(), camera=()',
        'content_security_policy' => getenv('SECURITY_CONTENT_SECURITY_POLICY') ?: "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; script-src 'self' 'unsafe-inline'; connect-src 'self' https:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        'rate_limits' => [
            'auth_per_minute' => (int) (getenv('RATE_LIMIT_AUTH_PER_MINUTE') ?: 15),
            'api_per_minute' => (int) (getenv('RATE_LIMIT_API_PER_MINUTE') ?: 300),
            'webhook_per_minute' => (int) (getenv('RATE_LIMIT_WEBHOOK_PER_MINUTE') ?: 180),
            'web_post_per_minute' => (int) (getenv('RATE_LIMIT_WEB_POST_PER_MINUTE') ?: 120),
        ],
    ],
    'integrations' => [
        'webhook_secrets' => [
            'bank' => getenv('WEBHOOK_SECRET_BANK') ?: 'bank-local-secret',
            'ocr' => getenv('WEBHOOK_SECRET_OCR') ?: 'ocr-local-secret',
            'erp' => getenv('WEBHOOK_SECRET_ERP') ?: 'erp-local-secret',
            'messaging' => getenv('WEBHOOK_SECRET_MESSAGING') ?: 'messaging-local-secret',
            'mail' => getenv('WEBHOOK_SECRET_MAIL') ?: (getenv('WEBHOOK_SECRET_MESSAGING') ?: 'messaging-local-secret'),
            'whatsapp' => getenv('WEBHOOK_SECRET_WHATSAPP') ?: (getenv('WEBHOOK_SECRET_MESSAGING') ?: 'messaging-local-secret'),
            'tax' => getenv('WEBHOOK_SECRET_TAX') ?: 'tax-local-secret',
        ],
    ],
    'storage' => [
        'object_root' => getenv('OBJECT_STORAGE_ROOT') ?: (__DIR__.'/../storage/object'),
    ],
    'worker' => [
        'max_attempts' => (int) (getenv('WORKER_MAX_ATTEMPTS') ?: 3),
        'retry_base_seconds' => (int) (getenv('WORKER_RETRY_BASE_SECONDS') ?: 60),
    ],
    'matching' => [
        'amount_tolerance' => (float) (getenv('MATCH_AMOUNT_TOLERANCE') ?: 2.00),
    ],
];
