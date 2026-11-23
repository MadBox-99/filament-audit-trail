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

        // Committed to the context, the report's user stats and the handled keys
        // only once the rule has finished: a rolled-back rule leaves no trace. Only records
        // that are stored (inserted now or already there) are committed, so later rules
        // never cite a row whose write failed; a dry run commits every accepted record.
        /** @var list<array{EvidenceRecord, int|null}> $accepted */
        $accepted = [];
        /** @var array<string, true> $keys */
        $keys = [];

        $process = function () use ($rule, $name, $dryRun, $report, &$accepted, &$keys): void {
            $buffer = [];

            foreach ($rule->collect($this->context) as $record) {
                $report->found($name);

                $reason = $this->rejectionReason($record);
                if ($reason !== null) {
                    $report->rejected($name, $reason);

                    continue;
                }

                $report->accepted($name);

                if (str_contains((string) ($record->evidence['note'] ?? ''), ReconstructionContext::UNKNOWN_TEAM_CREATION_NOTE)) {
                    $report->bootstrapUnchecked($name);
                }
                $buffer[] = $record;

                if (count($buffer) >= self::CHUNK) {
                    $this->flush($name, $buffer, $dryRun, $report, $keys, $accepted);
                    $buffer = [];
                }
            }

            $this->flush($name, $buffer, $dryRun, $report, $keys, $accepted);
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

            return;
        }

        foreach ($accepted as [$record, $userId]) {
            $this->context->addAccepted($record);
            $report->user($record, $userId);
        }

        $this->handledKeys += $keys;
    }

    private function rejectionReason(EvidenceRecord $record): ?string
    {
        if ($record->rejection !== null) {
            return $record->rejection;
        }

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
     * @param  array<string, true>  $keys  keys handled by the current rule
     * @param  list<array{EvidenceRecord, int|null}>  $accepted  records to commit once the rule has finished
     */
    private function flush(string $rule, array $records, bool $dryRun, ReconstructionReport $report, array &$keys, array &$accepted): void
    {
        if ($records === []) {
            return;
        }

        $existing = $this->existingKeys(array_map(fn (EvidenceRecord $r): string => $r->evidenceKey, $records));

        foreach ($records as $record) {
            $key = $record->evidenceKey;

            if (isset($existing[$key]) || isset($this->handledKeys[$key]) || isset($keys[$key])) {
                $report->existing($rule);
                $accepted[] = [$record, $this->context->concernedUserId($record)];

                continue;
            }

            if ($dryRun) {
                $keys[$key] = true;
                $accepted[] = [$record, $this->context->concernedUserId($record)];

                continue;
            }

            if ($this->recorder->recordReconstructed($record->toAttributes())) {
                $keys[$key] = true;
                $report->inserted($rule);
                $accepted[] = [$record, $this->context->concernedUserId($record)];
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
