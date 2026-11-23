<?php

declare(strict_types=1);

use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\AccountCreatedRule;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\User;

it('records account creation without an actor in receiver mode', function (): void {
    $user = insertUser(['created_at' => '2026-01-05 07:30:00', 'email_verified_at' => '2026-01-05 07:40:00']);

    reconstruct([new AccountCreatedRule]);

    $row = AuditEvent::query()->sole();
    expect($row->event)->toBe(AuditEventType::AccountCreated)
        ->and($row->user_id)->toBeNull()
        ->and($row->subject_type)->toBe((new User)->getMorphClass())
        ->and($row->subject_id)->toBe((string) $user)
        ->and($row->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-01-05 07:30:00')
        ->and($row->evidence_key)->toBe('users:'.$user.':created')
        ->and($row->evidence)->toMatchArray(['rule' => 'account_created', 'table' => 'users', 'id' => $user, 'column' => 'created_at'])
        ->and(AuditEvent::query()->where('event', 'login_inferred')->count())->toBe(0);
});

it('treats registration and email verification as inferred logins on the publisher', function (): void {
    config(['audit-trail.reconstruct.publisher' => true]);
    $user = insertUser(['email' => 'p@corp.test', 'created_at' => '2026-01-05 07:30:00', 'email_verified_at' => '2026-01-05 07:40:00']);

    reconstruct([new AccountCreatedRule]);

    $logins = AuditEvent::query()->where('event', 'login_inferred')->orderBy('occurred_at')->get();
    expect($logins)->toHaveCount(2)
        ->and($logins[0]->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-01-05 07:30:00')
        ->and($logins[0]->user_id)->toBe($user)
        ->and($logins[0]->user_email)->toBe('p@corp.test')
        ->and($logins[0]->inferred)->toBeTrue()
        ->and($logins[0]->evidence)->toMatchArray(['rule' => 'registration', 'column' => 'created_at'])
        ->and($logins[0]->evidence_key)->toBe('users:'.$user.':registration')
        ->and($logins[1]->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-01-05 07:40:00')
        ->and($logins[1]->evidence)->toMatchArray(['rule' => 'email_verification', 'column' => 'email_verified_at'])
        ->and($logins[1]->evidence_key)->toBe('users:'.$user.':verified')
        ->and(AuditEvent::query()->where('event', 'account_created')->sole()->user_id)->toBeNull();
});

it('does not infer logins for unverified users on the publisher', function (): void {
    config(['audit-trail.reconstruct.publisher' => true]);
    insertUser(['email_verified_at' => null]);

    reconstruct([new AccountCreatedRule]);

    expect(AuditEvent::query()->where('event', 'login_inferred')->count())->toBe(0)
        ->and(AuditEvent::query()->where('event', 'account_created')->count())->toBe(1);
});

it('skips users without a creation time', function (): void {
    insertUser(['created_at' => null]);

    expect(reconstruct([new AccountCreatedRule])->rule('account_created')['found'])->toBe(0);
});

it('infers no login for a placeholder creation time, even when verified', function (): void {
    config(['audit-trail.reconstruct.publisher' => true]);
    insertUser(['created_at' => '2025-10-10 09:00:00', 'email_verified_at' => '2026-01-05 07:40:00']);

    reconstruct([new AccountCreatedRule]);

    expect(AuditEvent::query()->where('event', 'login_inferred')->count())->toBe(0);
});

it('infers only the registration login when the email was verified at the moment of registration', function (): void {
    config(['audit-trail.reconstruct.publisher' => true]);
    $user = insertUser(['created_at' => '2026-01-05 07:30:00', 'email_verified_at' => '2026-01-05 07:30:00']);

    reconstruct([new AccountCreatedRule]);

    expect(AuditEvent::query()->where('event', 'login_inferred')->pluck('evidence_key')->all())
        ->toBe(['users:'.$user.':registration']);
});
