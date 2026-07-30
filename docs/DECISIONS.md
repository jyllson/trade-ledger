# DECISIONS — TradeLedger

Zapis važnih arhitektonskih i implementacionih odluka.

---

## D-001: MySQL 9.7.1 za lokalni development, ciljana kompatibilnost MySQL 8.4+

**Datum:** 2026-07-30
**Status:** odobreno od vlasnika projekta

**Kontekst:** PROJECT.md navodi MySQL 8. Na razvojnoj mašini je već instaliran
i pokrenut MySQL 9.7.1 (Yerd). Instalacija paralelne MySQL 8 instance uvela bi
nepotrebnu složenost.

**Odluka:**

- MySQL 9.7.1 se koristi za lokalni development.
- Minimalni ciljani nivo SQL kompatibilnosti aplikacije je **MySQL 8.4+**.
- Ne koriste se funkcije, tipovi, sintaksa ili konfiguracija specifični samo za
  MySQL 9.7 bez prethodnog obrazloženja i odobrenja vlasnika projekta.
- Standardne Laravel migracije, Eloquent i Query Builder imaju prednost nad
  ručnim vendor-specific SQL-om.

**Razmatrane alternative:** instalacija MySQL 8 (odbijeno — dodatna instanca
bez praktične koristi; Laravel sloj apstrahuje razlike).

---

## D-002: Naziv lokalne baze `trade_ledger`

**Datum:** 2026-07-30
**Status:** odobreno od vlasnika projekta

Naziv projekta je TradeLedger (radni repo `trade-ledger`), pa `.env.example`
koristi `DB_DATABASE=trade_ledger`, a ne naziv direktorijuma `tradelytics`.

---

## D-003: Laravel Boost se ne reinstalira

**Datum:** 2026-07-30

`laravel/boost` v2.4.13 je već prisutan u `require-dev`, a `boost:install` je
već izvršen (postoje `boost.json`, `.mcp.json` i Boost guidelines u
`CLAUDE.md`). Ponovna instalacija bi bila no-op sa rizikom prepisivanja
postojeće konfiguracije. Livewire 4 i Pest 5 su takođe već obezbeđeni starter
kitom i nisu zahtevani drugi put.

---

## D-004: Fail-closed read-only zaštita (`EtoroWriteGuard`)

**Datum:** 2026-07-30
**Status:** zahtev vlasnika projekta

**Kontekst:** PROJECT.md §2 i §17 — aplikacija je read-only by design;
`ETORO_ALLOW_WRITE` mora podrazumevano biti `false`, a produkcija mora odbiti
pokretanje trading servisa ako flag nije false tokom MVP-a.

**Odluka:** `App\Etoro\EtoroWriteGuard`:

- `allowsWrite()` u MVP-u **uvek vraća `false`**, nezavisno od konfiguracije
  (hard-coded fail-closed; write režim se ne može uključiti env promenljivom).
- `ensureReadOnly()` baca `EtoroWriteModeNotAllowedException` ako je
  `etoro.allow_write` konfigurisan na `true` — poziva se pri boot-u aplikacije
  (`AppServiceProvider::boot()`), tako da pogrešna konfiguracija obara
  aplikaciju umesto da tiho prođe.

**Razmatrane alternative:** samo prikaz `config('etoro.allow_write')` na
dashboardu (odbijeno — nije zaštita); provera samo u produkcijskom okruženju
(odbijeno — fail-closed treba da važi svuda tokom MVP-a).

---

## D-005: Arch test ograničen na eToro namespace

**Datum:** 2026-07-30
**Status:** zahtev vlasnika projekta

Test koji dokazuje odsustvo write metoda ne skenira ceo `app/` (aplikacija
legitimno sme da ima sopstvene POST/PUT/DELETE operacije), već refleksijom
proverava isključivo klase u `App\Etoro` namespace-u protiv eksplicitne liste
zabranjenih naziva metoda (`post`, `put`, `patch`, `delete`, `executeOrder`,
`startCopying`, `stopCopying`, `openPosition`, `closePosition`, `deposit`,
`withdraw`, `transfer`...). Grep po izvornom kodu je odbijen kao krhak
(false positives).

