<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;

it('runs all rules and prints the report', function (): void {
    insertUser(['email' => 'a@corp.test']);

    $this->artisan('audit-trail:reconstruct')
        ->expectsOutputToContain('account_created')
        ->expectsOutputToContain('imports_exports')
        ->expectsOutputToContain('activity_log')
        ->expectsOutputToContain('actor_columns')
        ->expectsOutputToContain('sole_member_team')
        ->expectsOutputToContain('derived_login')
        ->assertExitCode(0);

    expect(AuditEvent::query()->count())->toBe(1);
});

it('filters rules by name', function (): void {
    insertUser();

    $this->artisan('audit-trail:reconstruct', ['--rule' => ['imports_exports']])
        ->doesntExpectOutputToContain('account_created')
        ->assertExitCode(0);

    expect(AuditEvent::query()->count())->toBe(0);
});

it('writes nothing on --dry-run', function (): void {
    insertUser();

    $this->artisan('audit-trail:reconstruct', ['--dry-run' => true])->assertExitCode(0);

    expect(AuditEvent::query()->count())->toBe(0);
});

it('applies --since and --until', function (): void {
    insertUser(['created_at' => '2026-01-01 08:00:00']);
    insertUser(['created_at' => '2026-03-01 08:00:00']);

    $this->artisan('audit-trail:reconstruct', ['--since' => '2026-02-01', '--until' => '2026-03-01'])->assertExitCode(0);

    expect(AuditEvent::query()->sole()->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-03-01 08:00:00');
});

it('rejects an unknown rule name', function (): void {
    $this->artisan('audit-trail:reconstruct', ['--rule' => ['nope']])->assertExitCode(1);
});

it('warns about accepted rows whose team bootstrap check was skipped', function (): void {
    config(['audit-trail.reconstruct.actor_columns' => ['posts' => 'user_id']]);
    $user = insertUser();
    insertPost(insertTeam('2025-10-10 09:00:00'), $user, '2026-01-02 10:00:00');
    $known = insertTeam('2026-01-01 08:00:00');
    insertPost($known, $user, '2026-01-02 10:00:00');
    DB::table('posts')->insert(['team_id' => 999, 'user_id' => $user, 'title' => 'P', 'created_at' => '2026-01-02 11:00:00']);

    $this->artisan('audit-trail:reconstruct', ['--rule' => ['actor_columns'], '--dry-run' => true])
        ->expectsOutputToContain('actor_columns: 2')
        ->assertExitCode(0);
});

it('warns about configured tables and columns that do not exist', function (): void {
    config([
        'audit-trail.reconstruct.actor_columns' => ['posts' => 'created_by', 'invoices' => 'user_id'],
        'audit-trail.reconstruct.sole_member_tables' => ['documents', 'users'],
    ]);

    $this->artisan('audit-trail:reconstruct', ['--dry-run' => true])
        ->expectsOutputToContain('actor_columns: posts.created_by')
        ->expectsOutputToContain('actor_columns: invoices')
        ->expectsOutputToContain('sole_member_team: documents')
        ->expectsOutputToContain('sole_member_team: users.team_id')
        ->assertExitCode(0);
});

it('warns when the teams table used for the bootstrap check does not exist', function (): void {
    config(['audit-trail.reconstruct.actor_columns' => ['posts' => 'user_id'], 'audit-trail.reconstruct.teams_table' => 'workspaces']);

    $this->artisan('audit-trail:reconstruct', ['--dry-run' => true, '--rule' => ['actor_columns']])
        ->expectsOutputToContain('actor_columns: workspaces')
        ->assertExitCode(0);
});

it('prints no configuration warning for a valid configuration', function (): void {
    config(['audit-trail.reconstruct.actor_columns' => ['posts' => 'user_id'], 'audit-trail.reconstruct.sole_member_tables' => ['posts']]);

    $this->artisan('audit-trail:reconstruct', ['--dry-run' => true])
        ->doesntExpectOutputToContain('does not exist')
        ->assertExitCode(0);
});
