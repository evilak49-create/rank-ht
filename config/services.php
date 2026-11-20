<?php

$platformQueryPrivateDefaults = [];
$platformQueryDefaultsPath = storage_path('app/private/platform-query-defaults.json');
if (env('APP_ENV') !== 'testing' && is_file($platformQueryDefaultsPath) && is_readable($platformQueryDefaultsPath)) {
    $platformQueryPrivateDefaults = (array) json_decode((string) file_get_contents($platformQueryDefaultsPath), true);
}
$platformQueryEndpoint = env('PLATFORM_QUERY_DEFAULT_ENDPOINT', $platformQueryPrivateDefaults['endpoint'] ?? 'https://api5.v-n-r-1.com/api/backend/trpc/user.list');

return [
    'pix' => [
        'driver' => env('PIX_DRIVER'),
        'simulator_callback_key' => env('PIX_SIMULATOR_CALLBACK_KEY'),
    ],
    'platform_funds' => [
        'driver' => env('PLATFORM_FUNDS_DRIVER'),
    ],
    'platform_query' => [
        'allowed_hosts' => array_values(array_unique(array_filter(array_map(
            fn (string $host): string => strtolower(trim($host)),
            [...explode(',', (string) env('PLATFORM_QUERY_ALLOWED_HOSTS', '')), (string) parse_url((string) $platformQueryEndpoint, PHP_URL_HOST)],
        )))),
        'defaults' => [
            'endpoint' => $platformQueryEndpoint,
            'admin_host' => env('PLATFORM_QUERY_DEFAULT_ADMIN_HOST', $platformQueryPrivateDefaults['admin_host'] ?? 'admin5-public.y-7-l-x.com'),
            'tenant_id' => env('PLATFORM_QUERY_DEFAULT_TENANT_ID', $platformQueryPrivateDefaults['tenant_id'] ?? 9818088),
            'region_id' => env('PLATFORM_QUERY_DEFAULT_REGION_ID', $platformQueryPrivateDefaults['region_id'] ?? 1),
            'client_language' => env('PLATFORM_QUERY_DEFAULT_CLIENT_LANGUAGE', $platformQueryPrivateDefaults['client_language'] ?? 'zh-CN'),
            'token' => env('PLATFORM_QUERY_DEFAULT_TOKEN', env('BACKEND_BEARER_TOKEN', $platformQueryPrivateDefaults['token'] ?? null)),
            'account' => env('PLATFORM_QUERY_DEFAULT_ACCOUNT', env('BACKEND_ACCOUNT', $platformQueryPrivateDefaults['account'] ?? null)),
            'fingerprint' => env('PLATFORM_QUERY_DEFAULT_FINGERPRINT', env('BACKEND_FINGERPRINT_ID', $platformQueryPrivateDefaults['fingerprint'] ?? null)),
            'test_phone' => env('PLATFORM_QUERY_DEFAULT_TEST_PHONE', $platformQueryPrivateDefaults['test_phone'] ?? null),
        ],
    ],
    'sms' => [
        'driver' => env('SMS_DRIVER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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

];
