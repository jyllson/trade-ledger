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

## 2026-07-30 — Milestone 1: planiranje i istraživanje dokumentacije

Prebačen fokus na granu `milestone/1-etoro-api-spike`. Pre pisanja plana:

- Pročitani PROJECT.md, README.md, docs/DECISIONS.md, docs/WORKLOG.md,
  docs/REVIEW_STATUS.md u celosti.
- Registrovan MCP server `etoro-public-api` (`claude mcp add --transport
  http etoro-public-api https://mcp.public-api.etoro.com`) — alati nisu
  postali dostupni u već pokrenutoj sesiji (potreban restart), pa je
  istraživanje umesto toga urađeno preko `WebFetch` nad zvaničnim
  `api-portal.etoro.com` i `mcp.public-api.etoro.com/skill` stranicama.
- Potvrđena stvarna interna nekonzistentnost eToro dokumentacije o
  autentikaciji (Bearer vs. samo x-api-key/x-user-key) — vidi DECISIONS.md
  D-013.
- Predstavljen implementacioni plan (klase, komanda, endpoint tabela,
  eksperiment autentikacije, sanitizacija fixtures, test plan, greške/rate
  limit, fajlovi, format capability report-a) — vlasnik projekta odobrio uz
  7 korekcija (manje exception klasa, generički transport rezultat umesto
  DTO-ova, opt-in raw capture, ručno odobrenje fixtures, bez rate-limitera,
  testiranje `--live` putem `Http::fake()`, MCP registracija ostaje).

## 2026-07-30 — Milestone 1: implementacija (do pred prvi živi poziv)

Komande:

```bash
php artisan test --compact       # 49 passed, 148 assertions
vendor/bin/pint --format agent   # passed
vendor/bin/phpstan analyse       # 0 errors
php artisan list etoro           # etoro:doctor registrovana
php artisan etoro:doctor         # lokalna provera konfiguracije (bez mreže)
```

Novi fajlovi:

- `app/Etoro/EtoroErrorCategory.php`, `CapabilityStatus.php`,
  `EtoroEnvironment.php`, `RankingQuery.php`, `EtoroApiResponse.php`,
  `EtoroClient.php`
- `app/Etoro/Exceptions/{EtoroConfigurationException,EtoroRequestException,
  EtoroUnexpectedResponseException}.php`
- `app/Console/Commands/EtoroDoctorCommand.php`
- `tests/Feature/Etoro/EtoroClientTest.php`
- `tests/Feature/Console/EtoroDoctorCommandTest.php`

Izmenjeni fajlovi:

- `config/etoro.php`, `.env.example`, `PROJECT.md` — `ETORO_STORE_RAW_RESPONSES`
  podrazumevano `false`
- `tests/Feature/Etoro/ReadOnlySafetyTest.php` — dodat test da je
  `store_raw_responses` default `false` (proveren kroz izvorni kod
  `config/etoro.php`, ne kroz živi `config()`, jer lokalni `.env` vlasnika
  već eksplicitno postavlja tu vrednost na `true` — vidi napomenu ispod);
  ažuriran `.env.example` dataset test na tačan string
  `ETORO_STORE_RAW_RESPONSES=false` (isti obrazac kao za `ETORO_ALLOW_WRITE`)

### Greške i rešenja tokom rada

1. Test koji je proveravao `config('etoro.store_raw_responses')` je pao —
   lokalni `.env` vlasnika projekta već sadrži `ETORO_STORE_RAW_RESPONSES=true`
   (verovatno iz perioda pre ove izmene default vrednosti). Pošto se lokalni
   `.env` ne čita/ne menja, test je prepravljen da proverava izvorni kod
   `config/etoro.php` (literalni `env('ETORO_STORE_RAW_RESPONSES', false)`),
   isto kao što `.env.example`-test već proverava dokumentovani default.
2. `$this->artisan(...)->run()` u Pest testovima ne popunjava `Artisan::output()`
   (Laravel-ov `PendingCommand::run()` prosleđuje sopstveni Mockery-mock
   `BufferedOutput` koji "guta" pozive bez `expectsOutputToContain()`
   deklaracija). Rešeno korišćenjem `Artisan::call(...)` + `Artisan::output()`
   za dva testa koja moraju da pretraže pun tekst izlaza (bez
   full-payload/PII/kredencijala).
3. Vizuelni "artefakt" u terminalu (tačke-punjenje u `checkConfiguration()`
   izgledale su skraćene u ispisu alata) ispostavio se kao prikaz-only
   pojava — programska provera (`strlen`, `bin2hex`) potvrdila da je stvarni
   string ispravan; nikakva izmena koda nije bila potrebna.

### Rezultati

