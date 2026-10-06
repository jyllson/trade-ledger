# TradeLedger

Personal, read-only analytics application for discovering, comparing, and
monitoring copy traders, starting with eToro. See `PROJECT.md` for the full
product specification and milestone plan.

**Status:** Milestone 1 (API spike) complete. Product Milestone 2
(discovery and trader storage) is **COMPLETE** — merged via
[PR #6](https://github.com/jyllson/trade-ledger/pull/6). Product
Milestone 3 (performance analytics: monthly/daily gain series, returns,
drawdown, consistency, charts) is **COMPLETE** — merged via
[PR #8](https://github.com/jyllson/trade-ledger/pull/8). Product
Milestone 4 (live portfolio importer, instruments, portfolio snapshots,
concentration/leverage exposure and the copy amount simulator) is
**COMPLETE** on branch `codex/milestone-4-portfolio-simulator` (checkpoints
A–F, pending its PR/merge into `main`). Per-milestone evidence and the
`PROJECT.md` §20 acceptance criteria are in `docs/REVIEW_STATUS.md`. The
next product milestone per `PROJECT.md` §20 is Milestone 5 (trader
comparison). The application contains no trading/write capability at any
point.

All timestamps are stored in UTC and shown in the UI in `Europe/Malta`
with the zone abbreviation (e.g. `2026-10-06 10:30 CEST`;
`config('app.display_timezone')`, `docs/DECISIONS.md` D-046).

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
- `/admin/traders/{id}` — trader page: profile, performance (Milestone 3),
  the latest STORED portfolio snapshot (positions, concentration by
  instrument/asset class, leverage exposure) and the Livewire Copy Amount
  Simulator ($200/$500/$1,000 presets or a free amount, minimum position
  amount, optional target; per-position skip reasons; 90/95/99/100% target
  matrix; saved, reproducible simulations). Rendering never calls eToro;
  "Sync performance" and "Sync portfolio" only queue jobs. When the last
  sync found the portfolio private / not found, the last known snapshot is
  still shown — with a prominent "may be outdated" warning on the
  portfolio section and the simulator (D-045).

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

### Performance history sync (queued, real eToro GET requests)

```bash
php artisan etoro:sync-performance <username>      # queue one stored trader
php artisan etoro:sync-performance --watched       # queue every watched trader
php artisan etoro:sync-performance <username> --now  # run in this process
php artisan queue:work                             # process queued syncs
```

Each sync makes two read-only GETs — the trader's monthly and daily gain
series (`/api/v2/portfolios/{username}/gain/{monthly,daily}`) — stored in
`performance_points` and recorded as a `performance` `ImportRun`. Queued
jobs share one `etoro-api` rate limiter (`ETORO_REQUESTS_PER_MINUTE`). A
daily 03:00 UTC `--watched` sync is scheduled; it only runs if
`php artisan schedule:run` is triggered every minute (cron) and a queue
worker is running. See `docs/DECISIONS.md` D-032–D-035.

### Portfolio sync and copy simulator

```bash
php artisan etoro:sync-portfolio <username>        # queue one stored trader
php artisan etoro:sync-portfolio --watched         # queue every watched trader
php artisan etoro:sync-portfolio <username> --now  # run in this process
php artisan etoro:simulate-copy <username> 500 --target=95 --minimum-position=1
php artisan etoro:simulate-copy <username> 1000 --snapshot=<id>
```

`etoro:sync-portfolio` makes one read-only GET for the live portfolio and
stores it as a `portfolio_snapshots` row with its positions (an unchanged
portfolio only confirms the existing snapshot), plus best-effort
market-data GETs for missing instrument metadata. `etoro:simulate-copy` is
fully offline: it simulates a copy amount over a stored snapshot and saves
a reproducible `copy_simulations` row (`--target` is in percentage points).
See `docs/DECISIONS.md` D-037–D-045.

Rate limiting and timeouts: every HTTP attempt (including retries)
consumes one permit from a local limiter — `etoro-api`
(`ETORO_REQUESTS_PER_MINUTE`, default 45 of eToro's 60/min) and a separate
`etoro-market-data` budget (90/min). Without a permit the request is not
sent and nothing waits: the run fails as temporarily unavailable, a queued
job is released for a later retry and `--now` prints a warning (D-039).
Each HTTP attempt is bounded by `ETORO_TIMEOUT_SECONDS` /
`ETORO_CONNECT_TIMEOUT_SECONDS`; sync jobs have an 80 s timeout (below the
90 s queue `retry_after`), and an interrupted job's `ImportRun` is closed
as `failed` instead of staying `running` (D-040).

### Background services on macOS (launchd)

Two user LaunchAgents keep the queue worker and the scheduler running
(copies in `ops/launchd/`, paths are machine-specific):

- `com.tradeledger.queue-worker` — `php artisan queue:work --tries=1 --max-time=3600`
  (restarts hourly, so new code is picked up);
- `com.tradeledger.scheduler` — `php artisan schedule:work`.

Both use the app's normal `.env` (i.e. the development database and the
configured eToro credentials). Logs: `storage/logs/launchd-*.log`.

```bash
launchctl list | grep tradeledger                                   # status
launchctl kickstart -k gui/$(id -u)/com.tradeledger.queue-worker      # restart
launchctl bootout gui/$(id -u)/com.tradeledger.scheduler              # stop
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.tradeledger.scheduler.plist  # start
```

## Testing

```bash
php artisan test
vendor/bin/pint
composer types:check
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
