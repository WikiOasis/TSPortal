<?php

declare(strict_types=1);

return [
    'action_reasons' => [
        'harassment' => ['label' => 'Harassment', 'group' => 'conduct'],
        'threats' => ['label' => 'Threats or intimidation', 'group' => 'harm'],
        'child-protection' => ['label' => 'Child protection', 'group' => 'harm'],
        'self-harm' => ['label' => 'Risk of self-harm', 'group' => 'harm'],
        'doxxing' => ['label' => 'Disclosure of personal information', 'group' => 'privacy'],
        'sockpuppetry' => ['label' => 'Sockpuppetry or block evasion', 'group' => 'integrity'],
        'spam' => ['label' => 'Spam or advertising', 'group' => 'integrity'],
        'vandalism' => ['label' => 'Persistent vandalism', 'group' => 'integrity'],
        'copyright' => ['label' => 'Copyright or licensing', 'group' => 'content'],
        'illegal-content' => ['label' => 'Illegal content', 'group' => 'content'],
        'legal-demand' => ['label' => 'Legal demand or court order', 'group' => 'legal'],
        'data-protection' => ['label' => 'Data protection obligation', 'group' => 'legal'],
        'security' => ['label' => 'Account or platform security', 'group' => 'integrity'],
        'other' => ['label' => 'Something else', 'group' => 'other'],
    ],
    'groups' => [
        'conduct' => 'Conduct',
        'harm' => 'Harm and safety',
        'privacy' => 'Privacy',
        'integrity' => 'Project integrity',
        'content' => 'Content',
        'legal' => 'Legal',
        'data' => 'Data requests',
        'appeal' => 'Appeals',
        'enquiry' => 'Enquiries',
        'other' => 'Other',
    ],
    'aliases' => [],
    'appeal_categories' => array_values(array_unique(array_filter(array_map(
        fn (string $id) => strtolower(trim($id)),
        explode(',', (string) env(
            'TS_APPEAL_CATEGORIES',
            implode(',', [
                'appeal',
                'contact-appeal',
                'appeal-a-sanction',
                'appeal-an-action',
                'appeal-against-an-action',
            ]),
        )),
    )))),
    'appeal_group' => (string) env('TS_APPEAL_GROUP', 'appeal'),
    'threat_to_life' => [
        'categories' => array_values(array_unique(array_filter(array_map(
            fn (string $id) => strtolower(trim($id)),
            explode(',', (string) env(
                'TS_THREAT_TO_LIFE_CATEGORIES',
                implode(',', [
                    'threats',
                    'self-harm',
                    'threat-to-life',
                    'threat-of-physical-harm',
                    'threat-of-violence',
                    'violence',
                    'suicide',
                    'suicide-or-self-harm',
                    'risk-to-life',
                    'imminent-harm',
                ]),
            )),
        )))),
        'priority' => (string) env('TS_THREAT_TO_LIFE_PRIORITY', 'urgent'),
    ],
    'suppression_threshold' => (int) env('TS_TRANSPARENCY_THRESHOLD', 5),
];
