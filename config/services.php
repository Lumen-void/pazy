<?php

return [
    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'webhook_secret' => env('WEBHOOK_SECRET', 'local-stub-webhook-secret'),

    'providers' => [
        'bank' => [
            'webhook_secret' => env('BANK_WEBHOOK_SECRET', env('WEBHOOK_SECRET', 'local-stub-webhook-secret')),
        ],
        'erp' => [
            'webhook_secret' => env('ERP_WEBHOOK_SECRET', env('WEBHOOK_SECRET', 'local-stub-webhook-secret')),
        ],
        'messaging' => [
            'webhook_secret' => env('MESSAGING_WEBHOOK_SECRET', env('WEBHOOK_SECRET', 'local-stub-webhook-secret')),
        ],
    ],
];
