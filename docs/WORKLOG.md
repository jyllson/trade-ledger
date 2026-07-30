# WORKLOG — TradeLedger

Hronološki dnevnik rada. Novi unosi se dodaju na dno.

---

## 2026-07-30 — Milestone 0: priprema i provera okruženja

### Provera okruženja (pre izmena)

Komande:

```bash
php -v                      # PHP 8.5.8
composer --version          # Composer 2.9.4
node -v && npm -v           # Node 25.4.0, npm 11.13.0
mysql --version             # klijent 9.5.0; server mysqld 9.7.1 (Yerd)
php artisan --version       # Laravel Framework 13.23.0
composer show <paket>       # verzije ključnih paketa
```

Zatečeno stanje:

- Svež Laravel 13.23.0 skeleton (`laravel/blank-livewire-starter-kit`), bez custom koda.
- Instalirane verzije: livewire/livewire v4.3.3, pestphp/pest v5.0.2,
  laravel/boost v2.4.13 (dev), larastan/larastan v3.10.0, laravel/pint v1.30.0.
- Laravel Boost je već instaliran i konfigurisan (`boost.json`, `.mcp.json`,
  Boost guidelines u `CLAUDE.md`) — `boost:install` nije ponavljan.
- filament/filament NIJE instaliran — instalira se u ovom milestone-u.
- Direktorijum nije bio git repozitorijum.
- `phpunit.xml` koristi SQLite `:memory:` za testove (nezavisno od MySQL-a).

### Blokeri / odstupanja prijavljena korisniku

1. Nije git repo → odobreno `git init` (bez commit-a). Izvršeno: `git init`.
2. MySQL server 9.7.1 umesto MySQL 8 → korisnik odobrio (vidi DECISIONS.md).
3. Filament 5 nedostaje → korisnik odobrio `composer require filament/filament:^5.0`.
4. Lokalni `.env` se ne čita, ne prikazuje i ne menja (bezbednosno pravilo);
   menja se samo `.env.example`.

### Korekcije plana od korisnika (2026-07-30)

- `git init` samo ako repo ne postoji (bio je slučaj — izvršeno, bez commit-a).
- `DB_DATABASE=trade_ledger` u `.env.example` (ne `tradelytics`).
- Boost je deo Milestone 0 — proveren, već instaliran, dokumentovane verzije.
- Read-only zaštita mora biti fail-closed (`EtoroWriteGuard`), ne samo prikaz
  config vrednosti na dashboardu.
- Arch/skener test ograničen na eToro namespace (`App\Etoro`), ne na ceo `app/`.

---

## 2026-07-30 — Milestone 0: implementacija

### Komande

```bash
git init                                                  # novi repo, bez commit-a
composer require filament/filament:"^5.0" --no-interaction  # instaliran v5.7.4
php artisan filament:install --panels --no-interaction    # AdminPanelProvider (/admin, login)
php artisan make:filament-widget ReadOnlyModeWidget --panel=admin
php artisan test --compact                                # 3 iteracije, finalno 23/23
vendor/bin/pint --format agent                            # 3 fajla popravljena
vendor/bin/phpstan analyse                                # 0 grešaka
```

### Kreirani fajlovi

- `config/etoro.php` — svi dokumentovani ključevi, `allow_write` default `false`
- `app/Etoro/EtoroWriteGuard.php` — fail-closed zaštita
- `app/Etoro/Exceptions/EtoroWriteModeNotAllowedException.php`
- `app/Providers/Filament/AdminPanelProvider.php` (Filament installer)
- `app/Filament/Widgets/ReadOnlyModeWidget.php` + blade view
- `tests/Feature/Etoro/ReadOnlySafetyTest.php`
- `tests/Feature/Etoro/WriteSurfaceTest.php`
- `tests/Feature/Filament/DashboardTest.php`
- `docs/WORKLOG.md`, `docs/DECISIONS.md`, `docs/REVIEW_STATUS.md`

### Izmenjeni fajlovi