---

## D-006: Bez `pest-plugin-livewire`; widget testovi kroz `Livewire::test()`

**Datum:** 2026-07-30

Helper `Pest\Livewire\livewire()` zahteva dodatni paket `pestphp/pest-plugin-livewire`.
Pošto važi pravilo „bez novih paketa bez odobrenja", a `livewire/livewire` već
nudi ekvivalentan `Livewire::test()`, testovi koriste ugrađeni API. Plugin se
može dodati kasnije ako se pokaže potreban.

---

## D-007: `ReadOnlyModeWidget` nije lazy; `User` implementira `FilamentUser`

**Datum:** 2026-07-30

- Filament widgeti se podrazumevano učitavaju lazy; bezbednosni baner
  read-only režima mora biti vidljiv odmah u inicijalnom HTML-u, pa je
  `$isLazy = false` (ovo omogućava i pouzdan `assertSee` test dashboarda).
- `App\Models\User` implementira `Filament\Models\Contracts\FilamentUser` sa
  `canAccessPanel(): true` — single-user aplikacija bez javne registracije;
  bez ovog ugovora Filament u produkciji odbija sve korisnike (403).

---

## D-008: eToro ključevi nisu per-environment; `ETORO_ENVIRONMENT` bira samo endpoint

**Datum:** 2026-07-30
**Status:** korekcija pretpostavke iz PROJECT.md, na osnovu stvarnog UI-ja

**Kontekst:** Javna eToro dokumentacija još uvek opisuje poseban Demo/Real
izbor pri generisanju API ključa. Stvarni, trenutni Key Management UI to ne
nudi — **trenutni UI odstupa od javne dokumentacije**.

**Utvrđeno stanje (bez vrednosti ključeva):**

- Ključ se kreira za izabrani Account („Main Account“), bez Demo/Real izbora.
- Dozvole se biraju pojedinačno; ključ u upotrebi ima 16 read dozvola.
- „Trading – Real · Read“ je uključena; „Trading – Real · Write“ NIJE uključena
  i mora tako ostati.
- Mapiranje kredencijala: Public Key → `ETORO_API_KEY` → `x-api-key`;
  generisani Private Key → `ETORO_USER_KEY` → `x-user-key`.
- `ETORO_BEARER_TOKEN` ostaje prazan; `Authorization: Bearer` header se ne šalje.
  *(Prevaziđeno u D-009: promenljiva je potpuno uklonjena.)*

**Odluka:**

- `ETORO_ENVIRONMENT` se NE tretira kao osobina ili ograničenje ključa, već
  isključivo kao izbor account endpointa aplikacije:
  `real` → `/api/v1/trading/info/real/...`, `demo` → `/api/v1/trading/info/demo/...`.
  Lokalno je trenutno podešen `real`.
- Tokom Milestone 1 dostupnost OBA P&L endpointa se proverava read-only GET
  zahtevima i u capability report se upisuje stvarni rezultat za svaki
  (`real`/`demo`: available / forbidden / unavailable). Dostupnost se ne
  pretpostavlja iz dokumentacije niti iz naziva/načina generisanja ključa.

**Posledice:** PROJECT.md §3, §5, §8, §20 (Milestone 1) i §24 ažurirani
2026-07-30 u skladu sa ovim.

---

## D-009: Bearer autentikacija se ne implementira; `ETORO_BEARER_TOKEN` uklonjen

**Datum:** 2026-07-30
**Status:** zahtev vlasnika projekta; delimično prevazilazi D-008

**Odluka:**

- `ETORO_BEARER_TOKEN` je uklonjen iz `.env.example`, `config/etoro.php` i
  PROJECT.md — promenljiva više ne postoji.
- `Authorization: Bearer` header se ne implementira i ne šalje.
- Standardna autentikacija koristi isključivo headere: `x-api-key`,
  `x-user-key` i `x-request-id` (jedinstveni UUID po zahtevu).
