# REVIEW_STATUS — TradeLedger

**Trenutni implementation stream:** eToro target-copy coverage —
implementaciona grana `feature/etoro-target-coverage` (Checkpoint A–B; vidi
`docs/DECISIONS.md` D-018 za razliku između naziva grane/Checkpoint oznaka i
product milestone numeracije u `PROJECT.md` §20)
**Status:** implementation stream `feature/etoro-target-coverage` je
implementaciono završen (Checkpoint A: application use case; Checkpoint B:
read-only CLI). Documentation closeout je pripremljen; stream je spreman za
delivery/integration preko pull request-a prema `main`.
**Poslednje ažuriranje:** 2026-08-10

## Target-copy coverage sloj — završeno

- [x] `App\Application\Etoro\FindTraderMinimumCopyAmountForCoverage` use
      case — Checkpoint A (`EtoroClient::userLivePortfolio()` →
      `LivePortfolioMapper` →
      `LivePortfolioCoverageAdapter::toCoverageTargetRequest()` →
      `CopyCoverageCalculator::minimumAmountForCoverage()` →
      `FindTraderMinimumCopyAmountForCoverageResult`)
- [x] `FindTraderMinimumCopyAmountForCoverageResult` DTO (`traderUsername`,
      `requestId`, `coverageTarget: CoverageTargetResult`) — Checkpoint A
- [x] application arhitektonske provere (dependency smer nepromenjen, tačno
      jedan `EtoroClient` metod pozvan, transport/mapping/calculation
      exception-i propagirani nepromenjeni) — Checkpoint A
- [x] fixture-backed integration pipeline test (JSON fixture → mapper →
      adapter → calculator → use case) i test da se "no-positive-position"
      domain rezultat propagira nepromenjen (bez reinterpretacije) —
      Checkpoint A
- [x] `php artisan etoro:copy-target` CLI komanda — Checkpoint B
- [x] exact percentage-points parser (regex + BCMath, do 7 decimalnih
      mesta, bez float-a) — Checkpoint B
- [x] exact integer-cents parsing za minimum-position/platform-minimum,
      isti obrazac kao `etoro:copy-coverage` — Checkpoint B
- [x] odvojen prikaz Mathematical minimum copy / Effective minimum copy i
      termin "Covered observed weight" (ne "total portfolio coverage") —
      Checkpoint B
- [x] `N/A` prikaz null target-result vrednosti bez izmišljenog
      "impossible"/`isAchievable` domain state-a — Checkpoint B
- [x] sanitizovan prikaz operational grešaka (isti obrazac kao
      `etoro:copy-coverage`) — Checkpoint B
- [x] console arhitektonske provere (komanda ne referencira
      `EtoroClient`/mapper/adapter/calculator direktno; nema `--details`
      opciju; nema float konverzije) — Checkpoint B
- [x] D-022 (Target coverage semantics — `positiveObservedWeight`) i D-023
      (CLI `target-coverage-percent` input contract) dodate u
      `docs/DECISIONS.md`

## Target-copy coverage sloj — trenutna verifikacija

- `php artisan test`: **819 passed / 2504 assertions**, 0 failures (1
  poznato, nepovezano upozorenje)
- `vendor/bin/phpstan analyse` (level 7): 0 errors
- `vendor/bin/pint --test`: prošao
- Read-only zaštite (`EtoroWriteGuard`, arch testovi protiv write metoda u
  `App\Etoro`) ostaju aktivne i pokrivene testovima

## Šta NIJE implementirano u ovom stream-u

- persistence, migracije, Eloquent modeli;
- Filament/Livewire target simulator UI;
- multi-trader comparison;
- scheduled collection;
- profile/performance/rankings application orchestration;
- write eToro operacije (namerno zabranjene read-only politikom projekta —
  vidi PROJECT.md §2/§6.2/§17 — ne backlog stavka koja čeka
  implementaciju);
- `--details` CLI opcija.

## Target-copy coverage sloj — sledeći korak

1. Otvoriti i pregledati PR `feature/etoro-target-coverage` → `main`.
2. Nakon merge-a razmotriti sledeći korak (npr. persistence sloj ili
   Filament/Livewire simulator UI) u skladu sa stvarnim prioritetom iz
   `PROJECT.md`.

---

## Istorija: eToro application orchestration sloj (`feature/etoro-application-orchestration`)

**Status:** završeno, mergovano u `main` kao PR #3 (commit `b2e1c3d`, "feat:
add eToro application orchestration and copy coverage CLI")
**Poslednje ažuriranje ove istorijske sekcije:** 2026-08-10

### Application orchestration sloj — završeno

