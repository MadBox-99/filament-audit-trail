<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Reconstruction\EvidenceRecord;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionReport;
use Madbox99\FilamentAuditTrail\Reconstruction\Reconstructor;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\Rule;
use Madbox99\FilamentAuditTrail\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Inserts a user row directly (no Eloquent events, so no live audit rows).
 *
 * @param  array<string, mixed>  $attributes
 */
function insertUser(array $attributes = []): int
{
    return (int) DB::table('users')->insertGetId([
        'name' => 'U',
        'email' => 'u'.uniqid().'@corp.test',
        'created_at' => '2026-01-01 08:00:00',
        'updated_at' => '2026-01-01 08:00:00',
        ...$attributes,
    ]);
}

/**
 * @param  list<Rule>  $rules
 */
function reconstruct(array $rules, bool $dryRun = false, ?string $since = null, ?string $until = null): ReconstructionReport
{
    $context = new ReconstructionContext(
        $since === null ? null : CarbonImmutable::parse($since),
        $until === null ? null : CarbonImmutable::parse($until),
    );

    return app(Reconstructor::class, ['context' => $context])->run($rules, $dryRun);
}

/**
 * A rule that yields the given records, for framework tests.
 *
 * @param  list<EvidenceRecord>  $records
 */
function fakeRule(string $name, array $records): Rule
{
    return new class($name, $records) implements Rule
    {
        /**
         * @param  list<EvidenceRecord>  $records
         */
        public function __construct(private string $name, private array $records) {}

        public function name(): string
        {
            return $this->name;
        }

        public function collect(ReconstructionContext $ctx): iterable
        {
            return $this->records;
        }
    };
}

/**
 * @param  array<string, mixed>  $overrides
 */
function evidence(int $userId, string $at, string $key, array $overrides = []): EvidenceRecord
{
    return new EvidenceRecord(...[
        'type' => AuditEventType::Created,
        'occurredAt' => CarbonImmutable::parse($at),
        'userId' => $userId,
        'userEmail' => null,
        'teamId' => null,
        'subjectType' => 'posts',
        'subjectId' => '1',
        'properties' => [],
        'evidence' => ['rule' => 'fake', 'table' => 'posts', 'id' => 1],
        'evidenceKey' => $key,
        ...$overrides,
    ]);
}

function insertTeam(string $createdAt = '2026-01-01 08:00:00'): int
{
    return (int) DB::table('teams')->insertGetId(['name' => 'T', 'created_at' => $createdAt, 'updated_at' => $createdAt]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertPost(int $teamId, ?int $userId, string $createdAt, array $attributes = []): int
{
    return (int) DB::table('posts')->insertGetId([
        'team_id' => $teamId,
        'user_id' => $userId,
        'title' => 'P',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        ...$attributes,
    ]);
}

function addMember(int $teamId, int $userId, ?string $since = '2026-01-01 08:00:00'): void
{
    DB::table('team_user')->insert(['team_id' => $teamId, 'user_id' => $userId, 'created_at' => $since, 'updated_at' => $since]);
}