- `php artisan test`: **49 passed (148 assertions)** — 0 failures
- `vendor/bin/pint`: prošao
- `vendor/bin/phpstan analyse` (level 7): **0 errors**
- `php artisan etoro:doctor` (bez `--live`): čita lokalni `.env` vlasnika i
  ispravno prijavljuje ENABLED/present/present/BLOCKED/real, bez ijednog
  mrežnog poziva i bez prikaza vrednosti ključeva.

**Live poziv (`php artisan etoro:doctor --live`) NIJE izvršen.** Čeka se
posebno, eksplicitno odobrenje vlasnika projekta pre bilo kog stvarnog
zahteva ka `public-api.etoro.com`.

---

## 2026-07-30 — Milestone 1: korekcije posle code review-a (pre live poziva)

Vlasnik projekta je pregledao kod i tražio 6 ispravki pre prvog živog poziva.
Sve primenjene, bez commit-a i bez ijednog stvarnog API poziva. Detaljno
obrazloženje svake tačke: `docs/DECISIONS.md` D-015.

### Komande

```bash
php artisan test --compact       # 58 passed, 168 assertions
vendor/bin/pint --format agent   # passed
vendor/bin/phpstan analyse       # 0 errors
```

### Izmenjeni fajlovi

- `app/Etoro/EtoroClient.php` — `usernames` query kao scalar (potvrđeno
  ponovnim uvidom u OpenAPI: `explode: false`); `pathSegment()`/
  `assertUsernameProvided()` za `userPerformance`/`userLivePortfolio`/
  `userProfile`; svež UUID po pokušaju unutar retry petlje;
  `withOptions(['allow_redirects' => false])`; 3xx → `EtoroUnexpectedResponseException`
- `app/Etoro/Exceptions/EtoroRequestException.php` — dodati
  `rateLimitLimit`/`rateLimitRemaining`
- `app/Console/Commands/EtoroDoctorCommand.php` — `executeProbe(...,
  accountLevel: bool)` za kontekstualnu 403 klasifikaciju; tabela dobija
  kolone Request ID / RateLimit-Limit / RateLimit-Remaining / Retry-After
- `tests/Feature/Etoro/EtoroClientTest.php` — novi testovi: tačan query
  string, path-segment enkodiranje sa specijalnim karakterima, odbijanje
  blank username-a, svež request-id po fizičkom pokušaju (2×503+1×200 → 3
  zahteva, 3 različita ID-a), redirect ka drugom domenu (1 zahtev, drugi
  domen nikad pozvan, bez Location u poruci)
- `tests/Feature/Console/EtoroDoctorCommandTest.php` — novi testovi za
  kontekstualnu 403 klasifikaciju i prikaz request ID-a/rate-limit
  metapodataka; dodata `callEtoroDoctor()` pomoćna funkcija

### Greške i rešenja tokom rada

1. `Artisan::call()` + `Artisan::output()` je pisao u pravi STDOUT tokom
   testova → PHPUnit "risky: printed unexpected output" upozorenje. Rešeno
   prosleđivanjem eksplicitnog `Symfony\Component\Console\Output\BufferedOutput`
   kao trećeg argumenta `Artisan::call()`, čitanjem teksta preko
   `$buffer->fetch()`.
2. Test suite pokazuje jedno (1) upozorenje bez detalja
   (`warning_details: []`); provereno da se reprodukuje i sa izolovanim,
   trivijalnim testom nepovezanim sa Etoro kodom (pa i sa postojećim,
   nedirnutim `DashboardTest.php`) — zaključak: preduslovno/okruženje-nivo
   upozorenje (verovatno PHP 8.5 deprecation notice iz nekog paketa), nije
   izazvano ovim izmenama, ne utiče na prolaznost testova.

### Rezultati

- `php artisan test`: **58 passed (168 assertions)** — 0 failures (1
  preduslovno, nepovezano upozorenje bez detalja)
- `vendor/bin/pint`: prošao
- `vendor/bin/phpstan analyse` (level 7): 0 errors

**Live poziv i dalje NIJE izvršen.** Čeka posebno, eksplicitno odobrenje.

---

## 2026-07-30/31 — Prvi commit i push Milestone 1 koda

`git commit` + `git push` na `milestone/1-etoro-api-spike` (commit `87987db`)
— kod, testovi i dokumentacija od prethodnih koraka. `main` nije diran.

---

## 2026-07-31 — Run #1: prvi živi capability test

Nakon eksplicitnog odobrenja, pre-provere (grana, working tree, dry-run,
`ETORO_STORE_RAW_RESPONSES` status) i tačno jedan poziv:

```bash
php artisan etoro:doctor --live   # bez --capture-raw
```

Rezultat: 6/7 proba `works`/`available` (HTTP 200), bez 401/403/404/429/5xx,
bez rate-limit header-a. Proba #5 (Trader live portfolio) —
`temporarily_unavailable`, transport (konekciona) greška nakon ugrađenog
bounded retry-ja. Nijedan raw fajl napravljen; nijedan `Authorization`
header poslat; log fajl nepromenjen tokom poziva (provereno timestamp-om i
grep-om). `git status` čist.