- [x] `App\Application\Etoro\EvaluateTraderCopyCoverage` use case —
      Checkpoint A (`EtoroClient::userLivePortfolio()` →
      `LivePortfolioMapper` → `LivePortfolioCoverageAdapter` →
      `CopyCoverageCalculator` → `EvaluateTraderCopyCoverageResult`)
- [x] `EvaluateTraderCopyCoverageResult` DTO (`traderUsername`,
      `requestId`, `CopyCoverageResult`) — Checkpoint A
- [x] application arhitektonske provere (`App\Application` zavisi samo od
      `App\Etoro`/`App\Analytics`; `App\Etoro`/`App\Analytics` ne zavise od
      `App\Application`; tačno jedan `EtoroClient` metod pozvan) —
      Checkpoint A
- [x] fixture-backed integration pipeline test (JSON fixture → mapper →
      adapter → calculator → use case, kroz container resolution i
      `Http::fake()`) — Checkpoint A
- [x] `php artisan etoro:copy-coverage` CLI komanda — Checkpoint B
- [x] exact integer-cents parsing (regex + BCMath granica, bez float-a) —
      Checkpoint B
- [x] exact percentage formatting iz parts-per-billion (bez float-a) —
      Checkpoint B
- [x] sanitizovan prikaz operational grešaka
      (kategorija/status/request-id/transport-reason/errno, nikad
      originalna poruka/stack trace/payload/kredencijali) — Checkpoint B
- [x] console arhitektonske provere (komanda ne referencira
      `EtoroClient`/mapper/adapter/calculator direktno; nema `--details`
      opciju; nema float konverzije) — Checkpoint B
- [x] D-020 (Application orchestration boundary) i D-021 (CLI money and
      presentation boundary) dodate u `docs/DECISIONS.md`

### Application orchestration sloj — verifikacija na kraju grane

- `php artisan test`: 738 passed / 2118 assertions, 0 failures
- `vendor/bin/phpstan analyse` (level 7): 0 errors
- `vendor/bin/pint --test`: prošao
- Read-only zaštite (`EtoroWriteGuard`, arch testovi protiv write metoda u
  `App\Etoro`) ostaju aktivne i pokrivene testovima

**Napomena:** target-coverage application use case NIJE bio implementiran u
ovoj grani (bio je naveden kao sledeći korak) — implementiran je kasnije na
grani `feature/etoro-target-coverage` (Checkpoint A–B, vidi sekciju na vrhu
ovog dokumenta).

### Application orchestration sloj — sledeći korak (istorijski)

1. ~~Otvoriti i pregledati PR `feature/etoro-application-orchestration` →
   `main`.~~ Završeno — mergovano kao PR #3 (commit `b2e1c3d`).
2. ~~Nakon merge-a razmotriti sledeći use case (npr. target-coverage) ili
   presentation kanal.~~ Završeno — vidi `feature/etoro-target-coverage`
   sekciju na vrhu ovog dokumenta.

---

## Istorija: eToro domain-model sloj (`milestone/2-etoro-domain-model`)

**Status:** završeno, mergovano u `main` kao PR #2 (commit `5a36aff`,
"feat: add eToro domain model and copy coverage analytics (#2)")
**Poslednje ažuriranje ove istorijske sekcije:** 2026-08-07

### Domain-model sloj — završeno

- [x] exact value objects (`Money`, `Percentage`) — Checkpoint A
- [x] `LivePortfolio` DTO/mapper (`LivePortfolioMapper`) — Checkpoint B
- [x] `CopyCoverageCalculator` i request/result DTO-i — Checkpoint C
- [x] `PerformanceHistory` mapper (`PerformanceHistoryMapper`) — Checkpoint D1
- [x] `Rankings` mapper (`RankingsMapper`) — Checkpoint D2
- [x] `TraderProfile` mapper (`TraderProfileMapper`) — Checkpoint D3
- [x] `LivePortfolioCoverageAdapter` — Checkpoint E
- [x] fixture-to-calculator pipeline (JSON fixture → mapper → adapter →
      calculator), potvrđen na $200/$500/$1000 budžetima (vidi
      `docs/WORKLOG.md` 2026-08-06)
- [x] arhitektonske dependency provere (`App\Analytics` nezavisan od
      `App\Etoro`; `App\Etoro` sme zavisiti od `App\Analytics`)

### Domain-model sloj — verifikacija na kraju grane

- `php artisan test`: 689 passed / 1809 assertions, 0 failures
- `vendor/bin/phpstan analyse` (level 7): 0 errors
- `vendor/bin/pint --test`: prošao

