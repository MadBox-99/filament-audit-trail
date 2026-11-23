<?php

declare(strict_types=1);

use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\ActorColumnsRule;

beforeEach(function (): void {
    config(['audit-trail.reconstruct.actor_columns' => ['posts' => 'user_id']]);
});

it('records a created event for the user in the actor column', function (): void {
    $user = insertUser(['email' => 'a@corp.test']);
    $team = insertTeam();
    $post = insertPost($team, $user, '2026-01-02 10:00:00');

    reconstruct([new ActorColumnsRule]);

    $row = AuditEvent::query()->sole();
    expect($row->event)->toBe(AuditEventType::Created)
        ->and($row->user_id)->toBe($user)
        ->and($row->user_email)->toBe('a@corp.test')
        ->and($row->team_id)->toBe($team)
        ->and($row->subject_type)->toBe('table:posts')
        ->and($row->subject_id)->toBe((string) $post)
        ->and($row->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-01-02 10:00:00')
        ->and($row->inferred)->toBeFalse()
        ->and($row->evidence_key)->toBe('posts:'.$post.':actor')
        ->and($row->evidence)->toMatchArray(['rule' => 'actor_columns', 'table' => 'posts', 'id' => $post, 'column' => 'user_id']);
});

it('rejects records created within the team bootstrap window', function (): void {
    $user = insertUser();
    $team = insertTeam('2026-01-02 10:00:00');
    insertPost($team, $user, '2026-01-02 10:00:30');
    insertPost($team, $user, '2026-01-02 10:02:00'); // exactly 120 s: still bootstrap
    $late = insertPost($team, $user, '2026-01-02 10:02:01');

    $report = reconstruct([new ActorColumnsRule]);

    expect($report->rule('actor_columns')['rejected'])->toBe(['team_bootstrap' => 2])
        ->and(AuditEvent::query()->sole()->subject_id)->toBe((string) $late);
});

it('honours the configured grace period', function (): void {
    config(['audit-trail.reconstruct.team_creation_grace_seconds' => 10]);
    $user = insertUser();
    $team = insertTeam('2026-01-02 10:00:00');
    insertPost($team, $user, '2026-01-02 10:00:30');

    expect(reconstruct([new ActorColumnsRule])->rule('actor_columns')['accepted'])->toBe(1);
});

it('skips the bootstrap check when the team creation time is a placeholder, and notes it', function (): void {
    $user = insertUser();
    $team = insertTeam('2025-10-10 09:00:00');
    insertPost($team, $user, '2026-01-02 10:00:30');

    reconstruct([new ActorColumnsRule]);

    expect(AuditEvent::query()->sole()->evidence['note'] ?? null)->toContain('team creation time unknown');
});

it('rejects an actor id that is not a user', function (): void {
    $team = insertTeam();
    insertPost($team, 999, '2026-01-02 10:00:00');

    expect(reconstruct([new ActorColumnsRule])->rule('actor_columns')['rejected'])->toBe(['unknown_user' => 1])
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('ignores rows without an actor and tables not configured', function (): void {
    config(['audit-trail.reconstruct.actor_columns' => []]);
    $user = insertUser();
    insertPost(insertTeam(), $user, '2026-01-02 10:00:00');

    expect(reconstruct([new ActorColumnsRule])->rule('actor_columns')['found'])->toBe(0);

    config(['audit-trail.reconstruct.actor_columns' => ['posts' => 'user_id', 'missing_table' => 'user_id', 'teams' => 'nope']]);
    insertPost(insertTeam(), null, '2026-01-02 10:00:00');

    expect(reconstruct([new ActorColumnsRule])->rule('actor_columns')['found'])->toBe(1);
});

it('shows the team_bootstrap rejection in the command report', function (): void {
    $user = insertUser();
    $team = insertTeam('2026-01-02 10:00:00');
    insertPost($team, $user, '2026-01-02 10:00:30');

    $this->artisan('audit-trail:reconstruct', ['--rule' => ['actor_columns', 'sole_member_team'], '--dry-run' => true])
        ->expectsOutputToContain('team_bootstrap: 1')
        ->assertExitCode(0);
});
