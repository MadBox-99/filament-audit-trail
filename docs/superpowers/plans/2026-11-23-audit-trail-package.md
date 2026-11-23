# filament-audit-trail csomag — Implementation Plan (1/2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Egy Filament 5 plugin csomag, ami élőben naplózza a belépést/kilépést/sikertelen belépést, oldalmegtekintést, Filament-akciót és adatváltozást egy append-only `audit_events` táblába, és valódi nyomokból visszamenőleg helyreállítja a múltat.

**Architecture:** Egy `AuditRecorder` szolgáltatás az egyetlen író; élő forrásai Laravel auth-eseményekre, Eloquent wildcard-eseményekre, Livewire `call` hookra és egy panel-middleware-re kötnek. A helyreállítás `Rule`-okból áll, amik `EvidenceRecord`-okat adnak; a `Reconstructor` szűr, idempotensen ír (`evidence_key` unique), és riportot ad. Filament resource csak olvasásra, saját láthatósági szűkítéssel.

**Tech Stack:** PHP ^8.3, Laravel ^12 || ^13, Filament ^5 (Livewire 4), Pest 4 + Orchestra Testbench, Larastan level 6, Pint.

**Spec:** `docs/superpowers/specs/2026-11-23-audit-trail-design.md`

## Global Constraints

- Composer név `madbox-99/filament-audit-trail`, namespace `Madbox99\FilamentAuditTrail\`, provider `FilamentAuditTrailServiceProvider`, plugin `FilamentAuditTrailPlugin`, config kulcs `audit-trail`.
- Minden fájl `declare(strict_types=1);`, `final` osztályok, ahol lehet.
- Naplózási hiba SOHA nem dobhat kivételt a kérésbe: `try { … } catch (\Throwable $e) { report($e); }`.
- Belépés csak valódi nyomból; nincs véletlen/gépi kitöltés semmilyen kódútban.
- Érzékeny kulcsok (`password`, `remember_token`, `two_factor_*`, `*token*`, `*secret*`, `*api_key*` + a modell `$hidden`) soha nem kerülnek `properties`-be.
- `properties` max 16384 bájt JSON-ként; felette `{"_truncated": true}`.
- Kitöltő időbélyegek: `2025-10-10 09:00:00`, `2025-10-01 12:00:00`; csapat-létrehozás utáni kizárás: 120 mp; seed-email minta: `%@example.%`.
- Az `AuditEvent` modell append-only; `updated_at` nincs.
- Új fájlt CLI-generátorral hozz létre, ahol van (`vendor/bin/testbench make:*` / orchestra canvas), ne kézzel.
- Felület-szövegek magyarul és angolul (`resources/lang/{hu,en}/audit-trail.php`).

## Review Focus

1. **Livewire update kérés oldalmegtekintésnek számolva** — egy szűrő-kattintás vagy polling nem lehet `page_view`. (Task 4 teszt: `POST /livewire*/update` → 0 sor.)
2. **Más csapat eseménye URL-ből nyitható** — nem admin user `/audit-events/{id}` másik csapat eseményére 404-et kap. (Task 7 teszt.)
3. **Rendszer által gyártott rekordból levezetett belépés** — csapat létrehozása után 120 mp-en belüli rekord, kitöltő `created_at`, a user fiókja előtti nyom nem adhat `login_inferred`-et. (Task 9 tesztek.)
4. **Újrafuttatott helyreállítás duplikál** — a második `reconstruct` futás 0 új sort ír. (Task 8 teszt.)
5. **Naplózás DB-hibája eldönti a kérést** — ha az `audit_events` tábla hiányzik, a login és a mentés akkor is sikerül. (Task 2 teszt.)

---

### Task 1: Csomag-váz, migráció, modell, enumok

**Files:**
- Create: `composer.json`, `phpunit.xml`, `phpstan.neon.dist`, `pint.json`, `.gitignore`, `.github/workflows/tests.yml`, `LICENSE`, `README.md` (váz), `config/audit-trail.php`, `database/migrations/create_audit_events_table.php.stub` → betöltve `loadMigrationsFrom`-mal (`database/migrations/2026_11_23_000000_create_audit_events_table.php`), `src/FilamentAuditTrailServiceProvider.php`, `src/Enums/AuditEventType.php`, `src/Enums/AuditSource.php`, `src/Models/AuditEvent.php`, `src/Exceptions/AuditEventImmutable.php`, `tests/TestCase.php`, `tests/Pest.php`, `tests/Fixtures/{User,Team,AdminPanelProvider}.php`
- Test: `tests/Unit/AuditEventModelTest.php`

**Interfaces:**
- Produces: `AuditEventType` (string-backed, `HasLabel`): `Login='login'`, `LoginInferred='login_inferred'`, `Logout='logout'`, `LoginFailed='login_failed'`, `PageView='page_view'`, `ActionMounted='action_mounted'`, `ActionExecuted='action_executed'`, `Created='created'`, `Updated='updated'`, `Deleted='deleted'`, `Restored='restored'`, `Import='import'`, `Export='export'`, `AccountCreated='account_created'`.
- Produces: `AuditSource`: `Live='live'`, `Reconstructed='reconstructed'`.
- Produces: `AuditEvent` model — table `config('audit-trail.table', 'audit_events')`, connection `config('audit-trail.connection')`; casts: `occurred_at` datetime, `event` AuditEventType, `source` AuditSource, `properties`/`evidence` array, `inferred` bool; `const UPDATED_AT = null`; `$guarded = []`.
- Produces: config kulcsok: `enabled` (env `AUDIT_TRAIL_ENABLED`, true), `connection` (null), `table`, `guard` ('web'), `events` (minden típus => true), `page_views.exclude_paths` (['livewire*', 'up', '_debugbar*', 'filament/assets*', 'storage/*']), `models.exclude` (osztálynevek/táblanevek: `audit_events`, `sessions`, `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `notifications`, `sync_logs`, `oauth_*`, `telescope_*`, `pulse_*`, `activity_log`), `redact` (fenti minták), `max_properties_bytes` (16384), `reconstruct.*` (Task 8–10).

