<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Enums\AuditSource;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\ActivityLogRule;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\HiddenPost;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\User;

beforeEach(function (): void {
    Schema::create('activity_log', function (Blueprint $table): void {
        $table->id();
        $table->string('log_name')->nullable();
        $table->text('description');
        $table->nullableMorphs('subject', 'subject');
        $table->string('event')->nullable();
        $table->nullableMorphs('causer', 'causer');
        $table->json('properties')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamps();
    });
});

afterEach(function (): void {
    Relation::morphMap([], false);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function activity(array $attributes = []): int
{
    return (int) DB::table('activity_log')->insertGetId([
        'log_name' => 'default',
        'description' => 'created',
        'subject_type' => 'App\\Models\\Post',
        'subject_id' => 7,
        'event' => 'created',
        'causer_type' => User::class,
        'causer_id' => 1,
        'properties' => json_encode(['attributes' => ['title' => 'a', 'password' => 'p']]),
        'created_at' => '2026-03-01 10:00:00',
        'updated_at' => '2026-03-01 10:00:00',
        ...$attributes,
    ]);
}

it('lifts a spatie activity row into a created event', function (): void {
    $user = insertUser(['email' => 'a@corp.test']);
    $id = activity(['causer_id' => $user]);

    $report = reconstruct([new ActivityLogRule]);

    $row = AuditEvent::query()->sole();
    expect($row->event)->toBe(AuditEventType::Created)
        ->and($row->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-03-01 10:00:00')
        ->and($row->source)->toBe(AuditSource::Reconstructed)
        ->and($row->user_id)->toBe($user)
        ->and($row->user_email)->toBe('a@corp.test')
        ->and($row->subject_type)->toBe('App\\Models\\Post')
        ->and($row->subject_id)->toBe('7')
        ->and($row->evidence['table'])->toBe('activity_log')
        ->and($row->evidence['rule'])->toBe('activity_log')
        ->and($row->evidence['id'])->toBe($id)
        ->and($row->evidence_key)->toBe('activity_log:'.$id)
        ->and($row->properties)->toBe(['attributes' => ['title' => 'a']])
        ->and($report->rule('activity_log')['inserted'])->toBe(1);
});

it('maps events to audit event types', function (string $event, AuditEventType $type, ?string $action): void {
    $user = insertUser();
    activity(['causer_id' => $user, 'event' => $event]);

    reconstruct([new ActivityLogRule]);

    $row = AuditEvent::query()->sole();
    expect($row->event)->toBe($type)->and($row->action)->toBe($action);
})->with([
    ['created', AuditEventType::Created, null],
    ['updated', AuditEventType::Updated, null],
    ['deleted', AuditEventType::Deleted, null],
    ['viewed', AuditEventType::ActionExecuted, 'viewed'],
    ['handover', AuditEventType::ActionExecuted, 'handover'],
]);

it('falls back to the description when the event column is empty', function (): void {
    $user = insertUser();
    activity(['causer_id' => $user, 'event' => null, 'description' => 'updated']);

    reconstruct([new ActivityLogRule]);

    expect(AuditEvent::query()->sole()->event)->toBe(AuditEventType::Updated);
});

it('ignores rows not caused by a user', function (): void {
    $user = insertUser();
    activity(['causer_id' => $user, 'causer_type' => 'App\\Models\\Robot']);
    activity(['causer_id' => null, 'causer_type' => null]);

    $report = reconstruct([new ActivityLogRule]);

    expect(AuditEvent::query()->count())->toBe(0)
        ->and($report->rule('activity_log')['found'])->toBe(0);
});

it('accepts the morph alias of the user model', function (): void {
    Relation::morphMap(['user' => User::class]);
    $user = insertUser();
    activity(['causer_id' => $user, 'causer_type' => 'user']);

    reconstruct([new ActivityLogRule]);

    expect(AuditEvent::query()->sole()->user_id)->toBe($user);
});

it('is silently empty without an activity_log table', function (): void {
    Schema::drop('activity_log');

    $report = reconstruct([new ActivityLogRule]);

    expect($report->rule('activity_log'))->toMatchArray(['found' => 0, 'inserted' => 0, 'failed' => 0]);
});

it('drops attributes hidden on the subject model', function (string $subjectType): void {
    Relation::morphMap(['hidden_post' => HiddenPost::class]);
    $user = insertUser();
    activity([
        'causer_id' => $user,
        'event' => 'updated',
        'subject_type' => $subjectType,
        'properties' => json_encode([
            'attributes' => ['title' => 'new secret', 'team_id' => 2],
            'old' => ['title' => 'old secret', 'team_id' => 1],
        ]),
    ]);

    reconstruct([new ActivityLogRule]);

    expect(AuditEvent::query()->sole()->properties)->toBe([
        'attributes' => ['team_id' => 2],
        'old' => ['team_id' => 1],
    ]);
})->with([
    'class name' => [HiddenPost::class],
    'morph alias' => ['hidden_post'],
]);