- `.env.example` — `APP_NAME=TradeLedger`, MySQL blok (`trade_ledger`), `ETORO_*` blok
- `app/Providers/AppServiceProvider.php` — `EtoroWriteGuard::ensureReadOnly()` pri boot-u
- `app/Models/User.php` — `FilamentUser` ugovor (`canAccessPanel(): true`)
- `bootstrap/providers.php` — registrovan `AdminPanelProvider` (installer)
- `composer.json` / `composer.lock` — dodat `filament/filament`
- `public/` — Filament assets; `CLAUDE.md`/`.claude` — Boost update (automatski)

### Greške i rešenja tokom rada

1. Dashboard test nije video tekst widgeta — Filament widgeti su podrazumevano
   lazy; rešeno sa `protected static bool $isLazy = false` (bezbednosni baner
   treba odmah da bude vidljiv).
2. `Pest\Livewire\livewire()` ne postoji — `pest-plugin-livewire` nije
   instaliran; umesto novog paketa korišćen ugrađeni `Livewire::test()`.

### Rezultati

- `php artisan test`: **23 passed (40 assertions)** — 0 failures
- `vendor/bin/pint`: prošao (popravio 3 starter fajla)
- `vendor/bin/phpstan analyse`: **0 errors**

---

## 2026-07-30 — Korekcija pretpostavke o eToro ključevima (dokumentacija)

Korisnik je na osnovu stvarnog eToro API Key Management UI-ja utvrdio da UI
odstupa od javne dokumentacije: nema Demo/Real izbora pri kreiranju ključa;
bira se Account, dozvole se biraju pojedinačno (16 read dozvola; „Trading –
Real · Write“ isključena). `ETORO_ENVIRONMENT` je samo izbor account
endpointa, ne osobina ključa; lokalno `real`.

Izmenjeni fajlovi (samo dokumentacija, bez koda i bez vrednosti ključeva):

- `PROJECT.md` — §3 (novi odeljak „Key management UI“), §5 (pravila),
  §8 (P&L: proveriti oba endpointa GET-om tokom M1), §20 (M1 deliverable),
  §24 (otvorena pitanja ažurirana/razrešena)
- `docs/DECISIONS.md` — dodata odluka D-008

---

## 2026-07-30 — Uklanjanje bearer tokena (D-009); Milestone 0 zatvoren

Na zahtev vlasnika: bearer autentikacija se ne implementira; standardna
autentikacija koristi isključivo `x-api-key`, `x-user-key` i `x-request-id`.
Bearer se može dodati kasnije samo ako zvanični OpenAPI dokument eksplicitno
pokaže da je potreban za konkretan endpoint.

Izmenjeni fajlovi:

- `.env.example` — uklonjen `ETORO_BEARER_TOKEN=`
- `config/etoro.php` — uklonjen ključ `bearer_token`
- `PROJECT.md` — §3 (autentikacija, Key management UI), §5 (env blok),
  §10 (HTTP klijent: nikad ne slati Authorization header), §18 (testovi),
  §24 (pitanje o bearer-u preformulisano)
- `tests/Feature/Etoro/ReadOnlySafetyTest.php` — uklonjene bearer stavke iz
  postojećih testova; dodat test `defines no bearer token configuration`
- `docs/DECISIONS.md` — dodata D-009, napomena u D-008

Rezultati provere:

- `php artisan test`: 23 passed (40 assertions)
- `vendor/bin/pint`: prošao
- `vendor/bin/phpstan analyse`: 0 errors

**Milestone 0 je označen kao završen od strane vlasnika projekta.**
Milestone 1 ne počinje bez posebnog odobrenja.

---

## 2026-07-30 — Prvi commit i push na GitHub

Komande:

```bash
git add -A
git commit -m "Milestone 0: scaffold read-only TradeLedger analytics app"
git remote add origin git@github.com:jyllson/trade-ledger.git
git push -u origin main   # ispravljen remote alias/rebase, vidi ispod
```

Napomene:

