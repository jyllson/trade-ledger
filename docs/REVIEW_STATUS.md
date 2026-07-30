# REVIEW_STATUS — TradeLedger

**Trenutni milestone:** Milestone 1 — API spike i fixtures
**Status:** kod implementiran, testiran i ispravljen po code review-u; čeka
eksplicitno odobrenje za prvi živi API poziv (`php artisan etoro:doctor --live`)
**Poslednje ažuriranje:** 2026-07-30

## Ispunjeni kriterijumi prihvatanja (do pred live poziv)

- [x] `EtoroClient` — isključivo GET, typed javne metode (authenticatedUser,
      rankings, userProfile, userPerformance, userLivePortfolio, accountPnl),
      privatni GET-only helper, bez `Authorization` header-a
- [x] Tri exception klase (`EtoroConfigurationException`,
      `EtoroRequestException` s kategorijom/status/requestId/Retry-After,
      `EtoroUnexpectedResponseException`) — bez klase po HTTP statusu
- [x] Generički transport rezultat `EtoroApiResponse` (payload, status,
      requestId, durationMs, rateLimitHeaders) — bez pretpostavljenih DTO
      polja pre live probe
- [x] `php artisan etoro:doctor` — bez `--live` samo lokalna provera
      konfiguracije (bez mrežnog poziva); sa `--live` izvršava svih 7 proba
      sekvencijalno (~1s pauza, produžena po `Retry-After` do 60s)
- [x] `--capture-raw` opt-in flag za čuvanje sirovih odgovora u
      `storage/app/private/etoro/raw` (git-ignorisano); bez flag-a se ništa
      ne čuva; komanda upozorava na lične/finansijske podatke
- [x] `ETORO_STORE_RAW_RESPONSES` podrazumevano `false` (config + .env.example)
- [x] Auto-selekcija public trader username-a iz prvog `type === 'trader'`
      reda uspešnog rankings odgovora; bez hardkodovanog username-a u kodu;
      `--username=` override dostupan
- [x] Neuspešan rankings ispravno preskače probe #3–5, P&L probe #6/#7 se
      uvek oba izvršavaju nezavisno od `ETORO_ENVIRONMENT`
- [x] Sanitizovan terminalski izlaz — samo nazivi polja/broj stavki, nikad
      vrednosti (username, imena, balansi, kredencijali)
- [x] Bounded retry (max 3 pokušaja) sa backoff+jitter za 5xx/konekcione
      greške; 400/401/403/404/429 se ne retry-uju automatski; 429 nosi
      `Retry-After`
- [x] Svi testovi offline kroz `Http::fake()` — uključujući `--live` code
      path (7 proba, username selekcija, rankings-failure skip, oba P&L
      poziva, bez Authorization header-a, bez PII/kredencijala u izlazu,
      raw capture opt-in ponašanje)
- [x] **Code review korekcije (D-015):** `usernames` query kao scalar
      (potvrđeno OpenAPI `explode:false`); username kao URL path segment
      zaštićen `rawurlencode()` + odbijanje blank vrednosti; svež
      `x-request-id` po fizičkom HTTP pokušaju (ne po logičkom pozivu);
      `allow_redirects=false` + 3xx → `EtoroUnexpectedResponseException`
      (bez ikad čitanja `Location` vrednosti); kontekstualna 403
      klasifikacija (`requires_additional_scope` za account-level probe,
      `private_or_visibility_dependent` za public-trader probe); tabela
      prikazuje Request ID i rate-limit metapodatke (Limit/Remaining/
      Retry-After), nikad credential/payload vrednosti
- [x] `php artisan test`: 58 passed / 168 assertions
- [x] `vendor/bin/pint`: prošao
- [x] `vendor/bin/phpstan analyse` (level 7): 0 errors
- [x] Bez migracija, Eloquent modela, Filament resursa za eToro podatke
- [x] Bez pokušaja Bearer/OAuth; dokumentovana nekonzistentnost zvanične
      dokumentacije o autentikaciji (DECISIONS.md D-013)
- [x] MCP `etoro-public-api` registrovan u lokalnoj Claude konfiguraciji
      (ostaje po zahtevu vlasnika, ne utiče na projekat)

## Neispunjeni kriterijumi prihvatanja

- [ ] **Live poziv nije izvršen.** `php artisan etoro:doctor --live` čeka
      posebno, eksplicitno odobrenje vlasnika projekta.
- [ ] `docs/ETORO_API_CAPABILITIES.md` — pisaće se nakon live poziva, na
      osnovu stvarnih rezultata
- [ ] Sanitizovani fixtures u `tests/Fixtures/Etoro/` — prave se tek nakon
      live poziva, uz prikaz tačne redakcije vlasniku na odobrenje pre commit-a
- [ ] Commit code-a — čeka eksplicitno odobrenje (posebno od odobrenja za
      live poziv)

## Poznati problemi

- Lokalni `.env` vlasnika već ima `ETORO_ENABLED=true`,
  `ETORO_ENVIRONMENT=real`, i postavljene `ETORO_API_KEY`/`ETORO_USER_KEY`
  (potvrđeno indirektno kroz izlaz `php artisan etoro:doctor` bez ijedne
  prikazane vrednosti ključa) — spreman za live probu čim se odobri.
- Lokalni `.env` takođe ima `ETORO_STORE_RAW_RESPONSES=true` (verovatno
  zaostalo od pre promene bezbednog default-a) — nebitno za `etoro:doctor`,
  jer njegovo `--capture-raw` ponašanje zavisi isključivo od CLI flag-a, ne
  od tog config ključa (namerna odluka, DECISIONS.md D-014).
- Klasifikacija 403 je sada kontekstualna (account-level vs. public-trader
  probe, DECISIONS.md D-015 tačka 5), ali je i dalje prva razumna
  pretpostavka zasnovana na HTTP statusu, ne na uvidu u stvarni payload;
  može se prilagoditi nakon uvida u stvarne odgovore.
- Test suite prijavljuje jedno (1) upozorenje bez detalja koje se
  reprodukuje i sa trivijalnim, nepovezanim testom — preduslovno/okruženje-
  nivo, ne izazvano ovim izmenama, ne utiče na prolaznost (DECISIONS.md
  D-015, napomena na kraju).
- Dokumentacija je interno nekonzistentna po pitanju Authorization/Bearer
  header-a (DECISIONS.md D-013) — razrešava se isključivo empirijski, živim
  pozivom.

## Sledeći preporučeni korak

Vlasnik projekta eksplicitno odobrava `php artisan etoro:doctor --live`.
Nakon toga: pregled sanitizovanog izlaza, eventualno `--capture-raw` za
dijagnostiku, ručna izrada sanitizovanih fixtures uz odobrenje vlasnika pre
commit-a, i pisanje `docs/ETORO_API_CAPABILITIES.md` na osnovu stvarnih
rezultata. **Ne izvršavati live poziv bez te eksplicitne potvrde.**
