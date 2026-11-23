<?php

declare(strict_types=1);

$sensitive = ['*password*', 'remember_token', 'two_factor_*', '*token*', '*secret*', '*api_key*', '*private_key*', '*credential*'];

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

    // Who sees team-less rows on a tenant panel: the tenant's current members, read from this pivot
    // on the user model's connection. team_user_table null = reconstruct.team_user_table.
    'visibility' => [
        'team_user_table' => null,
        'team_foreign_key' => 'team_id',
        'user_foreign_key' => 'user_id',
    ],

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
        // export-sso: a token counts as a sign-in only if an authorization code of the same
        // user and client expires within this many minutes after it (Passport code TTL 10 + 1).
        'sso_auth_code_window_minutes' => 11,
        // Passport's authorization code lifetime: a code was issued at expires_at minus this.
        'sso_auth_code_ttl_minutes' => 10,
    ],
];
