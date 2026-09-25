<?php

declare(strict_types=1);

$key = (string) env('OPENROUTER_API_KEY', '');

return [
    'enabled' => (bool) env('AUTOREVIEW_ENABLED', $key !== '') && $key !== '',

    'openrouter' => [
        'key' => $key,
        'url' => rtrim((string) env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'), '/'),
        'model' => (string) env('AUTOREVIEW_MODEL', 'z-ai/glm-5.3-flash'),
        'timeout' => (int) env('AUTOREVIEW_TIMEOUT', 90),
        'max_tokens' => (int) env('AUTOREVIEW_MAX_TOKENS', 2000),
        'structured' => (bool) env('AUTOREVIEW_STRUCTURED_OUTPUT', true),
        'referer' => (string) env('AUTOREVIEW_REFERER', env('APP_URL', 'http://localhost:8000')),
    ],

    'queue' => (string) env('AUTOREVIEW_QUEUE', 'default'),
    'per_minute' => (int) env('AUTOREVIEW_PER_MINUTE', 60),
    'retry_for_hours' => (int) env('AUTOREVIEW_RETRY_FOR_HOURS', 12),

    'set_priority' => (bool) env('AUTOREVIEW_SET_PRIORITY', true),
    'fold_same_revision' => (bool) env('AUTOREVIEW_FOLD_SAME_REVISION', true),

    'fetch' => [
        'enabled' => (bool) env('AUTOREVIEW_FETCH_CONTENT', true),
        'timeout' => (int) env('AUTOREVIEW_FETCH_TIMEOUT', 15),
        'max_chars' => (int) env('AUTOREVIEW_MAX_CONTENT_CHARS', 12000),
    ],
];
