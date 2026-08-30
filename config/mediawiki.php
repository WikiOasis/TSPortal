<?php

declare(strict_types=1);

return [
    'central_url' => rtrim((string) env('MW_CENTRAL_URL', 'https://meta.wikioasis.org'), '/'),
    'api_url' => rtrim((string) env('MW_API_URL', env('MW_CENTRAL_URL', 'https://meta.wikioasis.org').'/w/api.php'), '/'),
    'rest_url' => rtrim((string) env('MW_REST_URL', env('MW_CENTRAL_URL', 'https://meta.wikioasis.org').'/w/rest.php'), '/'),
    'user_agent' => (string) env('MW_USER_AGENT', 'TSPortal/0.1.0 (https://ts.wikioasis.org; trustandsafety@wikioasis.org)'),
    'timeout' => (int) env('MW_HTTP_TIMEOUT', 15),
    'oauth' => [
        'client_id' => env('MW_OAUTH_CLIENT_ID'),
        'client_secret' => env('MW_OAUTH_CLIENT_SECRET'),
        'redirect_uri' => env('MW_OAUTH_REDIRECT_URI'),
        'authorize_endpoint' => env('MW_OAUTH_AUTHORIZE', '/oauth2/authorize'),
        'token_endpoint' => env('MW_OAUTH_TOKEN', '/oauth2/access_token'),
        'profile_endpoint' => env('MW_OAUTH_PROFILE', '/oauth2/resource/profile'),
        'scopes' => env('MW_OAUTH_SCOPES', 'basic'),
    ],
    'group_flags' => [
        'safety' => ['ts', 'admin'],
    ],
    'bootstrap_admins' => array_values(array_filter(
        array_map('trim', explode('|', (string) env('MW_BOOTSTRAP_ADMINS', '')))
    )),
    's2s' => [
        'secret' => env('MW_S2S_SECRET'),
        'tolerance' => (int) env('MW_S2S_TOLERANCE', 300),
        'nonce_ttl' => (int) env('MW_S2S_NONCE_TTL', 600),
        'push_enabled' => (bool) env('MW_S2S_PUSH_ENABLED', true),
    ],
    'supported_actions' => array_values(array_filter(
        array_map('trim', explode(',', (string) env(
            'MW_SUPPORTED_ACTIONS',
            'lock,unlock,warn,note,block,unblock,delete-wiki,undelete-wiki,rename,renamestatus,removepii',
        )))
    )),
    'centralauth_lock' => (bool) env('MW_CENTRALAUTH_LOCK', true),
    'pii' => [
        'enabled' => (bool) env('MW_PII_ENABLED', false),
        'username_prefix' => (string) env('MW_PII_USERNAME_PREFIX', 'WikiOasisGDPR_'),
        'rename_timeout_hours' => (int) env('MW_PII_RENAME_TIMEOUT_HOURS', 24),
    ],
];
