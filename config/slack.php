<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('SLACK_ACTIVITY_ENABLED', false),
    'webhooks' => [
        'default' => (string) env('SLACK_WEBHOOK_URL', ''),
        'urgent' => (string) env('SLACK_WEBHOOK_URGENT', ''),
        'cases' => (string) env('SLACK_WEBHOOK_CASES', ''),
        'actions' => (string) env('SLACK_WEBHOOK_ACTIONS', ''),
        'data' => (string) env('SLACK_WEBHOOK_DATA', ''),
        'admin' => (string) env('SLACK_WEBHOOK_ADMIN', ''),
    ],
    'routes' => [
        'case' => 'cases',
        'comment' => 'cases',
        'attachment' => 'cases',
        'appeal' => 'cases',
        'autoreview' => 'cases',
        'sanction' => 'actions',
        'data-request' => 'data',
        'data-removal' => 'data',
        'investigation' => 'admin',
        'transparency' => 'admin',
        'staff' => 'admin',
        'wikis' => 'admin',
        'checkuser' => 'admin',
        'subject' => 'admin',
        'auth' => 'admin',
    ],
    'ignore' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'SLACK_ACTIVITY_IGNORE',
        'auth.login,auth.logout,attachment.read,wikis.synced,subject.resolved-from-wiki,transparency.opened',
    ))))),
    'mention' => (string) env('SLACK_THREAT_MENTION', '<!channel>'),
    'include_subjects' => (bool) env('SLACK_ACTIVITY_INCLUDE_SUBJECTS', false),
    'portal_url' => rtrim((string) env('SLACK_PORTAL_URL', env('APP_URL', 'http://localhost:8000')), '/'),
    'timeout' => (int) env('SLACK_TIMEOUT', 5),
    'queue' => (bool) env('SLACK_ACTIVITY_QUEUE', true),
];
