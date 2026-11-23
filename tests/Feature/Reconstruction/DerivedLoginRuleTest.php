<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\AccountCreatedRule;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\DerivedLoginRule;

/**
 * @return Collection<int, AuditEvent>
 */
function derivedLogins()
{
    return AuditEvent::query()->where('event', AuditEventType::LoginInferred->value)->orderBy('occurred_at')->get();
}

function liveLogin(int $userId, string $at): void
{
    DB::table('audit_events')->insert([
        'occurred_at' => $at, 'event' => 'login', 'user_id' => $userId, 'source' => 'live', 'inferred' => false, 'created_at' => $at,
    ]);
}

it('derives one login per user and day at the earliest trace, with that trace as evidence', function (): void {
    $user = insertUser();

    reconstruct([
        fakeRule('actor_columns', [
            evidence($user, '2026-03-01 14:00:00', 'posts:2:actor', ['evidence' => ['rule' => 'actor_columns', 'table' => 'posts', 'id' => 2]]),
            evidence($user, '2026-03-01 09:10:00', 'posts:1:actor', ['evidence' => ['rule' => 'actor_columns', 'table' => 'posts', 'id' => 1]]),
        ]),
        new DerivedLoginRule,
    ]);

    $row = derivedLogins()->sole();
    expect($row->user_id)->toBe($user)
        ->and($row->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-03-01 09:10:00')
        ->and($row->inferred)->toBeTrue()
        ->and($row->evidence_key)->toBe('derived_login:'.$user.':2026-03-01')
        ->and($row->evidence)->toMatchArray([
            'rule' => 'derived_login',
            'table' => 'posts',
            'id' => 1,
            'evidence_key' => 'posts:1:actor',
        ]);
});

it('derives a login for every day and user with traces of all action types', function (): void {
    $a = insertUser();
    $b = insertUser();

    reconstruct([
        fakeRule('fake', [
            evidence($a, '2026-03-01 09:00:00', 'k1', ['type' => AuditEventType::Updated]),
            evidence($a, '2026-03-02 09:00:00', 'k2', ['type' => AuditEventType::Deleted]),
            evidence($b, '2026-03-01 11:00:00', 'k3', ['type' => AuditEventType::Import]),
            evidence($b, '2026-03-03 11:00:00', 'k4', ['type' => AuditEventType::Export]),
            evidence($b, '2026-03-04 11:00:00', 'k5', ['type' => AuditEventType::ActionExecuted]),
        ]),
        new DerivedLoginRule,
    ]);

    expect(derivedLogins()->map(fn (AuditEvent $e): string => $e->user_id.'@'.$e->occurred_at->format('Y-m-d'))->sort()->values()->all())
        ->toBe([$a.'@2026-03-01', $a.'@2026-03-02', $b.'@2026-03-01', $b.'@2026-03-03', $b.'@2026-03-04']);
});

it('derives nothing on a day that already has an SSO login', function (): void {
    $user = insertUser();

    reconstruct([
        fakeRule('sso_login', [evidence($user, '2026-03-01 18:00:00', 'sso:t1', ['type' => AuditEventType::Login])]),
        fakeRule('actor_columns', [evidence($user, '2026-03-01 09:10:00', 'posts:1:actor')]),
        new DerivedLoginRule,
    ]);

    expect(derivedLogins())->toHaveCount(0);
});

it('derives nothing on a day that already has a live or earlier reconstructed login', function (): void {
    $user = insertUser();
    liveLogin($user, '2026-03-01 20:00:00');
    DB::table('audit_events')->insert([
        'occurred_at' => '2026-03-02 20:00:00', 'event' => 'login', 'user_id' => $user, 'source' => 'reconstructed',
        'inferred' => false, 'evidence_key' => 'sso:old', 'created_at' => now(),
    ]);

    reconstruct([
        fakeRule('actor_columns', [
            evidence($user, '2026-03-01 09:10:00', 'posts:1:actor'),
            evidence($user, '2026-03-02 09:10:00', 'posts:2:actor'),
            evidence($user, '2026-03-03 09:10:00', 'posts:3:actor'),
        ]),
        new DerivedLoginRule,
    ]);

    expect(derivedLogins()->sole()->occurred_at->format('Y-m-d'))->toBe('2026-03-03');
});

it('derives nothing from account_created or registration logins alone', function (): void {
    config(['audit-trail.reconstruct.publisher' => true]);
    insertUser(['email_verified_at' => '2026-01-02 08:00:00']);

    $report = reconstruct([new AccountCreatedRule, new DerivedLoginRule]);

    expect($report->rule('derived_login')['found'])->toBe(0)
        ->and(AuditEvent::query()->where('evidence_key', 'like', 'derived_login:%')->count())->toBe(0);
});

it('never derives from a rejected record or one without a user', function (): void {
    $user = insertUser();

    $report = reconstruct([
        fakeRule('actor_columns', [
            evidence($user, '2026-03-01 09:10:00', 'posts:1:actor', ['rejection' => 'team_bootstrap']),
            evidence($user, '2025-10-10 09:00:00', 'posts:2:actor'),
            evidence($user, '2025-12-01 09:00:00', 'posts:3:actor'), // before the account
            evidence(0, '2026-03-02 09:10:00', 'posts:4:actor', ['userId' => null]),
        ]),
        new DerivedLoginRule,
    ]);

    expect(derivedLogins())->toHaveCount(0)
        ->and($report->rule('derived_login')['found'])->toBe(0)
        ->and($report->rule('derived_login')['error'])->toBeNull();
});

it('derives from reconstructed rows of an earlier run when run alone', function (): void {
    $user = insertUser();
    reconstruct([fakeRule('actor_columns', [evidence($user, '2026-03-01 09:10:00', 'posts:1:actor', ['evidence' => ['rule' => 'actor_columns', 'table' => 'posts', 'id' => 1]])])]);
    liveLogin($user, '2026-03-05 08:00:00');
    DB::table('audit_events')->insert([ // a live action is no reconstruction evidence
        'occurred_at' => '2026-03-06 08:00:00', 'event' => 'created', 'user_id' => $user, 'source' => 'live', 'inferred' => false, 'evidence_key' => 'live:1', 'created_at' => now(),
    ]);

    reconstruct([new DerivedLoginRule]);

    $row = derivedLogins()->sole();
    expect($row->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-03-01 09:10:00')
        ->and($row->evidence)->toMatchArray(['rule' => 'derived_login', 'table' => 'posts', 'id' => 1, 'evidence_key' => 'posts:1:actor', 'source_rule' => 'actor_columns']);
});

it('writes nothing new on a second run', function (): void {
    $user = insertUser();
    $rules = fn (): array => [fakeRule('actor_columns', [evidence($user, '2026-03-01 09:10:00', 'posts:1:actor')]), new DerivedLoginRule];

    reconstruct($rules());
    $report = reconstruct($rules());

    expect($report->rule('derived_login')['inserted'])->toBe(0)
        ->and($report->rule('derived_login')['existing'])->toBe(1)
        ->and(derivedLogins())->toHaveCount(1);
});

it('reports but writes nothing on a dry run', function (): void {
    $user = insertUser();

    $report = reconstruct([fakeRule('actor_columns', [evidence($user, '2026-03-01 09:10:00', 'posts:1:actor')]), new DerivedLoginRule], dryRun: true);

    expect($report->rule('derived_login')['accepted'])->toBe(1)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('cuts days in the app timezone', function (): void {
    config(['app.timezone' => 'Europe/Budapest']);
    $user = insertUser();
    liveLogin($user, '2026-03-02 00:30:00'); // Budapest time, as stored

    reconstruct([
        fakeRule('actor_columns', [
            evidence($user, '2026-03-01 23:30:00', 'posts:1:actor'),
            evidence($user, '2026-03-02 09:00:00', 'posts:2:actor'),
        ]),
        new DerivedLoginRule,
    ]);

    expect(derivedLogins()->sole()->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-03-01 23:30:00');
});

it('runs last in the reconstruct command and not when --rule leaves it out', function (): void {
    config(['audit-trail.reconstruct.actor_columns' => ['posts' => 'user_id']]);
    $user = insertUser();
    insertPost(insertTeam(), $user, '2026-03-01 09:10:00');

    $this->artisan('audit-trail:reconstruct', ['--rule' => ['actor_columns']])->assertExitCode(0);
    expect(derivedLogins())->toHaveCount(0);

    $this->artisan('audit-trail:reconstruct')->expectsOutputToContain('derived_login')->assertExitCode(0);
    expect(derivedLogins()->sole()->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-03-01 09:10:00');
});
