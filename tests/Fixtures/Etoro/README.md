# eToro Fixtures — TradeLedger

These fixtures are **fully synthetic**. They do **not** represent any real
eToro user, account, trader, or portfolio. Every identifying value, name,
URL, timestamp, and financial/allocation figure was hand-authored for
structural test coverage — none of them were derived by transforming,
scaling, or otherwise deriving from any real captured API response.

> **`rankings.json` moved.** As of Checkpoint C
> (`App\Etoro\FixtureSources\RankingFixtureSource`), the rankings fixture is
> the single canonical, production-resolvable fixture for the manual,
> fixture-only `etoro:import-ranking-page` command, and lives at
> `resources/fixtures/etoro/rankings.json` instead of this directory — it is
> not duplicated here. It is still fully synthetic, with the same
> provenance and guarantees described in this file.

## Files

- `public-profile.json` — 1 synthetic public trader profile
  (`trader_001`).
- `performance-history.json` — 24 synthetic monthly + 3 synthetic yearly
  performance records.
- `gain-history-monthly.json` — synthetic v2 gain time-series
  (`GET /api/v2/portfolios/{username}/gain/monthly`): 26 monthly points,
  gains as decimal fractions with 4 decimals, a partial first month
  (start date not on the 1st), and a `totalGain` equal to the compounded
  series (to 6 decimals). Shape and conventions match the live probe of
  2026-10-05 (see `docs/DECISIONS.md` D-032); all values, dates, and the
  username are hand-authored and passed a leakage scan against the raw
  capture.
- `gain-history-daily.json` — synthetic v2 daily series: 14 consecutive
  calendar days (weekends included, as zero gains), same conventions as
  the monthly file (D-035); leakage-scanned against the raw capture.
- `live-portfolio.json` — 16 synthetic positions across 6 synthetic
  instruments.

## What these fixtures preserve

- The observed eToro Public API JSON schema (field names, nesting,
  primitive types, and nullability) as captured during Milestone 1's live
  capability probes.
- Documented enum values (e.g. `type`, `subType`, `avatars[].type`) where
  safe and non-identifying.
- Structurally meaningful variation: positive/negative/zero numeric
  values, leveraged/unleveraged positions, buy/sell directions, duplicate
  vs. unique instrument allocations, and boundary-case allocation weights.

## What these fixtures do NOT guarantee

- **Documented-but-unobserved fields are not necessarily included.**
  Some fields exist in the official eToro OpenAPI schema but were never
  actually returned by the live API during capture (for example, the
  rankings `country` field). Those fields are intentionally **absent**
  from these base fixtures, matching what a real client will actually
  receive — they are not silently invented just because documentation
  mentions them. Any future test that needs to exercise such a
  documented-but-unobserved shape should do so via an explicit, separate
  mutation of a copy of these fixtures, not by treating these base files
  as already covering it.
- **`performance-history.json`'s `monthly` and `yearly` arrays are
  independent synthetic data series.** Do not assume the `yearly` values
  are (or must be) mathematically derivable from the `monthly` values, or
  vice versa — they were authored independently to exercise ordering,
  gaps, and sign variation at each granularity separately, not to satisfy
  a monthly→yearly compounding relationship.
- **`performance-history.json` gain values are NOT unit-faithful.** The
  real v1 `/gain` endpoint returns percentage points (e.g. `3.3` = 3.3%);
  this fixture's small values (e.g. `0.021`) only exercise mapping and
  ordering. Never build calculations on v1 values — use the v2 series
  (`gain-history-monthly.json`, D-032).
- **These are not a complete or representative sample of any real
  portfolio's composition.** Position counts, instrument counts, and
  weight distributions are chosen for test coverage, not to mirror any
  actual trader's real allocation.

## Why the specific `investmentPct` boundary values

`live-portfolio.json` deliberately includes positions with `investmentPct`
of exactly **0.1**, **0.2**, and **0.5**. Under the (currently
hypothesis-only) copy-fidelity formula
`copied_amount = copy_amount * investmentPct / 100`, with a $1 minimum
copied-position amount, these three values land exactly on the eligibility
boundary at three common copy amounts:

- `0.5` → exactly $1.00 at a $200 copy amount;
- `0.2` → exactly $1.00 at a $500 copy amount;
- `0.1` → exactly $1.00 at a $1,000 copy amount.

They exist specifically so future calculator tests can exercise the exact
eligibility boundary, not just clearly-above/clearly-below cases.

## Security

- Never add real API responses, credentials, or account data to this
  directory.
- Never add `x-api-key`, `x-user-key`, or any other credential value here.
- Only synthetic `https://example.invalid/...` URLs belong in these
  fixtures — never a real eToro or third-party URL.
