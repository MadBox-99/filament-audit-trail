<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction\Rules;

use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Reconstruction\EvidenceRecord;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;

/**
 * Rows of the configured tables (`reconstruct.actor_columns`: table => user
 * column) → a created event for the user the row names. Rows made within the
 * grace period after their team was created are system bootstrap data and are
 * rejected as `team_bootstrap`.
 */
final class ActorColumnsRule implements ChecksConfiguration, Rule
{
    public function name(): string
    {
        return 'actor_columns';
    }

    public function configProblems(ReconstructionContext $ctx): array
    {
        $schema = $ctx->connection()->getSchemaBuilder();
        $problems = [];

        foreach ((array) config('audit-trail.reconstruct.actor_columns', []) as $table => $column) {
            $table = (string) $table;

            if (! $schema->hasTable($table)) {
                $problems[] = ['table' => $table, 'column' => null];

                continue;
            }

            foreach (array_diff(['id', 'created_at', (string) $column], $schema->getColumnListing($table)) as $missing) {
                $problems[] = ['table' => $table, 'column' => $missing];
            }
        }

        return $problems;
    }

    public function collect(ReconstructionContext $ctx): iterable
    {
        $schema = $ctx->connection()->getSchemaBuilder();

        foreach ((array) config('audit-trail.reconstruct.actor_columns', []) as $table => $column) {
            if (! is_string($table) || ! is_string($column) || ! $schema->hasTable($table)) {
                continue;
            }

            $columns = $schema->getColumnListing($table);

            if (array_diff(['id', 'created_at', $column], $columns) !== []) {
                continue;
            }

            $hasTeam = in_array('team_id', $columns, true);
            $select = array_values(array_unique(array_filter(['id', 'created_at', $column, $hasTeam ? 'team_id' : null])));

            $rows = $ctx->connection()->table($table)->select($select)->whereNotNull($column)->lazyById(500);

            foreach ($rows as $row) {
                $row = (array) $row;
                $occurredAt = $ctx->parseTimestamp($row['created_at'] ?? null);

                if ($occurredAt === null || ! is_numeric($row[$column])) {
                    continue;
                }

                $userId = (int) $row[$column];
                $teamId = $hasTeam && is_numeric($row['team_id'] ?? null) ? (int) $row['team_id'] : null;
                $evidence = ['rule' => $this->name(), 'table' => $table, 'id' => $row['id'], 'column' => $column];
                $rejection = array_key_exists($userId, $ctx->users()) ? null : 'unknown_user';

                if ($teamId !== null) {
                    $bootstrap = $ctx->isTeamBootstrap($teamId, $occurredAt);

                    if ($bootstrap === null) {
                        $evidence['note'] = ReconstructionContext::UNKNOWN_TEAM_CREATION_NOTE;
                    } elseif ($bootstrap) {
                        $rejection ??= 'team_bootstrap';
                    }
                }

                yield new EvidenceRecord(
                    type: AuditEventType::Created,
                    occurredAt: $occurredAt,
                    userId: $userId,
                    userEmail: $ctx->userEmail($userId),
                    teamId: $teamId,
                    subjectType: 'table:'.$table,
                    subjectId: (string) $row['id'],
                    properties: [],
                    evidence: $evidence,
                    evidenceKey: $table.':'.$row['id'].':actor',
                    rejection: $rejection,
                );
            }
        }
    }
}