---

## 2026-07-31 — Korekcije pred ciljanu probu: `--only`, sanitizovana transportna dijagnostika

Pre ciljane probe probe #5, primenjeno 6 korekcija:

1. `docs/ETORO_API_CAPABILITIES.md` — kreiran, Run #1 rezultati (bez request
   ID-jeva/identiteta/vrednosti).
2. `--only=<capability>` opcija na `etoro:doctor` — allowlist (`me`,
   `rankings`, `profile`, `performance`, `live-portfolio`, `real-pnl`,
   `demo-pnl`); username-zavisne probe pozivaju `rankings` samo kao
   dependency kad `--username` nije dat.
3. `EtoroClient::diagnoseTransportFailure()` — normalizuje
   `ConnectionException` u jednu od 6 kategorija koristeći isključivo curl
   `errno`/`connect_time`, nikad originalnu poruku/URL/payload; fallback na
   `unknown_transport_failure` kad uzrok nije pouzdano odredljiv.
4. Testovi: `--only` scenariji (sa/bez `--username`, nevažeća vrednost),
   kategorizacija transportnih grešaka (dataset po errno), bez leak-a
   originalne poruke.
5. `php artisan test` (70 passed), `vendor/bin/pint`, `vendor/bin/phpstan
   analyse` (1 greška pronađena i ispravljena: neiscrpan `match` u
   `probeFor()` — dodat `default => throw new LogicException(...)`).
6. Ciljana proba `php artisan etoro:doctor --live --only=live-portfolio`
   (bez `--capture-raw`) — komanda je trajala >60s (premešteno u background,
   sačekan prirodni završetak, bez ponavljanja/izmene).

Vidi DECISIONS.md D-015.

---

## 2026-07-31 — Run #2: ciljana proba live-portfolio

Rezultat: **HTTP 200** za `Trader live portfolio` (rankings dependency 200,
~42s; live-portfolio 200, ~21s — oba neuobičajeno spora u odnosu na Run #1,
ukazuje na opšte usporenje mreže/eToro API-ja u tom trenutku, ne problem
specifičan za endpoint). Struktura odgovora: `realizedCreditPct,
unrealizedCreditPct, positions[](259), socialTrades[](0)`. Samo 2 zahteva
poslata (rankings + live-portfolio); ništa drugo. Nijedan raw fajl; nijedan
`Authorization` header. `git status` nepromenjen (samo kod od prethodnog
koraka, i dalje bez commit-a).

---

## 2026-07-31 — Milestone 1: kapabilnost dokumentovana, retry dijagnostika, double opt-in raw capture

Vlasnik projekta je prihvatio Run #2 i tražio finalni checkpoint pre
selektivnog raw capture-a. Detaljno obrazloženje: DECISIONS.md D-016.

### Komande

```bash
php artisan test --compact       # 78 passed, 237 assertions
vendor/bin/pint --format agent   # passed
vendor/bin/phpstan analyse       # 0 errors
git check-ignore -v storage/app/private/etoro/raw/example.json   # exit 0, potvrđeno
```

### Izmenjeni/novi fajlovi

- `docs/ETORO_API_CAPABILITIES.md` — Run #1 i Run #2, zaključak `works` za
  live-portfolio, bez request ID-jeva/username-a/vrednosti
- `app/Etoro/EtoroApiResponse.php` — `attemptCount`, `totalDurationMs`,
  `finalAttemptDurationMs` (zamenjuju stari `durationMs`)
- `app/Etoro/Exceptions/EtoroRequestException.php` — isti trojac dodat
- `app/Etoro/EtoroClient.php` — mereno po pokušaju unutar retry petlje
- `app/Console/Commands/EtoroDoctorCommand.php` — tri nove kolone
  (`Attempts`, `Total Duration`, `Final Attempt Duration`), napomena
  `recovered_after_retry` za uspeh posle retry-ja, double opt-in gate za
  `--capture-raw` (config `store_raw_responses` I flag zajedno)
- `tests/Feature/Etoro/EtoroClientTest.php`,
  `tests/Feature/Console/EtoroDoctorCommandTest.php` — retry dijagnostika,
  4 kombinacije double opt-in, `recovered_after_retry` prikaz
- `tests/Feature/Etoro/RawStorageGitignoreTest.php` (novo) — autoritativna
  `git check-ignore` provera

### Greške i rešenja tokom rada

1. `Http::fake()` ne evidentira pokušaje čiji fake closure baci exception
   (samo stvarne odgovore) — `assertSentCount(3)` je pao za
   timeout→503→200 scenario. Rešeno hvatanjem `x-request-id` direktno iz
   closure-a (poziva se na sva 3 pokušaja) umesto iz `Http::recorded()`.
