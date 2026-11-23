# filament-audit-trail — kivitelezési terv (design)

Dátum: 2026-11-23 · Csomag: `madbox-99/filament-audit-trail` · Repó: `MadBox-99/filament-audit-trail` (publikus, mint a chat-widget — a Forge nem éri el a privát repókat)

## 1. Cél és miért

Minden `*.cegem360.eu` Laravel/Filament appban rögzíteni kell, **ki, mikor, mit csinált**: belépés, kilépés,
sikertelen belépés, oldalmegtekintés, kattintás (Filament-akció), adatmódosítás. Cél: utólag bizonyítható és
visszakereshető legyen az appok használata.

A naplózás eddig nem volt bekapcsolva (csak 5 appban volt spatie activitylog, részlegesen). Ezért a múltat
**valódi nyomokból vezetjük le** (helyreállítás): ahol egy felhasználó egy adott időpontban rekordot hozott létre,
fájlt töltött fel, importált, SSO-val belépett, ott aznap be volt lépve. Minden ilyen sor jelölten
`reconstructed`, és visszavezethető a forrás-rekordra.

## 2. Célok / nem-célok / anti-célok

**Célok**
- Egy csomag, mind a ~26 éles .eu appba telepítve, élő rögzítéssel.
- Visszamenőleges helyreállítás az elérhető valódi nyomokból, appokként.
- Csak olvasható Filament felület az adminoknak (lista, szűrők, részletek, export).
- Napi használók története a valódi adatokból kirajzolódik.

**Nem-célok (v1)**
- Nyers JS-kattintáskövetés (egérmozgás, minden gombnyomás). A „kattintás" = Filament-akció megnyitása/futtatása + oldalnavigáció.
- Hash-lánc / kriptográfiai lezárás (későbbi bővítés; a tábla append-only már v1-ben).
- Központi, appokon átívelő napló-gyűjtő.
- Automatikus törlés/retention (bizonyítékot nem törlünk).

