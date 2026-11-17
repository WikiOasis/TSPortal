<?php

return [
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    'release' => env('SENTRY_RELEASE', env('APP_VERSION')),

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV')),

    'enable_logs' => env('SENTRY_ENABLE_LOGS', true),

    'logs_channel_level' => env('SENTRY_LOG_LEVEL', 'debug'),

    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),

    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null
        ? null
        : (float) env('SENTRY_TRACES_SAMPLE_RATE'),

    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE') === null
        ? null
        : (float) env('SENTRY_PROFILES_SAMPLE_RATE'),

    'breadcrumbs' => [
        'logs' => true,
        'cache' => true,
        'livewire' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,
    ],

    'tracing' => [
        'views' => true,
        'sql_queries' => true,
        'sql_bindings' => env('SENTRY_TRACE_SQL_BINDINGS', false),
        'sql_origin' => true,
        'queue_job_transactions' => env('SENTRY_TRACE_QUEUE_ENABLED', true),
        'queue_jobs' => true,
        'default_integrations' => true,
        'redis_commands' => env('SENTRY_TRACE_REDIS_COMMANDS', false),
        'http_client_requests' => true,
        'notifications' => true,
        'missing_routes' => false,
        'continue_after_response' => true,
    ],
];
