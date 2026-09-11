<?php

declare(strict_types=1);

return [
    'base_url' => rtrim((string) env('AUTHENTIK_BASE_URL', ''), '/'),
    'client_id' => env('AUTHENTIK_CLIENT_ID'),
    'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
    'redirect_uri' => env('AUTHENTIK_REDIRECT_URI'),
    'scopes' => (string) env('AUTHENTIK_SCOPES', 'openid profile email'),
    'authorize_endpoint' => (string) env('AUTHENTIK_AUTHORIZE', '/application/o/authorize/'),
    'token_endpoint' => (string) env('AUTHENTIK_TOKEN', '/application/o/token/'),
    'userinfo_endpoint' => (string) env('AUTHENTIK_USERINFO', '/application/o/userinfo/'),
    'timeout' => (int) env('AUTHENTIK_HTTP_TIMEOUT', 15),
    'group_flags' => [
        'safety' => ['ts', 'admin'],
    ],
    'bootstrap_admins' => array_values(array_filter(
        array_map('trim', explode('|', (string) env('AUTHENTIK_BOOTSTRAP_ADMINS', '')))
    )),
];