Séma pontosan a spec 5. pontja szerint (+`hash` char(64) null); indexek: `occurred_at`, `event`, `user_id`, `team_id`, `source`, (`subject_type`,`subject_id`); `evidence_key` unique.

- [ ] **Step 1:** Váz a `~/Herd/filament-chat-widget` mintájára (composer require/require-dev, TestCase provider-sorrend — a `LivewireServiceProvider` a `SupportServiceProvider` után!, Pest.php, phpunit.xml, phpstan level 6, CI). Fixtures: `User` (`isAdmin(): bool` egy `is_admin` oszlopból, `teams()` belongsToMany, `HasTenants`), `Team`, `AdminPanelProvider` (id `admin`, path `admin`, tenant `Team::class`, `->plugin(FilamentAuditTrailPlugin::make())` — a plugin osztály itt még üres váz). A TestCase migrációi: `users` (+`is_admin`, +`uuid` nullable), `teams`, `team_user` (+timestamps), `posts` (`id, team_id, user_id nullable, title, secret_token, timestamps`) fixture-modellel `Post`.
- [ ] **Step 2: Failing tests** — `tests/Unit/AuditEventModelTest.php`:
  - `it('casts enums and json')`: létrehozás `event=Login, source=Live, properties=['a'=>1]` → visszaolvasva `->event === AuditEventType::Login`, `->properties === ['a'=>1]`.
  - `it('refuses updates')`: `$e->update(['user_email'=>'x'])` → `toThrow(AuditEventImmutable::class)`.
  - `it('refuses deletes')`: `$e->delete()` → `toThrow(AuditEventImmutable::class)`; a sor megvan.
  - `it('enforces unique evidence_key')`: két insert azonos `evidence_key`-jel → `QueryException`.
- [ ] **Step 3:** `vendor/bin/pest` → FAIL.
- [ ] **Step 4:** Implementáld (modell `booted()`: `updating`/`deleting` → throw).
- [ ] **Step 5:** `vendor/bin/pest && vendor/bin/phpstan analyse && vendor/bin/pint --test` → zöld.
- [ ] **Step 6:** Commit `feat: package skeleton, audit_events table and immutable model`.

### Task 2: AuditRecorder + PropertySanitizer

