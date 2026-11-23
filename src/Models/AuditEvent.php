<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Enums\AuditSource;
use Madbox99\FilamentAuditTrail\Exceptions\AuditEventImmutable;

/**
 * @property int $id
 * @property Carbon $occurred_at
 * @property AuditEventType $event
 * @property int|null $user_id
 * @property string|null $user_email
 * @property int|null $team_id
 * @property string|null $panel
 * @property string|null $route
 * @property string|null $url
 * @property string|null $method
 * @property string|null $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $properties
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $session_hash
 * @property AuditSource $source
 * @property bool $inferred
 * @property array<string, mixed>|null $evidence
 * @property string|null $evidence_key
 * @property Carbon|null $created_at
 * @property string|null $hash
 */
final class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        /** @var string $table */
        $table = config('audit-trail.table', 'audit_events');

        return $table;
    }

    public function getConnectionName(): ?string
    {
        /** @var string|null $connection */
        $connection = config('audit-trail.connection');

        return $connection;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'event' => AuditEventType::class,
            'source' => AuditSource::class,
            'properties' => 'array',
            'evidence' => 'array',
            'inferred' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw AuditEventImmutable::make('update');
        });

        self::deleting(function (): never {
            throw AuditEventImmutable::make('delete');
        });
    }
}
