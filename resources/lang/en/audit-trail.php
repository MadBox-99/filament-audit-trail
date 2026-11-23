<?php

declare(strict_types=1);

return [
    'events' => [
        'login' => 'Login',
        'login_inferred' => 'Login (inferred)',
        'logout' => 'Logout',
        'login_failed' => 'Failed login',
        'page_view' => 'Page view',
        'action_mounted' => 'Action opened',
        'action_executed' => 'Action executed',
        'created' => 'Created',
        'updated' => 'Updated',
        'deleted' => 'Deleted',
        'restored' => 'Restored',
        'import' => 'Import',
        'export' => 'Export',
        'account_created' => 'Account created',
    ],
    'sources' => [
        'live' => 'Live',
        'reconstructed' => 'Reconstructed',
    ],
    'resource' => [
        'navigation_label' => 'Audit trail',
        'model_label' => 'audit event',
        'plural_model_label' => 'audit events',
    ],
    'columns' => [
        'occurred_at' => 'Time',
        'user' => 'User',
        'event' => 'Event',
        'team' => 'Team',
        'subject' => 'Subject',
        'subject_type' => 'Subject type',
        'subject_id' => 'Subject ID',
        'action' => 'Action / route',
        'url' => 'URL',
        'ip' => 'IP',
        'source' => 'Source',
        'properties' => 'Properties',
        'evidence' => 'Evidence',
    ],
    'inferred' => 'inferred time',
    'filters' => [
        'date_range' => 'Date range',
        'from' => 'From',
        'until' => 'Until',
    ],
    'export' => [
        'completed' => 'Your audit trail export has completed, :count rows.',
    ],
    'export_columns' => [
        'occurred_at' => 'Time',
        'event' => 'Event',
        'user_email' => 'User email',
        'team_id' => 'Team ID',
        'subject_type' => 'Subject type',
        'subject_id' => 'Subject ID',
        'action' => 'Action',
        'url' => 'URL',
        'ip' => 'IP',
        'source' => 'Source',
        'inferred' => 'Inferred',
        'evidence_key' => 'Evidence key',
    ],
];
