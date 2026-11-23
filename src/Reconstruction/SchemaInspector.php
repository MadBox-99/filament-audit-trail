<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction;

use Illuminate\Database\Connection;

/**
 * Suggests tables for the actor_columns and sole_member_team rules from the
 * schema. Only a suggestion: the rules read the app's config allowlist.
 */
final class SchemaInspector
{
    /** In order of preference: the most specific "who made it" column first. */
    public const array ACTOR_COLUMNS = ['created_by', 'creator_id', 'author_id', 'uploaded_by', 'sender_id', 'user_id'];

    /** Framework, auth, queue and audit tables: never evidence of a person's work. */
    private const array EXCLUDED = [
        'users', 'teams', 'team_user', 'migrations', 'sessions', '*_sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'activity_log', 'imports', 'exports', 'failed_import_rows',
        'notifications', 'password_reset_tokens', 'password_resets', '*token*', 'oauth_*',
        'telescope_*', 'pulse_*', 'audit_events',
        // spatie/laravel-permission
        'roles', 'permissions', 'model_has_*', 'role_has_*',
    ];

    private readonly ReconstructionContext $context;

    /** @var array<string, list<string>>|null */
    private ?array $columns = null;

    public function __construct(?ReconstructionContext $context = null)
    {
        $this->context = $context ?? new ReconstructionContext;
    }

    /**
     * Tables with a user column and created_at: table => column.
     *
     * @return array<string, string>
     */
    public function actorColumnCandidates(): array
    {
        $candidates = [];

        foreach ($this->tables() as $table => $columns) {
            foreach (self::ACTOR_COLUMNS as $column) {
                if (in_array($column, $columns, true)) {
                    $candidates[$table] = $column;

                    break;
                }
            }
        }

        return $candidates;
    }

    /**
     * Tables with team_id and created_at.
     *
     * @return list<string>
     */
    public function teamTableCandidates(): array
    {
        return array_keys(array_filter($this->tables(), fn (array $columns): bool => in_array('team_id', $columns, true)));
    }

    public function rowCount(string $table): int
    {
        return $this->connection()->table($table)->count();
    }

    /**
     * Candidate tables (with an id and created_at, not excluded): name => columns.
     *
     * @return array<string, list<string>>
     */
    private function tables(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $schema = $this->connection()->getSchemaBuilder();
        $prefix = $this->connection()->getTablePrefix();
        $this->columns = [];

        foreach ($schema->getTables($schema->getCurrentSchemaName()) as $table) {
            $name = $table['name'];

            if ($prefix !== '' && str_starts_with($name, $prefix)) {
                $name = substr($name, strlen($prefix));
            }

            if ($this->isExcluded($name)) {
                continue;
            }

            $columns = array_column($schema->getColumns($name), 'name');

            // Pivot tables have no id; rows without a creation time prove nothing.
            if (in_array('id', $columns, true) && in_array('created_at', $columns, true)) {
                $this->columns[$name] = $columns;
            }
        }

        ksort($this->columns);

        return $this->columns;
    }

    private function isExcluded(string $table): bool
    {
        $patterns = [
            ...self::EXCLUDED,
            ...(array) config('audit-trail.models.exclude', []),
            (string) config('audit-trail.table', 'audit_events'),
            (string) config('audit-trail.reconstruct.team_user_table', 'team_user'),
            (string) config('audit-trail.reconstruct.teams_table', 'teams'),
            $this->context->userTable(),
        ];

        foreach ($patterns as $pattern) {
            if (fnmatch((string) $pattern, $table, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    private function connection(): Connection
    {
        return $this->context->connection();
    }
}