**Files:**
- Create: `src/Support/AuditRecorder.php`, `src/Support/PropertySanitizer.php`
- Test: `tests/Unit/PropertySanitizerTest.php`, `tests/Feature/AuditRecorderTest.php`

**Interfaces:**
- Produces: `PropertySanitizer::sanitize(array $data, array $extraHidden = []): array` — rekurzívan törli a `redact` mintákra (fnmatch, kis-nagybetű független) és `$extraHidden`-re illeszkedő kulcsokat; ha a JSON > `max_properties_bytes` → `['_truncated' => true]`.
- Produces: `AuditRecorder` (singleton) —
  - `record(AuditEventType $type, array $attributes = []): ?AuditEvent` — ha `enabled` és `events.{type}` igaz: kitölti a hiányzó kontextust (`occurred_at` now(3), `user_id`/`user_email` a guard userből, `team_id` = `Filament::getTenant()?->getKey()`, `panel` = `Filament::getCurrentPanel()?->getId()`, `url`, `route` név, `method`, `ip`, `user_agent` (512-re vágva), `session_hash` = sha256(session id) ha van session, `source` Live), `properties` sanitizálva; hibánál `report()` és `null`.
  - `recordReconstructed(array $attributes): bool` — `source` Reconstructed, `insertOrIgnore` (evidence_key alapján), igaz ha új sor jött létre. Kontextust NEM tölt (nincs request).
  - `withoutRecording(callable $callback): mixed` — a callback idejére kikapcsol (helyreállítás és tesztek használják).

- [ ] **Step 1: Failing tests:**
  - `PropertySanitizerTest`: `['password'=>'x','name'=>'a','nested'=>['api_key'=>'k','ok'=>1],'two_factor_secret'=>'s','access_token'=>'t']` → `['name'=>'a','nested'=>['ok'=>1]]`; 20 000 bájtos string → `['_truncated'=>true]`.
  - `AuditRecorderTest`: `actingAs($user)` + `record(Login)` → sor `user_id`, `user_email`, `ip='127.0.0.1'`, `source=live`; `config(['audit-trail.events.login'=>false])` → `null`, 0 sor; `Schema::drop('audit_events')` után `record(Login)` → `null`, nem dob (Review Focus 5); `recordReconstructed` kétszer azonos `evidence_key`-jel → első `true`, második `false`, 1 sor.
- [ ] **Step 2:** Futtasd → FAIL. **Step 3:** Implementáld. **Step 4:** zöld + phpstan + pint.
- [ ] **Step 5:** Commit `feat: AuditRecorder and property sanitizer`.

### Task 3: Belépés / kilépés / sikertelen belépés

**Files:**
- Create: `src/Listeners/AuthEventSubscriber.php`; Modify: service provider (`Event::subscribe`)
- Test: `tests/Feature/AuthEventsTest.php`

**Interfaces:** Consumes `AuditRecorder::record`. `Failed` → `LoginFailed`, `user_id` = a talált user id-ja vagy null, `user_email` = `$event->credentials['email'] ?? null`; jelszó soha. Csak a `config('audit-trail.guard')` guard eseményei.

- [ ] **Step 1: Failing tests:** `Auth::login($user)` → 1 `login` sor a userrel; `Auth::logout()` → `logout` sor; `Auth::attempt(['email'=>$u->email,'password'=>'rossz'])` → `login_failed`, `properties`/egész sor JSON-ja nem tartalmazza a `rossz` stringet; más guard (`api`) login → 0 sor.
- [ ] **Step 2–4:** FAIL → implementáld → zöld.
- [ ] **Step 5:** Commit `feat: record login, logout and failed login`.

### Task 4: Oldalmegtekintés-middleware

**Files:**
- Create: `src/Http/Middleware/RecordPageView.php`; Modify: `src/FilamentAuditTrailPlugin.php` (`register()`: `$panel->middleware([RecordPageView::class])` — nem persistent)
- Test: `tests/Feature/PageViewTest.php`

**Interfaces:** `PageView` csak ha: GET, van auth user, a válasz 2xx, nem `X-Livewire` kérés, a path nem illik `page_views.exclude_paths`-ra. `properties` = `['title' => null]` helyett csak url/route (a recorder tölti).