- Pre commit-a dodat `.claude/settings.local.json` u `.gitignore`; provereno
  da `.env` ostaje ignorisan.
- U prvi (kasnije amendovan) commit je greškom ušao `.env.swp` (editor swap
  fajl, potencijalno sa sadržajem `.env`). Uklonjen iz indeksa, dodati
  `.env.*` (uz `!.env.example`), `*.swp`, `*.swo` u `.gitignore`, commit
  amendovan, pa `git reflog expire` + `git gc --prune=now` da obrišu i
  nereferencirani objekat. Commit u tom trenutku još nije bio push-ovan, pa
  rotacija ključeva nije bila potrebna.
- Podrazumevani SSH ključ se autentifikovao kao pogrešan GitHub nalog;
  korišćen postojeći `~/.ssh/config` alias `github-jyllson` (remote URL
  `git@github-jyllson:jyllson/trade-ledger.git`).
- Remote repo je već sadržao inicijalni commit sa `LICENSE` fajlom; lokalna
  grana rebase-ovana preko njega (bez konflikata) pre push-a.

Rezultat: grana `main` push-ovana i prati `origin/main`.

---

## 2026-07-30 — Code review korekcije Milestone 0

Code review Milestone 0 zahtevao je dve obavezne korekcije pre Milestone 1 i
nekoliko manjih cleanup izmena. Commit nije napravljen u okviru ovog koraka —
čeka eksplicitno odobrenje vlasnika projekta.

### 1. CI: SQLite umesto MySQL-a tokom Setup Application koraka

`.github/workflows/tests.yml` je pokretao `composer setup` (koji uključuje
`php artisan migrate --force`) bez ijedne dostupne baze, jer `.env.example`
konfiguriše MySQL a CI nema MySQL servis. Dodat korak koji kreira
`database/database.sqlite` i env varijable `DB_CONNECTION=sqlite` /
`DB_DATABASE=database/database.sqlite` isključivo za Setup Application korak.
`.env.example` i lokalni `.env` nisu menjani. Vidi DECISIONS.md D-010.

### 2. `WriteSurfaceTest`: dozvoljen privatni `request()` helper

Test je ranije zabranjivao `request`/`send` bez obzira na vidljivost, što je
u koliziji sa PROJECT.md §10 (zabranjen je javni generički request API, ne i
privatni infrastrukturni helper). Test je razdvojen na dve liste: zabrane bez
obzira na vidljivost (write/trading metode) i zabrane samo kada je metoda
public (`request`, `send`). Test i dalje samo proverava nazive metoda
refleksijom — stvarno GET-only ponašanje dokazuje se tek u Milestone 1 kroz
`Http::fake()`. Vidi DECISIONS.md D-011.

### 3. Manji cleanup

- `composer.json`: `name` → `jyllson/trade-ledger`, ažurirani `description` i
  `keywords`.
- Obrisani `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php`;
  uklonjeni `something()` i `expect()->extend('toBeOne', ...)` iz
  `tests/Pest.php`.
- Dodat `README.md` (lokalni setup + upozorenje o API ključevima).
- `ETORO_ENABLED` podrazumevano `false` u `config/etoro.php` i
  `.env.example`.

Vidi DECISIONS.md D-012.

### Komande i rezultati

```bash
php artisan test --compact       # 21 passed, 40 assertions (bilo 23 pre brisanja ExampleTest fajlova)
vendor/bin/pint --format agent   # passed
vendor/bin/phpstan analyse       # 0 errors
```

Izmenjeni/obrisani fajlovi u ovom koraku:

- `.github/workflows/tests.yml`
- `tests/Feature/Etoro/WriteSurfaceTest.php`
- `composer.json`
- `tests/Pest.php`
- `README.md` (novi)
- `config/etoro.php`, `.env.example`
- obrisani: `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php`

**Implementacija EtoroClient-a nije započeta u okviru ove korekcije**, kako
je i traženo. Commit čeka eksplicitno odobrenje vlasnika projekta.

---