2. Pokušaj dodavanja `storage/app/private/etoro/.gitignore` radi
   eksplicitne dokumentacije — otkriveno da git ne silazi u već isključen
   (`*`) direktorijum, pa taj fajl ne bi ni bio trackovan; uklonjen,
   zadržan samo postojeći roditeljski blanket rule + autoritativan test.

### Rezultati

- `php artisan test`: **78 passed (237 assertions)** — 0 failures (1
  preduslovno, nepovezano upozorenje, kao i pre)
- `vendor/bin/pint`: prošao
- `vendor/bin/phpstan analyse` (level 7): 0 errors

**Nijedan novi live API poziv nije izvršen. Bez commit-a, bez push-a, bez
fixtures.** Sledeći korak (kad se odobri): selektivni raw capture isključivo
javnih trader podataka (rankings, public profile, performance history, live
portfolio) — bez `/me` i bez Real/Demo P&L payload-a.

---

## 2026-07-31 — Selektivni raw capture: četiri javna dataset-a

Nakon eksplicitnog odobrenja, izvršena tačno četiri ciljana live poziva
(`--only=rankings`, `--only=profile`, `--only=performance`,
`--only=live-portfolio`, svaki uz `--capture-raw`) za isti test-trader,
biran iz rankings odgovora (username nikad ispisan ni zapisan u
dokumentaciju). `/me`, Real P&L i Demo P&L **nisu pozivani**. Svi raw
odgovori sačuvani isključivo u `storage/app/private/etoro/raw/`
(git-ignorisano, potvrđeno `git check-ignore`). Rezultati: sva četiri
poziva HTTP 200 (`works`).

---

## 2026-07-31 — Privatna analiza šeme

Lokalna, ne-committed analiza sva četiri raw fajla: kompletan schema
inventory (nazivi polja, tipovi, nullability, brojevi zapisa/pozicija),
cross-file relacije, i plan sanitizacije. Sve zapisano u
`storage/app/private/etoro/analysis/` (git-ignorisano).

**Ključni nalaz:** live-portfolio odgovor sadrži 259 zapisa pozicija koje
se svode na samo 14 jedinstvenih instrumenata (svaki instrument ima više
od jedne pozicije); zbir `investmentPct` vrednosti svih pozicija iznosi
približno 100 (procentne poene, ne 0–1 razlomak). Nikakve identifikacione
vrednosti (username, ID-jevi, tačni iznosi) nisu zapisane ni u ovom
worklog-u ni bilo gde van privatnih, git-ignorisanih fajlova.

---

## 2026-07-31 — Generisanje sintetičkih candidate fixture-a

Na osnovu privatne analize šeme, generisana četiri **potpuno sintetička**
candidate fixture-a (rankings, profile, performance-history,
live-portfolio) — deterministički placeholder-i (npr. `trader_001`,
`instrument_NNN`, `position_NNN`), sintetičke vrednosti, sintetički
datumi. Fixtures reprodukuju posmatranu API šemu ali ne predstavljaju
stvarnog tradera niti njegov stvarni portfolio. Sanitizacioni manifest
napisan uz svaki fixture, dokumentujući koje polje je zamenjeno, kojom
placeholder porodicom, i koja polja ostaju nerazjašnjena (npr.
`avgPosSize`/`optimalCopyPosSize`).

---

## 2026-07-31 — Leakage scan i korekcije candidate fixture-a

Automatski leakage scan candidate fixture-a protiv sva četiri raw fajla.
Prvi prolaz otkrio je koliziju: sintetički datumi (izvorno budući,
2029–2032) slučajno su se poklopili sa stvarnim opsegom performance
podataka. Ispravljeno pomeranjem na fiksni prošli interval (2010–2012),
strogo pre stvarnog opsega — ponovni scan: **PASS**, 0 kolizija. Dodatne
korekcije nakon internog pregleda: tačne granične `investmentPct`
vrednosti za testiranje minimalne copied-position granice, ispravljena
buy/sell take-profit/stop-loss semantika, i cross-file timeline
usklađivanje (`firstActivity` sintetičkog naloga ≤ prvi performance
period).

---

## 2026-07-31 — Premeštanje fixtures u Git; commit i PR #1

Nakon odobrenja, samo četiri JSON candidate fixture-a (preimenovana u
`rankings.json`, `public-profile.json`, `performance-history.json`,
`live-portfolio.json`) i novi `README.md` premešteni u
`tests/Fixtures/Etoro/`. Sanitizacioni manifest, leakage report i
copyability-hipoteza analiza **ostaju privatni** (git-ignorisano),
namerno nisu kopirani. Dodat `tests/Feature/Etoro/FixtureIntegrityTest.php`.

