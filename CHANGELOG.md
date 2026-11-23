# Changelog

## v0.1.1 - 2026-11-23

- Fix: the `sso_login` rule no longer rejects genuine first SSO logins as `before_account`. The first login
  creates the local user about a second after the publisher's token, so tokens up to
  `reconstruct.sso_provisioning_grace_seconds` (new, default 120; read with that default when missing from a
  published config) before `users.created_at` are accepted. Other rules keep the strict check.

## v0.1.0 - 2026-11-23

Initial release.

- Append-only `audit_events` table and model (`source`, `inferred`, `evidence`, `evidence_key` provenance columns).
- Live recording: login, logout, failed login, page views, Filament action mounted/executed, model changes
  (created, updated, deleted, restored), Filament imports and exports. Sensitive attributes are redacted, the
  size of `properties` is capped, and a recording failure never breaks a request.
- Filament plugin with a read-only audit resource: admins (`adminUsing()`, required; fail-closed without it)
  see everything, other users see their tenant's events and the team-less sign-ins of its members (or only
  their own on a panel without a tenant); filters, export, detail view with `properties` and `evidence`.
- `audit-trail:reconstruct` (`--suggest`, `--dry-run`, `--rule`, `--since`, `--until`, `--sso-file`) rebuilds past
  events from real records, idempotently, with a per-rule report; by default it stops before the earliest live event.
- `audit-trail:export-sso` exports a publisher's OAuth sign-ins for the `sso_login` rule.
- English and Hungarian translations.
