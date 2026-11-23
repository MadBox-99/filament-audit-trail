<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Enums\AuditSource;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Reconstruction\EvidenceRecord;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\AccountCreatedRule;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\Rule;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\Post;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\User;

it('does not duplicate rows when run twice', function (): void {
    insertUser();
    insertUser();

    $first = reconstruct([new AccountCreatedRule]);
    $second = reconstruct([new AccountCreatedRule]);

    expect($first->rule('account_created'))->toMatchArray(['found' => 2, 'accepted' => 2, 'inserted' => 2, 'existing' => 0, 'failed' => 0])
        ->and($second->rule('account_created'))->toMatchArray(['found' => 2, 'accepted' => 2, 'inserted' => 0, 'existing' => 2, 'failed' => 0])
        ->and(AuditEvent::query()->count())->toBe(2);
});

it('writes nothing on a dry run but still reports what it found', function (): void {
    insertUser();

    $report = reconstruct([new AccountCreatedRule], dryRun: true);

    expect($report->rule('account_created'))->toMatchArray(['found' => 1, 'accepted' => 1, 'inserted' => 0, 'failed' => 0])
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('rejects records on a placeholder timestamp', function (): void {
    insertUser(['created_at' => '2025-10-10 09:00:00']);

    $report = reconstruct([new AccountCreatedRule]);

    expect(AuditEvent::query()->where('event', 'account_created')->count())->toBe(0)
        ->and($report->rule('account_created')['rejected'])->toBe(['ignored_timestamp' => 1]);
});

it('rejects evidence older than the account', function (): void {
    $user = insertUser(['created_at' => '2026-02-01 00:00:00']);

    $report = reconstruct([fakeRule('fake', [
        evidence($user, '2026-01-31 23:59:59', 'fake:1'),
        evidence($user, '2026-02-01 00:00:00', 'fake:2'),
    ])]);

    expect($report->rule('fake'))->toMatchArray(['found' => 2, 'accepted' => 1, 'inserted' => 1, 'rejected' => ['before_account' => 1]])
        ->and(AuditEvent::query()->sole()->evidence_key)->toBe('fake:2');
});

it('accepts evidence of a user whose creation time is a placeholder', function (): void {
    $user = insertUser(['created_at' => '2025-10-01 12:00:00']);

    $report = reconstruct([fakeRule('fake', [evidence($user, '2025-01-01 10:00:00', 'fake:1')])]);

    expect($report->rule('fake')['accepted'])->toBe(1);
});

it('rejects seed accounts by email pattern', function (string $email): void {
    $user = insertUser(['email' => $email]);

    $report = reconstruct([new AccountCreatedRule, fakeRule('fake', [evidence($user, '2026-03-01 10:00:00', 'fake:1')])]);

    expect(AuditEvent::query()->count())->toBe(0)
        ->and($report->rule('account_created')['rejected'])->toBe(['excluded_user' => 1])
        ->and($report->rule('fake')['rejected'])->toBe(['excluded_user' => 1]);
})->with(['seed@example.com', 'x@example.org', 'Y@EXAMPLE.hu']);

it('treats the email pattern as SQL LIKE, not as a substring', function (): void {
    $user = insertUser(['email' => 'boss@example-corp.com']);

    $report = reconstruct([fakeRule('fake', [evidence($user, '2026-03-01 10:00:00', 'fake:1')])]);

    expect($report->rule('fake')['accepted'])->toBe(1);
});

it('honours since and until', function (): void {
    $user = insertUser();

    $report = reconstruct([fakeRule('fake', [
        evidence($user, '2026-02-28 23:59:59', 'fake:1'),
        evidence($user, '2026-03-01 10:00:00', 'fake:2'),
        evidence($user, '2026-03-02 00:00:01', 'fake:3'),
    ])], since: '2026-03-01 00:00:00', until: '2026-03-02 00:00:00');

    expect($report->rule('fake'))->toMatchArray(['accepted' => 1, 'rejected' => ['out_of_range' => 2]]);
});

it('stores reconstructed rows with evidence and original time', function (): void {
    $user = insertUser(['email' => 'a@corp.test']);

    reconstruct([fakeRule('fake', [evidence($user, '2026-03-01 10:00:00', 'fake:1', ['userEmail' => 'a@corp.test'])])]);

    $row = AuditEvent::query()->sole();
    expect($row->source)->toBe(AuditSource::Reconstructed)
        ->and($row->event)->toBe(AuditEventType::Created)
        ->and($row->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-03-01 10:00:00')
        ->and($row->user_id)->toBe($user)
        ->and($row->user_email)->toBe('a@corp.test')
        ->and($row->evidence)->toBe(['rule' => 'fake', 'table' => 'posts', 'id' => 1])
        ->and($row->inferred)->toBeFalse();
});

it('counts failed writes separately from existing ones', function (): void {
    insertUser();
    Schema::drop('audit_events');

    $report = reconstruct([new AccountCreatedRule]);

    expect($report->rule('account_created'))->toMatchArray(['accepted' => 1, 'inserted' => 0, 'existing' => 0, 'failed' => 1]);
});

it('exposes accepted records to later rules in order, also on a dry run', function (): void {
    $user = insertUser();
    $seen = new ArrayObject;

    $probe = new class($seen) implements Rule
    {
        public function __construct(private ArrayObject $seen) {}

        public function name(): string
        {
            return 'probe';
        }

        public function collect(ReconstructionContext $ctx): iterable
        {
            $this->seen->exchangeArray(array_map(fn (EvidenceRecord $r): string => $r->evidenceKey, $ctx->accepted()));

            return [];
        }
    };

    reconstruct([
        fakeRule('a', [evidence($user, '2026-03-01 10:00:00', 'a:1'), evidence($user, '2025-01-01 00:00:00', 'a:old')]),
        fakeRule('b', [evidence($user, '2026-03-02 10:00:00', 'b:1')]),
        $probe,
    ], dryRun: true);

    expect($seen->getArrayCopy())->toBe(['a:1', 'b:1']);
});

it('requires an evidence key', function (): void {
    evidence(1, '2026-03-01 10:00:00', '');
})->throws(InvalidArgumentException::class);

it('does not record live model events while reconstructing', function (): void {
    $user = insertUser();
    $this->actingAs(User::query()->findOrFail($user));
    $rule = new class implements Rule
    {
        public function name(): string
        {
            return 'side';
        }

        public function collect(ReconstructionContext $ctx): iterable
        {
            Post::create(['team_id' => 1, 'title' => 'x']);

            return [];
        }
    };

    reconstruct([$rule]);
    expect(AuditEvent::query()->count())->toBe(0);

    // Control: outside a run the same write is recorded, so the assertion above is not vacuous.
    Post::create(['team_id' => 1, 'title' => 'y']);
    expect(AuditEvent::query()->count())->toBe(1);
});

it('summarises users', function (): void {
    $a = insertUser(['email' => 'a@corp.test']);
    $b = insertUser(['email' => 'b@corp.test']);
    insertUser(['email' => 'c@corp.test']);
    insertUser(['email' => 'seed@example.com']);

    $report = reconstruct([
        new AccountCreatedRule,
        fakeRule('fake', [
            evidence($a, '2026-03-01 10:00:00', 'f:1', ['type' => AuditEventType::Login]),
            evidence($a, '2026-03-01 11:00:00', 'f:2'),
            evidence($a, '2026-03-03 11:00:00', 'f:3'),
            evidence($b, '2026-03-02 09:00:00', 'f:4', ['type' => AuditEventType::LoginInferred, 'inferred' => true]),
        ]),
    ]);

    expect($report->userSummary())->toBe([
        'users' => 3,
        'with_login' => 1,
        'with_inferred_login' => 1,
        'only_account_created' => 1,
    ])->and($report->topUsers(5))->toBe([
        ['user_id' => $a, 'email' => 'a@corp.test', 'active_days' => 2],
        ['user_id' => $b, 'email' => 'b@corp.test', 'active_days' => 1],
    ]);
});