- [ ] **Step 1: Failing tests:** belépett user `get('/admin/{tenant}')` → 1 `page_view` sor `panel='admin'`, `team_id` = tenant; vendég → 0 sor (redirect); `post` a `/livewire…/update`-re `X-Livewire` fejléccel → 0 sor (Review Focus 1); 404 oldal → 0 sor.
- [ ] **Step 2–4:** FAIL → implementáld → zöld. **Step 5:** Commit `feat: record panel page views`.

### Task 5: Adatváltozás (Eloquent) + import/export

**Files:**
- Create: `src/Listeners/ModelChangeListener.php`; Modify: service provider (`Event::listen('eloquent.created: *' …)` stb.)
- Test: `tests/Feature/ModelChangesTest.php`

**Interfaces:** Csak ha van auth user (guard). Kizárás: `models.exclude` (osztálynév vagy tábla, fnmatch). `Created` → `properties.new` = attribútumok; `Updated` → csak `getChanges()` kulcsai: `{old, new}` (`updated_at` nélkül; ha más nem változott → nincs sor); `Deleted`/`Restored` → `subject`. Sanitizálás a modell `getHidden()`-jével. `Filament\Actions\Imports\Models\Import` létrehozása → `Import` esemény (`properties`: file_name, total_rows), `Filament\Actions\Exports\Models\Export` → `Export` (`exporter`, `total_rows`) — ezek NEM kapnak külön `Created`-et.

- [ ] **Step 1: Failing tests:** `actingAs` + `Post::create([... 'secret_token'=>'s'])` → 1 `created` sor, `subject_type=Post::class`, `properties.new.title`, nincs `secret_token`; `update(['title'=>'b'])` → `updated` `{old:{title:a}, new:{title:b}}`; `touch()` → 0 új sor; `delete()` → `deleted`; auth nélkül (pl. queue) `Post::create` → 0 sor; `AuditEvent` létrehozása nem generál `created` sort (nincs rekurzió); Filament `Export` modell létrehozása → 1 `export` sor, 0 `created`.
- [ ] **Step 2–4:** FAIL → implementáld → zöld. **Step 5:** Commit `feat: record model changes, imports and exports`.

### Task 6: Kattintás — Filament akciók Livewire hookkal

**Files:**
- Create: `src/Livewire/ActionCallHook.php`; Modify: service provider `boot()` (regisztráció)
- Test: `tests/Feature/ActionTrackingTest.php`

**Interfaces:** Livewire 4 komponens-hook (`Livewire\on('call', …)` vagy a Livewire 4 megfelelője — ellenőrizd a telepített vendorban); `mountAction` → `ActionMounted` (`action` = első paraméter, `properties.arguments` sanitizálva, `properties.component` = komponens osztály); `callMountedAction` → `ActionExecuted` (az akció neve a komponens `mountedActions` állapotából, a hívás ELŐTT kiolvasva). `subject_*` = a rekord, ha a komponens `getRecord()`-ot ad vagy az argumentumban `recordKey` van.

- [ ] **Step 1: Failing tests:** Livewire teszt a fixture `PostResource` Edit oldalán: `->callAction(DeleteAction::class)` → 1 `action_mounted` + 1 `action_executed` sor `action='delete'`, `subject_id` = post id; táblázat-rekord akció (`callTableAction('edit', $post)`) → `action_mounted` `subject_id` = post id; sima `set('data.title', …)` (nem akció) → 0 akció-sor.
- [ ] **Step 2–4:** FAIL → implementáld (fixture: `tests/Fixtures/PostResource` Edit + List oldallal) → zöld. **Step 5:** Commit `feat: record Filament action clicks`.

### Task 7: Filament resource, plugin, láthatóság, fordítások

**Files:**
- Create: `src/Filament/Resources/AuditEvents/AuditEventResource.php`, `.../Pages/ListAuditEvents.php`, `.../Pages/ViewAuditEvent.php`, `.../Tables/AuditEventsTable.php`, `.../Schemas/AuditEventInfolist.php`, `src/Support/AuditVisibility.php`, `resources/lang/{hu,en}/audit-trail.php`; Modify: `src/FilamentAuditTrailPlugin.php`
- Test: `tests/Feature/AuditEventResourceTest.php`

