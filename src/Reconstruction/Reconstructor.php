<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction;

use Illuminate\Support\Facades\DB;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\Rule;
use Madbox99\FilamentAuditTrail\Support\AuditRecorder;
use Throwable;

/**
 * Runs rules, filters their records and writes the accepted ones idempotently
 * (one row per evidence_key). One transaction per rule.
 */
final class Reconstructor
{
    private const int CHUNK = 500;

    /** @var array<string, true> */
    private array $handledKeys = [];

    public function __construct(
        private readonly ReconstructionContext $context,
        private readonly AuditRecorder $recorder,
    ) {}

    /**
     * @param  list<Rule>  $rules  run in the given order
     */
    public function run(array $rules, bool $dryRun): ReconstructionReport
    {
        $this->handledKeys = [];

        $users = [];
        foreach ($this->context->users() as $id => $user) {
            if (! $this->context->isExcludedUser($id)) {
                $users[$id] = $user['email'];
            }
        }

        $report = new ReconstructionReport($dryRun, $users);

        $this->recorder->withoutRecording(function () use ($rules, $dryRun, $report): void {
            foreach ($rules as $rule) {
                $this->runRule($rule, $dryRun, $report);
            }
        });

        return $report;
    }

    private function runRule(Rule $rule, bool $dryRun, ReconstructionReport $report): void
    {
        $name = $rule->name();
        $report->startRule($name);

        $process = function () use ($rule, $name, $dryRun, $report): void {
            $buffer = [];

            foreach ($rule->collect($this->context) as $record) {
                $report->found($name);

                $reason = $this->rejectionReason($record);
                if ($reason !== null) {
                    $report->rejected($name, $reason);

                    continue;
                }

                $report->accepted($name, $record, $this->context->concernedUserId($record));
                $this->context->addAccepted($record);
                $buffer[] = $record;

                if (count($buffer) >= self::CHUNK) {
                    $this->flush($name, $buffer, $dryRun, $report);
                    $buffer = [];
                }
            }

            $this->flush($name, $buffer, $dryRun, $report);
        };

        try {
            if ($dryRun) {
                $process();
            } else {
                DB::connection((new AuditEvent)->getConnectionName())->transaction($process);
            }
        } catch (Throwable $e) {
            report($e);
            $report->ruleFailed($name, $e->getMessage(), rolledBack: ! $dryRun);
        }
    }

    private function rejectionReason(EvidenceRecord $record): ?string
    {
        if ($this->context->isIgnoredTimestamp($record->occurredAt)) {
            return 'ignored_timestamp';
        }

        $userId = $this->context->concernedUserId($record);

        if ($userId !== null && $this->context->isExcludedUser($userId)) {
            return 'excluded_user';
        }

        if (($this->context->since() !== null && $record->occurredAt->lt($this->context->since()))
            || ($this->context->until() !== null && $record->occurredAt->gt($this->context->until()))) {
            return 'out_of_range';
        }

        $createdAt = $userId === null ? null : $this->context->userCreatedAt($userId);

        if ($createdAt !== null && $record->occurredAt->lt($createdAt)) {
            return 'before_account';
        }

        return null;
    }

    /**
     * @param  list<EvidenceRecord>  $records
     */
    private function flush(string $rule, array $records, bool $dryRun, ReconstructionReport $report): void
    {
        if ($records === []) {
            return;
        }

        $existing = $this->existingKeys(array_map(fn (EvidenceRecord $r): string => $r->evidenceKey, $records));

        foreach ($records as $record) {
            $key = $record->evidenceKey;

            if (isset($existing[$key]) || isset($this->handledKeys[$key])) {
                $report->existing($rule);

                continue;
            }

            $this->handledKeys[$key] = true;

            if ($dryRun) {
                continue;
            }

            if ($this->recorder->recordReconstructed($record->toAttributes())) {
                $report->inserted($rule);
            } else {
                $report->failed($rule);
            }
        }
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, true>
     */
    private function existingKeys(array $keys): array
    {
        try {
            $model = new AuditEvent;

            /** @var list<string> $found */
            $found = DB::connection($model->getConnectionName())
                ->table($model->getTable())
                ->whereIn('evidence_key', $keys)
                ->pluck('evidence_key')
                ->all();

            return array_fill_keys($found, true);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }
}
