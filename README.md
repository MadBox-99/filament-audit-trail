# filament-audit-trail

Append-only audit trail for Laravel with a Filament v5 plugin. It records who did what and when: logins,
page views, Filament actions and data changes. It can also rebuild the past from real records, with every
rebuilt row marked and traceable to the record it came from.

Requirements: PHP 8.3+, Laravel 12 or 13, Filament 5.

## Installation

The package is installed from its GitHub repository. Add the repository to the app's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/MadBox-99/filament-audit-trail", "no-api": true }
]
```

```bash
composer require madbox-99/filament-audit-trail:^0.1
php artisan migrate
```

The service provider is auto-discovered and loads the migration (it creates the `audit_events` table). To
change the configuration, publish it:

```bash
php artisan vendor:publish --tag=audit-trail-config
```

Register the plugin in the panel provider:

```php
use Madbox99\FilamentAuditTrail\FilamentAuditTrailPlugin;

$panel->plugin(
    FilamentAuditTrailPlugin::make()
        ->adminUsing(fn ($user) => $user->isSuperAdmin())
        ->navigationGroup('System'), // optional
);
```

`adminUsing()` is required to give anyone the full view: without it **nobody** is an admin (fail-closed), and
every user sees only the scoped view described under "Who sees what".

The plugin adds the audit resource to the panel and puts a page-view middleware on the panel.
Live recording of logins, model changes and actions works application-wide once the package is installed.

## What is recorded live

Every row carries the time, the user (id and email snapshot), the Filament tenant (`team_id`) and panel, URL,
route, method, IP, user agent and a SHA-256 hash of the session id. `occurred_at` and `created_at` keep
milliseconds. Each event type can be switched off in
`events`; the whole package with `AUDIT_TRAIL_ENABLED=false`. A recording error is reported with `report()`
and never breaks the request.

| Event | Recorded when |
|---|---|
| `login`, `logout`, `login_failed` | Laravel's `Login`, `Logout` and `Failed` events of the configured `guard` (default `web`); other guards are ignored. `login_failed` stores the entered email (cut to 255 characters), never the password. |
| `page_view` | A full-page `GET` with a 2xx response by an authenticated user inside a panel that has the plugin. Livewire requests (`X-Livewire` header) and paths matching `page_views.exclude_paths` are skipped. |
| `action_mounted`, `action_executed` | A Filament action is opened or run, caught through Livewire's call hook (page, table and record actions). See the limits below. |
| `created`, `updated`, `deleted`, `restored` | An Eloquent model event, **only when a user is authenticated on the configured guard** (so queued jobs and console commands write nothing). Tables and classes in `models.exclude` are skipped. `updated` stores only the changed attributes (`old` and `new`) and ignores a change of `updated_at` alone. Restoring a soft-deleted model writes one `restored` row, not an extra `updated`. |
| `import`, `export` | A Filament `Import` or `Export` record is created: one row per run (file name, importer/exporter, total rows), not one per imported row. |

Redaction: attributes whose name matches `redact` (case-insensitive wildcards; default `*password*`,
`remember_token`, `two_factor_*`, `*token*`, `*secret*`, `*api_key*`, `*private_key*`, `*credential*`) and the
model's `$hidden` attributes are never stored. In every recorded URL the value of a sensitive query parameter
is replaced by `[redacted]`: a parameter (or a nested key of it) named `token`, `signature`, `code`, `password`,
`secret` or `key`, or whose name contains `token`, `password` or `secret`, or ends in `_key` (case-insensitive). If `properties` would exceed `max_properties_bytes` (default 16384) it is replaced by
`{"_truncated": true}`.

### Actions: how they are recorded and what is not

- Opening a modal action writes `action_mounted`. Completing it (the mounted stack shrinks) writes `action_executed`.
- An action without a modal that Filament runs inside `mountAction` (enabled and authorized) writes
  `action_mounted` plus `action_executed` with `properties.executed_inline = true`.
- Argument values are redacted and size-capped like any other properties.

Known limits:

- Actions mounted through the URL (the `?action=` query mechanism) are not recorded.
- An inline action that halts inside its own `action()` callback is still counted as executed: the package
  cannot see a halt that does not leave the mounted stack or the error bag changed.

### Append-only

`AuditEvent` throws `AuditEventImmutable` on update and delete. This is model-level protection only; there is
no database trigger. The package never changes or deletes existing `activity_log` tables, and there is no
automatic retention or deletion. IP address and user agent are personal data; the legal basis is a
security and evidentiary log.

## Who sees what

Every panel user sees the menu item; the list and the view page use the same scope, so another team's event
cannot be opened by URL (it returns 404).

- **Admin** (the `adminUsing()` callback returns true; without the callback nobody is an admin): all events,
  filterable by team.
- **Non-admin on a tenant panel:** events with `team_id` = the current tenant, plus the team-less **sign-in**
  events (`login`, `login_inferred`, `logout`, `login_failed`) of users who are currently members of the
  tenant. Any other team-less row (for example a change whose team is unknown) is not shown on a tenant panel:
  it may belong to another team of a user who is in several teams. The membership pivot is
  `visibility.team_user_table` (default: `reconstruct.team_user_table`) with the columns
  `visibility.team_foreign_key` / `visibility.user_foreign_key`, read on the user model's connection (when the
  audit table is on another connection, the member ids are read first and embedded in the query).
- **Non-admin on a panel without a tenant:** only their own events.

The resource is read-only (no create, edit or delete). The table has filters for event, source, user, team and
date range, and an export action.

## Configuration

`config/audit-trail.php`:

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `env('AUDIT_TRAIL_ENABLED', true)` | Master switch. |
| `connection` | `null` | Database connection of the table (default connection when null). |
| `table` | `audit_events` | Table name. |
| `guard` | `web` | The only guard that is audited. |
| `events.*` | all `true` | Per event type switch (`login`, `login_inferred`, `logout`, `login_failed`, `page_view`, `action_mounted`, `action_executed`, `created`, `updated`, `deleted`, `restored`, `import`, `export`, `account_created`). |
| `page_views.exclude_paths` | `livewire*`, `up`, `_debugbar*`, `filament/assets*`, `storage/*` | `Request::is()` patterns that are not recorded. |
| `models.exclude` | `audit_events`, `sessions`, `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `notifications`, `sync_logs`, `oauth_*`, `telescope_*`, `pulse_*`, `activity_log` | `fnmatch` patterns matched against the model class and table name. |
| `redact` | see above | Attribute-name patterns that are never stored. |
| `visibility.team_user_table` | `null` (= `reconstruct.team_user_table`) | Membership pivot for the non-admin view. |
| `visibility.team_foreign_key` | `team_id` | Team column of the pivot. |
| `visibility.user_foreign_key` | `user_id` | User column of the pivot. |
| `max_properties_bytes` | `16384` | Size cap of `properties`. |
| `reconstruct.ignored_timestamps` | `2025-10-10 09:00:00`, `2025-10-01 12:00:00` | Placeholder timestamps; evidence with exactly this time is rejected. |
| `reconstruct.excluded_email_patterns` | `%@example.%` | SQL-LIKE style patterns of seed/test users that are excluded. |
| `reconstruct.team_creation_grace_seconds` | `120` | Rows created this soon after their team are treated as automatic bootstrap data. |
| `reconstruct.sso_provisioning_grace_seconds` | `120` | `sso_login` only: a token issued up to this many seconds before the local user's `created_at` is still accepted (the first SSO login creates the account). Read with this default when the key is missing from a published config. |
| `reconstruct.publisher` | `false` | `true` on the SSO publisher app: a verified registration then counts as an inferred login. |
| `reconstruct.actor_columns` | `[]` | Allowlist `table => user column` for the `actor_columns` rule. |
| `reconstruct.sole_member_tables` | `[]` | Allowlist of tables with a `team_id` for the `sole_member_team` rule. |
| `reconstruct.team_user_table` | `team_user` | Team membership pivot (`team_id`, `user_id`) of the `sole_member_team` rule; the visibility default. |
| `reconstruct.teams_table` | `teams` | Teams table, read for the team creation time. |
| `reconstruct.sso_auth_code_window_minutes` | `11` | `export-sso`: a token counts only if an authorization code of the same user and client expires within this many minutes after it. |
| `reconstruct.sso_auth_code_ttl_minutes` | `10` | Passport's authorization code lifetime. |

## Reconstructing the past

Before the package was installed nothing was logged, but many records prove that a user was active. The
reconstruction turns those real records into `audit_events` rows. It is idempotent (`evidence_key` is unique,
a rerun inserts nothing twice), runs one transaction per rule, and does not write live events while running.

### Workflow

1. **Suggest the allowlists.** `php artisan audit-trail:reconstruct --suggest` lists candidate tables for
   `actor_columns` and `sole_member_tables` found in the schema, with row counts, plus a config snippet. It
   writes nothing. Auto-discovery only suggests: the rules never touch a table that is not in the config.
2. **Fill the config.** Put the tables you have reviewed into `reconstruct.actor_columns` and
   `reconstruct.sole_member_tables`. Missing tables or columns are reported as warnings.
3. **Dry run.** `php artisan audit-trail:reconstruct --dry-run` prints a per-rule report (found, accepted,
   rejected by reason, inserted, existing, failed, error), a per-user summary and the top five users by active
   days. Nothing is written.
4. **Real run.** `php artisan audit-trail:reconstruct`.

On the **publisher** app (the one that issues the OAuth tokens) first run `php artisan audit-trail:export-sso`
(writes `storage/app/audit-sso-export.json`; `--output=` to change, `--force` to overwrite). Copy the file to each
app and run `php artisan audit-trail:reconstruct --sso-file=path/to/audit-sso-export.json` there. The `sso_login`
rule only runs when `--sso-file` is given, and only uses the entries whose `app_host` equals the host of the
app's `APP_URL`. Set `reconstruct.publisher` to `true` on the publisher itself.

Options: `--rule=name` (repeatable) limits the run, `--since=` and `--until=` limit the evidence time (a bare
date in `--until` means the end of that day).

**Default cutoff:** without `--until`, evidence stops just before the earliest `source = live` row, because live
recording already covers the time after it and reconstructing it again would duplicate those events. The
command prints the effective cutoff. If there is no live row yet, there is no cutoff. Pass `--until` to
override it (for example when live recording was switched off for a while). Note that rows from after the
cutoff (also `account_created` of users registered later) are then not reconstructed.

### Rules

Rules run in this order; `derived_login` always last because it reads the other rules' results.

| Rule | Source record | Result |
|---|---|---|
| `activity_log` | spatie `activity_log` rows whose causer is a user | `created`/`updated`/`deleted`/`restored` by event name, anything else `action_executed`; properties carried over, redacted. `team_id` comes from the subject row when its table has a `team_id` column and the row still exists; otherwise it stays empty. |
| `imports_exports` | Filament `imports` and `exports` rows | `import` / `export` at `created_at` by `user_id`. |
| `account_created` | `users.created_at` | `account_created` with **no actor** (in receiver apps a sync created the account). On the publisher, if the user has `email_verified_at` and `created_at` is not a placeholder, also `login_inferred` at `created_at` (`registration`) and at `email_verified_at` (`email_verification`); the second is skipped when `email_verified_at` equals `created_at` (verified in the moment of registration). |
| `actor_columns` | Tables in `reconstruct.actor_columns` | `created` by the user the named column points to. |
| `sole_member_team` | Tables in `reconstruct.sole_member_tables` | `created` by the only member of the row's team. Known limit: former members are invisible; the evidence note says so. |
| `sso_login` | The `export-sso` file (`--sso-file`) | `login` at the exact token time, `inferred = false`. The account check is relaxed by `reconstruct.sso_provisioning_grace_seconds` (default 120), because the first SSO login creates the local user a moment after the token. Other rules stay strict. |
| `derived_login` | Action traces (accepted `created`, `updated`, `deleted`, `import`, `export`, `action_executed` of this run and already stored reconstructed rows) | One `login_inferred` per user and day (app timezone) that has a trace but no login of any kind, at the day's earliest trace, `inferred = true`. |

### Rejection reasons

Evidence that is found but not accepted is counted per reason in the report and never written.

| Reason | Meaning |
|---|---|
| `ignored_timestamp` | The time is one of `reconstruct.ignored_timestamps` (placeholder value). |
| `excluded_user` | The user's email matches `excluded_email_patterns`. |
| `out_of_range` | Outside `--since` / `--until`. |
| `before_account` | The evidence is older than the user's real `created_at` (`sso_login`: older than `created_at` minus `sso_provisioning_grace_seconds`). |
| `unknown_user` | The record names a user that does not exist here (`activity_log`, `imports_exports`, `actor_columns`, and an SSO token of no known user). |
| `team_bootstrap` | `actor_columns` / `sole_member_team`: the row was created within `team_creation_grace_seconds` of its team (automatic base data). If the team's creation time is unknown, the row is accepted with an evidence note and the report warns that the check was not applied. |
| `member_joined_later` | `sole_member_team`: the member joined after the row was created, so they cannot be its author. |
| `uuid_mismatch` | `sso_login`: the email matches a local user whose uuid differs from the token's user. Same email, different person. |

### Meaning of the columns

- `source`: `live` (recorded when it happened) or `reconstructed` (rebuilt later by the command). `created_at` of
  a reconstructed row is the time of the run; `occurred_at` is the time of the evidence.
- `inferred`: `true` only for `login_inferred` rows. The time is the earliest known action (or the registration)
  of that day, **not** the real login time. A `login` row is never inferred.
- `evidence`: JSON that points at the source record: `rule`, `table`, `id`, `column`, and where useful `note`,
  `member_since`, `app_host`, `auth_code_id`, `source_rule`. Every reconstructed row has it.
- `evidence_key`: the unique idempotency key (for example `activity_log:12`, `posts:7:actor`, `posts:7:sole`,
  `sso:<token id>`, `derived_login:<user>:<day>`, `users:<id>:created`, `users:<id>:registration`,
  `users:<id>:verified`).

### The package never fabricates entries

A reconstructed row exists only because a real record exists, and it points at that record. In particular:

- A `login` (`inferred = false`) exists only from a real OAuth authorization (an access token issued for an
  authorization code; refreshed and personal access tokens are not exported) or from a live `Login` event.
- A `login_inferred` exists only for a day on which a real action record of that user exists (or, on the
  publisher, a verified registration), and says so with `inferred = true` and the record in `evidence`.
- A user without any trace gets no login. Placeholder timestamps, seed accounts and automatic team data are rejected.
- Reconstruction never edits or deletes existing rows, including live ones.

## Magyar összefoglaló

Append-only audit napló Laravel + Filament v5 alá: belépés, kilépés, sikertelen belépés, oldalmegtekintés,
Filament-akciók és adatváltozások rögzítése, plusz a múlt helyreállítása valódi nyomokból.

- **Telepítés:** VCS repó bejegyzés a `composer.json`-ba, `composer require madbox-99/filament-audit-trail:^0.1`,
  `php artisan migrate`, a plugin a panelben: `FilamentAuditTrailPlugin::make()->adminUsing(fn ($user) => ...)`.
  Az `adminUsing()` kötelező: nélküle senki sem admin (fail-closed).
- **Láthatóság:** admin mindent lát; nem admin tenantos panelen a tenant eseményeit, plusz a tenant jelenlegi
  tagjainak tenant nélküli belépés-jellegű eseményeit (`login`, `login_inferred`, `logout`, `login_failed`); más
  tenant nélküli sort nem (több csapatos user másik csapatáé lehet). Tenant nélküli panelen csak a sajátját. Más
  csapat eseménye URL-ből sem nyitható (404). A tagsági pivot: `visibility.*` (tábla, oszlopnevek), a user
  modell kapcsolatán olvasva.
- **Adatvédelem:** érzékeny attribútumok (`*password*`, `*token*`, `*secret*`, `*credential*` stb.) nem kerülnek
  a `properties`-be; a rögzített URL-ben az érzékeny query-paraméterek (token, signature, code, password,
  secret, key) értéke `[redacted]`.
- **Ismert korlátok:** az URL-ből felnyitott akciót nem rögzíti; a saját `action()`-jében leállított inline akciót
  végrehajtottként számolja. Adatváltozást csak bejelentkezett felhasználóhoz ír.
- **Helyreállítás:** `--suggest` -> az allowlist a configba -> `--dry-run` -> valódi futás. A publisheren előbb
  `audit-trail:export-sso`, majd minden appon `--sso-file`. `--until` nélkül a nyomok a legkorábbi élő
  (`source = live`) esemény előtt állnak meg, hogy ne duplikálja az élő naplót; a parancs kiírja a határt.
- **Publisher:** a megerősített regisztráció levezetett belépés (`login_inferred`) a regisztráció és az
  e-mail-megerősítés idején (`users:<id>:registration`, `users:<id>:verified`); ha a kettő ugyanaz a pillanat,
  csak egy.
- **Jelölések:** `source` (`live` / `reconstructed`), `inferred` (levezetett belépés ideje nem a valódi belépés
  ideje), `evidence` (a forrás-rekord), `evidence_key` (idempotencia).
- **Kitalált bejegyzés nincs:** belépés csak valódi OAuth-engedélyezésből vagy valódi, aznapi művelet-nyomból
  keletkezik; akinek nincs nyoma, annak nincs belépése.

## License

MIT
