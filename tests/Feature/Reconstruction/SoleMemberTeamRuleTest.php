<?php

declare(strict_types=1);

use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\SoleMemberTeamRule;

beforeEach(function (): void {
    config(['audit-trail.reconstruct.sole_member_tables' => ['posts']]);
});

it('attributes a record of a one-member team to that member', function (): void {
    $user = insertUser(['email' => 's@corp.test']);
    $team = insertTeam();
    addMember($team, $user);
    $post = insertPost($team, null, '2026-01-02 10:00:00');

    reconstruct([new SoleMemberTeamRule]);

    $row = AuditEvent::query()->sole();
    expect($row->event)->toBe(AuditEventType::Created)
        ->and($row->user_id)->toBe($user)
        ->and($row->user_email)->toBe('s@corp.test')
        ->and($row->team_id)->toBe($team)
        ->and($row->subject_type)->toBe('table:posts')
        ->and($row->subject_id)->toBe((string) $post)
        ->and($row->evidence_key)->toBe('posts:'.$post.':sole')
        ->and($row->evidence)->toMatchArray([
            'rule' => 'sole_member_team',
            'table' => 'posts',
            'id' => $post,
            'column' => 'team_id',
            'note' => 'sole member at reconstruction time; former members not visible',
        ]);
});

it('ignores teams with more than one member', function (): void {
    $team = insertTeam();
    addMember($team, insertUser());
    addMember($team, insertUser());
    insertPost($team, null, '2026-01-02 10:00:00');

    expect(reconstruct([new SoleMemberTeamRule])->rule('sole_member_team')['found'])->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('rejects a record older than the membership', function (): void {
    $team = insertTeam();
    addMember($team, insertUser(), '2026-01-03 08:00:00');
    insertPost($team, null, '2026-01-02 10:00:00');

    expect(reconstruct([new SoleMemberTeamRule])->rule('sole_member_team')['rejected'])->toBe(['member_joined_later' => 1])
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('accepts a membership without a join time', function (): void {
    $team = insertTeam();
    addMember($team, insertUser(), null);
    insertPost($team, null, '2026-01-02 10:00:00');

    expect(reconstruct([new SoleMemberTeamRule])->rule('sole_member_team')['accepted'])->toBe(1);
});

it('rejects records created within the team bootstrap window', function (): void {
    $user = insertUser(['created_at' => '2026-01-02 09:00:00']);
    $team = insertTeam('2026-01-02 10:00:00');
    addMember($team, $user, '2026-01-02 10:00:00');
    insertPost($team, null, '2026-01-02 10:00:30');

    expect(reconstruct([new SoleMemberTeamRule])->rule('sole_member_team')['rejected'])->toBe(['team_bootstrap' => 1])
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('leaves rows with a configured actor column value to the actor rule', function (): void {
    config(['audit-trail.reconstruct.actor_columns' => ['posts' => 'user_id']]);
    $user = insertUser();
    $team = insertTeam();
    addMember($team, $user);
    insertPost($team, $user, '2026-01-02 10:00:00');

    expect(reconstruct([new SoleMemberTeamRule])->rule('sole_member_team')['found'])->toBe(0);
});

it('ignores tables that are not configured', function (): void {
    config(['audit-trail.reconstruct.sole_member_tables' => []]);
    $team = insertTeam();
    addMember($team, insertUser());
    insertPost($team, null, '2026-01-02 10:00:00');

    expect(reconstruct([new SoleMemberTeamRule])->rule('sole_member_team')['found'])->toBe(0);
});
