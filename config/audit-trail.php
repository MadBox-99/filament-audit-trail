<?php

declare(strict_types=1);

$sensitive = ['password', 'remember_token', 'two_factor_*', '*token*', '*secret*', '*api_key*'];

return [
    'enabled' => (bool) env('AUDIT_TRAIL_ENABLED', true),

    'connection' => null,

    'table' => 'audit_events',

    'guard' => 'web',

    'events' => [
        'login' => true,
        'login_inferred' => true,
        'logout' => true,
        'login_failed' => true,
        'page_view' => true,
        'action_mounted' => true,
        'action_executed' => true,
        'created' => true,
        'updated' => true,
        'deleted' => true,
        'restored' => true,
        'import' => true,
        'export' => true,
        'account_created' => true,
    ],

    'page_views' => [
        'exclude_paths' => ['livewire*', 'up', '_debugbar*', 'filament/assets*', 'storage/*'],
    ],

    'models' => [
        'exclude' => [
            'audit_events', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks',
            'notifications', 'sync_logs', 'oauth_*', 'telescope_*', 'pulse_*', 'activity_log',
        ],
    ],

    'redact' => $sensitive,

    'max_properties_bytes' => 16384,

    'reconstruct' => [
        'ignored_timestamps' => ['2025-10-10 09:00:00', '2025-10-01 12:00:00'],
        'excluded_email_patterns' => ['%@example.%'],
        'team_creation_grace_seconds' => 120,
        'publisher' => false,
        'actor_columns' => [],
        'sole_member_tables' => [],
        'team_user_table' => 'team_user',
        'teams_table' => 'teams',
    ],
];
