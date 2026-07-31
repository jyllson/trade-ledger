# REVIEW_STATUS — TradeLedger

**Trenutni milestone:** Milestone 1 — API spike i fixtures
**Status:** dve žive probe izvršene i prihvaćene (svih 7 capability-ja
potvrđeno kao `works`/`available`); kod dopunjen retry dijagnostikom i
double opt-in raw capture-om; čeka odobrenje za selektivni raw capture
javnih trader podataka
**Poslednje ažuriranje:** 2026-07-31

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
      `--username` nije dat)
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
      `--username=` override dostupan
- [x] Neuspešan rankings ispravno preskače username-zavisne probe; P&L probe
      se uvek oba izvršavaju nezavisno od `ETORO_ENVIRONMENT`
- [x] Sanitizovan terminalski izlaz — samo nazivi polja/broj stavki, nikad
      vrednosti (username, imena, balansi, kredencijali)
- [x] Bounded retry (max 3 pokušaja) sa backoff+jitter za 5xx/konekcione
      greške; 400/401/403/404/429 se ne retry-uju automatski; 429 nosi
      `Retry-After`
- [x] Kontekstualna 403 klasifikacija (`requires_additional_scope` za
      account-level probe, `private_or_visibility_dependent` za
      public-trader probe)
- [x] Svi testovi offline kroz `Http::fake()`/`Sleep::fake()` — uključujući
      `--live` i `--only` code path-ove, double opt-in kombinacije (sve 4),
      retry dijagnostiku, transportnu dijagnostiku, autoritativnu
      `git check-ignore` proveru
- [x] `php artisan test`: **78 passed / 237 assertions**
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

## Neispunjeni kriterijumi prihvatanja

- [ ] Selektivni raw capture (rankings, public profile, performance
      history, live portfolio — bez `/me` i bez Real/Demo P&L) — čeka
      eksplicitno odobrenje vlasnika projekta
- [ ] Sanitizovani fixtures u `tests/Fixtures/Etoro/` — prave se tek nakon
      selektivnog raw capture-a, uz prikaz tačne redakcije vlasniku na
      odobrenje pre commit-a
- [ ] Commit i push code-a od ovog checkpoint-a — čeka eksplicitno
      odobrenje

## Poznati problemi

- Lokalni `.env` vlasnika ima `ETORO_ENABLED=true`, `ETORO_ENVIRONMENT=real`,
  postavljene `ETORO_API_KEY`/`ETORO_USER_KEY`, i (od poslednje izmene
  vlasnika) `ETORO_STORE_RAW_RESPONSES=false`. Lokalni `.env` se ne čita ni
  ne menja od strane agenta.
- Klasifikacija 403 je kontekstualna ali i dalje prva razumna pretpostavka
  zasnovana na HTTP statusu, ne na uvidu u stvarni payload.
- Test suite prijavljuje jedno (1) preduslovno/okruženje-nivo upozorenje bez
  detalja, reprodukuje se i sa trivijalnim nepovezanim testom — ne utiče na
  prolaznost.
- Dokumentacija je interno nekonzistentna po pitanju Authorization/Bearer
  header-a (DECISIONS.md D-013) — u praksi razrešeno za sve testirane
  endpointe: nijedan nije zahtevao Bearer.

## Sledeći preporučeni korak

Vlasnik projekta eksplicitno odobrava selektivni raw capture isključivo
javnih trader podataka: rankings, public profile, performance history, live
portfolio. **Bez `/me` i bez Real/Demo P&L payload-a.** Nakon capture-a:
ručna sanitizacija i izrada fixtures uz odobrenje vlasnika pre commit-a.
