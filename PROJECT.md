# TradeLedger — PROJECT.md

**Status:** Draft v0.1<br>
**Last verified:** 2026-08-24<br>
**Primary owner:** Slavko<br>
**Working repository name:** `trade-ledger`

This document is the product spec and does not track day-to-day
implementation state. For current implementation status, what is
actually built vs. still pending, and exact verification commands/results,
see `docs/REVIEW_STATUS.md` (operational snapshot) and `docs/WORKLOG.md`
(chronological history). §9 below is kept updated as a structural summary
only.

---

## 1. Project summary

TradeLedger is a **personal, read-only analytics application** for discovering, comparing, and monitoring copy traders, starting with eToro.

The first practical question the application must answer is:

> **What is the smallest sensible amount to allocate to a specific trader, given eToro's platform constraints and the structure of that trader's portfolio?**

The second question is:

> **How does that trader compare with other candidates when return, risk, consistency, concentration, and copy fidelity are considered separately?**

The application must not initially execute trades, start or stop copying, deposit, withdraw, or perform any money-moving action.

---

## 2. Product principles

1. **Read-only by design.**
   - No trading execution in the MVP.
   - No generic HTTP method exposed outside the eToro infrastructure layer.
   - Write endpoints must be blocked by configuration and code.

2. **Transparent calculations.**
   - Every metric must have a documented formula.
   - Avoid unexplained “AI score” or opaque overall ranking.
   - The user must be able to see why one trader ranks above another.

3. **Actual data before architecture theatre.**
   - First prove which eToro endpoints are accessible with the generated key.
   - Save sanitized response fixtures.
   - Model the database from observed payloads, not assumptions.

4. **Single-user first.**
   - No SaaS multi-tenancy, subscriptions, billing, teams, or public signup in the MVP.
   - The architecture should not intentionally prevent future multi-user support, but must not optimize for it now.

5. **Measure data quality.**
   - Missing, private, stale, or incomplete trader data must be clearly marked.
   - A trader with incomplete data must not silently receive a normal score.

6. **Never treat historical performance as a promise.**
   - The UI must present this as analytics, not financial advice.

---

## 3. Verified platform constraints

At the time this document was written:

- Minimum eToro copy allocation per trader: **$200**.
- Minimum copied position: **$1**.
- A proportional copied position below $1 may not be opened.
- The eToro Public API base URL is:

```text
https://public-api.etoro.com/api/v1
```

Some endpoints use `/api/v2`.

Every request uses:

```text
x-request-id: unique UUID
x-api-key: public API key
x-user-key: user-specific key
```

Some endpoint documentation also displays an OAuth bearer token. Bearer authentication is **not implemented** and no `Authorization: Bearer` header is ever sent. Standard authentication uses exclusively `x-api-key`, `x-user-key`, and `x-request-id`. Bearer support may be added later only if the actual official OpenAPI document explicitly shows that a specific endpoint requires it.

### Credential warning

The API setup appears to involve **two credentials**, not one:

```env
ETORO_API_KEY=
ETORO_USER_KEY=
```

If only one value was copied from eToro, the connection doctor must report exactly which credential is missing. Never paste either key into `PROJECT.md`, source control, issue descriptions, screenshots, or AI prompts.

### Key management UI (verified 2026-07-30)

The current eToro API Key Management UI **deviates from the public documentation**, which still describes a separate Demo/Real environment choice when generating a key. Observed behavior:

- There is no Demo/Real environment selector. A key is created for a chosen **Account** (here: "Main Account").
- Permissions are granted individually. The key in use has **16 read permissions** selected.
- "Trading – Real · Read" is enabled. "Trading – Real · Write" is **not** enabled and must stay disabled.
- Credential mapping:
  - eToro Public Key → `ETORO_API_KEY` → `x-api-key` header;
  - eToro generated Private Key → `ETORO_USER_KEY` → `x-user-key` header;
  - no bearer token variable exists and no `Authorization: Bearer` header is sent.
- `ETORO_ENVIRONMENT` is therefore **not** a property or restriction of the key itself. It only selects which account endpoint the application calls:
  - `real` → `/api/v1/trading/info/real/...`
  - `demo` → `/api/v1/trading/info/demo/...`

  Locally it is currently set to `real`.

---

## 4. Technology stack

Use the current stable stack as of project creation:

- PHP 8.3+
- Laravel 13
- Filament 5
- Livewire 4
- MySQL 8
- Pest
- Laravel HTTP Client
- Laravel Scheduler
- Database queue for the MVP
- Redis/Horizon only when queue volume justifies them

### Recommended development tooling

```bash
composer require laravel/boost --dev
php artisan boost:install
```

When Boost asks which guidelines and skills to install, select Laravel, Filament, Livewire, and Pest.

The eToro Public API also exposes an official MCP server for Claude Code:

```bash
claude mcp add --transport http etoro-public-api https://mcp.public-api.etoro.com
claude mcp list
```

MCP is optional. It may be used to inspect the live OpenAPI definition, but credentials must remain in local secure configuration. The coding agent must never be granted permission to perform money-moving operations.

---

## 5. Environment configuration

Create `config/etoro.php`.

Example `.env.example` entries:

```env
ETORO_ENABLED=true
ETORO_BASE_URL=https://public-api.etoro.com
ETORO_API_KEY=
ETORO_USER_KEY=
ETORO_ENVIRONMENT=demo
ETORO_ALLOW_WRITE=false
ETORO_REQUESTS_PER_MINUTE=45
ETORO_TIMEOUT_SECONDS=20
ETORO_CONNECT_TIMEOUT_SECONDS=5
ETORO_STORE_RAW_RESPONSES=false
ETORO_RAW_RESPONSE_RETENTION_DAYS=90
```