**Anti-célok (ha ez megtörténik, kudarc, akkor is, ha „működik")**
- **Kitalált bejegyzés.** Belépés csak valódi nyomból vezethető le; véletlen/gépi kitöltés nincs. Akinek nincs nyoma, annak nem lesz belépése.
- Jelöletlen helyreállított sor (`source` és `evidence` nélkül).
- Érzékeny adat a naplóban (jelszó, token, secret, 2FA, API-kulcs).
- Az élő app lassulása vagy hibája a naplózás miatt (naplózási hiba soha nem dobhat 500-at).
- A meglévő spatie `activity_log` táblák módosítása vagy törlése.

## 3. Felmérés eredménye (2026-11-23, csak olvasás)

- `vps-cegem360-eu`: 29 site, 24 Laravel-DB. Nginx access log **ki van kapcsolva**; `sessions` táblák gyakorlatilag üresek.
- Valódi nyomok: publisher (`cegem360.eu`) `oauth_access_tokens` (73 SSO belépés, appokra bontva, 2026-08-től);
  `activity_log` (crm 1619, sales 1764, tothpaszomany.crm 4794, field 56); `imports`/`exports` (`user_id`);
  AI-/agent-beszélgetések, `seo_analyses`, `documents.created_by`, `report_access_logs` stb.
- A legtöbb üzleti tábla csak `team_id`-t hordoz → **csak egyszemélyes csapatnál** köthető felhasználóhoz.
- Lefedettség appokként jellemzően 5–40% felhasználó; a többi szinkronnal létrejött fiók, nyom nélkül.
- Csapdák: 46 user `created_at`-je kitöltő érték (`2025-10-10 09:00:00`, `2025-10-01 12:00:00`); field/mes/supply
  csapat-létrehozáskor automatikus rekordokat gyárt (pl. `work_order_templates`).
- 4 app DB-jébe a felmérő nem jutott be (campaigns, help, price-watcher, tarhely) — jelszó-idézés; a helyreállítás az app saját artisan parancsán fut, így ez nem akadály.

## 4. Megközelítések

1. **Saját `audit_events` tábla + saját listenerek (VÁLASZTOTT).** Egységes séma minden appban,
   `source`/`evidence` oszlopokkal, append-only modellel, a spatie verzióktól (v4/v5 vegyesen) függetlenül.
2. **Spatie activitylog kiterjesztése mindenhol.** Kevesebb kód, de: vegyes v4/v5 az appokban, a crm configjában
   `clean_after_days=365` (bizonyíték törlődne), a séma nem ad helyet a provenance-nak, és nem append-only.
3. **Kész csomag (pl. rappasoft/laravel-authentication-log + owen-it/auditing).** Két csomag, két tábla, belépés
   és modell-audit külön; oldalmegtekintés/akció nincs; helyreállítás-jelölésre nincs hely.

A 2. és 3. azért esik ki, mert a bizonyító erő a provenance-on és a nem-törölhetőségen múlik, amit egyik sem ad.

## 5. Adatmodell — `audit_events`

| oszlop | típus | megjegyzés |
|---|---|---|
| id | bigint PK | |
| occurred_at | datetime(3), index | az esemény ideje (helyreállításnál a forrás-időbélyeg) |
| event | string(40), index | enum `AuditEvent`: `login`, `login_inferred`, `logout`, `login_failed`, `page_view`, `action_mounted`, `action_executed`, `created`, `updated`, `deleted`, `restored`, `import`, `export`, `account_created` |
| user_id | unsigned bigint null, index | nincs FK (a user törlése után is maradjon) |
| user_email | string null | pillanatkép (törölt user, sikertelen belépés email-je) |
| team_id | unsigned bigint null, index | Filament aktuális tenant, ha van |
| panel | string null | Filament panel id |
| route / url | string null / text null | |
| method | string(10) null | |
| action | string null | Filament akció neve (`edit`, `delete`, `export` …) |
| subject_type / subject_id | string null / string null, index | érintett rekord |
| properties | json null | módosítás: `{old:{}, new:{}}` (szűrt mezők); akció: argumentumok szűrve |
| ip / user_agent | string(45) null / string(512) null | |
| session_hash | char(64) null | sha256(session id) — nem visszafejthető, de csoportosít |
| source | string(20), index | `live` vagy `reconstructed` |
| inferred | bool default false | helyreállított belépésnél: az időpont az első művelet ideje, nem a valódi belépésé |
| evidence | json null | `{rule, table, id, column, note}` — a forrás-rekord |
| evidence_key | string null, **unique** | idempotencia: `rule:table:id` |
| created_at | timestamp | a sor beírásának ideje (élőnél ≈ occurred_at; helyreállításnál a futás ideje) |
| hash | char(64) null | v1-ben üres; a későbbi hash-lánchoz előre felvéve |

- A modell (`AuditEvent`) **append-only**: `updating`/`deleting` eseményben kivételt dob; nincs `updated_at`.
- Csatlakozás: a default DB-kapcsolat (konfigurálható `audit-trail.connection`).

## 6. Élő rögzítés

- **Belépés/kilépés/sikertelen:** `Illuminate\Auth\Events\Login`, `Logout`, `Failed` listenerek. `Failed`-nél csak a beírt email (jelszó soha).
- **Oldalmegtekintés:** middleware a Filament panel(ek) `middleware` láncában (a plugin regisztrálja); csak teljes oldalas GET, a Livewire `/livewire/update` kérések nem; asset/health kérések nem.
- **Kattintás (akció):** Livewire komponens-hook a `mountAction` (megnyitás) és `callMountedAction` (futtatás) hívásokra → akció neve, rekord, panel. Argumentumok szűrve, max. méret korlátozva.
- **Adatváltozás:** globális `eloquent.created/updated/deleted/restored: *` listener, **csak ha van bejelentkezett user** (webes kérés); kizárt modellek (config): `AuditEvent`, sessions, jobs, cache, notifications, `sync_logs`, oauth-táblák, telescope/pulse. Kizárt mezők: `password`, `remember_token`, `two_factor_*`, `*token*`, `*secret*`, `*api_key*`, valamint a modell `$hidden` mezői. `updated` csak a ténylegesen változott mezőket írja.
- **Import/export:** a Filament `imports`/`exports` létrejöttekor `import`/`export` esemény.
- **Hibatűrés:** minden írás `try/catch`, hiba → `report()`, a kérés megy tovább. Szinkron insert (egy sor / esemény).
- Kikapcsolható: `AUDIT_TRAIL_ENABLED`, eseménytípusonként is.
- Queue-ban futó munka (pl. Filament import sorai) nem ír modell-eseményt (nincs bejelentkezett user) — a tömeges import egy `import` sor, nem soronként egy.
- Append-only: modell-szinten; DB-trigger nincs (Forge-on nincs MySQL root). Az IP és user-agent személyes adat; jogalap: biztonsági/bizonyítási napló, automatikus törlés nincs.

## 7. Helyreállítás — `php artisan audit-trail:reconstruct`

Opciók: `--dry-run` (csak riport, írás nincs), `--rule=…` (szűrés), `--sso-file=path` (publisher-export), `--since`, `--until`.
Idempotens (`evidence_key` unique → újrafuttatás nem duplikál). Tranzakció szabályonként.

**Szabályok (rule-ok), mind valódi rekordból:**
1. `activity_log` — meglévő spatie sorok átemelése (`causer_type` = User): `created/updated/deleted` esemény az eredeti időponttal, `properties` átvéve (szűrve).
2. `imports` / `exports` — `import`/`export` esemény (`user_id`, `created_at`).
3. `actor_columns` — konfigurálható lista: tábla + user-oszlop (`user_id`, `created_by`, `uploaded_by`, `sender_id` …) → `created` esemény. Az alapértelmezés auto-felderítés információs sémából, appokként felülírható (`include`/`exclude`).
4. `sole_member_team` — `team_id`-s táblák rekordjai, ha a csapatnak a rekord létrejöttekor és most is **pontosan egy** tagja van → `created` esemény arra a tagra. Kizárva: a csapat létrehozása utáni **120 mp-en belül** létrejött rekordok (automatikus alapadat), és a konfigurált rendszertáblák.
5. `sso_login` — a publisher OAuth tokenjeiből (JSON-fájl: `user_uuid`, `app_key`, `created_at`, `token_id`) → `login` esemény **pontos időponttal**, `inferred=false`.
6. `account_created` — `users.created_at` → `account_created` (minden usernek, aki nem kitöltő időbélyeget hordoz). Receiver appokban **aktor nincs** (`user_id` = null, a fiók a `subject`), mert ott a fiókot a szinkron hozta létre.
   **Publisheren (cegem360.eu) a regisztráció belépés:** a Filament `Register` oldal a regisztráció után beléptet. Ha a usernek van `email_verified_at`-je (valódi személy kattintott), a `created_at` időpontra `login_inferred` esemény kerül (`rule=registration`), aktorként a userrel; az `email_verified_at` időpontra egy második `login_inferred` (`rule=email_verification`). Receiver appokban a fiók létrejötte nem belépés — ott a valódi belépést az SSO-tokenek (5. szabály) bizonyítják.
7. `derived_login` — felhasználónként és naponként, ha aznap van ≥1 művelet-nyom (1–4. szabály) és nincs `sso_login`: egy **`login_inferred`** esemény (külön eseménytípus, felületen „Belépés (levezetett)”) a nap legkorábbi nyomának időpontjával, `inferred=true`, `evidence` = az a nyom. Így exportban és szűrésben sem keverhető a valódi `login`-nal.

**Kizárások minden szabályra:**
- Kitöltő időbélyegek (config `ignored_timestamps`, alapból a két ismert érték).
- Nyom, ami a user `created_at`-je előtt van (ha az nem kitöltő érték).
- Seed/teszt fiókok (config `excluded_emails` minta, alapból `@example.*`).

**Kimenet:** riport appokként: user-szám, ebből van belépés (SSO / levezetett), csak `account_created`, napok száma a top 5 usernél.

**Pontosítások (builder-check után, saját döntés):**
- `sole_member_team`: a csapatnak **most** pontosan egy tagja van, és ha a `team_user`-nek van `created_at`-je, az ≤ a rekord ideje. A korábban kilépett tagokat nem látjuk — ez ismert korlát, a `evidence.note` rögzíti.
- Az `actor_columns` és `sole_member_team` szabályok **appokénti allowlisttel** futnak élesben: az auto-felderítés csak javaslatot ad a dry-run riportban, a végleges táblalista az app `config/audit-trail.php`-jében van. A 120 mp-es csapat-létrehozás utáni kizárás az `actor_columns` szabályra is vonatkozik.
- `sso_login` user-párosítás: `users.uuid` (sync ≥ v1.11), ha nincs, email. Az `app_key` ↔ app leképezést az export parancs az OAuth kliens `redirect` hostjából adja, az import a saját `APP_URL` hostjára szűr.
- Egy guard (`web`, konfigurálható), egész számú user-kulcs; más guardot nem naplózunk v1-ben.

## 8. Filament felület

- `FilamentAuditTrailPlugin::make()` a panel providerben → `AuditEventResource` (csak lista + megtekintés; nincs create/edit/delete).
- **Láthatóság (felhasználói döntés, 2026-11-23):** minden panel-felhasználó látja a menüpontot.
  - **Admin** (alapból `$user->isAdmin()`, appokként felülírható `->adminUsing(fn ($user) => …)`): minden esemény, minden csapat; szűrhető csapatra.
  - **Nem admin, tenantos panel:** az aktuális tenant eseményei — `team_id = tenant`, **vagy** `team_id IS NULL` és a `user_id` a tenant jelenlegi tagja (így a belépések is látszanak, amik tenant nélkül keletkeznek).
  - **Nem admin, tenant nélküli panel:** csak a saját eseményei (`user_id = auth`).
  - A szűkítés a resource `getEloquentQuery()`-jében és a view oldal rekord-feloldásában is érvényes (más csapat eseménye URL-ből sem nyitható: 404).
  - A resource `isScopedToTenant = false` (a szűkítést a fenti saját logika végzi, mert a NULL-team belépéseket is mutatni kell).
- Szűrők: user, esemény, forrás (élő/helyreállított), dátum-tartomány, csapat. Oszlopok: idő, user, esemény, tárgy, akció/URL, IP, forrás-jelvény. Részlet nézet: `properties` és `evidence` JSON.
- `ExportAction` a listán (a 2026-11-04-i export-minta szerint).
- Felhasználó-oldali widget/reláció: v1-ben nem.
- Fordítás: magyar + angol.

## 9. Telepítés és kiadás

- Csomag: Laravel `^12 || ^13`, Filament `^5`, PHP `^8.3`. Pest + Testbench, PHPStan, Pint, CI. Tag: `v0.1.0`.
- Appok (site → repó): cegem360.eu→subscriber, crm, sales→crm-and-contacts, field→worksheet, seo→stat-analitics, controlling→controling, supply→Storage-cms, mes, workflow, marketinghub, datamind, pirometer, quantum→hardware, projects→project-manager, tarhely, campaigns, help, price-watcher, berodent.crm→berodent-crm, bujdosodent.sales→berodent-sales, tothpaszomany→tothpaszomany-app, tothpaszomany.{crm,mes,supply,controlling,workflow}.
  Kimarad: chat (0 user, nem Filament), gepardfen-ujoldal, okosgyogyaszat (nincs DB), anest-* (nem .eu, site-vps).
- Appokként: **külön worktree** (`~/Herd/_worktrees/<app>`, ág `feature/audit-trail`) — sok app munkakönyvtára piszkos vagy feature-ágon áll, azokhoz nem nyúlunk. `composer require`, plugin a panel(ek)be, migráció, app-specifikus config (helyreállítási include/exclude), egy smoke-teszt (belépés → `login` sor; oldal → `page_view`). A pre-push hook fut, **nem skippeljük**; régi hibát javítunk.
- Éles: push → Quick Deploy (migrate a deploy scriptben) → `audit-trail:reconstruct --dry-run` élesben → riport a felhasználónak → jóváhagyás után valódi futás. Publisheren előbb `audit-trail:export-sso` készíti a JSON-t (ugyanez a csomag adja).
- Mentés a valódi futás előtt nem kell (csak új táblába ír), de a futás előtti `audit_events` darabszám rögzítendő.

## 10. Döntések, amik a builderre maradnak

- A Livewire hook pontos API-ja (Livewire 3/4 `on('call')` vs. Filament akció-életciklus esemény) — ami mindkét akció-típust (oldal-, tábla-, rekord-akció) megbízhatóan elkapja.
- Az auto-felderített actor-oszlopok appokénti finomhangolása a dry-run riport alapján.
- Teljesítmény: indexek, `properties` méretkorlát (javaslat 16 KB).
