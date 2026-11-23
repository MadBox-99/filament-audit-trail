<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;
use RuntimeException;
use Throwable;

/**
 * Publisher side of the sso_login rule: every OAuth access token issued to a
 * user for an authorization code (a real sign-in; refreshed and personal
 * access tokens are left out), with the host of its client's (first)
 * redirect URI, as a JSON list for `audit-trail:reconstruct --sso-file`.
 */
final class ExportSsoCommand extends Command
{
    protected $signature = 'audit-trail:export-sso
        {--output= : Where to write the JSON (default: storage/app/audit-sso-export.json)}
        {--force : Overwrite an existing output file}';

    protected $description = 'Export the OAuth access tokens of users (publisher) for the sso_login reconstruction rule.';

    public function handle(): int
    {
        $output = $this->option('output');
        $output = is_string($output) && $output !== '' ? $output : storage_path('app/audit-sso-export.json');

        $context = new ReconstructionContext;
        $connection = $context->connection();
        $schema = $connection->getSchemaBuilder();

        foreach (['oauth_access_tokens', 'oauth_clients', 'oauth_auth_codes'] as $table) {
            if (! $schema->hasTable($table)) {
                $this->components->error(__('audit-trail::audit-trail.export_sso.missing_table', ['table' => $table]));

                return self::FAILURE;
            }
        }

        if (File::exists($output) && $this->option('force') !== true) {
            $this->components->error(__('audit-trail::audit-trail.export_sso.exists', ['path' => $output]));

            return self::FAILURE;
        }

        $users = $context->userTable();
        $clientColumns = $schema->getColumnListing('oauth_clients');
        $redirectColumn = in_array('redirect_uris', $clientColumns, true) ? 'redirect_uris' : 'redirect';
        $hasUuid = $schema->hasColumn($users, 'uuid');

        $select = [
            't.id as token_id',
            't.user_id as token_user_id',
            't.client_id as token_client_id',
            't.created_at as created_at',
            'u.email as user_email',
            'c.'.$redirectColumn.' as redirect',
        ];

        if ($hasUuid) {
            $select[] = 'u.uuid as user_uuid';
        }

        foreach (['grant_types', 'personal_access_client'] as $column) {
            if (in_array($column, $clientColumns, true)) {
                $select[] = 'c.'.$column;
            }
        }

        $rows = $connection->table('oauth_access_tokens as t')
            ->join($users.' as u', 'u.id', '=', 't.user_id')
            ->join('oauth_clients as c', 'c.id', '=', 't.client_id')
            ->select($select)
            ->whereNotNull('t.created_at')
            ->orderBy('t.created_at')
            ->orderBy('t.id')
            ->cursor();

        $entries = [];
        $skipped = 0;
        $withoutCode = 0;
        $timezone = ReconstructionContext::timezone();
        /** @var list<array{key: string, at: CarbonImmutable, entry: array<string, string|null>}> $candidates */
        $candidates = [];
        $window = max(0, (int) config('audit-trail.reconstruct.sso_auth_code_window_minutes', 11));
        $ttl = min($window, max(0, (int) config('audit-trail.reconstruct.sso_auth_code_ttl_minutes', 10)));

        foreach ($rows as $row) {
            $row = (array) $row;

            // A personal access token is created by hand, not by signing in to an app.
            if ($this->isPersonalAccess($row)) {
                continue;
            }

            $host = $this->host($row['redirect'] ?? null);
            $createdAt = $context->parseTimestamp($row['created_at']);

            if ($host === null || $createdAt === null) {
                $skipped++;

                continue;
            }

            $candidates[] = [
                'key' => (string) $row['token_user_id'].'|'.(string) $row['token_client_id'],
                'at' => $createdAt,
                'entry' => [
                    'token_id' => (string) $row['token_id'],
                    'user_uuid' => isset($row['user_uuid']) && is_string($row['user_uuid']) && $row['user_uuid'] !== '' ? $row['user_uuid'] : null,
                    'user_email' => is_string($row['user_email']) ? $row['user_email'] : null,
                    'app_host' => $host,
                    'created_at' => $createdAt->setTimezone($timezone)->toIso8601String(),
                ],
            ];
        }

        // Only a token issued for an authorization code proves a sign-in; a
        // refreshed token has no code of its own.
        $assigned = $this->assignCodes($this->authCodes($context), $candidates, $window, $ttl);

        foreach ($candidates as $index => $candidate) {
            if (! isset($assigned[$index])) {
                $withoutCode++;

                continue;
            }

            $entries[] = [...$candidate['entry'], 'auth_code_id' => $assigned[$index]];
        }

        try {
            File::ensureDirectoryExists(dirname($output));

            if (File::put($output, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('write failed');
            }
        } catch (Throwable $e) {
            $this->components->error(__('audit-trail::audit-trail.export_sso.write_failed', ['path' => $output, 'error' => $e->getMessage()]));

            return self::FAILURE;
        }

        if ($withoutCode > 0) {
            $this->components->warn(__('audit-trail::audit-trail.export_sso.without_code', ['count' => $withoutCode, 'minutes' => $window]));
        }

        if ($skipped > 0) {
            $this->components->warn(__('audit-trail::audit-trail.export_sso.skipped', ['count' => $skipped]));
        }

        $this->components->info(__('audit-trail::audit-trail.export_sso.done', ['count' => count($entries), 'path' => $output]));

        return self::SUCCESS;
    }

    /**
     * Authorization codes by "user|client", earliest expiry first: [id, expires at].
     *
     * @return array<string, array<int, array{string, CarbonImmutable}>>
     */
    private function authCodes(ReconstructionContext $context): array
    {
        $codes = [];

        $rows = $context->connection()->table('oauth_auth_codes')
            ->select(['id', 'user_id', 'client_id', 'expires_at'])
            ->whereNotNull('user_id')
            ->whereNotNull('expires_at')
            // Passport revokes a code when it is exchanged for a token.
            ->when($context->connection()->getSchemaBuilder()->hasColumn('oauth_auth_codes', 'revoked'), fn ($query) => $query->where('revoked', true))
            ->orderBy('expires_at')
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            $row = (array) $row;
            $expiresAt = $context->parseTimestamp($row['expires_at']);

            if ($expiresAt !== null) {
                $codes[(string) $row['user_id'].'|'.(string) $row['client_id']][] = [(string) $row['id'], $expiresAt];
            }
        }

        return $codes;
    }