Rules:

- `ETORO_ALLOW_WRITE` defaults to `false`.
- Production must refuse to boot any trading service if this flag is not false during the MVP.
- Keys are not generated per environment; permissions are granted individually in the key management UI, and the key must remain read-only ("Trading – Real · Write" disabled).
- `ETORO_ENVIRONMENT` only selects the account endpoint (`real`/`demo` trading info paths); it does not describe or restrict the key.
- Secrets stay in `.env` or a secret manager.
- Secrets must be redacted from logs and exception context.

---

## 6. Initial scope

### 6.1 In scope

- Verify eToro API connectivity and permissions.
- Discover ranked/public investors.
- Search for a trader by username.
- Import trader profile and summary data.
- Import historical performance.
- Import live portfolio composition when visible.
- Import asset allocation, exposure, copier statistics, and trade information when available.
- Store timestamped snapshots.
- Calculate transparent risk and consistency metrics.
- Simulate copy fidelity for arbitrary allocation amounts.
- Calculate the minimum amount needed for configurable portfolio coverage targets.
- Compare selected traders in Filament.
- Track the user's own Demo or Real account in read-only mode after public-trader analysis works.

### 6.2 Explicitly out of scope for MVP

- Placing, editing, or closing orders.
- Starting, changing, or stopping a copy relationship.
- Deposits, withdrawals, transfers, or wallet actions.
- Automated portfolio rebalancing.
- XTrade email import.
- Playwright/Selenium scraping.
- Mobile application.
- Public signup or SaaS billing.
- Tax reporting.
- AI-generated trade recommendations.
- Notifications telling the user to buy or sell.

---

## 7. Key user flows

### Flow A — API connection check

1. User configures local environment variables.
2. User runs:

```bash
php artisan etoro:doctor
```

3. The command validates:
   - required environment variables;
   - UUID request generation;
   - authentication;
   - granted scopes if returned;
   - Demo/Real environment;
   - API latency;
   - rate-limit headers if present.

4. Output must never display credentials.

Expected example:

```text
eToro configuration .............. OK
API key .......................... present
User key ......................... present
Write operations ................. BLOCKED
Authentication ................... OK
Authenticated username ........... slavko...
Demo account access .............. OK
Real account access .............. not tested
Average latency .................. 242 ms
```

### Flow B — Discover traders

1. Open the Filament `Discover Traders` page.
2. Choose a ranking preset or filters.
3. Import one or more pages of candidates.
4. Store ranking rows and basic profile data.
5. Mark imported traders as:
   - candidate;
   - watched;
   - ignored.

### Flow C — Sync a trader

1. Open a trader.
2. Click `Sync now`.
3. Queue a sync job.
4. Fetch all permitted datasets.
5. Store raw payloads and normalized records transactionally.
6. Recalculate analytics.
7. Show freshness and completeness.

### Flow D — Simulate a copy amount

1. Open a trader.
2. Enter an amount, for example `$200`, `$500`, or `$1,000`.
3. The Livewire widget calculates:
   - eligible positions;
   - skipped positions;
   - copied portfolio weight;
   - skipped portfolio weight;
   - estimated cash/unallocated weight;
   - minimum amount for 90%, 95%, 99%, and 100% coverage;
   - warnings caused by missing or stale portfolio data.

4. The widget explains every skipped position.

### Flow E — Compare candidates

1. Select between 2 and 10 traders.
2. Open the comparison page.
3. Compare independent dimensions:
   - performance;
   - drawdown;
   - consistency;
   - concentration;
   - leverage/exposure;
   - copyability at the user's budget;
   - data quality.

No single overall winner is required in the MVP.

---

## 8. eToro API capability map

These are candidate endpoints verified in current official documentation. Before implementation, use the official MCP/OpenAPI definition to confirm query parameters and response schemas.

### Authentication and identity

```text
GET /api/v1/me
```

Use for the authenticated profile and account identifiers.

### Own account P&L

```text
GET /api/v1/trading/info/demo/pnl
GET /api/v1/trading/info/real/pnl
```

During Milestone 1, probe the availability of **both** P&L endpoints with read-only GET requests and record the actual result for each in the capability report:

```text
real: available / forbidden / unavailable
demo: available / forbidden / unavailable
```

Do not assume availability from documentation or from how the key was generated — use only actual GET results.

### Trader discovery and rankings

```text
GET /api/v2/portfolios/rankings
```

Also inspect ranking presets, tags, single-investor rows, and bulk ranking endpoints.

### Public user information

```text
GET /api/v1/user-info/people
GET /api/v1/user-info/people/{username}/gain
GET /api/v1/user-info/people/{username}/portfolio/live
GET /api/v1/user-info/people/{username}/tradeinfo
```

The profile endpoint may accept query parameters or batches. Confirm from the live OpenAPI specification.

### User statistics

```text
GET /api/v2/portfolios/{username}/assets/history
GET /api/v2/portfolios/{username}/exposure/history
GET /api/v2/portfolios/{username}/copiers
```

Also inspect:

- investor gain time-series;
- daily performance;
- portfolio search;
- user search.

### Privacy and visibility

Some public portfolio endpoints may return `403` when a trader has opted out or data is not visible. This is a normal data state, not necessarily an application error.

Store visibility as one of:

```text
available
partial
private
forbidden
not_found
rate_limited
temporarily_unavailable
```

---

## 9. Suggested application structure

Do not implement a heavy modular package architecture in the MVP.

