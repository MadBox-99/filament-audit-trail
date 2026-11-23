<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Reconstruction\Rules;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Reconstruction\EvidenceRecord;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;
use Throwable;

/**
 * The publisher's OAuth access tokens (the `audit-trail:export-sso` file) →
 * a login at the exact time the token was issued, for the tokens of this app
 * (`app_host` = the host of `app.url`). Users are matched by uuid, then by
 * email unless the local user's uuid contradicts the entry's (`uuid_mismatch`);
 * tokens of no known user are rejected as `unknown_user`.
 */
final class SsoLoginRule implements Rule
{
    /** @var list<array{token_id: string, user_uuid: string|null, user_email: string|null, app_host: string, created_at: CarbonImmutable, auth_code_id: string}> */
    private readonly array $entries;

    private readonly string $host;

    /**
     * @throws InvalidArgumentException when the file is missing, unreadable or malformed, or app.url has no host
     */
    public function __construct(private readonly string $path)
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException('The SSO login rule needs app.url with a host to pick this app\'s tokens.');
        }

        $this->host = mb_strtolower($host);
        $this->entries = $this->load();
    }

    public function name(): string
    {
        return 'sso_login';
    }

    public function host(): string
    {
        return $this->host;
    }

    /**
     * All entries in the file, for any app.
     */
    public function entryCount(): int
    {
        return count($this->entries);
    }

    /**
     * Entries of this app.
     */
    public function hostEntryCount(): int
    {
        return count(array_filter($this->entries, fn (array $entry): bool => $entry['app_host'] === $this->host));
    }

    public function collect(ReconstructionContext $ctx): iterable
    {
        [$byUuid, $byEmail] = $this->userIndex($ctx);

        foreach ($this->entries as $entry) {
            if ($entry['app_host'] !== $this->host) {
                continue;
            }

            $uuid = $entry['user_uuid'];
            $userId = $uuid !== null ? $byUuid[$uuid] ?? null : null;
            $rejection = null;

            if ($userId === null && $entry['user_email'] !== null) {
                [$emailUserId, $localUuid] = $byEmail[mb_strtolower($entry['user_email'])] ?? [null, null];

                // Same email, different person: the publisher account's email was reused here.
                if ($emailUserId !== null && $uuid !== null && $localUuid !== null && $localUuid !== $uuid) {
                    $rejection = 'uuid_mismatch';
                } else {
                    $userId = $emailUserId;
                }
            }

            $rejection ??= $userId === null ? 'unknown_user' : null;

            yield new EvidenceRecord(
                type: AuditEventType::Login,
                occurredAt: $entry['created_at'],
                userId: $userId,
                userEmail: $userId === null ? $entry['user_email'] : $ctx->userEmail($userId),
                teamId: null,
                subjectType: null,
                subjectId: null,
                properties: [],
                evidence: [
                    'rule' => $this->name(),
                    'table' => 'oauth_access_tokens',
                    'id' => $entry['token_id'],
                    'app_host' => $entry['app_host'],
                    'auth_code_id' => $entry['auth_code_id'],
                    'file' => basename($this->path),
                ],
                evidenceKey: 'sso:'.$entry['token_id'],
                rejection: $rejection,
            );
        }
    }

    /**
     * @return array{array<string, int>, array<string, array{int, string|null}>} uuid => id, lowercased email => [id, uuid]
     */
    private function userIndex(ReconstructionContext $ctx): array
    {
        $table = $ctx->userTable();
        $hasUuid = $ctx->connection()->getSchemaBuilder()->hasColumn($table, 'uuid');
        /** @var array<int, string> $uuids */
        $uuids = [];

        if ($hasUuid) {
            foreach ($ctx->connection()->table($table)->select(['id', 'uuid'])->whereNotNull('uuid')->orderBy('id')->cursor() as $row) {
                $row = (array) $row;

                if (is_numeric($row['id']) && is_string($row['uuid']) && $row['uuid'] !== '') {
                    $uuids[(int) $row['id']] = $row['uuid'];
                }
            }
        }

        $byUuid = [];
        foreach ($uuids as $id => $uuid) {
            $byUuid[$uuid] ??= $id;
        }

        $byEmail = [];
        foreach ($ctx->users() as $id => $user) {
            if ($user['email'] !== null) {
                $byEmail[mb_strtolower($user['email'])] ??= [$id, $uuids[$id] ?? null];
            }
        }

        return [$byUuid, $byEmail];
    }

    /**
     * @return list<array{token_id: string, user_uuid: string|null, user_email: string|null, app_host: string, created_at: CarbonImmutable, auth_code_id: string}>
     */
    private function load(): array
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new InvalidArgumentException("The SSO file {$this->path} does not exist or is not readable.");
        }

        $contents = file_get_contents($this->path);

        try {
            $data = json_decode((string) $contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("The SSO file {$this->path} is not valid JSON: {$e->getMessage()}", previous: $e);
        }

        if (! is_array($data) || ! array_is_list($data)) {
            throw new InvalidArgumentException("The SSO file {$this->path} must hold a JSON list of tokens.");
        }

        $entries = [];

        foreach ($data as $index => $item) {
            $entries[] = $this->entry($item, $index);
        }

        return $entries;
    }

    /**
     * @return array{token_id: string, user_uuid: string|null, user_email: string|null, app_host: string, created_at: CarbonImmutable, auth_code_id: string}
     */
    private function entry(mixed $item, int $index): array
    {
        $invalid = fn (string $why): InvalidArgumentException => new InvalidArgumentException("The SSO file {$this->path} has an invalid entry #{$index}: {$why}.");

        if (! is_array($item)) {
            throw $invalid('not an object');
        }

        $tokenId = $item['token_id'] ?? null;
        $host = $item['app_host'] ?? null;
        $createdAt = $item['created_at'] ?? null;

        if (! (is_string($tokenId) || is_int($tokenId)) || trim((string) $tokenId) === '') {
            throw $invalid('token_id is missing');
        }

        if (! is_string($host) || trim($host) === '') {
            throw $invalid('app_host is missing');
        }

        $authCode = $item['auth_code_id'] ?? null;

        if (! (is_string($authCode) || is_int($authCode)) || trim((string) $authCode) === '') {
            throw $invalid('auth_code_id is missing (export it again with this package version)');
        }

        if (! is_string($createdAt) || trim($createdAt) === '') {
            throw $invalid('created_at is missing');
        }

        try {
            $at = CarbonImmutable::parse($createdAt, ReconstructionContext::timezone())->setTimezone(ReconstructionContext::timezone());
        } catch (Throwable) {
            throw $invalid('created_at is not a time');
        }

        $uuid = $item['user_uuid'] ?? null;
        $email = $item['user_email'] ?? null;

        return [
            'token_id' => (string) $tokenId,
            'user_uuid' => is_string($uuid) && $uuid !== '' ? $uuid : null,
            'user_email' => is_string($email) && $email !== '' ? $email : null,
            'app_host' => mb_strtolower(trim($host)),
            'created_at' => $at,
            'auth_code_id' => (string) $authCode,
        ];
    }
}
