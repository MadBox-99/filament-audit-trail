<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;
use Madbox99\FilamentAuditTrail\Reconstruction\Reconstructor;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\AccountCreatedRule;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\ActivityLogRule;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\ActorColumnsRule;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\ChecksConfiguration;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\DerivedLoginRule;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\ImportExportRule;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\Rule;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\SoleMemberTeamRule;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\SsoLoginRule;
use Madbox99\FilamentAuditTrail\Reconstruction\SchemaInspector;
use Throwable;

final class ReconstructCommand extends Command
{
    protected $signature = 'audit-trail:reconstruct
        {--dry-run : Report only, write nothing}
        {--rule=* : Only run these rules (by name)}
        {--sso-file= : Publisher OAuth token export (JSON)}
        {--since= : Ignore evidence before this time}
        {--until= : Ignore evidence after this time (a bare date means its end)}
        {--suggest : Suggest actor-column and sole-member tables}';

    protected $description = 'Reconstruct past audit events from real records (idempotent).';

    public function handle(): int
    {
        try {
            $since = $this->time('since', endOfDay: false);
            $until = $this->time('until', endOfDay: true);
        } catch (Throwable) {
            $this->components->error(__('audit-trail::audit-trail.reconstruct.invalid_time'));

            return self::FAILURE;
        }

        if ($this->option('suggest') === true) {
            return $this->suggest();
        }

        /** @var list<string> $only */
        $only = (array) $this->option('rule');
        $ssoFile = $this->option('sso-file');
        $ssoRule = null;

        if (is_string($ssoFile) && $ssoFile !== '') {
            try {
                $ssoRule = new SsoLoginRule($ssoFile);
            } catch (InvalidArgumentException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        } elseif (in_array('sso_login', $only, true)) {
            $this->components->error(__('audit-trail::audit-trail.reconstruct.sso_file_required'));

            return self::FAILURE;
        }

        $rules = $this->rules($ssoRule);

        if ($only !== []) {
            $names = array_map(fn (Rule $rule): string => $rule->name(), $rules);
            $unknown = array_diff($only, $names);

            if ($unknown !== []) {
                $this->components->error(__('audit-trail::audit-trail.reconstruct.unknown_rule', [
                    'rules' => implode(', ', $unknown),
                    'known' => implode(', ', $names),
                ]));

                return self::FAILURE;
            }

            $rules = array_values(array_filter($rules, fn (Rule $rule): bool => in_array($rule->name(), $only, true)));
        }

        $context = new ReconstructionContext($since, $until);

        if ($ssoRule instanceof SsoLoginRule && in_array($ssoRule, $rules, true)) {
            $this->components->info(__('audit-trail::audit-trail.reconstruct.sso_file_info', [
                'total' => $ssoRule->entryCount(),
                'matching' => $ssoRule->hostEntryCount(),
                'host' => $ssoRule->host(),
            ]));
        }

        foreach ($rules as $rule) {
            if (! $rule instanceof ChecksConfiguration) {
                continue;
            }

            foreach ($rule->configProblems($context) as $problem) {
                $this->components->warn(__(
                    'audit-trail::audit-trail.reconstruct.'.($problem['column'] === null ? 'missing_table' : 'missing_column'),
                    ['rule' => $rule->name(), 'table' => $problem['table'], 'column' => (string) $problem['column']],
                ));
            }
        }

        $report = app(Reconstructor::class, ['context' => $context])->run($rules, (bool) $this->option('dry-run'));

        if ($report->dryRun) {
            $this->components->warn(__('audit-trail::audit-trail.reconstruct.dry_run'));
        }

        $this->table(
            array_map(fn (string $key): string => __('audit-trail::audit-trail.reconstruct.columns.'.$key), [
                'rule', 'found', 'accepted', 'rejected', 'inserted', 'existing', 'failed', 'error',
            ]),
            $report->toTable(),
        );

        foreach ($report->bootstrapUncheckedCounts() as $rule => $count) {
            $this->components->warn(__('audit-trail::audit-trail.reconstruct.bootstrap_unchecked', ['rule' => $rule, 'count' => $count]));
        }

        $summary = $report->userSummary();
        $this->table(
            [__('audit-trail::audit-trail.reconstruct.summary.metric'), __('audit-trail::audit-trail.reconstruct.summary.count')],
            array_map(
                fn (string $key, int $count): array => [__('audit-trail::audit-trail.reconstruct.summary.'.$key), $count],
                array_keys($summary),
                $summary,
            ),
        );

        $this->table(
            [
                __('audit-trail::audit-trail.reconstruct.top.user_id'),
                __('audit-trail::audit-trail.reconstruct.top.email'),
                __('audit-trail::audit-trail.reconstruct.top.active_days'),
            ],
            array_map(fn (array $row): array => array_values($row), $report->topUsers(5)),
        );

        return self::SUCCESS;
    }

    /**
     * The rules in run order. Rules that read earlier results
     * (ReconstructionContext::accepted()) must come after those they read;
     * the derived-login rule always goes last. The SSO rule runs only with --sso-file.
     *
     * @return list<Rule>
     */
    private function rules(?SsoLoginRule $sso): array
    {
        return array_values(array_filter([
            new ActivityLogRule,
            new ImportExportRule,
            new AccountCreatedRule,
            new ActorColumnsRule,
            new SoleMemberTeamRule,
            $sso,
            new DerivedLoginRule,
        ]));
    }

    /**
     * Prints table candidates for the actor_columns and sole_member_team rules
     * and a config snippet to paste. Writes nothing.
     */
    private function suggest(): int
    {
        $inspector = app(SchemaInspector::class);
        $actors = $inspector->actorColumnCandidates();
        $teams = $inspector->teamTableCandidates();
        $label = fn (string $key): string => __('audit-trail::audit-trail.reconstruct.suggest.'.$key);

        $this->components->warn($label('intro'));
        $this->components->info($label('actor_columns'));
        $this->table(
            [$label('table'), $label('column'), $label('rows')],
            array_map(fn (string $table, string $column): array => [$table, $column, $inspector->rowCount($table)], array_keys($actors), $actors),
        );

        $this->components->info($label('sole_member_tables'));
        $this->table(
            [$label('table'), $label('rows')],
            array_map(fn (string $table): array => [$table, $inspector->rowCount($table)], $teams),
        );

        $snippet = ["    'actor_columns' => ["];
        foreach ($actors as $table => $column) {
            $snippet[] = '        '.var_export($table, true).' => '.var_export($column, true).',';
        }
        $snippet[] = '    ],';
        $snippet[] = "    'sole_member_tables' => [";
        foreach ($teams as $table) {
            $snippet[] = '        '.var_export($table, true).',';
        }
        $snippet[] = '    ],';

        $this->components->info($label('snippet'));
        $this->line(implode(PHP_EOL, $snippet));

        return self::SUCCESS;
    }

    private function time(string $option, bool $endOfDay): ?CarbonImmutable
    {
        $value = $this->option($option);

        if (! is_string($value) || $value === '') {
            return null;
        }

        $time = CarbonImmutable::parse($value);

        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $time->endOfDay();
        }

        return $time;
    }
}
