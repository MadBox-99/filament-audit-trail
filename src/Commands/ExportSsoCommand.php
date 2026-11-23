<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Madbox99\FilamentAuditTrail\Reconstruction\ReconstructionContext;
use RuntimeException;
use Throwable;

/**
 * Publisher side of the sso_login rule: every OAuth access token issued to a
 * user, with the host of its client's (first) redirect URI, as a JSON list
 * for `audit-trail:reconstruct --sso-file` in the receiver apps.
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

        foreach (['oauth_access_tokens', 'oauth_clients'] as $table) {
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
        $timezone = ReconstructionContext::timezone();

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

            $entries[] = [
                'token_id' => (string) $row['token_id'],
                'user_uuid' => isset($row['user_uuid']) && is_string($row['user_uuid']) && $row['user_uuid'] !== '' ? $row['user_uuid'] : null,
                'user_email' => is_string($row['user_email']) ? $row['user_email'] : null,
                'app_host' => $host,
                'created_at' => $createdAt->setTimezone($timezone)->toIso8601String(),
            ];
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

        if ($skipped > 0) {
            $this->components->warn(__('audit-trail::audit-trail.export_sso.skipped', ['count' => $skipped]));
        }

        $this->components->info(__('audit-trail::audit-trail.export_sso.done', ['count' => count($entries), 'path' => $output]));

        return self::SUCCESS;
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
