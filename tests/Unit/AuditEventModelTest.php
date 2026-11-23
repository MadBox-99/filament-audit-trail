<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Enums\AuditSource;
use Madbox99\FilamentAuditTrail\Exceptions\AuditEventImmutable;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;

function makeEvent(array $overrides = []): AuditEvent
{
    return AuditEvent::create(array_merge([
        'occurred_at' => now(),
        'event' => AuditEventType::Login,
        'source' => AuditSource::Live,
    ], $overrides));
}

it('casts enums and json', function (): void {
    $id = makeEvent(['properties' => ['a' => 1]])->id;

    $event = AuditEvent::findOrFail($id);

    expect($event->event)->toBe(AuditEventType::Login)
        ->and($event->source)->toBe(AuditSource::Live)
        ->and($event->properties)->toBe(['a' => 1])
        ->and($event->inferred)->toBeFalse();
});

it('refuses updates', function (): void {
    $event = makeEvent();

    $event->update(['user_email' => 'x']);
})->throws(AuditEventImmutable::class);

it('refuses deletes', function (): void {
    $event = makeEvent();

    try {
        $event->delete();
    } catch (AuditEventImmutable) {
    }

    expect(AuditEvent::count())->toBe(1);

    $event->delete();
})->throws(AuditEventImmutable::class);

it('enforces unique evidence_key', function (): void {
    makeEvent(['evidence_key' => 'rule:table:1']);
    makeEvent(['evidence_key' => 'rule:table:1']);
})->throws(QueryException::class);

it('has translated enum labels', function (): void {
    app()->setLocale('hu');

    expect(AuditEventType::LoginInferred->getLabel())->toBe('Belépés (levezetett)');
});

it('keeps milliseconds of occurred_at and created_at', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00.123'));

    $id = makeEvent(['occurred_at' => now()])->id;
    $event = AuditEvent::findOrFail($id);

    expect($event->occurred_at->format('Y-m-d H:i:s.v'))->toBe('2026-03-01 10:00:00.123')
        ->and($event->created_at?->format('Y-m-d H:i:s.v'))->toBe('2026-03-01 10:00:00.123')
        ->and($event->getRawOriginal('occurred_at'))->toBe('2026-03-01 10:00:00.123');
});

it('serialises its dates with milliseconds', function (): void {
    expect((new AuditEvent)->getDateFormat())->toBe('Y-m-d H:i:s.v');
});

it('indexes team and user timelines', function (): void {
    $indexes = array_map(fn (array $index): array => $index['columns'], Schema::getIndexes('audit_events'));

    expect($indexes)->toContain(['team_id', 'occurred_at'], ['user_id', 'occurred_at']);
});