**Napomena:** application/use-case orchestration sloj koji povezuje
`EtoroClient`, mappere, adapter i calculator NIJE bio implementiran u ovoj
grani (vidi D-019) — implementiran je kasnije na grani
`feature/etoro-application-orchestration` (Checkpoint A–B, vidi sekciju na
vrhu ovog dokumenta).

---

## Istorija: Milestone 1 — API spike i fixtures

**Status:** završen, PR #1 mergovan (vidi `docs/WORKLOG.md`)
**Poslednje ažuriranje ove istorijske sekcije:** 2026-07-31

## Ispunjeni kriterijumi prihvatanja

- [x] `EtoroClient` — isključivo GET, typed javne metode (authenticatedUser,
      rankings, userProfile, userPerformance, userLivePortfolio, accountPnl),
      privatni GET-only helper, bez `Authorization` header-a
- [x] Tri exception klase (`EtoroConfigurationException`,
      `EtoroRequestException`, `EtoroUnexpectedResponseException`) — bez
      klase po HTTP statusu; `EtoroRequestException` nosi kategoriju,
      status, requestId, retryAfter, rate-limit metapodatke, transportnu
      dijagnozu (D-015) i retry dijagnostiku (D-016)
- [x] Generički transport rezultat `EtoroApiResponse` (payload, status,
      requestId, `attemptCount`, `totalDurationMs`, `finalAttemptDurationMs`,
      rateLimitHeaders) — bez pretpostavljenih DTO polja pre live probe
- [x] `php artisan etoro:doctor` — bez `--live` samo lokalna provera
      konfiguracije; sa `--live` svih 7 proba ili, sa `--only=<capability>`,
      tačno jedna (uz rankings-dependency samo kad je potrebna i
      `--username` nije dat); kontrolisan, smislen exit code (D-017)
- [x] Sanitizovana transportna dijagnostika — normalizovane kategorije
      (`connect_timeout`, `request_timeout`, `dns_failure`, `tls_failure`,
      `connection_reset`, `unknown_transport_failure`) iz curl
      errno/connect_time, nikad originalna poruka/URL/payload
- [x] Retry dijagnostika — `Attempts`, `Total Duration`, `Final Attempt
      Duration` kolone; `recovered_after_retry` napomena kad uspeh stigne
      posle > 1 pokušaja
- [x] **Double opt-in raw capture** — potreban i `ETORO_STORE_RAW_RESPONSES=true`
      U konfiguraciji I `--capture-raw` flag; config-only ili flag-only ne
      čuva ništa; flag bez config dozvole → kontrolisana greška, bez
      mrežnog poziva; oba → čuvanje isključivo u
      `storage/app/private/etoro/raw`, autoritativno potvrđeno
      git-ignorisano (`git check-ignore`, potvrđeno i testom)
- [x] Auto-selekcija public trader username-a iz prvog `type === 'trader'`
      reda uspešnog rankings odgovora; bez hardkodovanog username-a u kodu;
      `--username=` override dostupan; prazan/whitespace-only `--username`
      vraća kontrolisanu validation grešku (D-017)
- [x] `ETORO_BASE_URL` mora biti validan apsolutni HTTPS URL pre slanja
      credential header-a — nevalidan ili non-HTTPS URL baca
      `EtoroConfigurationException` bez ijednog mrežnog poziva (D-017)
- [x] Neuspešan rankings ispravno preskače username-zavisne probe; P&L probe
      se uvek oba izvršavaju nezavisno od `ETORO_ENVIRONMENT`
- [x] Sanitizovan terminalski izlaz — samo nazivi polja/broj stavki, nikad
      vrednosti (username, imena, balansi, kredencijali)
- [x] Bounded retry (max 3 pokušaja) sa backoff+jitter za 5xx/konekcione
      greške; 400/401/403/404/429 se ne retry-uju automatski; 429 nosi
      `Retry-After`
- [x] Kontekstualna 403 klasifikacija (`requires_additional_scope` za
      account-level probe — uključujući Real/Demo P&L —
      `private_or_visibility_dependent` za public-trader probe)
- [x] Svi testovi offline kroz `Http::fake()`/`Sleep::fake()` — uključujući
      `--live` i `--only` code path-ove, double opt-in kombinacije (sve 4),
      retry dijagnostiku, transportnu dijagnostiku, exit code scenarije,
      username/HTTPS validaciju, autoritativnu `git check-ignore` proveru
- [x] **Sedam capability-ja potvrđeno živim probama**: authenticated
      profile, investor rankings, public trader profile, trader
      performance history, trader live portfolio, real account P&L, demo
      account P&L — svih 7 klasifikovano kao `works`/`available`. Detalji u
      `docs/ETORO_API_CAPABILITIES.md`.
