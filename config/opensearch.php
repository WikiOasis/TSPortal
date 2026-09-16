<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('OPENSEARCH_ENABLED', false),

    'url' => rtrim((string) env('OPENSEARCH_URL', 'http://localhost:9200'), '/'),

    'username' => (string) env('OPENSEARCH_USERNAME', ''),
    'password' => (string) env('OPENSEARCH_PASSWORD', ''),

    'verify' => (bool) env('OPENSEARCH_VERIFY_TLS', false),

    'prefix' => (string) env('OPENSEARCH_INDEX_PREFIX', 'tsportal'),

    'timeout' => (int) env('OPENSEARCH_TIMEOUT', 5),

    'shards' => (int) env('OPENSEARCH_SHARDS', 1),
    'replicas' => (int) env('OPENSEARCH_REPLICAS', 1),

    'chunk' => (int) env('OPENSEARCH_CHUNK', 200),

    'refresh' => (bool) env('OPENSEARCH_REFRESH', false),

    'fragment_size' => (int) env('OPENSEARCH_FRAGMENT_SIZE', 160),
];