```text
app/
├── Analytics/
│   ├── Calculators/
│   │   ├── CopyCoverageCalculator.php
│   │   ├── DrawdownCalculator.php
│   │   ├── PerformanceCalculator.php
│   │   ├── ConcentrationCalculator.php
│   │   └── DataQualityCalculator.php
│   ├── Data/
│   └── ValueObjects/
├── Etoro/
│   ├── EtoroClient.php
│   ├── EtoroRequest.php
│   ├── Endpoint.php
│   ├── Data/
│   ├── Exceptions/
│   └── Mappers/
├── Actions/
│   ├── Traders/
│   └── Imports/
├── Jobs/
│   ├── SyncTrader.php
│   ├── SyncWatchedTraders.php
│   └── SyncOwnAccount.php
├── Models/
├── Filament/
│   ├── Resources/
│   ├── Pages/
│   └── Widgets/
└── Console/Commands/
    ├── EtoroDoctor.php
    ├── DiscoverEtoroTraders.php
    └── SyncEtoroTrader.php
```

### Architectural rules

- API DTOs are not Eloquent models.
- API mappers normalize external payloads before persistence.
- Calculators accept plain DTOs/value objects and must be unit-testable without Laravel or the database.
- Filament resources must not contain business calculations.
- Jobs orchestrate; Actions perform use cases; Calculators calculate.
- Store all monetary values as decimal strings or integer minor units where practical. Never use binary floating point for persisted money.
- Percentages are normalized internally to decimal fractions:
  - `0.25` means 25%.
  - Conversion to/from API and display formats happens at boundaries.
- Store timestamps in UTC.
- Display timestamps in `Europe/Malta`.

### Current implementation status (updated 2026-08-24)

The tree above is the target shape and does not yet exist in full. What is
actually implemented today, in addition to the eToro domain-model layer
(mappers, adapter, calculator — see `docs/DECISIONS.md` D-018/D-019), is a
thin application orchestration layer and its console entry points:

```text
app/
├── Application/
│   └── Etoro/
│       ├── EvaluateTraderCopyCoverage.php
│       ├── EvaluateTraderCopyCoverageResult.php
│       ├── FindTraderMinimumCopyAmountForCoverage.php
│       └── FindTraderMinimumCopyAmountForCoverageResult.php
└── Console/Commands/
    ├── EtoroDoctorCommand.php
    ├── EtoroCopyCoverageCommand.php
    └── EtoroCopyTargetCommand.php
```

Dependency direction (see `docs/DECISIONS.md` D-020):

```text
Console
  ↓
Application
 ↙       ↘
Etoro   Analytics
```

Within `App\Application\Etoro\EvaluateTraderCopyCoverage` and
`App\Application\Etoro\FindTraderMinimumCopyAmountForCoverage`, the existing
eToro domain-model pipeline is unchanged:

```text
EtoroClient::userLivePortfolio()
  → LivePortfolioMapper
  → LivePortfolioCoverageAdapter
  → CopyCoverageCalculator
```

`FindTraderMinimumCopyAmountForCoverage` additionally accepts a target
coverage percentage, a minimum position amount, and a platform minimum copy
amount, and calls `CopyCoverageCalculator::minimumAmountForCoverage()`
instead of `evaluate()`. Target coverage is relative to the snapshot's
positive observed weight, not to the nominal 100% total — see
`docs/DECISIONS.md` D-022. Its console entry point, `etoro:copy-target`,
takes the target as a human-facing percentage-points decimal string, parsed
exactly (no float) — see `docs/DECISIONS.md` D-023.

Neither this stream nor the earlier application-orchestration stream added
TradeLedger persistence, application-specific migrations/Eloquent models,
new Filament application resources/pages, jobs, scheduling, or web/API
routes. The repository's existing framework/default scaffold (e.g. the base
`User` model/migrations and the base Filament panel provider) predates both
streams and is unrelated to them.