Commit `b1e350b` ("feat: add read-only eToro capability spike"), push na
`milestone/1-etoro-api-spike`, otvoren **PR #1** prema `main`
("Milestone 1: Add read-only eToro capability spike").

---

## 2026-07-31 — Prvi GitHub Actions failure: nedostaje tests/Unit

Prvi CI run na PR #1 vratio je exit code 2 — `phpunit.xml` deklariše
`tests/Unit` testsuite, ali direktorijum nije bio trackovan u Git-u (git
ne prati prazne direktorijume), pa na svežem checkout-u u CI-ju uopšte
nije postojao.

---

## 2026-07-31 — Code-review nalazi i korekcije (D-017)

Pet nalaza iz code review-a ispravljeno:

1. `tests/Unit/.gitkeep` dodat — rešava CI failure.
2. `composer.lock` usklađen sa `composer.json` (`composer update --lock`)
   — potvrđeno da nijedna verzija paketa nije promenjena, samo
   `content-hash`.
3. `etoro:doctor` sada vraća kontrolisan, smislen exit code: `--only`
   uspešan samo za `works`/`works_with_partial_data`; pun `--live` run
   neuspešan ako bilo koja izvršena (ne-skipped) capability ne uspe.
4. Prazan/whitespace-only `--username` sada vraća kontrolisanu validation
   grešku (bez HTTP poziva, bez neuhvaćenog `InvalidArgumentException`).
5. `ETORO_BASE_URL` mora biti validan apsolutni HTTPS URL pre slanja
   credential header-a — nevalidan ili non-HTTPS URL baca
   `EtoroConfigurationException` bez mrežnog poziva.

Dodato 10 novih offline testova. Vidi `docs/DECISIONS.md` D-017.

