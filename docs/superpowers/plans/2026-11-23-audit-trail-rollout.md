# filament-audit-trail bevezetése az .eu appokba — Implementation Plan (2/2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `madbox-99/filament-audit-trail` v0.1.x telepítése mind a 26 éles `*.cegem360.eu` Laravel/Filament appba, élő naplózással, és a múlt helyreállítása valódi nyomokból.

**Architecture:** Appokként azonos eljárás (Task A) külön worktree-ben; éles deploy a Forge Quick Deploy-jal; helyreállítás élesben előbb `--dry-run`, riport a felhasználónak, csak jóváhagyás után valódi futás.

**Tech Stack:** Composer VCS repó (GitHub `MadBox-99/filament-audit-trail`, publikus), Forge (`vps-cegem360-eu`, SSH `forge`), artisan.

**Spec:** `docs/superpowers/specs/2026-11-23-audit-trail-design.md` · Előfeltétel: az 1/2 terv kész, `v0.1.0` tag kint.

## Global Constraints

- A felhasználó munkakönyvtárához (piszkos fa / feature-ág) NEM nyúlunk: mindig `git worktree add ~/Herd/_worktrees/<repo>-audit -b feature/audit-trail origin/<default-branch>`.
- Pre-push hookot nem skippelünk (`SKIP_HOOKS`, `--no-verify` tilos); régi pint-/teszthibát javítunk. Nagy suite: `COMPOSER_PROCESS_TIMEOUT=0 git push`.
- Worktree-ben kell `public/build` (`npm ci && npm run build`), különben a tesztek 500-at kapnak.
- Élesben a `reconstruct` valódi futása CSAK a felhasználó jóváhagyása után, a dry-run riport bemutatása után.
- Rector: ha az app `rector.php`-ja hiányos, nem ennek a feladatnak a része.
- Constraint: `"madbox-99/filament-audit-trail": "^0.1"`; `repositories`-be `{ "type": "vcs", "url": "https://github.com/MadBox-99/filament-audit-trail", "no-api": true }`, ha nincs packagist.

## Review Focus

1. **Admin-gate hiányzik** — ahol nincs `isAdmin()` (Storage-cms, help, price-watcher, tarhely, campaigns, project-manager, tothpaszomany-app, -mes, -workflow), ott `->adminUsing(...)` kell, különben senki nem admin. (Task A, 4. lépés + smoke-teszt.)
2. **Több panel** (tothpaszomany-app: Crm/Mes/Supply) — mindhárom panelbe kell a plugin. (Task A teszt panelenként.)
3. **Tenant nélküli panel** (workflow, help, price-watcher, tarhely) — a nem admin csak a saját eseményét látja. (Task A smoke-teszt.)
4. **Migráció-névütközés** — ha az appban már van `audit_events` tábla, a telepítés álljon meg és jelezze.
5. **Queue nélküli app** — az élő naplózás szinkron, nem kell worker; az export (Filament ExportAction) igen — ahol nincs worker, ne kerüljön export a resource-ra (config).

---

## App-lista (site → repó, panel, admin-gate)

| # | site | repó (`~/Herd/…`) | panel provider(s) | admin-gate |
|---|---|---|---|---|
| 1 | cegem360.eu (publisher) | subscriber | AdminPanelServiceProvider | isAdmin() |
| 2 | crm | crm | AdminPanelServiceProvider | isAdmin() |
| 3 | sales | crm-and-contacts | AdminPanelServiceProvider | isAdmin() |
| 4 | field | worksheet | AdminPanelProvider | isAdmin() |
| 5 | seo | stat-analitics | AdminPanelServiceProvider | isAdmin() |
| 6 | controlling | controling | AdminPanelServiceProvider | isAdmin() |
| 7 | supply | Storage-cms | AdminPanelServiceProvider | `UserRole::SUPER` → adminUsing |
| 8 | mes | mes | AdminPanelServiceProvider | isAdmin() |
| 9 | workflow | workflow | AdminPanelProvider | isAdmin() |
| 10 | marketinghub | marketinghub | AdminPanelServiceProvider | isAdmin() |
| 11 | datamind | datamind | AdminPanelServiceProvider | isAdmin() |
| 12 | pirometer | pirometer | AdminPanelProvider | isAdmin() |
| 13 | quantum | hardware | AdminPanelProvider | isAdmin() |
| 14 | projects | project-manager | AppPanelProvider | super_admin → adminUsing |
| 15 | tarhely | tarhely.cegem360.eu | AdminPanelProvider | super_admin → adminUsing |
| 16 | campaigns | campaigns.cegem360.eu | AdminPanelProvider | super_admin → adminUsing |
| 17 | help | help-cegem360-eu | AdminPanelProvider | nézd meg a canAccessPanel-t |
| 18 | price-watcher | price-watcher | AdminPanelProvider | nézd meg a canAccessPanel-t |
| 19 | berodent.crm | berodent-crm | AdminPanelServiceProvider | isAdmin() |
| 20 | bujdosodent.sales | berodent-sales | AdminPanelServiceProvider | isAdmin() |
| 21 | tothpaszomany | tothpaszomany-app (ág: master) | Crm/Mes/SupplyPanelProvider | nézd meg |
| 22 | tothpaszomany.crm | tothpaszomany-crm | AdminPanelServiceProvider | isAdmin() |
| 23 | tothpaszomany.mes | tothpaszomany-mes | AdminPanelServiceProvider | super_admin → adminUsing |
| 24 | tothpaszomany.supply | tothpaszomany-supply | AdminPanelServiceProvider | isAdmin() |
| 25 | tothpaszomany.controlling | tothpaszomany-controlling (Laravel 12) | AdminPanelServiceProvider | isAdmin() |
| 26 | tothpaszomany.workflow | tothpaszomany-workflow (Laravel 12) | AdminPanelProvider | nézd meg |