That gap has since been partially closed by a separate implementation
stream, `feature/trader-ranking-import` (Checkpoint A–C; branched from
`144f1da`, which was `main`'s tip at the time — the target-coverage
stream's own merge result; the earlier application-orchestration and
domain-model streams merged as their own separate commits, `b2e1c3d` and
`5a36aff` respectively). Checkpoint A
(`f22bde8`) added the first application-specific persistence:
`app/Models/{Trader,TraderStatus,ImportRun,ImportRunStatus}.php`, migrations
`2026_08_10_080517_create_traders_table.php` and
`2026_08_10_080518_create_import_runs_table.php`, and matching factories.
Checkpoint B (`c6c7580`) added `App\Application\Imports\ImportRankingPage`,
an idempotent persistence use case that upserts an already-mapped
`RankingPage` into `traders`/`import_runs` (identity/collation/transaction
contract — `docs/DECISIONS.md` D-025). Checkpoint C (`d739e4c`) added a
manual, offline, fixture-only CLI, `php artisan etoro:import-ranking-page
{period}` (`App\Console\Commands\EtoroImportRankingPageCommand`), which
reads the single canonical synthetic fixture at
`resources/fixtures/etoro/rankings.json` through
`App\Etoro\FixtureSources\RankingFixtureSource` →
`App\Etoro\Mappers\RankingsMapper` →
`App\Application\Imports\ImportRankingPage`, hard-codes `page=1`/`pageSize=3`
(the fixture's only real content), and refuses to run outside
`local`/`testing` (`docs/DECISIONS.md` D-026). This stream does not add a
general `EtoroClient`-based live rankings import/orchestration path — the
CLI never performs a network request. Dependency direction remains layered:
Console → Application → Etoro/persistence, mirroring the existing
Console → Application → Etoro/Analytics direction documented above.

That `feature/trader-ranking-import` stream (Checkpoint A–D) was reviewed
and merged into `main` as PR #5 (commit `cf5ac83`). A further
implementation stream, branch `codex/milestone-2-discovery-and-ui`
(branched from `cf5ac83`; Checkpoint E–I; see `docs/REVIEW_STATUS.md` and
`docs/WORKLOG.md` for full detail), closed the remaining implementation
gaps: live, multi-page ranking discovery
(`App\Application\Imports\DiscoverEtoroTraders`, `php artisan
etoro:discover-traders {period}`, `docs/DECISIONS.md` D-027); row-level
failure persistence (`ImportRunFailure`, D-028); trader profile search and
identity-matched enrichment (`App\Application\Traders\{TraderUsername,
FindStoredTraderByUsername, LookupEtoroTraderProfile}`, D-029); a
candidate/watched/ignored triage use case and a sanitized, fail-closed
discovery retry use case (`ChangeTraderStatus`,
`RetryEtoroTraderDiscovery`, D-030); and a read-only Filament UI
(`TraderResource` at `/admin/traders`, `ImportRunResource` at
`/admin/import-runs`, and the `DiscoverTraders` page at
`/admin/discover-traders`, D-031).

**Product Milestone 2 (§20) implementation is complete, and all three §20
acceptance criteria are now satisfied** (Checkpoint J, 2026-08-24; see
`docs/REVIEW_STATUS.md` and `docs/WORKLOG.md` for full detail) — two of the
three are confirmed directly by live runs, the third remains proven
deterministically offline. A project owner explicitly approved a live,
read-only `etoro:discover-traders` call, which was executed twice against
a temporary, isolated SQLite acceptance database created solely for this
purpose — never the development `trade_ledger` database. The first run
imported 40 distinct real candidates (well above the "at least 20" bar),
satisfying the candidate-count criterion. The second, identical run
against the same database left the trader count and distinct-identity
counts unchanged, confirming "repeated import creates no duplicates" under
a real live call, in addition to the offline idempotent-rerun tests that
already proved this deterministically. "Failed rows are visible" remains
proven deterministically OFFLINE by the same dedicated Pest coverage as
before (row-level-failure/Partial-status tests and `ImportRunResource` UI
visibility tests) — the live acceptance run did not itself produce a
failed row, and none was deliberately induced, consistent with the
acceptance requirement.

Milestone 2 is therefore functionally accepted. The only remaining step is
integration: opening, reviewing, and merging the final pull request for
`codex/milestone-2-discovery-and-ui` → `main`. See `docs/REVIEW_STATUS.md`
for the exact remaining steps and the current PR/merge status.

The target-coverage stream described above completes, via a one-off CLI
call against the live API, only the calculation portion of one Milestone 4
(§20) deliverable — "minimum
amount for coverage targets." Milestone 4 as defined in §20 is not
complete: its remaining deliverables are a persistence-backed live
portfolio importer, `instruments`/`portfolio_snapshots` storage,
concentration metrics, and a copy fidelity simulator meeting the §20
acceptance criteria (each skipped position explained; $200/$500/$1,000
presets; 90/95/99/100% target calculations; results reproducible from a
stored snapshot) — none of which this stream adds. §20 does not name a
specific UI technology for the simulator deliverable, so this gap should
not be described as "missing Filament/Livewire" specifically; only that no
persistence-backed, preset-driven, reproducible simulator exists yet.

Other roadmap capabilities that also remain unimplemented belong to
different milestones, not Milestone 4: profile/performance analytics
(Milestone 3), multi-trader comparison (Milestone 5), and
scheduled/queued collection (§16, cross-cutting infrastructure for
Milestones 2 and 6). Write eToro operations are not an unfinished roadmap
item at all — they are intentionally prohibited by the project's
read-only-by-design policy (§2, §6.2, §17), not a backlog capability
awaiting implementation.

---

## 10. HTTP client requirements

`EtoroClient` must:

- use Laravel's HTTP client;
- add a new UUID to every request;
- always add `x-api-key` and `x-user-key`;
- never send an `Authorization: Bearer` header (bearer auth is not implemented; it may be added only if the official OpenAPI document explicitly requires it for an endpoint);
- use timeouts from config;
- retry transient failures;
- respect `Retry-After` on `429`;
- use exponential backoff with jitter;
- cap normal traffic below the platform limit;
- redact all credentials from logs;
- expose typed read methods rather than a public generic request method.

Allowed public methods may initially include:

```php
authenticatedUser(): AuthenticatedUserData
rankings(RankingQuery $query): RankingPageData
userProfile(string $username): TraderProfileData
userPerformance(string $username): PerformanceHistoryData
userLivePortfolio(string $username): LivePortfolioData
userTradeInfo(string $username): TradeInfoData
assetAllocationHistory(string $username, Period $period): AssetAllocationHistoryData
marketExposureHistory(string $username, Period $period): ExposureHistoryData
copierStats(string $username): CopierStatsData
accountPnl(EtoroEnvironment $environment): AccountPnlData
```

There must be no `post()`, `delete()`, `executeOrder()`, or `startCopying()` method in the MVP.

### Response handling

For every call, capture:

- request UUID;
- endpoint key;
- started and completed timestamps;
- HTTP status;
- duration;
- retry count;
- rate-limit headers when present;
- sanitized request parameters;
- sanitized raw response when enabled;
- normalized import outcome.

Never store request headers containing secrets.

---

## 11. Database design

The final schema may change after real payloads are inspected. Start with the following.

### `traders`

```text
id
username                  unique
display_name              nullable
avatar_url                nullable
country_code              nullable
is_popular_investor       nullable boolean
visibility_status
status                    candidate|watched|ignored
first_seen_at
last_seen_at
last_synced_at             nullable
raw_profile                nullable json
created_at
updated_at
```

### `trader_snapshots`

One summary row per trader per capture time.

```text
id
trader_id
captured_at
risk_score                 nullable decimal
copiers_count              nullable unsigned integer
aum_range                  nullable string
total_gain                 nullable decimal
gain_12m                   nullable decimal
max_drawdown               nullable decimal
profitable_months_ratio    nullable decimal
trades_count               nullable unsigned integer
data_completeness          decimal default 0
source_hash                string
raw_payload                nullable json
created_at
updated_at
```

Index:

```text
(trader_id, captured_at)
unique(trader_id, source_hash)
```

Do not force fields that the API does not actually provide. Remove or rename speculative columns after the API spike.

### `performance_points`

```text
id
trader_id
granularity                daily|monthly|yearly
period_start               date
gain                       decimal
source                     string
created_at
updated_at
```

Unique:

```text
(trader_id, granularity, period_start, source)
```

### `portfolio_snapshots`

```text
id
trader_id
captured_at
cash_weight                nullable decimal
invested_weight            nullable decimal
source_hash
raw_payload                nullable json
created_at
updated_at
```

### `portfolio_positions`

```text
id
portfolio_snapshot_id
external_position_id       nullable string
instrument_id              nullable
external_instrument_id     string
opened_at                  nullable
is_buy                     nullable boolean
leverage                   nullable decimal
investment_weight          nullable decimal
open_rate                  nullable decimal
net_profit                 nullable decimal
stop_loss_rate             nullable decimal
take_profit_rate           nullable decimal
raw_payload                nullable json
created_at
updated_at
```

### `instruments`

```text
id
external_instrument_id     unique
symbol                     nullable
name                       nullable
asset_class                nullable
exchange                   nullable
raw_payload                nullable json
created_at
updated_at
```

### `copy_simulations`

```text
id
trader_id
portfolio_snapshot_id
analysis_profile_id        nullable
copy_amount                decimal
minimum_position_amount    decimal default 1
eligible_positions_count   unsigned integer
skipped_positions_count    unsigned integer
eligible_weight            decimal
skipped_weight             decimal
cash_weight                nullable decimal
target_coverage            nullable decimal
minimum_target_amount      nullable decimal
methodology_version        string
result                     json
calculated_at
created_at
updated_at
```

### `analysis_profiles`

Stores the user's configurable restrictions.

```text
id
name
budget                     decimal
target_coverage            decimal default 0.95
maximum_drawdown           nullable decimal
maximum_risk_score         nullable decimal
maximum_single_position    nullable decimal
minimum_history_months     nullable unsigned integer
minimum_positive_months    nullable decimal
maximum_allocation_per_trader nullable decimal
is_default                 boolean
created_at
updated_at
```

### `import_runs`

```text
id
source                     etoro
type                       rankings|profile|performance|portfolio|account
subject                    nullable string
status                     pending|running|completed|partial|failed
request_count              unsigned integer default 0
success_count              unsigned integer default 0
failure_count              unsigned integer default 0
started_at
finished_at                nullable
error_summary              nullable text
metadata                   nullable json
created_at
updated_at
```

### `api_responses`

```text
id
import_run_id               nullable
request_id                  uuid
endpoint_key
http_method
http_status                 nullable unsigned small integer
duration_ms                 nullable unsigned integer
attempt                     unsigned small integer default 1
requested_at
responded_at                nullable
parameters                  nullable json
response_body               nullable json
response_hash               nullable string
error_type                  nullable string
error_message               nullable text
created_at
updated_at
```

Retention may later prune raw payloads after normalized records are stable.

---

## 12. Core calculation: copy fidelity

### 12.1 Definitions

For each current portfolio position:

```text
wᵢ = normalized position weight as a decimal fraction
A  = proposed copy amount in USD
M  = minimum copied position amount, initially $1
```

Estimated proportional copied amount:

```text
position_amountᵢ = A × wᵢ
```

Position eligibility:

```text
eligibleᵢ = position_amountᵢ >= M
```

Portfolio coverage:

```text
coverage(A) = Σ wᵢ for eligible positions
```

Skipped weight:

```text
skipped_weight(A) = Σ wᵢ for ineligible positions
```

Position-count coverage:

```text
position_coverage(A) = eligible_position_count / total_position_count
```

The primary metric is **capital-weight coverage**, not position-count coverage.

### 12.2 Minimum amount for a target coverage

Given a target `T`, for example `0.95`:

1. Normalize all positive position weights.
2. Generate candidate amounts:

```text
candidateᵢ = ceil(M / wᵢ)
```

3. Add the platform minimum copy amount of `$200`.
4. Evaluate coverage at each unique candidate.
5. Return the smallest amount satisfying:

```text
coverage(A) >= T
```

6. If current data is incomplete, return a warning and mark the result as an estimate.

### 12.3 Minimum amount for all visible positions

```text
max(
    platform_minimum_copy_amount,
    ceil(M / smallest_positive_position_weight)
)
```

This value is informational and may be economically silly when a portfolio contains tiny positions. The application should emphasize 95% and 99% coverage before 100%.

### 12.4 Weight normalization

Preferred source:

```text
investmentPct
```

Normalize the API representation into a decimal fraction.

Fallback:

```text
position invested amount / total visible invested amount
```

Do not combine values until the API payload's units are confirmed by fixtures.

### 12.5 Cash

Cash is not an “ineligible position.”

Report it separately:

```text
visible_position_weight
cash_weight
unknown_weight
```

The calculation must not normalize away a real cash allocation unless explicitly displaying an “invested-only” view.

---

## 13. Performance and risk calculations

All formulas must have unit tests.

### 13.1 Compounded return

For periodic returns `rₜ` represented as decimal fractions:

```text
cumulative_return = Π(1 + rₜ) - 1
```

Never sum long sequences of percentage returns.

### 13.2 Equity index

Start at 1:

```text
equity₀ = 1
equityₜ = equityₜ₋₁ × (1 + rₜ)
```

### 13.3 Maximum drawdown

```text
peakₜ = max(equity₀ ... equityₜ)
drawdownₜ = equityₜ / peakₜ - 1
max_drawdown = abs(min(drawdownₜ))
```

Label the granularity:

- daily maximum drawdown;
- monthly maximum drawdown.

Do not present monthly-derived drawdown as equivalent to intraday platform drawdown.

### 13.4 Positive-month ratio

```text
number of months with gain > 0 / number of observed months
```

Also show:

- negative months;
- flat months;
- longest positive streak;
- longest negative streak.

### 13.5 Volatility

Use sample standard deviation of periodic returns. Always label the period and whether annualized.

### 13.6 Concentration

For portfolio weights:

```text
HHI = Σ(wᵢ²)
effective_positions = 1 / HHI
largest_position = max(wᵢ)
top_3_weight = sum of three largest wᵢ
```

Calculate by:

- individual instrument;
- asset class;
- sector when data is available.

### 13.7 Leverage exposure

When leverage is available:

```text
weighted_leverage = Σ(wᵢ × leverageᵢ)
```

Also show:

- leveraged portfolio weight;
- maximum leverage;
- number of leveraged positions.

### 13.8 Data completeness

Create a versioned, transparent formula based on the availability and freshness of:

- profile;
- at least 24 monthly performance points;
- daily data;
- live portfolio;
- asset history;
- exposure history;
- trade info;
- copier history.

Do not compare two traders as equals when one has materially less data.

---

## 14. Comparison dimensions

The MVP must present separate dimensions.

### Performance

- cumulative return;
- trailing 12-month return;
- trailing 24-month return;
- average monthly return;
- median monthly return;
- profitable-month ratio.

### Risk

- daily/monthly max drawdown;
- volatility;
- risk score when provided;
- largest position;
- top-three concentration;
- weighted leverage.

### Consistency

- positive-month ratio;
- longest losing streak;
- dispersion of monthly returns;
- dependency on the best month;
- return excluding the best month;
- return excluding the best three months.

### Copyability

- coverage at $200;
- coverage at $500;
- coverage at $1,000;
- minimum amount for 90%;
- minimum amount for 95%;
- minimum amount for 99%;
- minimum amount for all visible positions;
- number and weight of skipped positions.

### Operational/data quality

- last successful sync;
- source visibility;
- stale-data warning;
- completeness score;
- failed endpoint count.

### No overall score in the first release

A composite score may be added later only if:

- all component weights are visible;
- the methodology is versioned;
- users can change weights;
- the raw dimensions remain visible.

---

## 15. Filament application design

### Navigation

```text
Dashboard
Discover Traders
Watched Traders
Compare
Own Account
Imports
Settings
```

`Own Account` may remain hidden until its milestone is implemented.

### `TraderResource`

Table columns:

- username;
- display name;
- status;
- data completeness;
- history months;
- 12-month return;
- max drawdown;
- positive months;
- current risk score;
- largest position;
- effective positions;
- copy coverage at default budget;
- minimum amount for 95%;
- copiers;
- last synced.

Filters:

- status;
- public/private;
- minimum history;
- maximum drawdown;
- maximum risk score;
- minimum positive-month ratio;
- minimum coverage at current budget;
- asset class exposure;
- data freshness.

Actions:

- watch;
- ignore;
- sync now;
- simulate;
- add to comparison.

### Trader detail page

Sections:

1. Profile and freshness.
2. Performance chart.
3. Drawdown chart.
4. Monthly return table/heatmap.
5. Current portfolio allocation.
6. Concentration and leverage.
7. Copier history.
8. Copy Amount Simulator.
9. Raw-data availability.
10. Import history.

### Livewire `CopyAmountSimulator`

Inputs:

```text
copy amount
minimum position amount
target coverage
use visible cash allocation
```

Outputs:

```text
eligible position count
skipped position count
eligible weight
skipped weight
minimum amount for target
minimum amount for 90/95/99/100%
position-by-position explanation
```

Input changes should update calculations without an API call. Simulations use the latest stored portfolio snapshot.

### Compare page

Allow 2–10 traders.

Use:

- one comparison table;
- small individual charts where useful;
- no radar chart in MVP;
- clear warning when observation periods differ.

---

## 16. Scheduling and queues

Initial schedule:

```text
Daily 02:00 UTC     Discover/update rankings
Daily 03:00 UTC     Sync watched trader profiles and performance
Every 6 hours       Sync live portfolio for watched traders
Daily 04:00 UTC     Recalculate analytics
Hourly              Sync own account only after that feature is enabled
Daily 05:00 UTC     Prune expired raw responses
```

For local development, all commands must be runnable manually.

### Rate limiting

- Default application budget: 45 requests/minute.
- Do not assume each endpoint has an independent quota.
- On `429`, respect `Retry-After`.
- Add jitter to retries.
- Avoid retrying `400`, `401`, `403`, and `404` unless explicitly justified.
- A `403` caused by private trader data is a data state, not a retry loop.

### Idempotency

- Derive `source_hash` from canonicalized payload content.
- Repeated imports of the same response must not create duplicate snapshots.
- Performance points use unique keys.
- Sync jobs must be safe to retry.

---

## 17. Security controls

Mandatory:

- Read-only API key.
- `ETORO_ALLOW_WRITE=false`.
- No write methods in `EtoroClient`.
- Credentials redacted in logs.
- Raw API payloads reviewed for personal information.
- Filament authentication enabled.
- No public registration.
- Local development database must not be publicly reachable.
- Production secrets stored outside Git.
- Optional IP whitelist for the API key.
- Expiration date and rotation procedure documented.

Add a test that scans logged request context and proves configured secret values are absent.

Do not store:

- full API headers;
- API keys;
- SMS verification codes;
- card or banking details;
- government identity data.

---

## 18. Testing strategy

Use Pest.

### Unit tests

Mandatory calculators:

```text
CopyCoverageCalculator
DrawdownCalculator
PerformanceCalculator
ConcentrationCalculator
DataQualityCalculator
```

Copy coverage cases:

- amount below platform minimum;
- zero positions;
- one position;
- all positions eligible;
- some positions skipped;
- weights expressed in percentages and normalized before calculation;
- cash allocation;
- missing weights;
- duplicate instruments;
- tiny position requiring a very large amount;
- exact $1 boundary;
- 95% target reached;
- impossible target due to unknown weight.

### API client tests

Use `Http::fake()`.

Test:

- required headers;
- unique request ID;
- no `Authorization` header is ever sent;
- timeout configuration;
- `429` handling;
- retry behavior;
- `401` authentication failure;
- `403` visibility mapping;
- secret redaction;
- raw payload storage;
- prohibition of write methods.

### Import tests

Use sanitized JSON fixtures captured during the API spike.

Test:

- mapping;
- idempotent re-import;
- transaction rollback;
- partial endpoint failure;
- stale snapshot detection;
- unknown API fields do not break import.

### Filament/Livewire tests

Test:

- resource visibility;
- filtering and sorting;
- sync action dispatches a job;
- simulator updates when amount changes;
- private trader warnings;
- comparison period mismatch warnings.

### No live calls in the normal test suite

Live API tests must be opt-in:

```bash
ETORO_LIVE_TESTS=true php artisan test --group=etoro-live
```

They must default to Demo and remain read-only.

---

## 19. Observability

Log structured events:

```text
etoro.request.started
etoro.request.completed
etoro.request.failed
etoro.rate_limited
etoro.visibility_denied
trader.sync.started
trader.sync.completed
trader.sync.partial
analytics.recalculated
```

Useful metrics:

- requests per endpoint;
- error rate;
- `429` count;
- average latency;
- last successful trader sync;
- incomplete trader count;
- stale trader count;
- calculator methodology version.

The Filament `Imports` page must show failed runs and retry actions without exposing secrets.

---

## 20. Delivery milestones

> **Napomena o nomenklaturi (dodato 2026-08-06, vidi DECISIONS.md D-018):**
> git nazivi grana poput `milestone/2-etoro-domain-model` označavaju
> implementacioni tok (implementation stream), ne numeraciju product
> milestone-a ispod. Oznake Checkpoint A–E koje se pojavljuju u
> `docs/WORKLOG.md`/`docs/DECISIONS.md` su review/delivery checkpoint-i
> korišćeni tokom implementacije jednog takvog toka. Ni naziv grane ni
> Checkpoint oznake ne menjaju niti renumerišu product milestone brojeve
> (Milestone 0–7) definisane u ovoj sekciji.

### Milestone 0 — Scaffold

Deliver:

- Laravel 13 application;
- Filament 5 admin panel;
- authentication;
- Pest;
- Laravel Boost;
- MySQL configuration;
- `config/etoro.php`;
- `.env.example`;
- read-only safety test.

Acceptance:

```bash
php artisan test
```

passes, and Filament opens locally.

### Milestone 1 — API spike and fixtures

Deliver:

- `EtoroClient`;
- `php artisan etoro:doctor`;
- authenticated profile call;
- one rankings call;
- one known public trader profile call;
- read-only availability probe of both account P&L endpoints (`demo` and `real`), each recorded as available / forbidden / unavailable;
- sanitized fixtures;
- capability report in `docs/ETORO_API_CAPABILITIES.md`.

The capability report must list each investigated endpoint as:

```text
works
works with partial data
requires additional scope
private/visibility dependent
not available
unexpected schema
```

Acceptance:

- no secrets in code or logs;
- requests are GET-only;
- actual response shapes are documented;
- no speculative production schema beyond data observed.

**Stop after this milestone and review the API capability report before building the full database.**

### Milestone 2 — Discovery and trader storage

Deliver:

- migrations based on observed payloads;
- ranking importer;
- trader search;
- `TraderResource`;
- candidate/watched/ignored states;
- import history.

Acceptance:

- at least 20 candidates imported;
- repeated import creates no duplicates;
- failed rows are visible.

### Milestone 3 — Performance analytics

Deliver:

- performance history importer;
- compounded return;
- drawdown;
- positive-month analysis;
- consistency metrics;
- charts and tests.

Acceptance:

- all formulas tested with hand-calculated fixtures;
- displayed period/granularity is explicit.

### Milestone 4 — Portfolio and copy simulator

Deliver:

- live portfolio importer;
- instruments;
- portfolio snapshots;
- concentration metrics;
- copy fidelity simulator;
- minimum amount for coverage targets.

Acceptance:

- simulator explains each skipped position;
- $200/$500/$1,000 presets;
- 90/95/99/100% target calculations;
- results reproducible from a stored snapshot.

### Milestone 5 — Trader comparison

Deliver:

- comparison page;
- default analysis profile;
- transparent filters;
- data-quality warnings;
- export to CSV optional.

Acceptance:

- compare 2–10 traders;
- no hidden overall score;
- differing observation periods clearly shown.

### Milestone 6 — Own account tracking

Deliver:

- Demo P&L import first;
- balance/equity/positions/copies snapshots;
- actual copy performance;
- comparison between predicted fidelity and actual result.

Real account read access is enabled only after Demo acceptance.

### Milestone 7 — XTrade email importer

Future backlog:

- receive or fetch XTrade report emails;
- parse daily account summaries;
- create broker-neutral account snapshots;
- compare XTrade and eToro experiments.

---

## 21. Definition of done

A feature is done only when:

- code follows existing project conventions;
- business logic is outside Filament resources;
- tests cover happy path and meaningful failure modes;
- API responses are mapped through DTOs;
- secrets are not logged;
- database writes are idempotent;
- UI states include loading, empty, stale, partial, and failed;
- documentation is updated;
- `php artisan test` passes;
- code formatter passes;
- static analysis passes if configured.

---

## 22. Instructions for Claude Code or Codex

The coding agent must:

1. Read this entire file before changing code.
2. Implement only the requested milestone.
3. Never invent an eToro endpoint or response field.
4. Inspect the live official OpenAPI specification before implementing an endpoint.
5. Save a sanitized fixture from a successful real response before building its mapper.
6. Never include `.env` secrets in output, tests, logs, commits, or documentation.
7. Never call POST, PUT, PATCH, or DELETE on eToro.
8. Never enable real trading, transfers, or Copy Trading actions.
9. Ask for review when an API payload materially differs from this plan.
10. Prefer simple Laravel code over speculative abstractions.
11. Add tests with every feature.
12. Do not install third-party packages unless the benefit is explicit and approved.
13. Do not create a multi-tenant architecture.
14. Do not add AI-generated investment advice.
15. Report:
    - files changed;
    - commands run;
    - tests run;
    - assumptions;
    - unresolved API questions.

---

## 23. First implementation prompt

Use this as the first prompt to the coding agent:

```text
Read PROJECT.md in full.

Implement Milestone 0 only.

Create a Laravel 13 application using PHP 8.3+, Filament 5, Livewire 4,
MySQL 8, Pest, and Laravel Boost.

Add config/etoro.php and the documented .env.example variables.

Add an application-level read-only safety mechanism:
- ETORO_ALLOW_WRITE must default to false.
- The application must not contain any eToro write client methods.
- Add tests proving write mode is disabled by default.
- Do not call the eToro API yet.
- Do not add speculative trader migrations yet.

Create the Filament panel with local authentication and a simple dashboard
showing that the project is in read-only analytics mode.

Run the complete test suite and report every changed file and command.
Stop after Milestone 0.
```

After Milestone 0 is reviewed:

```text
Read PROJECT.md and implement Milestone 1 only.

Before coding endpoint mappers, inspect the live official eToro OpenAPI
definition. Implement a read-only EtoroClient and the etoro:doctor command.

Start with:
- authenticated profile;
- investor rankings;
- one public trader profile.

Use GET requests only. Store no secrets. Capture and sanitize response fixtures.
Write docs/ETORO_API_CAPABILITIES.md from actual results.

Do not build the final trader database schema yet.
Stop after the capability report and tests pass.
```

---

## 24. Open questions to resolve during Milestone 1

- ~~Does the user's copied credential include both the public API key and user key?~~ Resolved 2026-07-30: both exist (Public Key → `ETORO_API_KEY`, generated Private Key → `ETORO_USER_KEY`).
- Which endpoints work with only the key pair?
- Does the official OpenAPI document mark any endpoint as explicitly requiring bearer authentication? (Only then would bearer support be considered.)
- Which read scopes were granted? (UI shows 16 selected read permissions — confirm what the API reports.)
- Which of the demo/real P&L endpoints actually respond to the generated key? (Keys are no longer generated per Demo/Real environment; verify by GET, not by assumption.)
- What units does `investmentPct` use in each endpoint?
- Does live portfolio data include cash or only positions?
- Are closed trades available in sufficient detail for turnover calculations?
- How much ranking history is available?
- Which public-trader endpoints return `403` due to privacy?
- Are rate-limit response headers reliable?
- Can the application obtain instrument metadata in bulk?
- Do ranking results include enough fields to pre-filter before expensive profile calls?

---

## 25. Official references

- eToro API introduction:  
  `https://api-portal.etoro.com/`

- Authentication:  
  `https://api-portal.etoro.com/getting-started/authentication`

- API documentation index:  
  `https://api-portal.etoro.com/llms.txt`

- eToro Claude Code MCP guide:  
  `https://api-portal.etoro.com/vibe-code/claude-code`

- CopyTrader mechanics and minimums:  
  `https://www.etoro.com/copytrader/how-it-works/`

- Laravel 13 documentation:  
  `https://laravel.com/docs/13.x`

- Filament 5 documentation:  
  `https://filamentphp.com/docs/5.x`

- Livewire 4 documentation:  
  `https://livewire.laravel.com/docs/4.x`

---

## 26. Final MVP success criterion

The MVP succeeds when the user can enter a budget, select several public eToro traders, and receive a reproducible comparison such as:

```text
Budget: $500
Target portfolio coverage: 95%

Trader A
- visible portfolio coverage at $500: 98.4%
- minimum amount for 95%: $327
- 24-month return: ...
- max daily drawdown: ...
- positive months: ...
- largest position: ...
- effective positions: ...
- data completeness: 94%

Trader B
- visible portfolio coverage at $500: 83.2%
- minimum amount for 95%: $1,240
- ...
```

Every number must link back to:

- its source snapshot;
- calculation methodology;
- data timestamp;
- any assumptions or missing data.

That is the product. Everything else is backlog.