Commit `aa0f493c5be01e2a302795386c44782b0b5d82f8` ("fix: address milestone
1 review findings"), push na `milestone/1-etoro-api-spike`.

### Rezultati

- `php artisan test`: **101 passed (323 assertions)** — 0 failures
- `vendor/bin/pint`: prošao
- `vendor/bin/phpstan analyse` (level 7): 0 errors
- **GitHub Actions: zelen** nakon ovog push-a.

---

## 2026-08-06 — Grana `milestone/2-etoro-domain-model`: domain-model sloj završen, documentation closeout

Kroz sedam commit-ova na grani `milestone/2-etoro-domain-model` završen je
eToro domain-model implementacioni stream (Checkpoint A–E; razlika u odnosu
na product milestone numeraciju iz `PROJECT.md` dokumentovana u
`docs/DECISIONS.md` D-018):

1. `23837c3` feat: add exact analytics value objects (Checkpoint A) —
   `Money` (signed integer cents) i `Percentage` (signed parts-per-billion)
   value objects u `App\Analytics\ValueObjects`, sa arhitektonskim testom
   koji potvrđuje izolaciju `App\Analytics` od `App\Etoro`.
2. `081f69a` feat: add eToro live portfolio domain mapping (Checkpoint B) —
   `LivePortfolio`/`PortfolioPosition` DTO-i, `LivePortfolioMapper`,
   `Identifiers` helper, `EtoroMappingException`.
3. `491f0c7` feat: add exact copy coverage analytics (Checkpoint C) —
   `CopyCoverageCalculator` sa BCMath aritmetikom (exact arithmetic, bez
   binary floating point), i request/result DTO-i (`CopyCoverageRequest`,
   `CopyCoverageResult`, `CoverageTargetRequest`, `CoverageTargetResult`,
   `CoverageWarning`, `PositionAllocation`, `PositionCoverageOutcome`,
   `PositionSkipReason`).
4. `c77a2bc` feat: map eToro performance history (Checkpoint D1) —
   `PerformanceHistory`/`PerformancePoint` DTO-i i
   `PerformanceHistoryMapper`.
5. `ef1ebdf` feat: map eToro rankings (Checkpoint D2) —
   `RankingEntry`/`RankingPage`/`RankingPagination` DTO-i i
   `RankingsMapper`.
6. `f88535d` feat: map eToro trader profile (Checkpoint D3) —
   `TraderProfile` DTO i `TraderProfileMapper`.
7. `72894a9` feat: add eToro copy coverage adapter (Checkpoint E) —
   `LivePortfolioCoverageAdapter`, koji prevodi mapirani eToro live
   portfolio u Analytics request DTO-e; fixture-to-calculator pipeline test
   (JSON fixture → mapper → adapter → calculator).

Ključne napomene:

- `Money` koristi signed integer cents; `Percentage` koristi signed
  parts-per-billion.
- BCMath se koristi za exact calculator arithmetic.
- Mapirani su live portfolio, performance history, rankings i trader
  profile — isključivo nad sintetičkim fixture-ima iz `tests/Fixtures/Etoro/`
  (bez ijednog novog live eToro API poziva).
- Dodat je Etoro → Analytics adapter (`LivePortfolioCoverageAdapter`) —
  čista translacija, bez pozivanja calculator-a, bez sortiranja,
  filtriranja ili deduplikacije pozicija.
- Dodat je fixture pipeline: JSON → mapper → adapter → calculator,
  potvrđen na tri budžeta:
  - $200: 13 eligible / 3 skipped / 99.3% pokrivenosti;
  - $500: 15 eligible / 1 skipped / 99.9% pokrivenosti;
  - $1000: 16 eligible / 0 skipped / 100% pokrivenosti.
- Trenutni pun test suite: **689 testova / 1809 assertions / 0 failures**.
- `vendor/bin/phpstan analyse` (level 7): **0 errors**.
- Tokom Checkpoint A–E nije bilo novih live API poziva, raw capture-a,
  izmena fixture fajlova niti write operacija — sav rad zasnovan je na već
  postojećim sintetičkim fixture-ima iz Milestone 1.

**Application/use-case orchestration sloj (EtoroClient + mapperi + adapter
+ calculator povezani u jedan tok) NIJE implementiran u ovoj grani** — vidi
`docs/DECISIONS.md` D-019.

Grana je nakon ovog documentation closeout-a spremna za PR prema `main`.

---

## 2026-08-07 — Grana `feature/etoro-application-orchestration`: application orchestration sloj, documentation closeout

Kroz dva commit-a implementiran je application orchestration sloj iznad
postojećeg eToro domain-model sloja (D-018 dokumentuje razliku između
naziva grane/Checkpoint oznaka i product milestone numeracije):

1. `b5ed34f` feat: add application use case for evaluating trader copy
   coverage (Checkpoint A) — `App\Application\Etoro\EvaluateTraderCopyCoverage`
   i `EvaluateTraderCopyCoverageResult`. Use case ima tačno jedan logical
   eToro endpoint poziv (`EtoroClient::userLivePortfolio()`) i povezuje
   postojeći tok `LivePortfolioMapper → LivePortfolioCoverageAdapter →
   CopyCoverageCalculator`, bez sopstvene HTTP, mapping ili calculation
   logike. Arhitektonski test (`EtoroApplicationArchitectureTest`) potvrđuje
   dependency smer (`App\Application` → `App\Etoro`/`App\Analytics`, nikad
   obrnuto) i da se poziva isključivo `userLivePortfolio()`.
2. `a053f99` feat: add etoro copy coverage console command (Checkpoint B) —
   `php artisan etoro:copy-coverage <trader-username> <copy-amount-cents>
   <minimum-position-cents>`; tanki presentation sloj koji zavisi isključivo
   od `EvaluateTraderCopyCoverage`, prima money kao integer cente (bez float
   konverzije bilo gde), formatira covered percentage exact iz
   parts-per-billion, i prikazuje operational greške sanitizovano.
   Arhitektonski test (`EtoroCopyCoverageCommandArchitectureTest`) potvrđuje
   da komanda ne referencira `EtoroClient`/mapper/adapter/calculator direktno
   i da nema `--details` opciju.

Ključne napomene:

- Produkcija: kad se `EvaluateTraderCopyCoverage` stvarno izvrši (npr. kroz
  `etoro:copy-coverage` komandu), koristi postojeći
  `EtoroClient::userLivePortfolio()` endpoint — nije uveden novi endpoint.
  CLI poziva taj use case direktno.
- Testovi/razvoj: tokom implementacije i review-a ovog stream-a nije
  izvršen novi live capture/probe. Testovi oba checkpoint-a rade nad
  postojećim sintetičkim `tests/Fixtures/Etoro/live-portfolio.json`
  fixture-om i `Http::fake()`/mockovanim `EtoroClient` odgovorima; fixture
  fajlovi nisu menjani.
- Nema persistence, migracija, Eloquent modela, web/API ruta ili
  Filament/Livewire UI-ja uvedenih u ovom stream-u.
- Komanda ima tačno jedan integracioni test koji pokreće pun pipeline kroz
  `Http::fake()` (fixture → mapper → adapter → calculator → CLI izlaz), plus
  scenariji za validacione/operativne greške i data-quality warning prikaz
  (`unmodeled_portfolio_entries_present`).
- Dodate odluke D-020 (Application orchestration boundary) i D-021 (CLI
  money and presentation boundary) u `docs/DECISIONS.md`.

Finalna verifikacija (posle oba checkpoint-a, pre documentation closeout-a):

```bash
php artisan test --compact       # 738 passed, 2118 assertions
vendor/bin/pint --test           # passed
vendor/bin/phpstan analyse       # 0 errors
```

Grana je nakon ovog documentation closeout-a spremna za PR prema `main`; PR
još nije otvoren.

---

## 2026-08-10 — Grana `feature/etoro-target-coverage`: target-copy coverage application + CLI, documentation closeout

Kroz dva commit-a implementiran je target-copy coverage application use case
i njegov CLI konzument, iznad postojećeg eToro domain-model sloja (base
`origin/main` pre ovog stream-a: `b2e1c3d`, "feat: add eToro application
orchestration and copy coverage CLI", PR #3):

1. `e59a756` feat: add application use case for target copy coverage
   (Checkpoint A) —
   `App\Application\Etoro\FindTraderMinimumCopyAmountForCoverage` i
   `FindTraderMinimumCopyAmountForCoverageResult`. Use case ima tačno jedan
   logical eToro endpoint poziv (`EtoroClient::userLivePortfolio()`) i
   povezuje postojeći tok `LivePortfolioMapper →
   LivePortfolioCoverageAdapter::toCoverageTargetRequest() →
   CopyCoverageCalculator::minimumAmountForCoverage()`, bez sopstvene HTTP,
   mapping ili calculation logike — isti dependency-direction obrazac kao
   `EvaluateTraderCopyCoverage` (D-020).
2. `37460f9` feat: add target copy coverage console command (Checkpoint B) —
   `php artisan etoro:copy-target <trader-username>
   <target-coverage-percent> <minimum-position-cents>
   <platform-minimum-copy-cents>`; tanki presentation sloj koji zavisi
   isključivo od `FindTraderMinimumCopyAmountForCoverage`.

Ključne napomene:

- **Application capability:** `FindTraderMinimumCopyAmountForCoverage`
  prosleđuje `Percentage targetCoverage`, `Money minimumPositionAmount` i
  `Money platformMinimumCopyAmount` do
  `CopyCoverageCalculator::minimumAmountForCoverage()` preko
  `LivePortfolioCoverageAdapter::toCoverageTargetRequest()`; ne dodaje
  sopstvenu domain/calculation logiku (isti obrazac kao D-020).
- **CLI capability i percentage-points semantika:**
  `target-coverage-percent` je human-facing percentage-points decimalni
  string (`95` = 95%, `95.5` = 95.5%, `0.05` = 0.05% — NIKAD `0.05` = 5%),
  parsiran exact/string-only (regex `^\d{1,3}(?:\.\d{1,7})?$` + BCMath
  kompozicija u PPB, bez float-a), rezolucija do 7 decimalnih mesta,
  validan opseg strogo `> 0` i `<= 100`. Detaljan ugovor u
  `docs/DECISIONS.md` D-023.
- **Target semantics:** target coverage je relativan prema
  `positiveObservedWeight` (zbir strogo pozitivnih weight-ova), ne prema
  nominalnom 100% ukupnom weight-u; negativne pozicije se izuzimaju iz
  denominator-a i postavljaju `hasIncompleteSourceData=true`; nulte pozicije
  se izuzimaju iz denominator-a ali ne postavljaju taj flag; odsustvo
  pozitivnih pozicija samo po sebi ne postavlja `hasIncompleteSourceData`.
  Ovo ponašanje potiče iz `CopyCoverageCalculator` (Checkpoint C grane
  `milestone/2-etoro-domain-model`, već mergovano u `main` kao PR #2) i nije
  menjano ovim stream-om — prvi put je učinjeno application/CLI-dostupnim.
  Formalizovano u `docs/DECISIONS.md` D-022.
- Produkcija: kad se `FindTraderMinimumCopyAmountForCoverage` stvarno
  izvrši (npr. kroz `etoro:copy-target` komandu), koristi postojeći
  `EtoroClient::userLivePortfolio()` endpoint — nije uveden novi endpoint.
- Testovi/razvoj: tokom implementacije i review-a ovog stream-a nije
  izvršen novi live capture/probe. Testovi oba checkpoint-a koji prolaze
  kroz HTTP/application pipeline koriste postojeći sintetički
  `tests/Fixtures/Etoro/live-portfolio.json` fixture kroz `Http::fake()`
  ili mockovan `EtoroClient`; fixture fajlovi nisu menjani. (Ovo se odnosi
  na testove koji stvarno prolaze kroz taj pipeline — arhitektonski/source
  testovi u ovom stream-u ne zahtevaju fixture jer refleksijom proveravaju
  oblik klasa, ne runtime ponašanje.)
- Nema persistence, migracija, Eloquent modela, web/API ruta ili
  Filament/Livewire UI-ja uvedenih u ovom stream-u.
- Dodate odluke D-022 (Target coverage semantics — `positiveObservedWeight`)
  i D-023 (CLI `target-coverage-percent` input contract) u
  `docs/DECISIONS.md`.

Finalna verifikacija (posle oba checkpoint-a, pre documentation closeout-a):

```bash
php artisan test --compact       # 819 passed, 2504 assertions
vendor/bin/pint --test           # passed
vendor/bin/phpstan analyse       # 0 errors
```

Grana je nakon ovog documentation closeout-a spremna za PR prema `main`; PR
još nije otvoren.

---

## 2026-08-21 — Grana `feature/trader-ranking-import`: Checkpoint A–C implementacija, Checkpoint D documentation closeout

Baza grane: `144f1da` ("feat: add eToro target copy coverage", potvrđeno
mergovano u `main` — vidi `docs/REVIEW_STATUS.md` istorijsku sekciju "eToro
target-copy coverage sloj"). Tri implementaciona commit-a:

1. `f22bde8` feat: add trader persistence foundation (Checkpoint A) —
   `traders`/`import_runs` migracije, `Trader`/`ImportRun` Eloquent modeli,
   `TraderStatus`/`ImportRunStatus` enum-i, factory-ji. Vidi
   `docs/DECISIONS.md` D-024.
2. `c6c7580` feat: import eToro ranking pages (Checkpoint B) —
   `App\Application\Imports\ImportRankingPage`, idempotentni persistence
   use case sa in-page identity ambiguity rezolucijom, driver-specifičnim
   (MySQL collation-aware / SQLite byte-exact) equality putevima, i
   transakcionim rollback-om na neočekivan failure. Vidi
   `docs/DECISIONS.md` D-025.
3. `d739e4c` feat: add fixture-only ranking page import command
   (Checkpoint C) — `App\Etoro\FixtureSources\RankingFixtureSource`,
   `App\Application\Imports\ImportRankingPageFromFixture`,
   `App\Console\Commands\EtoroImportRankingPageCommand`
   (`etoro:import-ranking-page {period}`). Kanonski fixture premešten na
   `resources/fixtures/etoro/rankings.json`. Vidi `docs/DECISIONS.md`
   D-026.

Checkpoint C je prošao kroz dva review kruga (v1 nalazi: development-only
fail-closed environment guard, tanka CLI exception granica, input/metadata
ugovor test, git index cleanup; v2 korekcije primenjene, testirane i
push-ovane) pre push-a na `origin/feature/trader-ranking-import` na commit
`d739e4c` (`git push -u origin feature/trader-ranking-import`, bez force-a,
bez taga, bez PR-a).

**Checkpoint D je documentation-only closeout** (ovaj unos) — ne dodaje
niti menja produkcijski/testni/config/database kod, fixture JSON, API-je,
command behavior, scheduler/queue/UI/search niti generički framework.
Menja isključivo `PROJECT.md` §9, `docs/REVIEW_STATUS.md`,
`docs/DECISIONS.md` (D-024–D-026), i ovaj unos u `docs/WORKLOG.md`.

### Arhitektonske/security granice (A–C, potvrđeno kodom i testovima)

- Manualni CLI je local/testing-only (environment guard proveren PRE
  fixture I/O-a i DB upisa) — sintetički podaci ne mogu dospeti u realnu
  bazu.
- CLI nema direktnu zavisnost ni od jednog `App\Etoro` exception tipa;
  jedan `catch (Throwable)`, jedna statična sanitizovana poruka.
- `EtoroClient` ostaje potpuno nepromenjen i nije referenciran nigde u
  ovom lancu (arhitektonski testovi to dokazuju strukturno).
- Nijedan novi live eToro poziv nije izvršen tokom cele grane (A–D); jedini
  fixture je potpuno sintetički, iz Milestone 1 spike-a, sada premešten
  (ne dupliran).

### Finalna nezavisno potvrđena verifikacija (pre Checkpoint D izmena dokumentacije)

```bash
php artisan test --compact       # 916 total, 912 passed, 0 failed, 4 skipped, 3017 assertions
vendor/bin/pint --test           # passed
vendor/bin/phpstan analyse       # 0 errors
```

Četiri skipped testa su izolovani MySQL collation testovi
(`tests/Feature/Application/Imports/ImportRankingPageMySqlCollationTest.php`)
— **nisu izvršeni** bez dedikovane `MYSQL_COLLATION_TEST_*` konekcije;
development baza `trade_ledger` nikad korišćena tokom cele grane.

### Review artefakti

`checkpoint-c-manual-ranking-import-v1.{patch,zip}` i
`checkpoint-c-manual-ranking-import-v2.{patch,zip}` su git-ignorisani
(`.gitignore` `/*.patch`, `/*.zip`) i nikad nisu ušli u git istoriju —
služili su isključivo za review pre commit-a. Finalni v2 je pregledan i
odobren pre commit-a `d739e4c`.

### Remote stanje

`origin/feature/trader-ranking-import` potvrđen na `d739e4c`
(`git ls-remote --heads origin refs/heads/feature/trader-ranking-import`).
Pull request prema `main` **NIJE otvoren**; grana **NIJE mergovana**.

---
