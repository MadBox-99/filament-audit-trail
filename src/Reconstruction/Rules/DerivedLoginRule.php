<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction\Rules;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Enums\AuditSource;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Reconstruction\EvidenceRecord;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;

/**
 * Per user and day (app timezone) with at least one action trace and no
 * login (real, or inferred by another rule such as registration): a login_inferred at the day's earliest trace, with that trace as
 * evidence. Traces are the records the other rules accepted in this run and
 * the reconstructed action rows already stored, so the rule also works on
 * its own. It reads the other rules' results, so it must run last.
 */
final class DerivedLoginRule implements Rule
{
    public const array TRACE_TYPES = [
        AuditEventType::Created,
        AuditEventType::Updated,
        AuditEventType::Deleted,
        AuditEventType::Import,
        AuditEventType::Export,
        AuditEventType::ActionExecuted,
    ];

    public function name(): string
    {
        return 'derived_login';
    }

    public function collect(ReconstructionContext $ctx): iterable
    {
        /** @var array<int, array<string, array{at: CarbonImmutable, key: string, evidence: array<string, mixed>}>> $earliest user => day => trace */
        $earliest = [];
        /** @var array<int, array<string, true>> $loginDays */
        $loginDays = [];

        $trace = function (int $userId, CarbonImmutable $at, string $key, array $evidence) use (&$earliest): void {
            $day = $this->day($at);
            $known = $earliest[$userId][$day] ?? null;

            if ($known === null || $at->lt($known['at']) || ($at->eq($known['at']) && strcmp($key, $known['key']) < 0)) {
                $earliest[$userId][$day] = ['at' => $at, 'key' => $key, 'evidence' => $evidence];
            }
        };

        foreach ($ctx->accepted() as $record) {
            if ($record->userId === null) {
                continue;
            }

            if ($this->isOtherLogin($record->type, $record->evidenceKey)) {
                $loginDays[$record->userId][$this->day($record->occurredAt)] = true;
            } elseif (in_array($record->type, self::TRACE_TYPES, true)) {
                $trace($record->userId, $record->occurredAt, $record->evidenceKey, $record->evidence);
            }
        }

        $this->readStored($ctx, $trace, $loginDays);

        $tz = ReconstructionContext::timezone();

        foreach ($earliest as $userId => $days) {
            foreach ($days as $day => $source) {
                if (isset($loginDays[$userId][$day])) {
                    continue;
                }

                yield new EvidenceRecord(
                    type: AuditEventType::LoginInferred,
                    occurredAt: $source['at']->setTimezone($tz),
                    userId: $userId,
                    userEmail: $ctx->userEmail($userId),
                    teamId: null,
                    subjectType: null,
                    subjectId: null,
                    properties: [],
                    evidence: [
                        'rule' => $this->name(),
                        'table' => $source['evidence']['table'] ?? null,
                        'id' => $source['evidence']['id'] ?? null,
                        'evidence_key' => $source['key'],
                        'source_rule' => $source['evidence']['rule'] ?? null,
                    ],
                    evidenceKey: $this->name().':'.$userId.':'.$day,
                    inferred: true,
                );
            }
        }
    }

    /**
     * Stored logins (see isOtherLogin) mark their day; stored reconstructed
     * action rows are traces. Live action rows are not: a live day has its own login.
     *
     * @param  callable(int, CarbonImmutable, string, array<string, mixed>): void  $trace
     * @param  array<int, array<string, true>>  $loginDays
     */
    private function readStored(ReconstructionContext $ctx, callable $trace, array &$loginDays): void
    {
        $model = new AuditEvent;
        $query = fn () => DB::connection($model->getConnectionName())->table($model->getTable())->whereNotNull('user_id');

        $logins = $query()
            ->select(['user_id', 'occurred_at', 'event', 'evidence_key'])
            ->whereIn('event', [AuditEventType::Login->value, AuditEventType::LoginInferred->value])
            ->orderBy('id')
            ->cursor();

        foreach ($logins as $row) {
            $at = $ctx->parseTimestamp($row->occurred_at ?? null);
            $type = AuditEventType::tryFrom((string) $row->event);

            if ($at !== null && is_numeric($row->user_id) && $type !== null
                && $this->isOtherLogin($type, is_string($row->evidence_key) ? $row->evidence_key : null)) {
                $loginDays[(int) $row->user_id][$this->day($at)] = true;
            }
        }

        $rows = $query()
            ->select(['id', 'user_id', 'occurred_at', 'evidence', 'evidence_key'])
            ->where('source', AuditSource::Reconstructed->value)
            ->whereIn('event', array_map(fn (AuditEventType $type): string => $type->value, self::TRACE_TYPES))
            ->whereNotNull('evidence_key')
            ->when($ctx->since(), fn ($q, CarbonImmutable $since) => $q->where('occurred_at', '>=', $since->setTimezone(ReconstructionContext::timezone())->format('Y-m-d H:i:s.v')))
            ->when($ctx->until(), fn ($q, CarbonImmutable $until) => $q->where('occurred_at', '<=', $until->setTimezone(ReconstructionContext::timezone())->format('Y-m-d H:i:s.v')))
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            $at = $ctx->parseTimestamp($row->occurred_at ?? null);

            if ($at === null || ! is_numeric($row->user_id) || ! is_string($row->evidence_key)) {
                continue;
            }

            $evidence = is_string($row->evidence) ? json_decode($row->evidence, true) : null;

            $trace((int) $row->user_id, $at, $row->evidence_key, is_array($evidence) ? $evidence : []);
        }
    }

    /**
     * A login that already covers its day: a real (live or SSO) login, or an
     * inferred one of another rule (registration, email verification). This
     * rule's own earlier rows do not count, so a rerun finds them as existing.
     */
    private function isOtherLogin(AuditEventType $type, ?string $evidenceKey): bool
    {
        return $type === AuditEventType::Login
            || ($type === AuditEventType::LoginInferred && ! str_starts_with((string) $evidenceKey, $this->name().':'));
    }

    private function day(CarbonImmutable $at): string
    {
        return $at->setTimezone(ReconstructionContext::timezone())->format('Y-m-d');
    }
}