**Interfaces:**
- `FilamentAuditTrailPlugin::make(): self`, `->adminUsing(Closure $callback): self` (alap: `fn ($user) => method_exists($user, 'isAdmin') && $user->isAdmin()`), `->navigationGroup(?string)`, `register()` resource + middleware (Task 4).
- `AuditVisibility::isAdmin(Authenticatable $user): bool`; `AuditVisibility::scope(Builder $query, Authenticatable $user, ?Model $tenant): Builder` — admin: változatlan; tenant esetén `team_id = tenant` VAGY (`team_id IS NULL` ÉS `user_id IN` a tenant jelenlegi tagjai); tenant nélkül `user_id = auth`.
- Resource: `$isScopedToTenant = false`; `getEloquentQuery()` → `AuditVisibility::scope`; `canCreate/canEdit/canDelete` false; `canViewAny` = van user; oszlopok: occurred_at, user_email, event (badge), subject (type basename#id), action/route, ip, source (badge, reconstructed + inferred jelölés); szűrők: `SelectFilter` event `->options(AuditEventType::class)`, source `->options(AuditSource::class)`, user, team (csak adminnak), dátum-tartomány; alapsorrend `occurred_at desc`; `ExportAction` (exporter: `AuditEventExporter`, `src/Filament/Exports/`). View: infolist `properties`, `evidence` KeyValue/JSON.

- [ ] **Step 1: Failing tests:** admin a lista oldalon látja A és B csapat eseményét; nem admin A tenantban: látja A `team_id`-s eseményt és A-tag NULL-team `login`-ját, nem látja B-ét; nem admin `get(ViewAuditEvent::getUrl(['record'=>$bEvent]))` → 404 (Review Focus 2); nincs Create/Edit/Delete akció; `hu` locale-ban az `login_inferred` címke „Belépés (levezetett)”.
- [ ] **Step 2–4:** FAIL → implementáld → zöld. **Step 5:** Commit `feat: read-only audit event resource with team visibility`.

### Task 8: Helyreállítás — keret, `activity_log`, import/export, fiók-létrehozás

**Files:**
- Create: `src/Reconstruction/EvidenceRecord.php`, `src/Reconstruction/ReconstructionContext.php`, `src/Reconstruction/Reconstructor.php`, `src/Reconstruction/ReconstructionReport.php`, `src/Reconstruction/Rules/Rule.php`, `.../Rules/ActivityLogRule.php`, `.../Rules/ImportExportRule.php`, `.../Rules/AccountCreatedRule.php`, `src/Commands/ReconstructCommand.php`
- Test: `tests/Feature/Reconstruction/FrameworkTest.php`, `ActivityLogRuleTest.php`, `ImportExportRuleTest.php`, `AccountCreatedRuleTest.php`

**Interfaces:**
- `EvidenceRecord` (readonly): `AuditEventType $type, CarbonImmutable $occurredAt, ?int $userId, ?string $userEmail, ?int $teamId, ?string $subjectType, ?string $subjectId, array $properties, array $evidence /* rule, table, id, column, note */, string $evidenceKey, bool $inferred = false`; `toAttributes(): array`.
- `Rule`: `name(): string`; `collect(ReconstructionContext $ctx): iterable<EvidenceRecord>`.
- `ReconstructionContext`: `isIgnoredTimestamp(?CarbonInterface): bool`, `userCreatedAt(int $userId): ?CarbonImmutable` (null ha kitöltő), `isExcludedUser(int $userId): bool` (email-minta), `since/until`, `connection(): Connection`.
- `Reconstructor::run(array $rules, bool $dryRun): ReconstructionReport` — minden rekordot elvet, ha: kitöltő időbélyeg; a user kizárt; `occurredAt` < user `created_at` (ha az ismert); kívül esik since/until-on. Szabályonként tranzakció; írás `AuditRecorder::recordReconstructed`. A modell-listener a futás alatt kikapcsolva (`withoutRecording`).
- `ReconstructionReport`: szabályonként `found/accepted/rejected(by reason)/inserted`; userenként `login`/`login_inferred`/`account_created`/aktív napok; `toTable(): array`, `topUsers(int $n = 5)`.
- `audit-trail:reconstruct {--dry-run} {--rule=*} {--sso-file=} {--since=} {--until=} {--suggest}` — kiírja a riportot (szabály-tábla + user-összesítő: összes user, SSO-belépéses, levezetett belépéses, csak account_created, top 5 user aktív napjai).
- Rules: `ActivityLogRule` (ha van `activity_log` tábla; `causer_type` a user modell osztálya/morph aliasa; event → Created/Updated/Deleted, egyéb (`viewed`, `handover`…) → ActionExecuted `action`=event; `properties` sanitizálva; key `activity_log:{id}`); `ImportExportRule` (key `imports:{id}` / `exports:{id}`); `AccountCreatedRule` (minden user, `user_id` null, subject = user; key `users:{id}:created`; ha `reconstruct.publisher` igaz és `email_verified_at` nem null: + `LoginInferred` a `created_at`-re aktor = user, rule `registration`, key `users:{id}:registration`; + `LoginInferred` az `email_verified_at`-re, rule `email_verification`, key `users:{id}:verified`).

- [ ] **Step 1: Failing tests:**
  - Framework: kétszeri futás → második `inserted` = 0 (Review Focus 4); `--dry-run` → 0 sor, riport `found>0`; kitöltő `created_at` (`2025-10-10 09:00:00`) user → nincs `account_created`, riportban `rejected.ignored_timestamp`; user `created_at` előtti nyom → elutasítva `before_account`; `@example.com` user → elutasítva.
  - ActivityLog: 1 spatie-sor (`created`, causer user 1, 2026-03-01 10:00) → `created` esemény `occurred_at` = 2026-03-01 10:00, `evidence.table='activity_log'`, `source=reconstructed`.
  - AccountCreated: receiver módban `user_id` null és nincs login_inferred; `publisher=true` + verified user → 2 `login_inferred` sor a két időpontra; nem verified → 0.
- [ ] **Step 2–4:** FAIL → implementáld → zöld. **Step 5:** Commit `feat: reconstruction framework with activity_log, import/export and account rules`.

### Task 9: Helyreállítás — actor-oszlopok és egyszemélyes csapat

**Files:**
- Create: `src/Reconstruction/Rules/ActorColumnsRule.php`, `.../Rules/SoleMemberTeamRule.php`, `src/Reconstruction/SchemaInspector.php`
- Test: `tests/Feature/Reconstruction/ActorColumnsRuleTest.php`, `SoleMemberTeamRuleTest.php`, `SuggestTest.php`

**Interfaces:**
- Config: `reconstruct.actor_columns` (`['documents' => 'created_by', …]`), `reconstruct.sole_member_tables` (`['work_orders', …]`), `reconstruct.team_creation_grace_seconds` (120), `reconstruct.team_user_table` ('team_user'), `reconstruct.teams_table` ('teams').
- `SchemaInspector::actorColumnCandidates(): array<string,string>` (táblák `user_id|created_by|uploaded_by|sender_id|author_id|creator_id` oszloppal + `created_at`-tel, a `models.exclude` és ismert pivot/token táblák nélkül), `teamTableCandidates(): array<string>` (`team_id` + `created_at`), mindkettő soronkénti darabszámmal.
- `--suggest` → kiírja a jelölteket darabszámmal és a kész config-részletet; NEM ír.
- `ActorColumnsRule`: csak a configban felsorolt táblák; `Created` esemény, user = oszlop értéke, key `{table}:{id}:actor`; elveti, ha van `team_id` és a rekord a csapat `created_at`-je után 120 mp-en belül jött létre (`team_bootstrap`).
- `SoleMemberTeamRule`: configban felsorolt táblák; a csapatnak most pontosan 1 tagja van; ha `team_user.created_at` létezik és nem null, az ≤ rekord ideje; 120 mp-es kizárás; `Created` esemény a tagra, `evidence.note` = 'sole member at reconstruction time; former members not visible', key `{table}:{id}:sole`.

- [ ] **Step 1: Failing tests:** actor: `posts.user_id=1` → `created` user 1; csapat létrehozása után 30 mp-cel készült post → elutasítva `team_bootstrap` (Review Focus 3); nem felsorolt tábla → 0. Sole: 1 tagú csapat postja (user_id null) → `created` a tagra; 2 tagú csapat → 0; tag `team_user.created_at` a rekord után → 0. Suggest: `posts` megjelenik `user_id`-val és darabszámmal, `sessions` nem.
- [ ] **Step 2–4:** FAIL → implementáld → zöld. **Step 5:** Commit `feat: actor column and sole team member reconstruction rules`.

### Task 10: SSO-export (publisher), SSO-belépés és levezetett belépés

**Files:**
- Create: `src/Commands/ExportSsoCommand.php`, `src/Reconstruction/Rules/SsoLoginRule.php`, `src/Reconstruction/Rules/DerivedLoginRule.php`
- Test: `tests/Feature/Reconstruction/SsoTest.php`, `DerivedLoginRuleTest.php`

**Interfaces:**
- `audit-trail:export-sso {--output=}` (publisheren): `oauth_access_tokens` ⨝ `oauth_clients` ⨝ `users` → JSON lista `{token_id, user_uuid, user_email, app_host, created_at}`; `app_host` = a kliens redirect URI-jának hostja (Passport `redirect_uris` JSON vagy régi `redirect` oszlop — mindkettőt kezeld). Ha nincs `oauth_access_tokens` tábla: hibaüzenet, exit 1.
- `SsoLoginRule(string $path)`: csak a `parse_url(config('app.url'), PHP_URL_HOST)`-tal egyező `app_host` sorok; user párosítás `users.uuid`, ha nincs uuid oszlop vagy nincs találat: email; `Login`, `inferred=false`, key `sso:{token_id}`.
- `DerivedLoginRule`: a többi szabály ELFOGADOTT rekordjain (memóriában, a már DB-ben lévő `reconstructed` sorokkal együtt) fut, ezért a `Reconstructor` mindig utolsóként hívja; művelet-típusok: Created, Updated, Deleted, Import, Export, ActionExecuted; userenként és naponként (app timezone) a legkorábbi → `LoginInferred`, `inferred=true`, `evidence` = az a nyom (`rule`, `table`, `id`, `evidence_key`), key `derived_login:{userId}:{Y-m-d}`; kihagyja a napot, ha aznap van `Login` (SSO vagy élő).

- [ ] **Step 1: Failing tests:** export: 2 token két klienshez → JSON 2 elem helyes `app_host`-tal; Sso: a saját hostú elem → `login` pontos időponttal, uuid-párosítással; idegen host → 0; uuid nélkül email-párosítás. Derived: user 1-nek 2026-03-01-én 09:10 és 14:00 `created` nyoma → 1 `login_inferred` 09:10-kor `evidence.evidence_key` = a 09:10-es nyom kulcsa; ugyanarra a napra SSO `login` → 0 `login_inferred`; csak `account_created` nyom → 0 `login_inferred`.
- [ ] **Step 2–4:** FAIL → implementáld → zöld. **Step 5:** Commit `feat: SSO login import and derived daily logins`.

### Task 11: Dokumentáció, minőség, kiadás

**Files:** Modify: `README.md`, `CHANGELOG.md`

- [ ] **Step 1:** README: telepítés (`composer require`, `->plugin(FilamentAuditTrailPlugin::make()->adminUsing(...))`, `php artisan migrate`), config, a helyreállítás folyamata (`--suggest` → config → `--dry-run` → valódi futás; publisheren `export-sso`), a jelölések jelentése (`source`, `inferred`, `evidence`), és hogy kitalált bejegyzést a csomag nem készít.
- [ ] **Step 2:** `vendor/bin/pest && vendor/bin/phpstan analyse && vendor/bin/pint --test` → mind zöld; mutációs próba a Review Focus 2. és 3. tesztjén (töröld a szűkítést / a 120 mp-es szűrést → a megnevezett teszt a megnevezett assertionnél bukik; visszaállítás után zöld).
- [ ] **Step 3:** `gh repo create MadBox-99/filament-audit-trail --public --source=. --push`; tag `v0.1.0`, push.
- [ ] **Step 4:** Commit `docs: README and changelog for v0.1.0`.
