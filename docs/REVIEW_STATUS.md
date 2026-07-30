# REVIEW_STATUS — TradeLedger

**Trenutni milestone:** Milestone 0 — Scaffold
**Status:** ZAVRŠEN — prihvaćen od vlasnika projekta 2026-07-30; code review
korekcije primenjene 2026-07-30 (commit čeka odobrenje)
**Poslednje ažuriranje:** 2026-07-30

## Ispunjeni kriterijumi prihvatanja

- [x] Laravel 13 aplikacija (v13.23.0)
- [x] Filament 5 admin panel (v5.7.4) sa lokalnom login autentifikacijom,
      bez javne registracije
- [x] Livewire 4 (v4.3.3)
- [x] Pest (v5.0.2)
- [x] Laravel Boost (v2.4.13, već instaliran — nije reinstaliran)
- [x] MySQL konfiguracija u `.env.example` (`DB_CONNECTION=mysql`,
      `DB_DATABASE=trade_ledger`)
- [x] `config/etoro.php` sa svim dokumentovanim ključevima (bez bearer tokena — D-009)
- [x] Sve `ETORO_*` promenljive u `.env.example`; `ETORO_ALLOW_WRITE=false`;
      `ETORO_ENABLED=false` podrazumevano (D-012); `ETORO_BEARER_TOKEN`
      uklonjen (autentikacija: `x-api-key`, `x-user-key`, `x-request-id`)
- [x] Fail-closed `EtoroWriteGuard` + boot provera u `AppServiceProvider`
- [x] Testovi: default false, flag=true aktivira zaštitu, dashboard prikazuje
      read-only stanje, eToro sloj nema write metode (reflection scan,
      dozvoljen privatni `request()` helper — D-011)
- [x] Dashboard widget „Read-only analytics mode" (nije lazy, uvek vidljiv)
- [x] `php artisan test`: 21 passed / 40 assertions (starter ExampleTest
      fajlovi obrisani — D-012)
- [x] `vendor/bin/pint`: prošao
- [x] `vendor/bin/phpstan analyse` (Larastan): 0 errors
- [x] Git repo inicijalizovan, prvi commit napravljen i push-ovan na
      `git@github.com:jyllson/trade-ledger.git` (grana `main`)
- [x] CI (`.github/workflows/tests.yml`) koristi SQLite tokom Setup
      Application koraka umesto nedostupnog MySQL-a (D-010)
- [x] Composer package identity (`jyllson/trade-ledger`), README.md sa
      upozorenjem o API ključevima (D-012)
- [x] Bez poziva ka eToro API-ju; bez spekulativnih trader/portfolio migracija

## Neispunjeni kriterijumi prihvatanja

- (nema — Milestone 0 prihvaćen; code review korekcije primenjene)

## Poznati problemi

- Lokalni MySQL server je 9.7.1; ciljana kompatibilnost aplikacije je
  MySQL 8.4+ (DECISIONS.md D-001). Poseban MySQL 8.4 integration CI job
  ostavljen za kasnije, kada aplikacija dobije sopstvene migracije (D-010).
- Lokalni `.env` nije menjan (bezbednosno pravilo). Za lokalno pokretanje
  korisnik ručno podešava: `APP_NAME=TradeLedger`, `DB_CONNECTION=mysql`,
  `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=trade_ledger`,
  `DB_USERNAME`/`DB_PASSWORD`, i ceo `ETORO_*` blok iz `.env.example`
  (ključeve bez vrednosti popunjava sam; `ETORO_ALLOW_WRITE` ostaje `false`;
  `ETORO_ENABLED` uključuje ručno tek na početku Milestone 1).
  Zatim: kreirati bazu `trade_ledger`, `php artisan migrate`,
  `php artisan make:filament-user`.
- Commit sa code review korekcijama još nije napravljen — čeka eksplicitno
  odobrenje vlasnika projekta (upute: ne pravi commit bez odobrenja).

## Sledeći preporučeni korak

Vlasnik projekta odobrava commit code review korekcija. Zatim sledi
**Milestone 1 — API spike i fixtures**: EtoroClient (read-only, GET-only),
`etoro:doctor`, provera dostupnosti oba P&L endpointa (real/demo) stvarnim
GET zahtevima, sanitizovani fixtures, `docs/ETORO_API_CAPABILITIES.md`.
**Ne počinje bez posebnog, eksplicitnog odobrenja vlasnika projekta.**