Kimarad: chat, gepardfen-ujoldal, okosgyogyaszat, anest-*.

---

### Task A: Telepítés egy appba (ismételve mind a 26-ra; pilot: `crm`)

**Files (appban):** Modify: `composer.json`, `composer.lock`, `app/Providers/Filament/<Panel>.php`; Create: `config/audit-trail.php` (publish), `tests/Feature/AuditTrailInstallTest.php`

- [ ] **Step 1:** Worktree (Global Constraints), `composer install`, `.env` a fő munkakönyvtárból másolva, `npm ci && npm run build`.
- [ ] **Step 2:** `composer require madbox-99/filament-audit-trail:^0.1` (+ VCS repo bejegyzés, ha kell); `php artisan vendor:publish --tag=audit-trail-config`.
- [ ] **Step 3:** Ha létezik `audit_events` tábla/migráció az appban → STOP, jelezd (Review Focus 4).
- [ ] **Step 4:** Plugin minden panelbe: `->plugin(FilamentAuditTrailPlugin::make())`; ahol a táblában „adminUsing” áll: `->adminUsing(fn ($user) => $user->hasRole('super_admin'))` illetve Storage-cms-ben `fn ($user) => $user->role === UserRole::SUPER` (ellenőrizd a tényleges mezőt).
- [ ] **Step 5: Smoke-teszt** `tests/Feature/AuditTrailInstallTest.php` (az app saját factory-jaival):
  - `it('records a login')`: `Auth::login($user)` → `AuditEvent::where('event','login')->where('user_id',$user->id)->exists()`.
  - `it('records a panel page view')`: belépett user a panel kezdőoldalára (tenantos appnál a tenant URL-lel) → 1 `page_view`.
  - `it('shows the audit log to an admin')`: admin user `get(AuditEventResource::getUrl())` → 200.
  - `it('hides other teams events from a non-admin')` (csak tenantos appban): B-csapat eseménye nem látszik A-tag nem adminnak.
  - Több panelnél a page-view teszt panelenként.
- [ ] **Step 6:** Teljes app suite + pint + (ha van) phpstan → zöld.
- [ ] **Step 7:** `php artisan audit-trail:reconstruct --suggest` a helyi DB-n → a javasolt `actor_columns` / `sole_member_tables` közül a felmérés szerint valódi felhasználói táblák beírása a `config/audit-trail.php`-be; automatikusan gyártott táblák (pl. `work_order_templates`, alapadat-táblák) kihagyva. Publisheren `reconstruct.publisher => true`.
- [ ] **Step 8:** Commit `feat: audit trail logging (filament-audit-trail)`; merge a default ágra (fast-forward, a worktree-ből), push (hook fut) → Quick Deploy.
- [ ] **Step 9: Éles ellenőrzés:** `ssh vps-cegem360-eu` → `cd /home/forge/<site>/current && php artisan migrate:status | grep audit_events` (Ran); böngészőben/`curl` login oldal 200; egy saját belépés után `select event, count(*) from audit_events group by event` → van `login` és `page_view`.

### Task B: SSO-export a publisherről

- [ ] **Step 1:** `ssh vps-cegem360-eu 'cd /home/forge/cegem360.eu/current && php artisan audit-trail:export-sso --output=/home/forge/audit-sso-20261123.json && chmod 600 /home/forge/audit-sso-20261123.json'` (abszolút út — lásd forge-mentes-fajlnev-csapda).
- [ ] **Step 2:** Ellenőrzés: elemszám = 73 (a felmérés szerint) ± az azóta kiadott tokenek; `app_host`-ok a site-listára illeszkednek.

### Task C: Helyreállítás — dry-run minden appon, riport

- [ ] **Step 1:** Appokként: `php artisan audit-trail:reconstruct --dry-run --sso-file=/home/forge/audit-sso-20261123.json > /home/forge/audit-dryrun-<site>-20261123.txt`.
- [ ] **Step 2:** Összesítő a felhasználónak appokként: user-szám; SSO-belépéses; levezetett belépéses; csak `account_created`; top 5 user aktív napjai; elutasított nyomok okonként. Gyanús tétel (pl. egy user több száz levezetett napja egy importból, vagy egyetlen tábla adja a nyomok zömét) → config szűkítése, új dry-run.
- [ ] **Step 3:** Felhasználói jóváhagyás. **Jóváhagyás nélkül nincs Task D.**

### Task D: Helyreállítás — valódi futás

- [ ] **Step 1:** Appokként: `select count(*) from audit_events` rögzítése; `php artisan audit-trail:reconstruct --sso-file=…` ; utána `inserted` = a dry-run `accepted` száma; második futás `inserted=0`.
- [ ] **Step 2:** Admin felületen szúrópróba appokként 2 userre: a `login_inferred` sor `evidence`-e a megnevezett forrás-rekordra mutat, és az létezik.
- [ ] **Step 3:** Memória frissítése (projekt-állapot, appokénti eltérések).
