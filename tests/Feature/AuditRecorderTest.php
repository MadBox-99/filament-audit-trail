<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Enums\AuditSource;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Support\AuditRecorder;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\User;

function recorder(): AuditRecorder
{
    return app(AuditRecorder::class);
}

it('is a singleton', function (): void {
    expect(app(AuditRecorder::class))->toBe(app(AuditRecorder::class));
});

it('records a login with context', function (): void {
    $user = User::create(['name' => 'A', 'email' => 'a@corp.test']);
    $this->actingAs($user);

    $event = recorder()->record(AuditEventType::Login);

    expect($event)->not->toBeNull();
    $row = AuditEvent::query()->sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->user_email)->toBe('a@corp.test')
        ->and($row->ip)->toBe('127.0.0.1')
        ->and($row->source)->toBe(AuditSource::Live)
        ->and($row->event)->toBe(AuditEventType::Login)
        ->and($row->occurred_at)->not->toBeNull();
});

it('sanitizes properties and truncates the user agent', function (): void {
    request()->headers->set('User-Agent', str_repeat('u', 600));

    recorder()->record(AuditEventType::Created, ['properties' => ['password' => 'x', 'name' => 'n']]);

    $row = AuditEvent::query()->sole();
    expect($row->properties)->toBe(['name' => 'n'])
        ->and(mb_strlen((string) $row->user_agent))->toBe(512);
});

it('does not record when the event type is disabled', function (): void {
    config(['audit-trail.events.login' => false]);

    expect(recorder()->record(AuditEventType::Login))->toBeNull()
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('does not record when globally disabled', function (): void {
    config(['audit-trail.enabled' => false]);

    expect(recorder()->record(AuditEventType::Login))->toBeNull()
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('does not throw when the table is missing', function (): void {
    Schema::drop('audit_events');

    expect(recorder()->record(AuditEventType::Login))->toBeNull();
});

it('suspends recording within withoutRecording and restores afterwards', function (): void {
    $result = recorder()->withoutRecording(fn () => recorder()->record(AuditEventType::Login));

    expect($result)->toBeNull()
        ->and(AuditEvent::query()->count())->toBe(0);

    expect(fn () => recorder()->withoutRecording(fn () => throw new RuntimeException('x')))->toThrow(RuntimeException::class);

    expect(recorder()->record(AuditEventType::Login))->not->toBeNull();
});

it('inserts a reconstructed row only once per evidence key', function (): void {
    $attributes = [
        'event' => AuditEventType::LoginInferred,
        'user_id' => 1,
        'occurred_at' => '2026-01-01 10:00:00',
        'inferred' => true,
        'evidence' => ['table' => 'posts'],
        'evidence_key' => 'k1',
    ];

    expect(recorder()->recordReconstructed($attributes))->toBeTrue()
        ->and(recorder()->recordReconstructed($attributes))->toBeFalse()
        ->and(AuditEvent::query()->count())->toBe(1);

    $row = AuditEvent::query()->sole();
    expect($row->source)->toBe(AuditSource::Reconstructed)
        ->and($row->evidence)->toBe(['table' => 'posts'])
        ->and($row->created_at)->not->toBeNull()
        ->and($row->ip)->toBeNull();
});

it('reconstructs even inside withoutRecording', function (): void {
    $result = recorder()->withoutRecording(fn () => recorder()->recordReconstructed([
        'event' => AuditEventType::LoginInferred,
        'occurred_at' => '2026-01-01 10:00:00',
        'evidence_key' => 'k2',
    ]));

    expect($result)->toBeTrue();
});
