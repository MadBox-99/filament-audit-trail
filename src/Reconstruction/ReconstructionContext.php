<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Shared, read-only knowledge for a reconstruction run: the user table,
 * placeholder timestamps, seed-account patterns and the time window.
 */
final class ReconstructionContext
{
    /** @var array<int, array{email: string|null, created_at: CarbonImmutable|null, email_verified_at: CarbonImmutable|null}>|null */
    private ?array $users = null;

    /** @var list<EvidenceRecord> */
    private array $accepted = [];

    /** @var list<string>|null */
    private ?array $excludedRegexes = null;

    public function __construct(
        private readonly ?CarbonImmutable $since = null,
        private readonly ?CarbonImmutable $until = null,
    ) {}

    public function since(): ?CarbonImmutable
    {
        return $this->since;
    }

    public function until(): ?CarbonImmutable
    {
        return $this->until;
    }

    /**
     * The connection holding the application's users (and, by default, its data).
     */
    public function connection(): Connection
    {
        $connection = $this->userModel()->getConnection();

        return $connection;
    }

    public function userModel(): Model
    {
        /** @var class-string<Model> $class */
        $class = config('auth.providers.users.model');

        return new $class;
    }

    public function userTable(): string
    {
        return $this->userModel()->getTable();
    }

    /**
     * The values a polymorphic column may hold for the user model: its class and its morph alias.
     *
     * @return list<string>
     */
    public function userMorphClasses(): array
    {
        $model = $this->userModel();

        return array_values(array_unique([$model::class, $model->getMorphClass()]));
    }

    public function isIgnoredTimestamp(?CarbonInterface $at): bool
    {
        if (! $at instanceof CarbonInterface) {
            return false;
        }

        /** @var list<string> $ignored */
        $ignored = (array) config('audit-trail.reconstruct.ignored_timestamps', []);

        return in_array($at->format('Y-m-d H:i:s'), $ignored, true);
    }

    /**
     * Parses a database timestamp; null when empty or unparsable.
     */
    public function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '' || ! (is_string($value) || $value instanceof CarbonInterface)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * When the account was really created; null if unknown or a placeholder value.
     */
    public function userCreatedAt(int $userId): ?CarbonImmutable
    {
        $createdAt = $this->users()[$userId]['created_at'] ?? null;

        return $this->isIgnoredTimestamp($createdAt) ? null : $createdAt;
    }

    public function userEmail(int $userId): ?string
    {
        return $this->users()[$userId]['email'] ?? null;
    }

    /**
     * Seed and test accounts, matched against the SQL LIKE patterns in
     * `audit-trail.reconstruct.excluded_email_patterns` (case-insensitive).
     */
    public function isExcludedUser(int $userId): bool
    {
        $email = $this->userEmail($userId);

        if ($email === null) {
            return false;
        }

        foreach ($this->excludedRegexes() as $regex) {
            if (preg_match($regex, $email) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * All users, keyed by id, with their raw (possibly placeholder) timestamps.
     *
     * @return array<int, array{email: string|null, created_at: CarbonImmutable|null, email_verified_at: CarbonImmutable|null}>
     */
    public function users(): array
    {
        if ($this->users !== null) {
            return $this->users;
        }

        $table = $this->userTable();
        $schema = $this->connection()->getSchemaBuilder();
        $columns = array_values(array_filter(
            ['id', 'email', 'created_at', 'email_verified_at'],
            fn (string $column): bool => $schema->hasColumn($table, $column),
        ));

        $this->users = [];

        foreach ($this->connection()->table($table)->select($columns)->orderBy('id')->cursor() as $row) {
            $row = (array) $row;

            if (! is_numeric($row['id'] ?? null)) {
                continue;
            }

            $email = $row['email'] ?? null;

            $this->users[(int) $row['id']] = [
                'email' => is_string($email) ? $email : null,
                'created_at' => $this->parseTimestamp($row['created_at'] ?? null),
                'email_verified_at' => $this->parseTimestamp($row['email_verified_at'] ?? null),
            ];
        }

        return $this->users;
    }

    /**
     * The user an event is about: its actor, or the account itself when the
     * subject is a user (e.g. account_created, which has no actor).
     */
    public function concernedUserId(EvidenceRecord $record): ?int
    {
        if ($record->userId !== null) {
            return $record->userId;
        }

        if ($record->subjectType !== null
            && in_array($record->subjectType, $this->userMorphClasses(), true)
            && is_numeric($record->subjectId)) {
            return (int) $record->subjectId;
        }

        return null;
    }

    /**
     * Records accepted so far in this run, in rule order (dry runs included).
     *
     * @return list<EvidenceRecord>
     */
    public function accepted(): array
    {
        return $this->accepted;
    }

    /**
     * @internal Called by the Reconstructor only.
     */
    public function addAccepted(EvidenceRecord $record): void
    {
        $this->accepted[] = $record;
    }

    /**
     * @return list<string>
     */
    private function excludedRegexes(): array
    {
        if ($this->excludedRegexes !== null) {
            return $this->excludedRegexes;
        }

        /** @var list<string> $patterns */
        $patterns = (array) config('audit-trail.reconstruct.excluded_email_patterns', []);

        return $this->excludedRegexes = array_map(
            static fn (string $pattern): string => '/^'.strtr(preg_quote($pattern, '/'), ['%' => '.*', '_' => '.']).'$/iu',
            $patterns,
        );
    }
}
