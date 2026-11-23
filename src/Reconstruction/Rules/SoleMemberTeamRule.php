<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction\Rules;

use Carbon\CarbonImmutable;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Reconstruction\EvidenceRecord;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;

/**
 * Rows of the configured tables (`reconstruct.sole_member_tables`) whose team
 * has exactly one member now → a created event for that member. Rejected when
 * the member joined after the row was made (`member_joined_later`) or the row
 * is team bootstrap data (`team_bootstrap`). Former members are not visible,
 * which the evidence note records.
 */
final class SoleMemberTeamRule implements ChecksConfiguration, Rule
{
    public const string NOTE = 'sole member at reconstruction time; former members not visible';

    public function name(): string
    {
        return 'sole_member_team';
    }

    public function configProblems(ReconstructionContext $ctx): array
    {
        $tables = (array) config('audit-trail.reconstruct.sole_member_tables', []);

        if ($tables === []) {
            return [];
        }

        $schema = $ctx->connection()->getSchemaBuilder();
        $problems = [];
        /** @var string $pivot */
        $pivot = config('audit-trail.reconstruct.team_user_table', 'team_user');

        foreach ([$pivot => ['team_id', 'user_id'], ...array_fill_keys(array_map(strval(...), $tables), ['id', 'team_id', 'created_at'])] as $table => $required) {
            $table = (string) $table;

            if (! $schema->hasTable($table)) {
                $problems[] = ['table' => $table, 'column' => null];

                continue;
            }

            foreach (array_diff($required, $schema->getColumnListing($table)) as $missing) {
                $problems[] = ['table' => $table, 'column' => $missing];
            }
        }

        return [...$problems, ...$ctx->teamsTableProblems()];
    }

    public function collect(ReconstructionContext $ctx): iterable
    {
        $tables = (array) config('audit-trail.reconstruct.sole_member_tables', []);

        if ($tables === []) {
            return;
        }

        $members = $this->soleMembers($ctx);

        if ($members === []) {
            return;
        }

        $schema = $ctx->connection()->getSchemaBuilder();
        /** @var array<string, mixed> $actorColumns */
        $actorColumns = (array) config('audit-trail.reconstruct.actor_columns', []);

        foreach ($tables as $table) {
            if (! is_string($table) || ! $schema->hasTable($table)) {
                continue;
            }

            $columns = $schema->getColumnListing($table);

            if (array_diff(['id', 'team_id', 'created_at'], $columns) !== []) {
                continue;
            }

            // A row naming its own actor is the actor rule's evidence, and stronger.
            $actor = $actorColumns[$table] ?? null;
            $actor = is_string($actor) && in_array($actor, $columns, true) ? $actor : null;

            $query = $ctx->connection()->table($table)
                ->select(array_values(array_unique(array_filter(['id', 'team_id', 'created_at', $actor]))))
                ->whereNotNull('team_id');

            if ($actor !== null) {
                $query->whereNull($actor);
            }

            foreach ($query->lazyById(500) as $row) {
                $row = (array) $row;

                if (! is_numeric($row['team_id']) || ! isset($members[(int) $row['team_id']])) {
                    continue;
                }

                $occurredAt = $ctx->parseTimestamp($row['created_at'] ?? null);

                if ($occurredAt === null) {
                    continue;
                }

                $teamId = (int) $row['team_id'];
                [$userId, $joinedAt] = $members[$teamId];

                $evidence = ['rule' => $this->name(), 'table' => $table, 'id' => $row['id'], 'column' => 'team_id', 'note' => self::NOTE];

                if ($joinedAt !== null) {
                    $evidence['member_since'] = $joinedAt->format('Y-m-d H:i:s');
                }

                $rejection = array_key_exists($userId, $ctx->users()) ? null : 'unknown_user';
                $bootstrap = $ctx->isTeamBootstrap($teamId, $occurredAt);

                if ($bootstrap === null) {
                    $evidence['note'] .= '; '.ReconstructionContext::UNKNOWN_TEAM_CREATION_NOTE;
                } elseif ($bootstrap) {
                    $rejection ??= 'team_bootstrap';
                }

                if ($joinedAt !== null && $joinedAt->gt($occurredAt)) {
                    $rejection ??= 'member_joined_later';
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
                    evidenceKey: $table.':'.$row['id'].':sole',
                    rejection: $rejection,
                );
            }
        }
    }

    /**
     * Teams with exactly one member now: team id => [user id, latest real join time or null].
     *
     * @return array<int, array{int, CarbonImmutable|null}>
     */
    private function soleMembers(ReconstructionContext $ctx): array
    {
        /** @var string $pivot */
        $pivot = config('audit-trail.reconstruct.team_user_table', 'team_user');
        $schema = $ctx->connection()->getSchemaBuilder();

        if (! $schema->hasTable($pivot) || ! $schema->hasColumns($pivot, ['team_id', 'user_id'])) {
            return [];
        }

        $hasJoinedAt = $schema->hasColumn($pivot, 'created_at');

        /** @var array<int, array<int, CarbonImmutable|null>> $teams team id => user id => joined at */
        $teams = [];

        $rows = $ctx->connection()->table($pivot)
            ->select($hasJoinedAt ? ['team_id', 'user_id', 'created_at'] : ['team_id', 'user_id'])
            ->whereNotNull('team_id')
            ->whereNotNull('user_id')
            ->cursor();

        foreach ($rows as $row) {
            $row = (array) $row;

            if (! is_numeric($row['team_id']) || ! is_numeric($row['user_id'])) {
                continue;
            }

            $teamId = (int) $row['team_id'];
            $userId = (int) $row['user_id'];

            $joinedAt = $hasJoinedAt ? $ctx->parseTimestamp($row['created_at'] ?? null) : null;
            $joinedAt = $ctx->isIgnoredTimestamp($joinedAt) ? null : $joinedAt;
            $known = $teams[$teamId][$userId] ?? null;

            // Duplicate pivot rows: keep the latest join time, the conservative bound.
            $teams[$teamId][$userId] = $known === null || ($joinedAt !== null && $joinedAt->gt($known)) ? ($joinedAt ?? $known) : $known;
        }

        $sole = [];

        foreach ($teams as $teamId => $users) {
            if (count($users) === 1) {
                $sole[$teamId] = [(int) array_key_first($users), $users[array_key_first($users)]];
            }
        }

        return $sole;
    }
}
