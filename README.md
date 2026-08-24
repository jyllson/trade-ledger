# TradeLedger

Personal, read-only analytics application for discovering, comparing, and
monitoring copy traders, starting with eToro. See `PROJECT.md` for the full
product specification and milestone plan.

**Status:** Milestone 1 (API spike) complete. Product Milestone 2 (discovery
and trader storage — see `PROJECT.md` §20) is **implemented, and all three
§20 acceptance criteria are now satisfied** on branch
`codex/milestone-2-discovery-and-ui` (live multi-page ranking discovery,
row-level failure persistence, trader profile lookup, candidate/watched/
ignored triage, discovery retry, and a read-only Filament UI). Two of the
three criteria are confirmed directly by a live discovery run, owner-
approved and run twice against a temporary, isolated acceptance database
(never the development `trade_ledger` database): "at least 20 real
candidates imported" (40 distinct candidates imported) and "repeated
import creates no duplicates" (an identical second run left the trader
count and distinct-identity counts unchanged). "Failed rows are visible"
remains proven deterministically OFFLINE by dedicated Pest coverage — see
`docs/REVIEW_STATUS.md`. Only opening/reviewing/merging the final pull
request to `main` remains before Milestone 2 is formally closed — see
`docs/REVIEW_STATUS.md` for the exact remaining steps. The application
contains no trading/write capability at any point.

## Security warning

**Never commit real API keys.** `ETORO_API_KEY` and `ETORO_USER_KEY` belong
only in your local `.env` file (already git-ignored) or a secret manager.
Never paste key values into source control, issue descriptions, screenshots,
commit messages, or AI prompts.

`ETORO_ALLOW_WRITE` must stay `false`. Write/trading capability is not
implemented in this application during the MVP — see `app/Etoro/EtoroWriteGuard.php`.

## Requirements

- PHP 8.3+
- Composer
- Node.js and npm
- MySQL 8.4+ (local development targets MySQL 8.4+ compatibility; see
  `docs/DECISIONS.md` D-001 for the exact locally-used version)

## Local setup

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set your local database credentials
(`DB_DATABASE=trade_ledger` by default) and your eToro credentials. Leave
`ETORO_ENABLED=false` and `ETORO_ALLOW_WRITE=false` until you are
intentionally working on the live eToro integration — a live import against
this database is a deliberate, user-approved action, not something any
command does by default.

```bash
composer install
npm install
php artisan migrate
php artisan make:filament-user
npm run build
php artisan serve
```

Visit `/admin` and sign in with the Filament user you just created.

### Read-only research UI

- `/admin/traders` — imported traders, local triage status
  (candidate/watched/ignored), and observed eToro profile fields.
- `/admin/import-runs` — audit trail for every discovery/profile-lookup run,
  with row-level failure visibility and a gated manual "Retry" action
  (only shown/allowed when the underlying run is actually eligible).
- `/admin/discover-traders` — "Run discovery" and "Lookup profile" action
  forms for live, read-only eToro requests.

Several distinct surfaces can trigger a real eToro HTTP request — none of
them by rendering a page, only by an explicit user action, and only when
`ETORO_ENABLED=true` with valid credentials configured: the two action
forms on `/admin/discover-traders`, the "Lookup profile" row action on
`/admin/traders`, the "Retry" row action on `/admin/import-runs` (when
eligible), and the `etoro:discover-traders` CLI command below.

### Offline, fixture-only ranking import (no network call)

```bash
php artisan etoro:import-ranking-page lastYear
```

Reads the single synthetic fixture at `resources/fixtures/etoro/rankings.json`
and refuses to run outside `local`/`testing` — see `docs/DECISIONS.md` D-026.

### Live, read-only, multi-page ranking discovery (real eToro GET requests)

```bash
php artisan etoro:discover-traders lastYear --max-pages=1 --start-page=1
```

Running this performs a real, live, read-only GET request when
`ETORO_ENABLED=true` and valid credentials are configured — it is not a
placeholder to fill in, it's the command as written (`lastYear` is a real
eToro ranking period; swap it for another supported period if needed). It
sends no live HTTP request when eToro is disabled/unconfigured — though it
can still record a sanitized `Failed` aggregate `ImportRun` in whichever
database is configured, for audit trail purposes, before returning a
non-zero exit code — see `docs/DECISIONS.md` D-027. The same flow is also
available as the
"Run discovery" action on `/admin/discover-traders`.

## Testing

```bash
php artisan test
vendor/bin/pint
vendor/bin/phpstan analyse
```

The default/local/CI suite runs against an isolated SQLite `:memory:`
database (forced by `phpunit.xml`), never the local `trade_ledger` MySQL
database. Tests that exercise the eToro HTTP transport use `Http::fake()`/
`Http::preventStrayRequests()`; tests unrelated to eToro don't involve HTTP
at all either way. Four dedicated tests under
`tests/Feature/Application/Imports/ImportRankingPageMySqlCollationTest.php`
only run against a separate `MYSQL_COLLATION_TEST_*` connection — without
it they are skipped, which is expected in normal local/CI runs.

## Project control docs

- `docs/WORKLOG.md` — chronological log of commands run and changes made
- `docs/DECISIONS.md` — architectural and implementation decisions
- `docs/REVIEW_STATUS.md` — current milestone status and next steps
