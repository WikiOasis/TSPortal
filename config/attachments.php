<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('TS_ATTACHMENTS_ENABLED', true),
    'disk' => (string) env('TS_ATTACHMENTS_DISK', env('FILESYSTEM_DISK', 'local')),
    'prefix' => trim((string) env('TS_ATTACHMENTS_PREFIX', 'case-attachments'), '/'),
    'max_bytes' => (int) env('TS_ATTACHMENTS_MAX_BYTES', 25 * 1024 * 1024),
    'max_per_case' => (int) env('TS_ATTACHMENTS_MAX_PER_CASE', 20),
    'allowed_mime' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'TS_ATTACHMENTS_ALLOWED_MIME',
        implode(',', [
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/webp',
            'image/heic',
            'application/pdf',
            'text/plain',
            'text/csv',
            'video/mp4',
            'video/webm',
            'video/quicktime',
            'audio/mpeg',
            'audio/ogg',
        ]),
    ))))),
    'extensions' => [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        'audio/mpeg' => 'mp3',
        'audio/ogg' => 'ogg',
    ],
];