- [x] **Selektivni raw capture izvršen** — isključivo za četiri javna
      dataset-a istog test-tradera (rankings, public profile, performance
      history, live portfolio). `/me`, Real P&L i Demo P&L payload-i
      **nisu capture-ovani** ni u jednom trenutku.
- [x] **Privatna analiza šeme izvršena** lokalno (schema inventory, cross-file
      relations, sanitizacioni plan) — bez izlaganja identifikacionih
      vrednosti.
- [x] **Kandidat fixtures potpuno sintetizovani** (ne redigovani real podaci)
      — deterministički placeholder-i, sintetičke vrednosti, sintetički
      datumi van stvarnog opsega capture-a. **Leakage scan prošao (PASS)**
      protiv sva četiri raw fajla pre premeštanja u Git.
- [x] Četiri fixture JSON-a (`rankings.json`, `public-profile.json`,
      `performance-history.json`, `live-portfolio.json`) i `README.md`
      premešteni u `tests/Fixtures/Etoro/` i commit-ovani. Pokriveni
      `tests/Feature/Etoro/FixtureIntegrityTest.php` testom.
- [x] Privatni raw fajlovi, sanitization manifest, leakage report i
      copyability-hipoteza analiza **nisu commit-ovani** — ostaju
      isključivo u `storage/app/private/etoro/` (git-ignorisano).
- [x] PR #1 otvoren prema `main` sa capability i test-plan opisom.
- [x] Code-review nalazi ispravljeni (D-017): kontrolisan exit code za
      `etoro:doctor`, validacija praznog `--username`, validacija HTTPS
      `ETORO_BASE_URL`, `composer.lock` usklađen, CI `tests/Unit` fix.
- [x] GitHub Actions zelen nakon poslednjeg push-a.
- [x] `php artisan test`: **101 passed / 323 assertions**, 0 failures
- [x] `vendor/bin/pint`: prošao
- [x] `vendor/bin/phpstan analyse` (level 7): 0 errors
- [x] Bez migracija, Eloquent modela, Filament resursa za eToro podatke
- [x] MCP `etoro-public-api` registrovan u lokalnoj Claude konfiguraciji
      (ostaje po zahtevu vlasnika, ne utiče na projekat)

### Rezultati živih proba

- **Run #1** (`--live`, svih 7 proba): 6/7 `works`/`available` (HTTP 200),
  bez 401/403/404/429/5xx, bez rate-limit header-a. Proba „Trader live
  portfolio" → `temporarily_unavailable` (transport greška nakon bounded
  retry-ja).
- **Run #2** (`--live --only=live-portfolio`): **HTTP 200**. Zaključak:
  Run #1 neuspeh je bio privremena transportna smetnja, ne sistemski
  problem — endpoint je potvrđen kao `works`.
- **Svih 7 capability-ja je sada potvrđeno kao radno** (`works`/`available`).
  Detalji u `docs/ETORO_API_CAPABILITIES.md`.

## Poznati problemi

- Klasifikacija 403 je kontekstualna ali i dalje prva razumna pretpostavka
  zasnovana na HTTP statusu, ne na uvidu u stvarni payload.
- Test suite prijavljuje jedno (1) preduslovno/okruženje-nivo upozorenje bez
  detalja, reprodukuje se i sa trivijalnim nepovezanim testom — ne utiče na
  prolaznost.
- Dokumentacija je interno nekonzistentna po pitanju Authorization/Bearer
  header-a (DECISIONS.md D-013) — u praksi razrešeno za sve testirane
  endpointe: nijedan nije zahtevao Bearer.
- `avgPosSize` i `optimalCopyPosSize` (rankings) ostaju zvanično
  nedokumentovani (bez opisa u OpenAPI šemi) — ne koriste se ni u jednoj
  kalkulaciji.

## Sledeći preporučeni korak (istorijski, iz perioda pre PR #1 merge-a)

1. ~~Merge PR #1 u `main`.~~ Završeno.
2. ~~Kreiranje nove grane za Milestone 2.~~ Završeno — grana
   `milestone/2-etoro-domain-model`.
3. ~~Milestone 2: DTO-i, mapperi i `CopyCoverageCalculator` zasnovani na
   posmatranoj (i sintetičkim fixtures pokrivenoj) API šemi.~~ **Završeno —
   vidi sekciju "Domain-model sloj — završeno" na vrhu ovog dokumenta.**
4. Bez ponovnog live raw capture-a, osim ako se tokom daljeg rada otkrije
   konkretna schema rupa koja zahteva dodatni uvid u stvarni odgovor. Ovo
   je i dalje na snazi — nijedan novi live capture nije izvršen tokom
   domain-model implementacije.