    /**
     * Gives each code (earliest expiry first) to one token of the same user and
     * client created in [expires_at - window, expires_at]: the earliest one made
     * at or after the code was issued (expires_at - ttl), else the earliest in
     * the window. So a token refreshed just before a sign-in cannot take its code.
     *
     * @param  array<string, array<int, array{string, CarbonImmutable}>>  $codes
     * @param  list<array{key: string, at: CarbonImmutable, entry: array<string, string|null>}>  $candidates  in created_at order
     * @return array<int, string> candidate index => code id
     */
    private function assignCodes(array $codes, array $candidates, int $window, int $ttl): array
    {
        /** @var array<string, list<int>> $byKey */
        $byKey = [];
        foreach ($candidates as $index => $candidate) {
            $byKey[$candidate['key']][] = $index;
        }

        $assigned = [];

        foreach ($codes as $key => $keyCodes) {
            foreach ($keyCodes as [$id, $expiresAt]) {
                $from = $expiresAt->subMinutes($window);
                $issued = $expiresAt->subMinutes($ttl);
                $fallback = null;
                $chosen = null;

                foreach ($byKey[$key] ?? [] as $index) {
                    $at = $candidates[$index]['at'];

                    if (isset($assigned[$index]) || $at->lt($from) || $at->gt($expiresAt)) {
                        continue;
                    }

                    if ($at->gte($issued)) {
                        $chosen = $index;

                        break;
                    }

                    $fallback ??= $index;
                }

                $chosen ??= $fallback;

                if ($chosen !== null) {
                    $assigned[$chosen] = $id;
                }
            }
        }

        return $assigned;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isPersonalAccess(array $row): bool
    {
        if (! empty($row['personal_access_client'])) {
            return true;
        }

        $grants = $row['grant_types'] ?? null;

        if (! is_string($grants) || $grants === '') {
            return false;
        }

        $decoded = json_decode($grants, true);

        return is_array($decoded) && in_array('personal_access', $decoded, true);
    }

    /**
     * The lowercased host of the client's first redirect URI: a JSON array
     * (Passport 13 `redirect_uris`) or a comma-separated string (older `redirect`).
     */
    private function host(mixed $redirect): ?string
    {
        if (! is_string($redirect) || trim($redirect) === '') {
            return null;
        }

        $decoded = json_decode($redirect, true);
        $uris = is_array($decoded) ? $decoded : explode(',', $redirect);
        $first = $uris[array_key_first($uris)] ?? null;

        if (! is_string($first)) {
            return null;
        }

        $host = parse_url(trim($first), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? mb_strtolower($host) : null;
    }
}
