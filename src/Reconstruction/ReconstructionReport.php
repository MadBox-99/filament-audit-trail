<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction;

use Madbox99\FilamentAuditTrail\Enums\AuditEventType;

/**
 * @phpstan-type RuleStats array{found: int, accepted: int, rejected: array<string, int>, inserted: int, existing: int, failed: int, error: string|null, bootstrap_unchecked: int}
 * @phpstan-type UserStats array{login: int, login_inferred: int, account_created: int, other: int, days: array<string, true>}
 */
final class ReconstructionReport
{
    /** @var array<string, RuleStats> */
    private array $rules = [];

    /** @var array<int, UserStats> */
    private array $userStats = [];

    /**
     * @param  array<int, string|null>  $users  non-excluded users: id => email
     */
    public function __construct(
        public readonly bool $dryRun,
        private readonly array $users,
    ) {}

    public function startRule(string $rule): void
    {
        $this->rules[$rule] ??= ['found' => 0, 'accepted' => 0, 'rejected' => [], 'inserted' => 0, 'existing' => 0, 'failed' => 0, 'error' => null, 'bootstrap_unchecked' => 0];
    }

    public function found(string $rule): void
    {
        $this->rules[$rule]['found']++;
    }

    public function rejected(string $rule, string $reason): void
    {
        $this->rules[$rule]['rejected'][$reason] = ($this->rules[$rule]['rejected'][$reason] ?? 0) + 1;
    }

    public function accepted(string $rule): void
    {
        $this->rules[$rule]['accepted']++;
    }

    /**
     * Adds an accepted record of a successfully finished rule to the user statistics.
     */
    public function user(EvidenceRecord $record, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $stats = $this->userStats[$userId] ?? ['login' => 0, 'login_inferred' => 0, 'account_created' => 0, 'other' => 0, 'days' => []];

        $counter = match ($record->type) {
            AuditEventType::Login => 'login',
            AuditEventType::LoginInferred => 'login_inferred',
            AuditEventType::AccountCreated => 'account_created',
            default => 'other',
        };
        $stats[$counter]++;

        if ($record->userId !== null) {
            $stats['days'][$record->occurredAt->toDateString()] = true;
        }

        $this->userStats[$userId] = $stats;
    }

    /**
     * An accepted record whose team-bootstrap check could not be applied
     * (team row missing, or its creation time unknown or a placeholder).
     */
    public function bootstrapUnchecked(string $rule): void
    {
        $this->rules[$rule]['bootstrap_unchecked']++;
    }

    /**
     * Rules with accepted records that skipped the team-bootstrap check: rule => count.
     *
     * @return array<string, int>
     */
    public function bootstrapUncheckedCounts(): array
    {
        return array_filter(array_map(fn (array $stats): int => $stats['bootstrap_unchecked'], $this->rules));
    }

    public function inserted(string $rule): void
    {
        $this->rules[$rule]['inserted']++;
    }

    public function existing(string $rule): void
    {
        $this->rules[$rule]['existing']++;
    }

    public function failed(string $rule): void
    {
        $this->rules[$rule]['failed']++;
    }

    /**
     * The rule threw; its transaction was rolled back, so nothing it inserted remains.
     */
    public function ruleFailed(string $rule, string $message, bool $rolledBack): void
    {
        $this->rules[$rule]['error'] = $message;

        if ($rolledBack) {
            $this->rules[$rule]['failed'] += $this->rules[$rule]['inserted'];
            $this->rules[$rule]['inserted'] = 0;
        }
    }

    /**
     * @return RuleStats
     */
    public function rule(string $rule): array
    {
        return $this->rules[$rule] ?? ['found' => 0, 'accepted' => 0, 'rejected' => [], 'inserted' => 0, 'existing' => 0, 'failed' => 0, 'error' => null, 'bootstrap_unchecked' => 0];
    }

    /**
     * @return array<string, RuleStats>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * One row per rule: rule, found, accepted, rejected (by reason), inserted, existing, failed, error.
     *
     * @return list<list<string|int>>
     */
    public function toTable(): array
    {
        $rows = [];

        foreach ($this->rules as $name => $stats) {
            $rejected = [];
            foreach ($stats['rejected'] as $reason => $count) {
                $rejected[] = $reason.': '.$count;
            }

            $rows[] = [
                $name,
                $stats['found'],
                $stats['accepted'],
                $rejected === [] ? '0' : implode(', ', $rejected),
                $stats['inserted'],
                $stats['existing'],
                $stats['failed'],
                $stats['error'] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * @return array{users: int, with_login: int, with_inferred_login: int, only_account_created: int}
     */
    public function userSummary(): array
    {
        $summary = ['users' => count($this->users), 'with_login' => 0, 'with_inferred_login' => 0, 'only_account_created' => 0];

        foreach ($this->userStats as $userId => $stats) {
            if (! array_key_exists($userId, $this->users)) {
                continue;
            }

            if ($stats['login'] > 0) {
                $summary['with_login']++;
            }

            if ($stats['login_inferred'] > 0) {
                $summary['with_inferred_login']++;
            }

            if ($stats['account_created'] > 0 && $stats['login'] + $stats['login_inferred'] + $stats['other'] === 0) {
                $summary['only_account_created']++;
            }
        }

        return $summary;
    }

    /**
     * Users with the most active days (days with evidence of their own activity).
     *
     * @return list<array{user_id: int, email: string|null, active_days: int}>
     */
    public function topUsers(int $n = 5): array
    {
        $rows = [];

        foreach ($this->userStats as $userId => $stats) {
            if ($stats['days'] === []) {
                continue;
            }

            $rows[] = ['user_id' => $userId, 'email' => $this->users[$userId] ?? null, 'active_days' => count($stats['days'])];
        }

        usort($rows, fn (array $a, array $b): int => [$b['active_days'], $a['user_id']] <=> [$a['active_days'], $b['user_id']]);

        return array_slice($rows, 0, $n);
    }
}
