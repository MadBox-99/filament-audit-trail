<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;
use Madbox99\FilamentAuditTrail\Reconstruction\Reconstructor;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\AccountCreatedRule;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\ActivityLogRule;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\ImportExportRule;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\Rule;
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

        $rules = $this->rules();

        /** @var list<string> $only */
        $only = (array) $this->option('rule');

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
     * the derived-login rule always goes last.
     *
     * @return list<Rule>
     */
    private function rules(): array
    {
        return [
            new ActivityLogRule,
            new ImportExportRule,
            new AccountCreatedRule,
        ];
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
