<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction\Rules;

use Illuminate\Support\Facades\DB;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Reconstruction\EvidenceRecord;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;
use Madbox99\FilamentAuditTrail\Support\PropertySanitizer;

/**
 * Lifts existing spatie/laravel-activitylog rows caused by a user.
 */
final class ActivityLogRule implements Rule
{
    public function name(): string
    {
        return 'activity_log';
    }

    public function collect(ReconstructionContext $ctx): iterable
    {
        /** @var string|null $connectionName */
        $connectionName = config('activitylog.database_connection');
        $connection = $connectionName === null ? $ctx->connection() : DB::connection($connectionName);
        /** @var string $table */
        $table = config('activitylog.table_name') ?? 'activity_log';
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            return;
        }

        $hasEvent = $schema->hasColumn($table, 'event');

        $rows = $connection->table($table)
            ->whereIn('causer_type', $ctx->userMorphClasses())
            ->whereNotNull('causer_id')
            ->lazyById(500);

        foreach ($rows as $row) {
            $row = (array) $row;
            $occurredAt = $ctx->parseTimestamp($row['created_at'] ?? null);

            if ($occurredAt === null || ! is_numeric($row['causer_id'])) {
                continue;
            }

            $userId = (int) $row['causer_id'];
            $event = $hasEvent && is_string($row['event'] ?? null) && $row['event'] !== ''
                ? $row['event']
                : (string) $row['description'];

            $type = match ($event) {
                'created' => AuditEventType::Created,
                'updated' => AuditEventType::Updated,
                'deleted' => AuditEventType::Deleted,
                'restored' => AuditEventType::Restored,
                default => AuditEventType::ActionExecuted,
            };

            $properties = is_string($row['properties'] ?? null) ? json_decode($row['properties'], true) : null;

            yield new EvidenceRecord(
                type: $type,
                occurredAt: $occurredAt,
                userId: $userId,
                userEmail: $ctx->userEmail($userId),
                teamId: null,
                subjectType: is_string($row['subject_type'] ?? null) ? $row['subject_type'] : null,
                subjectId: isset($row['subject_id']) && is_scalar($row['subject_id']) ? (string) $row['subject_id'] : null,
                properties: is_array($properties) ? PropertySanitizer::sanitize($properties) : [],
                evidence: ['rule' => $this->name(), 'table' => $table, 'id' => $row['id'], 'column' => 'created_at'],
                evidenceKey: 'activity_log:'.$row['id'],
                action: $type === AuditEventType::ActionExecuted ? mb_substr($event, 0, 255) : null,
            );
        }
    }
}
