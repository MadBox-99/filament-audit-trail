<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Reconstruction\EvidenceRecord;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;
use Madbox99\FilamentAuditTrail\Support\PropertySanitizer;
use Throwable;

/**
 * Lifts existing spatie/laravel-activitylog rows caused by a user. The team is taken from
 * the subject row when its table has a team_id column; otherwise the row stays team-less
 * (visible to its own user and admins only).
 */
final class ActivityLogRule implements Rule
{
    private const int CHUNK = 500;

    /** @var array<string, list<string>> */
    private array $hidden = [];

    /** @var array<string, Model|null> */
    private array $teamModels = [];

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
            ->lazyById(self::CHUNK);

        $buffer = [];

        foreach ($rows as $row) {
            $buffer[] = (array) $row;

            if (count($buffer) >= self::CHUNK) {
                yield from $this->records($ctx, $table, $hasEvent, $buffer);
                $buffer = [];
            }
        }

        yield from $this->records($ctx, $table, $hasEvent, $buffer);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return iterable<EvidenceRecord>
     */
    private function records(ReconstructionContext $ctx, string $table, bool $hasEvent, array $rows): iterable
    {
        $teams = $this->subjectTeams($rows);

        foreach ($rows as $row) {
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

            $subjectType = is_string($row['subject_type'] ?? null) ? $row['subject_type'] : null;
            $subjectId = isset($row['subject_id']) && is_scalar($row['subject_id']) ? (string) $row['subject_id'] : null;
            $properties = is_string($row['properties'] ?? null) ? json_decode($row['properties'], true) : null;

            yield new EvidenceRecord(
                type: $type,
                occurredAt: $occurredAt,
                userId: $userId,
                userEmail: $ctx->userEmail($userId),
                teamId: $subjectType !== null && $subjectId !== null ? ($teams[$subjectType][$subjectId] ?? null) : null,
                subjectType: $subjectType,
                subjectId: $subjectId,
                properties: is_array($properties) ? PropertySanitizer::sanitize($properties, $this->hiddenOf($subjectType)) : [],
                evidence: ['rule' => $this->name(), 'table' => $table, 'id' => $row['id'], 'column' => 'created_at'],
                evidenceKey: 'activity_log:'.$row['id'],
                action: $type === AuditEventType::ActionExecuted ? mb_substr($event, 0, 255) : null,
                rejection: array_key_exists($userId, $ctx->users()) ? null : 'unknown_user',
            );
        }
    }

    /**
     * Best effort: the team_id of each subject row that still exists in a table with a
     * team_id column, one query per subject type and chunk.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, int>> subject type => subject id => team id
     */
    private function subjectTeams(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (is_string($row['subject_type'] ?? null) && isset($row['subject_id']) && is_scalar($row['subject_id'])) {
                $ids[$row['subject_type']][] = (string) $row['subject_id'];
            }
        }

        $teams = [];

        foreach ($ids as $subjectType => $subjectIds) {
            $model = $this->teamScopedModel($subjectType);

            if (! $model instanceof Model) {
                continue;
            }

            try {
                $found = $model->getConnection()->table($model->getTable())
                    ->whereIn($model->getKeyName(), array_values(array_unique($subjectIds)))
                    ->whereNotNull('team_id')
                    ->pluck('team_id', $model->getKeyName());
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            foreach ($found as $id => $teamId) {
                if (is_numeric($teamId)) {
                    $teams[$subjectType][(string) $id] = (int) $teamId;
                }
            }
        }

        return $teams;
    }

    /**
     * The subject's model when its table has a team_id column (class name or morph alias).
     */
    private function teamScopedModel(string $subjectType): ?Model
    {
        if (! array_key_exists($subjectType, $this->teamModels)) {
            $this->teamModels[$subjectType] = null;
            $class = Relation::getMorphedModel($subjectType) ?? $subjectType;

            try {
                if (class_exists($class) && is_subclass_of($class, Model::class)) {
                    $model = new $class;

                    if ($model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'team_id')) {
                        $this->teamModels[$subjectType] = $model;
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $this->teamModels[$subjectType];
    }

    /**
     * The subject model's $hidden attributes (class name or morph alias).
     *
     * @return list<string>
     */
    private function hiddenOf(?string $subjectType): array
    {
        if ($subjectType === null) {
            return [];
        }

        if (! array_key_exists($subjectType, $this->hidden)) {
            $class = Relation::getMorphedModel($subjectType) ?? $subjectType;

            try {
                $this->hidden[$subjectType] = class_exists($class) && is_subclass_of($class, Model::class)
                    ? array_values((new $class)->getHidden())
                    : [];
            } catch (Throwable $e) {
                report($e);
                $this->hidden[$subjectType] = [];
            }
        }

        return $this->hidden[$subjectType];
    }
}