- Bearer autentikacija se može dodati kasnije SAMO ako stvarni zvanični
  OpenAPI dokument za konkretan endpoint eksplicitno pokaže da je potrebna.
- Test `defines no bearer token configuration` garantuje da se promenljiva
  ne vrati slučajno.

`ETORO_ENVIRONMENT=demo` ostaje bezbedan default u `.env.example`; lokalni
`.env` vlasnika koristi `real` (lokalni `.env` se ne čita i ne menja).

---

## D-010: CI koristi SQLite umesto MySQL-a tokom Milestone 0

**Datum:** 2026-07-30
**Status:** korekcija posle code review-a Milestone 0

**Kontekst:** `.github/workflows/tests.yml` je pokretao `composer setup`, koji
izvršava `php artisan migrate --force`, dok `.env.example` konfiguriše MySQL.
Workflow nije imao MySQL servis, pa migracija u CI-ju nije imala dostupnu bazu.

**Odluka:**

- Setup Application korak u CI-ju sada kreira `database/database.sqlite` i
  eksplicitno postavlja `DB_CONNECTION=sqlite` i
  `DB_DATABASE=database/database.sqlite` kao environment varijable samo za taj
  korak — ne menja `.env.example` niti lokalni `.env`.
- Poseban MySQL 8.4 integration job se ne dodaje u Milestone 0 — ostavljen za
  kasnije, kada aplikacija dobije sopstvene migracije (trader/portfolio
  tabele) čije bi ponašanje moglo zavisiti od MySQL-specifičnih detalja.

**Razmatrane alternative:** dodavanje MySQL service kontejnera u workflow već
u Milestone 0 (odbijeno — nema još migracija specifičnih za MySQL da bi to
opravdalo; SQLite je dovoljan za CI dok schema ne postoji).

---

## D-011: `WriteSurfaceTest` dozvoljava privatni `request()` helper

**Datum:** 2026-07-30
**Status:** korekcija posle code review-a Milestone 0

**Kontekst:** Test je zabranjivao metode `request` i `send` bez obzira na
vidljivost (visibility). PROJECT.md §10 zabranjuje **javni generički**
request API ("expose typed read methods rather than a public generic request
method"), ali ne zabranjuje privatni/protected infrastrukturni helper koji
`EtoroClient` može interno koristiti za slanje GET zahteva.

**Odluka:**

- Metode zabranjene bez obzira na vidljivost: eksplicitne write/trading
  operacije (`post`, `put`, `patch`, `delete`, `executeOrder`,
  `startCopying`, `deposit`, `withdraw`, `transfer`, ...).
- Metode zabranjene samo kada su **public**: `request`, `send` — privatni
  read-only helper istog imena je dozvoljen.
- Ovaj test i dalje samo proverava nazive i vidljivost metoda refleksijom;
  ne dokazuje da klijent stvarno šalje samo GET zahteve.
- Stvarno read-only ponašanje (isključivo GET) dokazuje se tek u Milestone 1
  kroz `Http::fake()` testove nad implementiranim `EtoroClient`-om.

---

## D-012: Composer package identity, cleanup i podrazumevano `ETORO_ENABLED=false`

**Datum:** 2026-07-30
**Status:** korekcija posle code review-a Milestone 0

- `composer.json` `name` promenjen sa `laravel/blank-livewire-starter-kit` na
  `jyllson/trade-ledger`; ažurirani `description` i `keywords`.
- Obrisani starter fajlovi `tests/Feature/ExampleTest.php`,
  `tests/Unit/ExampleTest.php`, i primeri `something()` /
  `expect()->extend('toBeOne', ...)` iz `tests/Pest.php`.
- Dodat `README.md` sa lokalnim setupom i eksplicitnim upozorenjem da se API
  ključevi nikada ne commituju.
- `ETORO_ENABLED` podrazumevano `false` (u `config/etoro.php` i
  `.env.example`) — integracija ostaje isključena dok vlasnik projekta ručno
  ne uključi u lokalnom `.env` na početku Milestone 1.

---
