<?php

declare(strict_types=1);

use Madbox99\FilamentAuditTrail\Models\AuditEvent;

it('runs all rules and prints the report', function (): void {
    insertUser(['email' => 'a@corp.test']);

    $this->artisan('audit-trail:reconstruct')
        ->expectsOutputToContain('account_created')
        ->expectsOutputToContain('imports_exports')
        ->expectsOutputToContain('activity_log')
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
