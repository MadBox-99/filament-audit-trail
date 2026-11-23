<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\ImportExportRule;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\SsoLoginRule;

/**
 * Passport 13 (`redirect_uris` JSON) or older Passport (`redirect` string) tables.
 */
function createPassportTables(bool $legacy = false): void
{
    Schema::create('oauth_clients', function (Blueprint $table) use ($legacy): void {
        $table->char('id', 36)->primary();
        $table->string('name');
        $table->string('secret')->nullable();

        if ($legacy) {
            $table->text('redirect');
            $table->boolean('personal_access_client')->default(false);
            $table->boolean('password_client')->default(false);
        } else {
            $table->text('redirect_uris');
            $table->text('grant_types');
        }

        $table->boolean('revoked')->default(false);
        $table->timestamps();
    });

    Schema::create('oauth_access_tokens', function (Blueprint $table): void {
        $table->char('id', 80)->primary();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->char('client_id', 36);
        $table->string('name')->nullable();
        $table->text('scopes')->nullable();
        $table->boolean('revoked')->default(false);
        $table->timestamps();
        $table->dateTime('expires_at')->nullable();
    });

    Schema::create('oauth_auth_codes', function (Blueprint $table): void {
        $table->char('id', 80)->primary();
        $table->unsignedBigInteger('user_id');
        $table->char('client_id', 36);
        $table->text('scopes')->nullable();
        $table->boolean('revoked');
        $table->dateTime('expires_at')->nullable();
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertClient(string $id, array $attributes): void
{
    DB::table('oauth_clients')->insert(['id' => $id, 'name' => 'C', 'created_at' => '2026-01-01 00:00:00', ...$attributes]);
}

/**
 * A token and, by default, the authorization code it was exchanged for
 * (Passport: the code expires 10 minutes after it was issued, just before the token).
 */
function insertToken(string $id, ?int $userId, string $clientId, string $createdAt, bool $withCode = true): void
{
    DB::table('oauth_access_tokens')->insert([
        'id' => $id, 'user_id' => $userId, 'client_id' => $clientId,
        'created_at' => $createdAt, 'updated_at' => $createdAt,
    ]);

    if ($withCode && $userId !== null) {
        insertAuthCode('code-'.$id, $userId, $clientId, CarbonImmutable::parse($createdAt)->addMinutes(9)->format('Y-m-d H:i:s'));
    }
}

function insertAuthCode(string $id, int $userId, string $clientId, string $expiresAt, bool $revoked = true): void
{
    DB::table('oauth_auth_codes')->insert(['id' => $id, 'user_id' => $userId, 'client_id' => $clientId, 'revoked' => $revoked, 'expires_at' => $expiresAt]);
}

/**
 * @param  list<array<string, mixed>>|string  $entries
 */
function ssoFile(array|string $entries): string
{
    $path = sys_get_temp_dir().'/audit-sso-'.uniqid().'.json';
    File::put($path, is_string($entries) ? $entries : json_encode($entries));

    return $path;
}

/**
 * @return array<string, mixed>
 */
function ssoEntry(string $tokenId, ?string $uuid, ?string $email, string $host, string $createdAt): array
{
    return ['token_id' => $tokenId, 'user_uuid' => $uuid, 'user_email' => $email, 'app_host' => $host, 'created_at' => $createdAt, 'auth_code_id' => 'code-'.$tokenId];
}

describe('audit-trail:export-sso', function (): void {
    beforeEach(function (): void {
        $this->output = sys_get_temp_dir().'/audit-sso-export-'.uniqid().'.json';
    });

    afterEach(function (): void {
        File::delete($this->output);
    });

    it('exports one element per user token with the host of the first redirect uri', function (): void {
        createPassportTables();
        $user = insertUser(['email' => 'a@corp.test', 'uuid' => '11111111-1111-1111-1111-111111111111']);
        insertClient('c-crm', ['redirect_uris' => json_encode(['https://CRM.cegem360.eu/auth/callback', 'https://other.test/cb']), 'grant_types' => json_encode(['authorization_code', 'refresh_token'])]);
        insertClient('c-sales', ['redirect_uris' => json_encode(['https://sales.cegem360.eu/cb']), 'grant_types' => json_encode(['authorization_code'])]);
        insertClient('c-pat', ['redirect_uris' => json_encode(['http://localhost']), 'grant_types' => json_encode(['personal_access'])]);
        insertClient('c-nohost', ['redirect_uris' => json_encode([]), 'grant_types' => json_encode(['authorization_code'])]);
        insertToken('t1', $user, 'c-crm', '2026-03-01 09:10:00');
        insertToken('t2', $user, 'c-sales', '2026-03-02 10:00:00');
        insertToken('t-pat', $user, 'c-pat', '2026-03-03 10:00:00');
        insertToken('t-nohost', $user, 'c-nohost', '2026-03-03 10:00:00');
        insertToken('t-nouser', 999, 'c-crm', '2026-03-03 10:00:00');
        insertToken('t-client', null, 'c-crm', '2026-03-03 10:00:00');

        $this->artisan('audit-trail:export-sso', ['--output' => $this->output])
            ->expectsOutputToContain('2')
            ->assertExitCode(0);

        $data = json_decode(File::get($this->output), true);
        expect($data)->toBe([
            ['token_id' => 't1', 'user_uuid' => '11111111-1111-1111-1111-111111111111', 'user_email' => 'a@corp.test', 'app_host' => 'crm.cegem360.eu', 'created_at' => '2026-03-01T09:10:00+00:00', 'auth_code_id' => 'code-t1'],
            ['token_id' => 't2', 'user_uuid' => '11111111-1111-1111-1111-111111111111', 'user_email' => 'a@corp.test', 'app_host' => 'sales.cegem360.eu', 'created_at' => '2026-03-02T10:00:00+00:00', 'auth_code_id' => 'code-t2'],
        ])->and(File::get($this->output))->toContain("\n    ");
    });

    it('reads the redirect column of older Passport versions', function (): void {
        createPassportTables(legacy: true);
        $user = insertUser(['email' => 'a@corp.test']);
        insertClient('c-crm', ['redirect' => 'https://crm.cegem360.eu/cb,https://x.test/cb']);
        insertClient('c-pat', ['redirect' => 'http://localhost', 'personal_access_client' => true]);
        insertToken('t1', $user, 'c-crm', '2026-03-01 09:10:00');
        insertToken('t-pat', $user, 'c-pat', '2026-03-01 09:10:00');

        $this->artisan('audit-trail:export-sso', ['--output' => $this->output])->assertExitCode(0);

        $data = json_decode(File::get($this->output), true);
        expect($data)->toHaveCount(1)
            ->and($data[0])->toMatchArray(['token_id' => 't1', 'user_uuid' => null, 'app_host' => 'crm.cegem360.eu']);
    });

    it('writes the created_at in the app timezone', function (): void {
        config(['app.timezone' => 'Europe/Budapest']);
        createPassportTables();
        $user = insertUser();
        insertClient('c', ['redirect_uris' => json_encode(['https://crm.cegem360.eu/cb']), 'grant_types' => '[]']);
        insertToken('t1', $user, 'c', '2026-03-01 09:10:00');

        $this->artisan('audit-trail:export-sso', ['--output' => $this->output])->assertExitCode(0);

        expect(json_decode(File::get($this->output), true)[0]['created_at'])->toBe('2026-03-01T09:10:00+01:00');
    });

    it('exports only tokens issued for an authorization code (a real sign-in)', function (): void {
        createPassportTables();
        $user = insertUser();
        $other = insertUser();
        insertClient('c', ['redirect_uris' => json_encode(['https://crm.cegem360.eu/cb']), 'grant_types' => '["authorization_code","refresh_token"]']);
        insertClient('c2', ['redirect_uris' => json_encode(['https://sales.cegem360.eu/cb']), 'grant_types' => '["authorization_code"]']);
        insertToken('t-ok', $user, 'c', '2026-03-01 09:10:00');
        insertToken('t-refresh', $user, 'c', '2026-03-01 11:00:00', withCode: false);
        insertToken('t-late', $user, 'c', '2026-03-02 09:00:00', withCode: false);
        insertAuthCode('code-late', $user, 'c', '2026-03-02 09:12:00'); // 12 min: outside the 11 min window
        insertToken('t-early', $user, 'c', '2026-03-03 09:00:00', withCode: false);
        insertAuthCode('code-early', $user, 'c', '2026-03-03 08:59:59'); // expired before the token
        insertToken('t-wrong-user', $user, 'c', '2026-03-04 09:00:00', withCode: false);
        insertAuthCode('code-other-user', $other, 'c', '2026-03-04 09:05:00');
        insertToken('t-wrong-client', $user, 'c', '2026-03-05 09:00:00', withCode: false);
        insertAuthCode('code-other-client', $user, 'c2', '2026-03-05 09:05:00');
        insertToken('t-edge', $user, 'c', '2026-03-06 09:00:00', withCode: false);
        insertAuthCode('code-edge', $user, 'c', '2026-03-06 09:11:00');

        $this->artisan('audit-trail:export-sso', ['--output' => $this->output])
            ->expectsOutputToContain('5')
            ->assertExitCode(0);

        $data = json_decode(File::get($this->output), true);
        expect(array_column($data, 'auth_code_id', 'token_id'))->toBe(['t-ok' => 'code-t-ok', 't-edge' => 'code-edge']);
    });

    it('uses one authorization code for one token only', function (): void {
        createPassportTables();
        $user = insertUser();
        insertClient('c', ['redirect_uris' => json_encode(['https://crm.cegem360.eu/cb']), 'grant_types' => '[]']);
        insertToken('t1', $user, 'c', '2026-03-01 09:10:00');
        insertToken('t2', $user, 'c', '2026-03-01 09:11:00', withCode: false); // e.g. refreshed right away

        $this->artisan('audit-trail:export-sso', ['--output' => $this->output])->assertExitCode(0);

        expect(array_column(json_decode(File::get($this->output), true), 'token_id'))->toBe(['t1']);
    });

    it('ignores authorization codes that were never exchanged (not revoked)', function (): void {
        createPassportTables();
        $user = insertUser();
        insertClient('c', ['redirect_uris' => json_encode(['https://crm.cegem360.eu/cb']), 'grant_types' => '[]']);
        insertToken('t1', $user, 'c', '2026-03-01 09:10:00', withCode: false);
        insertAuthCode('code-open', $user, 'c', '2026-03-01 09:19:00', revoked: false);

        $this->artisan('audit-trail:export-sso', ['--output' => $this->output])->assertExitCode(0);

        expect(json_decode(File::get($this->output), true))->toBe([]);
    });

    it('does not give the code to a refreshed token made just before the authorization', function (): void {
        createPassportTables();
        $user = insertUser();
        insertClient('c', ['redirect_uris' => json_encode(['https://crm.cegem360.eu/cb']), 'grant_types' => '[]']);
        insertToken('t-refresh', $user, 'c', '2026-03-01 09:09:30', withCode: false); // refresh, 30 s before the authorize
        insertToken('t-signin', $user, 'c', '2026-03-01 09:10:05', withCode: false);
        insertAuthCode('code-1', $user, 'c', '2026-03-01 09:20:00'); // issued 09:10:00 (TTL 10 min)

        $this->artisan('audit-trail:export-sso', ['--output' => $this->output])->assertExitCode(0);

        expect(array_column(json_decode(File::get($this->output), true), 'auth_code_id', 'token_id'))->toBe(['t-signin' => 'code-1']);
    });

    it('honours the configured authorization code window', function (): void {
        config(['audit-trail.reconstruct.sso_auth_code_window_minutes' => 5]);
        createPassportTables();
        $user = insertUser();
        insertClient('c', ['redirect_uris' => json_encode(['https://crm.cegem360.eu/cb']), 'grant_types' => '[]']);
        insertToken('t1', $user, 'c', '2026-03-01 09:10:00'); // code expires +9 min

        $this->artisan('audit-trail:export-sso', ['--output' => $this->output])->assertExitCode(0);

        expect(json_decode(File::get($this->output), true))->toBe([]);
    });

    it('fails without an oauth_auth_codes table', function (): void {
        createPassportTables();
        Schema::drop('oauth_auth_codes');

        $this->artisan('audit-trail:export-sso', ['--output' => $this->output])
            ->expectsOutputToContain('oauth_auth_codes')
            ->assertExitCode(1);

        expect(File::exists($this->output))->toBeFalse();
    });

    it('fails without an oauth_access_tokens table', function (): void {
        $this->artisan('audit-trail:export-sso', ['--output' => $this->output])
            ->expectsOutputToContain('oauth_access_tokens')
            ->assertExitCode(1);

        expect(File::exists($this->output))->toBeFalse();
    });

    it('refuses to overwrite an existing file unless forced', function (): void {
        createPassportTables();
        File::put($this->output, 'keep');

        $this->artisan('audit-trail:export-sso', ['--output' => $this->output])->assertExitCode(1);
        expect(File::get($this->output))->toBe('keep');

        $this->artisan('audit-trail:export-sso', ['--output' => $this->output, '--force' => true])->assertExitCode(0);
        expect(json_decode(File::get($this->output), true))->toBe([]);
    });

    it('writes to storage/app by default', function (): void {
        createPassportTables();
        $default = storage_path('app/audit-sso-export.json');
        File::delete($default);

        $this->artisan('audit-trail:export-sso')->assertExitCode(0);

        expect(File::exists($default))->toBeTrue();
        File::delete($default);
    });
});

describe('sso_login rule', function (): void {
    beforeEach(function (): void {
        config(['app.url' => 'https://crm.cegem360.eu']);
    });

    it('records a login at the exact token time for this app, matched by uuid', function (): void {
        $user = insertUser(['email' => 'a@corp.test', 'uuid' => 'u-1']);
        insertUser(['email' => 'other@corp.test', 'uuid' => 'u-2']);

        // Same email as user 2, but the uuid wins.
        $path = ssoFile([ssoEntry('t1', 'u-1', 'other@corp.test', 'CRM.cegem360.eu', '2026-03-01T09:10:00+00:00')]);
        reconstruct([new SsoLoginRule($path)]);

        $row = AuditEvent::query()->sole();
        expect($row->event)->toBe(AuditEventType::Login)
            ->and($row->user_id)->toBe($user)
            ->and($row->user_email)->toBe('a@corp.test')
            ->and($row->inferred)->toBeFalse()
            ->and($row->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-03-01 09:10:00')
            ->and($row->evidence_key)->toBe('sso:t1')
            ->and($row->evidence)->toMatchArray(['rule' => 'sso_login', 'table' => 'oauth_access_tokens', 'id' => 't1', 'auth_code_id' => 'code-t1']);
    });

    it('ignores tokens of other apps', function (): void {
        insertUser(['uuid' => 'u-1']);

        $report = reconstruct([new SsoLoginRule(ssoFile([ssoEntry('t1', 'u-1', null, 'sales.cegem360.eu', '2026-03-01T09:10:00+00:00')]))]);

        expect(AuditEvent::query()->count())->toBe(0)
            ->and($report->rule('sso_login')['found'])->toBe(0);
    });

    it('matches by email, case-insensitively, when the entry has no uuid or the local user has none', function (): void {
        $a = insertUser(['email' => 'A@Corp.test', 'uuid' => 'u-a']);
        $b = insertUser(['email' => 'b@corp.test']);

        reconstruct([new SsoLoginRule(ssoFile([
            ssoEntry('t1', null, 'a@corp.TEST', 'crm.cegem360.eu', '2026-03-01T09:10:00+00:00'),
            ssoEntry('t2', 'u-b', 'B@corp.test', 'crm.cegem360.eu', '2026-03-01T10:10:00+00:00'),
        ]))]);

        expect(AuditEvent::query()->orderBy('evidence_key')->pluck('user_id')->all())->toBe([$a, $b]);
    });

    it('rejects an email match whose local uuid contradicts the entry uuid', function (): void {
        // The publisher account's email was reused locally by someone else.
        insertUser(['email' => 'b@corp.test', 'uuid' => 'u-b']);

        $report = reconstruct([new SsoLoginRule(ssoFile([
            ssoEntry('t2', 'u-unknown', 'B@corp.test', 'crm.cegem360.eu', '2026-03-01T10:10:00+00:00'),
        ]))]);

        expect($report->rule('sso_login')['rejected'])->toBe(['uuid_mismatch' => 1])
            ->and(AuditEvent::query()->count())->toBe(0);
    });

    it('matches by email when the users table has no uuid column', function (): void {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('uuid'));
        $a = insertUser(['email' => 'a@corp.test']);

        reconstruct([new SsoLoginRule(ssoFile([ssoEntry('t1', 'u-1', 'a@corp.test', 'crm.cegem360.eu', '2026-03-01T09:10:00+00:00')]))]);

        expect(AuditEvent::query()->sole()->user_id)->toBe($a);
    });

    it('rejects tokens of unknown users as unknown_user', function (): void {
        insertUser(['email' => 'a@corp.test', 'uuid' => 'u-1']);

        $report = reconstruct([new SsoLoginRule(ssoFile([ssoEntry('t1', 'u-x', 'x@corp.test', 'crm.cegem360.eu', '2026-03-01T09:10:00+00:00')]))]);

        expect($report->rule('sso_login')['rejected'])->toBe(['unknown_user' => 1])
            ->and(AuditEvent::query()->count())->toBe(0);
    });

    it('converts the token time to the app timezone', function (): void {
        config(['app.timezone' => 'Europe/Budapest']);
        insertUser(['uuid' => 'u-1']);

        reconstruct([new SsoLoginRule(ssoFile([ssoEntry('t1', 'u-1', null, 'crm.cegem360.eu', '2026-03-01T23:30:00+00:00')]))]);

        expect(AuditEvent::query()->sole()->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-03-02 00:30:00');
    });

    it('applies the common exclusions', function (): void {
        insertUser(['email' => 'seed@example.com', 'uuid' => 'u-1']);
        insertUser(['email' => 'late@corp.test', 'uuid' => 'u-2', 'created_at' => '2026-04-01 00:00:00']);

        $report = reconstruct([new SsoLoginRule(ssoFile([
            ssoEntry('t1', 'u-1', null, 'crm.cegem360.eu', '2026-03-01T09:10:00+00:00'),
            ssoEntry('t2', 'u-2', null, 'crm.cegem360.eu', '2026-03-01T09:10:00+00:00'),
        ]))]);

        expect($report->rule('sso_login')['rejected'])->toBe(['excluded_user' => 1, 'before_account' => 1]);
    });

    describe('provisioning grace', function (): void {
        it('accepts a token issued 1 second before the local account, which the first SSO login created', function (): void {
            insertUser(['uuid' => 'u-1', 'created_at' => '2026-03-01 09:10:01']);

            $report = reconstruct([new SsoLoginRule(ssoFile([ssoEntry('t1', 'u-1', null, 'crm.cegem360.eu', '2026-03-01T09:10:00+00:00')]))]);

            expect($report->rule('sso_login')['inserted'])->toBe(1)
                ->and(AuditEvent::query()->sole()->event)->toBe(AuditEventType::Login);
        });

        it('rejects a token issued 121 seconds before the local account', function (): void {
            insertUser(['uuid' => 'u-1', 'created_at' => '2026-03-01 09:12:01']);

            $report = reconstruct([new SsoLoginRule(ssoFile([ssoEntry('t1', 'u-1', null, 'crm.cegem360.eu', '2026-03-01T09:10:00+00:00')]))]);

            expect($report->rule('sso_login')['rejected'])->toBe(['before_account' => 1])
                ->and(AuditEvent::query()->count())->toBe(0);
        });

        it('keeps the strict check for records of other rules', function (): void {
            $user = insertUser(['created_at' => '2026-03-01 10:00:01']);
            DB::table('imports')->insert([
                'file_name' => 'a.csv', 'file_path' => 'x/a.csv', 'importer' => 'App\\Imp', 'total_rows' => 3,
                'user_id' => $user, 'created_at' => '2026-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00',
            ]);

            $report = reconstruct([new ImportExportRule]);

            expect($report->rule('imports_exports')['rejected'])->toBe(['before_account' => 1])
                ->and(AuditEvent::query()->count())->toBe(0);
        });

        it('defaults the window to 120 seconds when the config key is absent', function (): void {
            $reconstruct = config('audit-trail.reconstruct');
            unset($reconstruct['sso_provisioning_grace_seconds']);
            config(['audit-trail.reconstruct' => $reconstruct]);
            insertUser(['uuid' => 'u-1', 'created_at' => '2026-03-01 09:12:00']);
            insertUser(['uuid' => 'u-2', 'created_at' => '2026-03-01 09:12:01']);

            $report = reconstruct([new SsoLoginRule(ssoFile([
                ssoEntry('t1', 'u-1', null, 'crm.cegem360.eu', '2026-03-01T09:10:00+00:00'),
                ssoEntry('t2', 'u-2', null, 'crm.cegem360.eu', '2026-03-01T09:10:00+00:00'),
            ]))]);

            expect($report->rule('sso_login')['inserted'])->toBe(1)
                ->and($report->rule('sso_login')['rejected'])->toBe(['before_account' => 1]);
        });
    });

    it('rejects a missing, unreadable or malformed file', function (string $contents): void {
        new SsoLoginRule($contents === 'missing' ? '/nonexistent/sso.json' : ssoFile($contents));
    })->with([
        'missing' => ['missing'],
        'not json' => ['{nope'],
        'not a list' => ['{"token_id": "t1"}'],
        'entry without created_at' => [json_encode([['token_id' => 't1', 'app_host' => 'crm.cegem360.eu', 'auth_code_id' => 'c1']])],
        'entry without auth_code_id' => [json_encode([['token_id' => 't1', 'app_host' => 'crm.cegem360.eu', 'created_at' => '2026-03-01T09:10:00+00:00']])],
        'unparsable created_at' => [json_encode([['token_id' => 't1', 'app_host' => 'crm.cegem360.eu', 'created_at' => 'yesterday-ish', 'auth_code_id' => 'c1']])],
    ])->throws(InvalidArgumentException::class);

    it('is run by the reconstruct command with --sso-file', function (): void {
        insertUser(['uuid' => 'u-1']);
        $path = ssoFile([ssoEntry('t1', 'u-1', null, 'crm.cegem360.eu', '2026-03-01T09:10:00+00:00')]);

        $this->artisan('audit-trail:reconstruct', ['--sso-file' => $path, '--rule' => ['sso_login']])
            ->expectsOutputToContain('sso_login')
            ->assertExitCode(0);

        expect(AuditEvent::query()->sole()->evidence_key)->toBe('sso:t1');
    });

    it('fails the reconstruct command clearly when the file is missing', function (): void {
        $this->artisan('audit-trail:reconstruct', ['--sso-file' => '/nonexistent/sso.json'])
            ->expectsOutputToContain('/nonexistent/sso.json')
            ->assertExitCode(1);

        expect(AuditEvent::query()->count())->toBe(0);
    });

    it('needs --sso-file to run sso_login', function (): void {
        $this->artisan('audit-trail:reconstruct', ['--rule' => ['sso_login']])
            ->expectsOutputToContain('--sso-file')
            ->assertExitCode(1);
    });
});
