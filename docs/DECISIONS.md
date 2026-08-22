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

## D-013: Dokumentovana nekonzistentnost eToro dokumentacije o autentikaciji

**Datum:** 2026-07-30
**Status:** informativno, potvrđeno istraživanjem pred Milestone 1

Istraživanje zvanične eToro dokumentacije (`api-portal.etoro.com`,
`mcp.public-api.etoro.com/skill`) pred Milestone 1 potvrdilo je da je
dokumentacija interno nekonzistentna po pitanju autentikacije:

- Stranica `mcp.public-api.etoro.com/skill` i većina pojedinačnih
  endpoint-referenci (identity, rankings, user-info/people, gain, real/demo
  pnl) eksplicitno kažu da se Bearer token ne koristi — samo `x-api-key` +
  `x-user-key` + `x-request-id`, sa scope-ovima vezanim za sam par ključeva.
- Stranica za `GET /api/v1/user-info/people/{username}/portfolio/live`
  eksplicitno navodi četvrti obavezan header: `Authorization: OAuth2 token`.

Ne pretpostavlja se koja je tačna — Milestone 1 testira empirijski (bez
`Authorization` header-a u prvom pokušaju; 401/eksplicitan bearer zahtev se
beleži kao `AuthenticationBlocked`, bez izmišljanja tokena). Takođe uočeno:
indeks dokumentacije (`llms.txt`) navodi rankings kao `GET /api/v1/rankings`
i P&L kao `/api/v1/trading/portfolio/details`, dok detaljne stranice (i
PROJECT.md §8) navode `GET /api/v2/portfolios/rankings` i
`/api/v1/trading/info/{real,demo}/pnl` — korišćene su ove druge, dokumentovanije
varijante.

MCP server `etoro-public-api` je registrovan u lokalnoj Claude Code
konfiguraciji (`claude mcp add --transport http etoro-public-api
https://mcp.public-api.etoro.com`) radi uvida u OpenAPI definiciju; ostaje
registrovan po eksplicitnom zahtevu vlasnika projekta, ne utiče na projekat.

---

## D-014: Pojednostavljenja Milestone 1 implementacije (korekcije vlasnika projekta)

**Datum:** 2026-07-30
**Status:** zahtev vlasnika projekta, primenjeno pre bilo kakvog živog poziva

1. **Samo tri exception klase** umesto po-status-koda: `EtoroConfigurationException`
   (nedostaje konfiguracija/onemogućeno), `EtoroRequestException` (sve
   HTTP 4xx/5xx i konekcione greške — nosi `category` enum
   `EtoroErrorCategory`, `httpStatus`, `requestId`, `retryAfterSeconds`,
   nikad payload/kredencijale), `EtoroUnexpectedResponseException` (2xx sa
   telom koje se ne dekodira kao očekivano).
2. **Bez specifičnih DTO polja pre live probe.** `EtoroClient`-ove typed
   metode vraćaju generički `EtoroApiResponse` (payload, status, requestId,
   durationMs, rateLimitHeaders) umesto `AuthenticatedUserData` i sličnih
   klasa iz prvobitnog plana — te DTO klase se prave tek nakon uvida u
   sanitizovane fixtures iz stvarnog odgovora, i samo ako ih šema opravda.
3. **`ETORO_STORE_RAW_RESPONSES` podrazumevano `false`** (config/etoro.php i
   .env.example). Čuvanje sirovih odgovora u `etoro:doctor --live` je
   isključivo opt-in preko `--capture-raw` flag-a (nezavisno od tog config
   ključa, koji ostaje opšta politika za buduće scheduled-import funkcije).
   Komanda ispisuje eksplicitno upozorenje o ličnim/finansijskim podacima kad
   je flag uključen.
4. **Sanitizovani fixtures se ne commituju automatski.** Posle live probe,
   kandidat fixtures i tačna redakciona mapa polja se prvo pokazuju vlasniku
   projekta na odobrenje, tek zatim idu u `tests/Fixtures/Etoro/`.
5. **Bez posebnog rate-limitera** za 7 proba — sekvencijalno izvršavanje uz
   pauzu od ~1s između poziva (`Illuminate\Support\Sleep`, testable putem
   `Sleep::fake()`); ako poslednji odgovor nosi `Retry-After`, pauza pre
   sledeće probe se produžava na tu vrednost (ograničeno na 60s radi
   predvidljivog trajanja komande).
6. **`--live` code path je pokriven testovima** kroz `Http::fake()` — vidi
   `tests/Feature/Console/EtoroDoctorCommandTest.php`: bez `--live` ništa se
   ne šalje; sa `--live` šalje se svih 7 proba; username se bira iz prvog
   `type === 'trader'` reda lažnog rankings odgovora; neuspešan rankings
   (500) preskače probe #3–5 ali oba P&L poziva se i dalje izvršavaju;
   nijedan `Authorization` header se ne šalje; senzitivne vrednosti
   (imena, username, balansi, kredencijali) se ne pojavljuju u terminalskom
   izlazu (samo nazivi polja/broj stavki); bez `--capture-raw` nijedan raw
   fajl se ne kreira; sa `--capture-raw` fajl se kreira isključivo na
   `local` disk-u (`storage/app/private`, već git-ignorisano).
7. MCP registracija (D-013) ostaje, projekat nije menjan zbog nje.

**Klasifikacija grešaka u `EtoroDoctorCommand`:** 401→`AuthenticationBlocked`,
404/400→`NotAvailable`, 429/5xx/konekcija→`TemporarilyUnavailable`, 2xx
uspeh→`Works`. Klasifikacija 403 je kontekstualna — vidi D-015 tačku 5.

---

## D-015: Korekcije nakon pregleda koda Milestone 1 (pre live poziva)

**Datum:** 2026-07-30
**Status:** zahtev vlasnika projekta, primenjeno pre bilo kakvog živog poziva

1. **Query za `usernames` ispravljen na scalar/comma-separated format.**
   Ponovo proveren zvanični OpenAPI za `GET /api/v1/user-info/people`:
   parametar `usernames` je dokumentovan kao `type: array, items: string,
   explode: false` — što se serijalizuje kao comma-separated lista
   (`usernames=a,b`). Za jedan username (jedini slučaj koji `userProfile()`
   podržava) to je bajt-identično prostom scalar-u. Prethodni kod je slao
   `['usernames' => [$username]]`, što Laravel kodira kao
   `usernames[0]=...` — ne odgovara dokumentovanom formatu. Ispravljeno na
   `['usernames' => $username]`; test sada proverava tačan query string
   (`usernames=demo_trader_one`), ne samo wildcard URL. Ako `userProfile()`
   ikad podrži više username-ova odjednom, vrednosti treba spojiti zarezom
   (`implode(',', $usernames)`), ne slati kao PHP array parametar.

2. **Username kao URL path segment je zaštićen.** `userPerformance()` i
   `userLivePortfolio()` sada: (a) odbijaju prazan/blank username kroz
   `InvalidArgumentException` (`assertUsernameProvided()`); (b) enkoduju
   username kroz `rawurlencode()` (`pathSegment()`) pre umetanja u path, tako
   da `/`, `?`, `#`, razmaci i slični karakteri ne mogu promeniti endpoint
   putanju (RFC 3986 percent-encoding — Guzzle/PSR-7 šalje već-enkodiran
   path bukvalno, ne dekodira `%2F` nazad u `/`). Test koristi username
   `'evil/user?x=1#frag with space'` i potvrđuje da je konačni URL tačno
   `.../people/evil%2Fuser%3Fx%3D1%23frag%20with%20space/gain`.

3. **Svež `x-request-id` po fizičkom pokušaju, ne po logičkom pozivu.**
   UUID se sada generiše unutar `for` petlje u `EtoroClient::get()`, pre
   svakog HTTP pokušaja (uključujući retry-je), umesto jednom pre petlje.
   `EtoroApiResponse` i `EtoroRequestException` nose `requestId` POSLEDNJEG
   stvarno izvršenog pokušaja. Test: dva uzastopna 503, treći 200 → tri
   poslata zahteva, sva tri `x-request-id` header-a međusobno različita, a
   `EtoroApiResponse::requestId` odgovara trećem (poslednjem).

4. **HTTP redirect-i su eksplicitno zabranjeni.** `EtoroClient::get()` šalje
   `->withOptions(['allow_redirects' => false])` na svaki zahtev. Odgovor sa
   statusom 3xx se klasifikuje kao `EtoroUnexpectedResponseException`
   (birana umesto uvođenja četvrte exception klase ili nove kategorije —
   jednostavnije, u skladu sa "najviše tri exception klase"), bez ikad
   čitanja/prikazivanja `Location` header vrednosti. Test: fake 302 ka
   `evil.example.com` — poslat tačno jedan zahtev (ka pravom eToro domenu, sa
   API ključevima), drugi domen nikad pozvan, poruka exception-a ne sadrži
   `evil.example.com`.

5. **Kontekstualna klasifikacija 403 u `EtoroDoctorCommand`.**
   `executeProbe()` sada prima `bool $accountLevel`: `true` za Authenticated
   profile, Investor rankings, Real/Demo P&L (403 →
   `requires_additional_scope`); `false` (default) za Public trader profile,
   Trader performance history, Trader live portfolio (403 →
   `private_or_visibility_dependent`, sa napomenom u "note" polju da
   nedovoljan scope još nije isključen dok live rezultat ne razjasni
   situaciju). Klasifikacija se zasniva isključivo na HTTP statusu i
   unapred poznatom kontekstu probe — nikad na analizi ili prikazu response
   payload-a.

6. **Sanitizovan rezultat sada prikazuje Request ID i dostupne rate-limit
   metapodatke.** `EtoroRequestException` prošren sa `rateLimitLimit`/
   `rateLimitRemaining` (pored postojećeg `retryAfterSeconds`), popunjeno iz
   response header-a na isti način kao kod uspešnog odgovora. Tabela u
   `EtoroDoctorCommand::renderResults()` dobija četiri nove kolone: `Request
   ID`, `RateLimit-Limit`, `RateLimit-Remaining`, `Retry-After` — svaka
   prikazuje `-` kad header ne postoji. Nikad se ne prikazuju credential
   vrednosti niti response payload vrednosti (i dalje samo nazivi polja za
   telo odgovora).

Testovi ažurirani u `tests/Feature/Etoro/EtoroClientTest.php` (query format,
path-segment enkodiranje, blank-username odbijanje, fresh request-id po
pokušaju, redirect handling) i
`tests/Feature/Console/EtoroDoctorCommandTest.php` (kontekstualna 403
klasifikacija, prikaz request ID-a i rate-limit metapodataka — uz pomoćnu
`callEtoroDoctor()` funkciju koja koristi eksplicitan `BufferedOutput` umesto
`Artisan::output()`, jer je potonji pisao u pravi STDOUT i izazivao PHPUnit
"risky: printed unexpected output" upozorenje).

**Napomena:** Test suite pokazuje jedno (1) upozorenje bez detalja
(`warning_details: []`) koje se reprodukuje čak i sa trivijalnim,
nepovezanim testom — potvrđeno da je preduslovno/okruženje-nivo, ne izazvano
ovim izmenama, i ne utiče na prolaznost (0 failures).

---

## D-016: Retry dijagnostika, double opt-in raw capture, potvrda .gitignore-a (nakon Run #2)

**Datum:** 2026-07-31
**Status:** zahtev vlasnika projekta, primenjeno pre selektivnog raw capture-a

### 1. Trader live portfolio → `works`

Nakon Run #2 (ciljana `--only=live-portfolio` proba, HTTP 200,
`realizedCreditPct/unrealizedCreditPct/positions[]/socialTrades[]`), endpoint
je potvrđen kao radna capability. Run #1 klasifikacija
(`temporarily_unavailable`) je bila privremena transportna smetnja, ne
sistemski problem — dokumentovano u `docs/ETORO_API_CAPABILITIES.md` sa oba
run-a i eksplicitnim zaključkom, bez request ID-jeva/identiteta/vrednosti.

### 2. Retry dijagnostika (`attemptCount`, `totalDurationMs`,
`finalAttemptDurationMs`)

`EtoroApiResponse` i `EtoroRequestException` nose broj fizičkih pokušaja i
trajanje (ukupno i samo poslednji pokušaj). `EtoroClient::get()` generiše
`x-request-id` i mери trajanje po pokušaju unutar `for` petlje;
`EtoroDoctorCommand` prikazuje tri nove kolone (`Attempts`, `Total
Duration`, `Final Attempt Duration`) i, kada uspešan odgovor stigne posle
više od jednog pokušaja, dodaje sanitizovanu napomenu `recovered_after_retry`
ispred postojeće šeme polja — nikad originalnu poruku ili URL.

**Nalaz tokom testiranja:** `Http::fake()` evidentira u `Http::recorded()`/
`assertSentCount()` samo pokušaje koji rezultuju stvarnim odgovorom — ako
fake closure baci exception (simulacija `ConnectionException`), taj pokušaj
se NE broji u `Http::recorded()`. Test koji simulira
timeout→503→200 zato hvata `x-request-id` direktno iz closure-a (koji se
izvršava na sva 3 poziva, bez obzira da li zatim baca ili vraća odgovor),
umesto da se osloni na `Http::recorded()`.

### 3. Double opt-in raw capture

Ranije je `--capture-raw` sam bio dovoljan da omogući čuvanje sirovih
odgovora (D-014). Sada su potrebna OBA uslova istovremeno:
`ETORO_STORE_RAW_RESPONSES=true` u konfiguraciji I `--capture-raw` flag.
Ponašanje:

- config `false` + bez flag-a → ništa se ne čuva (kao i pre);
- config `false` + flag → **kontrolisana greška** ("capture nije omogućen u
  konfiguraciji"), komanda se prekida PRE bilo kog mrežnog poziva — biran
  fail-loudly pristup umesto tihog ignorisanja, da korisnik ne pretpostavi
  da se capture dešava kad se ne dešava;
- config `true` + bez flag-a → ništa se ne čuva (flag je per-run okidač);
- config `true` + flag → čuvanje dozvoljeno, isključivo u
  `storage/app/private` (već git-ignorisano).

Provera se radi na dva mesta (u `handle()` pre pokretanja proba, i ponovo
unutar `executeProbe()` pre stvarnog upisa) — defense-in-depth, ne oslanja se
samo na raniju validaciju. Lokalni `.env` nije menjan ni čitan ni u ovom
koraku.

### 4. Potvrda `.gitignore` pokrivenosti i otkriven gotcha

`storage/app/private/etoro/raw/*` je već potpuno pokriven postojećim
`storage/app/private/.gitignore` (`*` + `!.gitignore`) — potvrđeno pomoću
`git check-ignore` (exit 0). **Pokušaj dodavanja eksplicitnog, ugnježdenog
`storage/app/private/etoro/.gitignore`** radi jasnoće je napravljen i zatim
**uklonjen** kada se ispostavilo da je git-ov poznati limit relevantan:
kada roditeljski `.gitignore` isključi čitav direktorijum (`*` isključuje i
sam `etoro/` direktorijum kao jedinicu), git ne silazi u njega da primeni
dodatne pravila iz ugnježdenog `.gitignore` fajla — taj fajl bi bio potpuno
neviljiv/netrackovan od strane git-a (paradoksalno, sam `.gitignore` fajl
biva ignorisan). Zaključak: blanket `*` na nivou `storage/app/private/` je
i dovoljan i jedini praktičan način; dodatni nested `.gitignore` fajlovi
unutar već isključenih direktorijuma ne rade. Dodat autoritativan test
(`tests/Feature/Etoro/RawStorageGitignoreTest.php`) koji poziva stvarni
`git check-ignore` da ovo trajno potvrdi.

---

## D-017: Code-review korekcije pred merge PR #1

**Datum:** 2026-07-31
**Status:** zahtev code review-a, primenjeno pre mergea

1. **Raw payload-i i privatni analitički dokumenti nikad ne idu u Git.**
   Sirovi API odgovori, schema-inventory/analysis dokumenti, sanitization
   manifest, leakage report i copyability-hipoteza analiza ostaju
   isključivo u `storage/app/private/etoro/` (git-ignorisano). Samo
   izričito odobreni, potpuno sintetički fixture JSON-i (i njihov README)
   se ikad premeštaju u trackovan direktorijum.

2. **Committed fixture-i moraju biti potpuno sintetički, ne samo
   redigovani.** Redakcija (zamena stvarnih vrednosti placeholder-ima uz
   zadržavanje ostatka payload-a) nije dovoljna — fixture mora biti
   hand-authored sintetički skup vrednosti koji reprodukuje šemu, ne
   transformisan stvaran odgovor, čak i kad su identifikacione vrednosti
   uklonjene.

3. **Documented-but-unobserved polja ne ulaze automatski u base fixture.**
   Ako zvanična OpenAPI šema dokumentuje polje koje stvarni live capture
   nikad nije vratio (npr. rankings `country`), to polje se ne dodaje u
   osnovni fixture samo zato što je dokumentovano — osnovni fixture prati
   stvarno posmatranu šemu. Testiranje dokumentovanog-ali-neposmatranog
   oblika radi se kroz eksplicitno označenu, zasebnu mutation varijantu.

4. **`etoro:doctor --live` vraća non-zero exit code kada izvršena
   capability ne uspe.** Ranije je `handle()` uvek vraćao `SUCCESS` posle
   `--live` run-a, bez obzira na klasifikaciju proba. Sada: `--only=<cap>`
   vraća `SUCCESS` samo za `works`/`works_with_partial_data`, a `FAILURE`
   za svaku drugu klasifikaciju (uključujući `skipped`); pun `--live` run
   vraća `FAILURE` ako bilo koja **izvršena** (ne-skipped) capability nije
   `works`/`works_with_partial_data`. Same klasifikacije i enum vrednosti
   nisu menjane — samo mapiranje klasifikacije u exit code.

5. **`ETORO_BASE_URL` mora biti validan apsolutni HTTPS URL pre slanja
   credential header-a.** `EtoroClient::ensureConfigured()` sada proverava
   da `parse_url()` uspešno vrati i `scheme === 'https'` i neprazan
   `host`; u suprotnom baca `EtoroConfigurationException` pre ijednog
   mrežnog poziva. Provera nije vezana za jedan hardkodovan hostname —
   budući demo/staging HTTPS endpoint ostaje konfigurabilan preko
   `ETORO_BASE_URL` bez izmene koda.

---

## D-018: Implementaciona nomenklatura — grana i Checkpoint oznake odvojene od product milestone numeracije

**Datum:** 2026-08-06
**Status:** dokumentacioni closeout, informativno

**Kontekst:** Git grana `milestone/2-etoro-domain-model` i review/delivery
oznake Checkpoint A–E korišćene tokom rada na njoj (u `docs/WORKLOG.md`)
lako se mogu pobrkati sa numerisanim product milestone-ima definisanim u
`PROJECT.md` §20 (Milestone 0–7).

**Odluka:**

- Naziv grane `milestone/2-etoro-domain-model` označava tehnički
  implementation stream (eToro domain-model sloj: exact value objects,
  eToro→domain mapperi, copy-coverage calculator, Etoro→Analytics
  adapter), ne product milestone broj 2 iz `PROJECT.md`.
- Checkpoint A–E su review/delivery jedinice korišćene tokom rada na ovoj
  grani: A — exact Analytics value objects; B — eToro live portfolio
  domain mapping; C — exact copy-coverage analytics; D1 — performance
  history mapping; D2 — rankings mapping; D3 — trader profile mapping;
  E — `LivePortfolioCoverageAdapter` i fixture pipeline.
- Ova nomenklatura je potpuno odvojena od product milestone numeracije u
  `PROJECT.md` §20 i ne menja, ne renumeriše niti reinterpretira postojeći
  roadmap. Vidi napomenu dodatu u `PROJECT.md` §20.

---

## D-019: Granica završenog domain-model sloja (pred PR milestone/2-etoro-domain-model)

**Datum:** 2026-08-06
**Status:** dokumentacioni closeout, potvrđuje postojeću arhitekturu (bez izmene koda)

**Kontekst:** Nakon Checkpoint E (commit `72894a9`) potrebno je eksplicitno
zapisati tačnu granicu odgovornosti između `App\Analytics`, `App\Etoro` i
budućeg application/use-case sloja, pre otvaranja PR-a prema `main`.

**Odluka:**

- `App\Analytics` ostaje nezavisan od `App\Etoro` — potvrđeno arhitektonskim
  testovima na ovoj grani.
- `App\Etoro` sme zavisiti od `App\Analytics` (npr. `Adapters` namespace).
- `LivePortfolioCoverageAdapter` isključivo prevodi mapirane eToro domain
  podatke (`LivePortfolio`, `PortfolioPosition`) u source-neutralne
  Analytics request DTO-e (`CopyCoverageRequest`, `CoverageTargetRequest`).
- Adapter ne poziva `CopyCoverageCalculator`.
- Adapter ne sortira, ne filtrira niti deduplikuje pozicije — svaka
  pozicija (uključujući nulte/negativne weight-ove i duplirane position
  ID-jeve) prolazi nepromenjena, u originalnom redosledu, tako da
  calculator-ova sopstvena detekcija data-quality upozorenja vidi pun,
  netaknut snapshot.
- Warnings i eligibility logika ostaju isključivo odgovornost
  `CopyCoverageCalculator`-a, ne adaptera.
- HTTP orkestracija, persistence, scheduling i UI ostaju van ove grane.
- Sledeći praktični korak je zaseban application/use-case sloj koji
  povezuje `EtoroClient`, mappere, adapter i calculator.

---

## D-020: Granica orchestration sloja `App\Application`

**Datum:** 2026-08-07
**Status:** dokumentovano nakon implementacije Checkpoint A, bez izmene koda

**Kontekst:** Checkpoint A (`b5ed34f`) dodao je
`App\Application\Etoro\EvaluateTraderCopyCoverage` — prvi use case koji
povezuje `EtoroClient`, `LivePortfolioMapper`, `LivePortfolioCoverageAdapter`
i `CopyCoverageCalculator` u jedan tok. Potrebno je eksplicitno zapisati
granicu odgovornosti ovog novog sloja pre otvaranja PR-a prema `main`.

**Odluka:**

- `App\Application` je orchestration sloj: povezuje postojeće `App\Etoro` i
  `App\Analytics` komponente u use case-ove, ne dodaje sopstvenu domain ili
  calculation logiku.
- `App\Application` sme zavisiti od `App\Etoro` i `App\Analytics` —
  potvrđeno arhitektonskim testom
  (`tests/Feature/Application/EtoroApplicationArchitectureTest.php`) da
  `App\Application\Etoro` ne referencira nijedan drugi `App\` namespace.
- `App\Etoro` i `App\Analytics` ne smeju zavisiti od `App\Application` —
  potvrđeno istim testom u obrnutom smeru.
- Presentation sloj (CLI, a kasnije eventualno Filament/Livewire/web) poziva
  `App\Application`, ne direktno `EtoroClient`/mapper/adapter/calculator.
- `EvaluateTraderCopyCoverage::handle()` ne pravi HTTP request detalje (bez
  Laravel HTTP klijenta, config/env, curl-a ili bilo kog transport poziva u
  samom use case-u) — to ostaje isključivo odgovornost `EtoroClient`-a.
- `EvaluateTraderCopyCoverage::handle()` ne mapira ručno payload — mapiranje
  ostaje isključiva odgovornost `LivePortfolioMapper`-a.
- `EvaluateTraderCopyCoverage::handle()` ne računa coverage ručno —
  kalkulacija ostaje isključiva odgovornost `CopyCoverageCalculator`-a.
- `LivePortfolioCoverageAdapter` ostaje prevodilac eToro domain podataka
  (`LivePortfolio`) u Analytics request DTO-e (`CopyCoverageRequest`) — use
  case ga poziva, ne dodaje mu logiku.
- `CopyCoverageCalculator` ostaje jedini vlasnik coverage logike i
  data-quality warning-a.
- Use case ima tačno jedan logical eToro endpoint poziv po pozivu
  (`userLivePortfolio()`); arhitektonski test potvrđuje da nijedan drugi
  `EtoroClient` metod (`authenticatedUser`, `rankings`, `userProfile`,
  `userPerformance`, `accountPnl`) nije pozvan iz
  `EvaluateTraderCopyCoverage`.
- Transport/mapping/calculation exception-i (`EtoroRequestException`,
  `EtoroMappingException`, `CoverageCalculationException`, itd.) se ne
  prevode u novu application-specific exception hijerarhiju — use case ih
  propušta nepromenjene pozivaocu (potvrđeno testom da je uhvaćena instanca
  identična bačenoj).
- Nema posebnog `EtoroClientInterface`/gateway apstrakcije uvedene ovim
  checkpoint-om — postojeći `EtoroClient` + `Http::fake()` u testovima već
  daju dovoljnu testability granicu za trenutne potrebe. Ovo se može
  revidirati kasnije ako broj use case-ova ili potreba za alternativnim
  implementacijama to opravda.
- Ova odluka ne uvodi persistence niti UI, i ne propisuje da ova tačna
  struktura mora nepromenjena važiti za svaki budući `App\Application` use
  case bez ponovnog razmatranja.

---

## D-021: CLI money i presentation granica (`etoro:copy-coverage`)

**Datum:** 2026-08-07
**Status:** dokumentovano nakon implementacije Checkpoint B, bez izmene koda

**Kontekst:** Checkpoint B (`a053f99`) dodao je prvi interni CLI entry point
za eToro copy coverage
(`php artisan etoro:copy-coverage <trader-username> <copy-amount-cents>
<minimum-position-cents>`). Potrebno je zapisati kako komanda tretira
money/percentage vrednosti i gde prestaje njena nadležnost.

**Odluka:**

- Komanda prima money isključivo kao integer cente (`copy-amount-cents`,
  `minimum-position-cents`) — nema decimal-string parsera u ovom
  checkpoint-u.
- Nema float/double konverzije bilo gde u komandi (`(float)`, `(double)`,
  `floatval()`, `doubleval()`) — potvrđeno arhitektonskim testom. Ulazni
  string se parsira regexom (`^-?\d+$`) i BCMath granicom (`bccomp` prema
  `PHP_INT_MAX`/`PHP_INT_MIN`) pre bilo kog cast-a u `int`.
- `Money` ostaje currency-neutral value object iz `App\Analytics` — komanda
  ne dodaje currency semantiku. Nema hard-kodovanog `$`/`€`/`USD`/`EUR`
  simbola bilo gde u izlazu; prikazani iznosi su formatirani samo kao
  decimalni broj + cent vrednost u zagradi (npr. `200.00 (20000 cents)`).
- Covered percentage se formatira exact iz parts-per-billion
  (`Percentage::partsPerBillion()`) bez float konverzije — samo string
  manipulacija (`substr`/`str_pad`/`rtrim`).
- Parsing, range i sintaksna validacija ulaznih argumenata (blank username,
  non-integer cents, integer overflow, negativan copy-amount, non-pozitivan
  minimum-position) je presentation-level fail-fast/UX zaštita ove komande.
  Za ove greške komanda vraća `FAILURE` pre bilo kakvog poziva use case-a —
  `EvaluateTraderCopyCoverage` se ne poziva i HTTP zahtev se ne šalje.
- Ova presentation-level provera sme proveravati isti constraint ranije (npr.
  blank trader-username se presreće u komandi pre use case poziva), ali ne
  uklanja, ne zamenjuje niti slabi odgovarajuću underlying/domain
  invarijantu. Underlying invarijanta ostaje authoritative za bilo koji poziv
  koji ne dolazi kroz ovu CLI komandu:
  - `EtoroClient` poseduje sopstvenu username invarijantu
    (`assertUsernameProvided`, blank username baca
    `InvalidArgumentException`);
  - `CopyCoverageRequest` poseduje sopstvene copy-amount (ne sme biti
    negativan) i minimum-position-amount (mora biti strogo pozitivan) money
    invarijante;
  - `Money` poseduje samo sopstveni value-object ugovor i ne uvodi copy/
    minimum-position business pravila.
- Komanda zavisi isključivo od `EvaluateTraderCopyCoverage` kao business
  dependency — nema direktne zavisnosti od `EtoroClient`,
  `LivePortfolioMapper`, `LivePortfolioCoverageAdapter` ili
  `CopyCoverageCalculator` (potvrđeno arhitektonskim testom).
- Komanda ne dira mrežu, config/env, Storage/DB/Queue niti Filament/Livewire
  sama — sve to ostaje u niže-slojnim komponentama koje
  `EvaluateTraderCopyCoverage` orkestrira.
- Komanda nema `--details` opciju u ovom checkpoint-u.
- Operational exception-i (`EtoroConfigurationException`,
  `EtoroRequestException`, `EtoroUnexpectedResponseException`,
  `EtoroMappingException`, `CoverageCalculationException`) se prikazuju
  sanitizovano — samo kategorija/status/request-id/transport-reason/errno
  kada postoje, nikad originalna transport poruka, stack trace ili payload;
  credential vrednosti se nikad ne pojavljuju u izlazu (potvrđeno
  testovima).
- Ova odluka ne predstavlja trajni javni UX ugovor za budući web UI —
  currency-aware formatter/prikaz može biti zasebna buduća odluka ako se
  pojavi stvarna potreba (npr. multi-currency support ili web prikaz).

---

## D-022: Target coverage semantics — relativno prema `positiveObservedWeight`

**Datum:** 2026-08-10
**Status:** formalizacija postojećeg calculator ponašanja, bez izmene koda; prvi put dostupno preko application/CLI sloja kroz `feature/etoro-target-coverage`

**Kontekst:** `CopyCoverageCalculator::minimumAmountForCoverage()`,
`CoverageTargetRequest` i `CoverageTargetResult` su implementirani ranije, u
Checkpoint C grane `milestone/2-etoro-domain-model` (commit `491f0c7`,
mergovano u `main` kao PR #2). Ovaj stream (`feature/etoro-target-coverage`,
Checkpoint A `App\Application\Etoro\FindTraderMinimumCopyAmountForCoverage` i
Checkpoint B `php artisan etoro:copy-target`) ne menja to ponašanje — samo ga
prvi put čini dostupnim application/CLI konzumentima. Ponašanje dosad nije
imalo sopstveni decision zapis; ova odluka ga formalizuje sada, jer postaje
consumer-facing ugovor.

**Odluka (zapis postojećeg, testovima potvrđenog ponašanja):**

- Target coverage (`targetCoverage`/`targetRatio`) je relativan prema
  `positiveObservedWeight` — zbiru weight-a isključivo pozicija sa strogo
  pozitivnim weight-om u posmatranom snapshot-u — a NE prema nominalnom
  `Percentage::whole()` (100%) ukupnom weight-u pozicija.
- Pozicije sa negativnim weight-om se izuzimaju iz denominator-a I postavljaju
  `hasIncompleteSourceData=true`.
- Pozicije sa nultim weight-om se izuzimaju iz denominator-a ali NE
  postavljaju `hasIncompleteSourceData=true` same po sebi.
- `hasIncompleteSourceData` zavisi isključivo od: `unmodeledEntryCount > 0`
  ILI prisustva bar jedne negativno-weighted pozicije. Odsustvo pozitivnih
  pozicija samo po sebi (npr. prazan snapshot, ili snapshot sa isključivo
  nultim weight-ovima i bez unmodeled entries) NE postavlja
  `hasIncompleteSourceData=true` — takav rezultat je legitimno "kompletan ali
  prazan/nepokriv", ne "nepotpun".
- Kad nema pozitivnih pozicija, `CoverageTargetResult` legitimno vraća
  `null` za `mathematicalMinimumCopyAmount`, `effectiveMinimumCopyAmount`,
  `achievedRatio` i `coveredRawWeight` (grupna all-or-nothing invarijanta u
  konstruktoru — sva četiri su ili svi `null` ili svi ne-`null`).
  `hasIncompleteSourceData` u tom slučaju i dalje zavisi isključivo od gornjeg
  pravila, ne od samog null-rezultata.
- Zaokruživanje: target apsolutni breakpoint i breakpoint svake pojedinačne
  pozicije koriste ceiling (`ceil`) BCMath deljenje; `achievedRatio` koristi
  floor/truncating BCMath deljenje. Ova asimetrija je namerna i testovima
  potvrđena.
- `effectiveMinimumCopyAmount = max(mathematicalMinimumCopyAmount,
  platformMinimumCopyAmount)` — platform minimum floor može legitimno
  podići postignutu (`achievedRatio`) pokrivenost iznad ciljane.
- Observed weight anomalije (negative weight, duplicate position id,
  unmodeled entries, zbir weight-ova različit od nominalnog whole-a,
  odsustvo pozitivnih pozicija) se signaliziraju kroz postojeći
  `CoverageWarning` contract. Negative weight i `unmodeledEntryCount > 0`
  dodatno postavljaju `hasIncompleteSourceData` (vidi tačke iznad) — ovo
  nije u koliziji sa `CoverageWarning` signalizacijom, već dodatni,
  paralelni signal. Nijedna od ovih anomalija ne menja denominator
  semantiku target coverage-a: denominator ostaje isključivo zbir strogo
  pozitivnih weight-ova, bez obzira na to koji su warning-i/flag-ovi
  postavljeni.

**Posledice:** `App\Analytics\Data\CoverageTargetRequest` već nosi ovaj
ugovor kao doc-comment na klasi; ova odluka ga podiže na nivo formalnog
decision zapisa jer je od `feature/etoro-target-coverage` nadalje
consumer-facing (application use case i `etoro:copy-target` CLI).

---

## D-023: CLI `target-coverage-percent` input contract (`etoro:copy-target`)

**Datum:** 2026-08-10
**Status:** dokumentovano nakon implementacije Checkpoint B, bez izmene koda

**Kontekst:** Checkpoint B (`37460f9`) grane `feature/etoro-target-coverage`
dodao je `php artisan etoro:copy-target <trader-username>
<target-coverage-percent> <minimum-position-cents>
<platform-minimum-copy-cents>`. Za razliku od `etoro:copy-coverage`
(D-021), ova komanda prima i target-coverage argument koji nije novčani
iznos, već procenat — potreban je poseban decision zapis za njegov ulazni
ugovor; D-021 ga ne pokriva jer `etoro:copy-coverage` nema ekvivalentan
argument.

**Odluka:**

- `target-coverage-percent` je human-facing **percentage-points** decimalni
  string, NE `0-1` razlomak: `95` znači 95%, `95.5` znači 95.5%, `0.05`
  znači 0.05% (ne 5%).
- Parsing je exact/string-only: sintaksna validacija regex-om
  `^\d{1,3}(?:\.\d{1,7})?$`, zatim BCMath kompozicija
  (`bcadd(bcmul($wholePart, '10000000', 0), $fractionPadded, 0)`) u
  parts-per-billion (PPB) reprezentaciju koju koristi `Percentage`. Nema
  `(float)`/`floatval()`/`round()` bilo gde u parsiranju.
- Rezolucija: do 7 decimalnih mesta percentage points (npr.
  `95.0000001` je validno, `95.12345678` nije — više od 7 decimala se
  odbija).
- Validan opseg: strogo `> 0` i `<= 100` (percentage points; u PPB terminima
  `0 < ppb <= 1_000_000_000`). `100` i `100.0000000` su oba validna i
  prikazuju se kao `100%` (trailing nula u frakciji se u potpunosti trimuje,
  uključujući decimalnu tačku).
- Odbijeno bez mrežnog poziva: `0`, vrednosti iznad `100`, negativan predznak,
  eksplicitan `+` predznak, trailing tačka bez frakcijskih cifara (`95.`),
  leading tačka bez celobrojnih cifara (`.95`), scientific notation (`1e2`),
  zarez kao decimalni separator (`95,5`), i bilo koji ne-numerički ulaz.
- Ova komanda zavisi isključivo od
  `App\Application\Etoro\FindTraderMinimumCopyAmountForCoverage` — nema
  direktne zavisnosti od `EtoroClient`/mapper/adapter/calculator
  (potvrđeno arhitektonskim testom, isti obrazac kao D-021 za
  `etoro:copy-coverage`).
- `minimum-position-cents` i `platform-minimum-copy-cents` koriste isti
  integer-cents/BCMath parsing contract kao `etoro:copy-coverage` (D-021):
  regex `^-?\d+$`, BCMath granica prema `PHP_INT_MAX`/`PHP_INT_MIN` pre
  cast-a, bez float-a. `minimum-position-cents` mora biti strogo pozitivan;
  `platform-minimum-copy-cents` sme biti nula ali ne negativan.
- Komanda prikazuje odvojeno "Mathematical minimum copy" i "Effective
  minimum copy", i koristi termin "Covered observed weight" — nikad ne
  tvrdi "total portfolio coverage" (vidi D-022 za zašto je razlika
  značajna). Null target-result vrednosti (mathematical/effective minimum,
  achieved coverage, covered observed weight) se prikazuju kao `N/A`, bez
  izmišljanja "impossible" ili `isAchievable` domain state-a koji ne
  postoji u `CoverageTargetResult`.
- Operational exception-i se prikazuju sanitizovano, istim obrascem kao
  `etoro:copy-coverage` (D-021) — kategorija/status/request-id/transport
  detalji, nikad originalna poruka/stack trace/payload/kredencijali.

---

## D-024: Trader/ImportRun persistence schema i status model

**Datum:** 2026-08-21
**Status:** dokumentovano nakon implementacije Checkpoint A grane
`feature/trader-ranking-import` (commit `f22bde8`), bez izmene koda —
formalizuje postojeći, testovima potvrđen schema ugovor

**Kontekst:** Checkpoint A dodao je prvu application-specific persistenciju
u projektu — `traders`/`import_runs` tabele, `Trader`/`ImportRun` Eloquent
modele i `TraderStatus`/`ImportRunStatus` enum-e. Ugovor dosad nije imao
sopstveni decision zapis.

**Odluka (zapis postojećeg, testovima potvrđenog ponašanja):**

- `traders.external_cid` i `traders.username` su dva **nezavisna** unique
  constraint-a na DB nivou. `external_cid` je zamišljen kao stabilan eToro
  identitet na schema nivou (eToro-ov `cid`, primljen već normalizovan
  preko `App\Etoro\Mappers\Support\Identifiers::normalize()`) i skladišti
  se isključivo kao string — nikad reinterpretiran kao numerički tip.
  **Ovo je schema-level intent, ne tvrdnja o postojećem importer
  ponašanju:** aktuelni `ImportRankingPage` (Checkpoint B, D-025) namerno
  fail-closed tretira isti `external_cid` sa različitim `username`-om (ili
  obrnuto) kao controlled conflict — entry se odbija, postojeći red se
  NIKAD tiho ne rename-uje ni rebind-uje. Ako eToro zaista dozvoljava
  promenu username-a, obrada takve promene (npr. poseban
  username-change/rebind workflow) zahteva sopstvenu, posebnu odluku — nije
  pokrivena ovim ili D-025 ugovorom.
- `TraderStatus` (`candidate`/`watched`/`ignored`) i `ImportRunStatus`
  (`pending`/`running`/`completed`/`partial`/`failed`) su lokalni,
  eToro-nezavisni ugovori — ne odražavaju nijedno polje koje eToro API
  vraća. `TraderStatus` podrazumeva `candidate` pri kreiranju; nema
  aplikacionu ili UI mutacionu putanju u ovom stream-u (vidi PROJECT.md §9
  i D-026).
- `import_runs` je `source`/`type`-parametrizovana audit tabela
  (`source='etoro'`, `type='rankings'` jedini par koji se trenutno
  koristi) — ovo NIJE tvrdnja o postojanju generičkog import/transport
  framework-a; parametrizacija postoji da bi budući importer tipovi mogli
  ponovo koristiti istu tabelu bez šeme promene, ne da bi opravdala
  apstrakciju koja danas ne postoji.
- Scope je isključivo foundation-only: nijedna druga spekulativna tabela iz
  PROJECT.md §11 (`trader_snapshots`, `performance_points`,
  `portfolio_snapshots`, `portfolio_positions`, `instruments`,
  `copy_simulations`, `analysis_profiles`, `api_responses`) nije uvedena
  ovim ili bilo kojim kasnijim checkpoint-om ove grane.

---

## D-025: Idempotentni ranking-page importer — identity/collation/transakcioni ugovor

**Datum:** 2026-08-21
**Status:** dokumentovano nakon implementacije Checkpoint B grane
`feature/trader-ranking-import` (commit `c6c7580`), bez izmene koda —
formalizuje postojeći, testovima potvrđen ugovor
`App\Application\Imports\ImportRankingPage`

**Odluka (zapis postojećeg, testovima potvrđenog ponašanja):**

- `ImportRankingPage::handle()` prima već mapiran `RankingPage` i
  `RankingQuery` — ne poziva `EtoroClient`, ne čita fixture, ne mapira
  sirovi payload. Sav HTTP/fixture pristup je odgovornost pozivaoca.
- Identity rezolucija je dvoslojna: (1) in-page ambiguity (entries unutar
  iste stranice sa suprotstavljenim cid/username parovima) rezolvuje se za
  celu stranicu PRE bilo kog write-a; (2) svaki preostali entry se zatim
  rezolvuje protiv postojećih redova kroz stvarne, žive upite (ne
  in-memory mapu izgrađenu unapred), tako da equality semantika tačno
  odgovara DB-ovom sopstvenom unique indeksu.
- Na MySQL/MariaDB, equality koristi stvarnu column collation očitanu iz
  `information_schema.COLUMNS` (nikad pretpostavljenu), sa collation
  imenom validiranim protiv strogog allow-list regex-a pre interpolacije u
  SQL, i sa stvarnom fizičkom (prefiksovanom) tabelom — nikad logičkim
  Eloquent nazivom. Na SQLite equality je PHP-exact string poređenje
  (SQLite-ovo podrazumevano poređenje je već byte-exact po konstrukciji).
  Svaki drugi driver fail-closed baca `RuntimeException` — nema tihog
  fallback-a na pretpostavljenu equality semantiku.
- Consistent duplicate (isti cid I isti username, bilo koliko puta
  ponovljen) kolapsira u jedan trader write koristeći podatke poslednjeg
  pojavljivanja po listing poziciji. Conflict (isti cid sa različitim
  username-om, ili obrnuto — bilo unutar stranice, bilo protiv postojećeg
  reda) je controlled failure: entry se odbija, postojeći red se NE
  mutira.
- Svi trader write-ovi za jednu stranicu I finalizujući `ImportRun` save
  žive u JEDNOJ transakciji. Neočekivan `Throwable` u toku obrade rollback-
  uje sve write-ove te stranice. Van te transakcije, kod zatim **best
  effort** (ne garantovano) pokušava da postojeći `Running` `ImportRun` red
  markira `Failed` sa sanitizovanim, count-only `error_summary`-jem (nikad
  cid/username/payload) — taj recovery `save()` je poseban, negarantovan
  upis van rollback-ovane transakcije. Samo ako TAJ recovery save uspe,
  originalni uhvaćeni exception se ponovo baca pozivaocu nepromenjen. Ako
  recovery save sam zakaže, kod ne garantuje ni očuvanje/re-throw
  originalnog exception-a ni postojanje sanitizovanog `Failed` zapisa —
  ovo je namerno slabiji ugovor od "uvek", ne propust u dokumentaciji.
- `ImportRunStatus::Failed` može značiti dve suštinski različite stvari:
  (a) svi entry-ji su bili controlled identity conflict (nula uspeha, bez
  bačenog exception-a — normalan, ne-fatalan ishod), ili (b) neočekivan
  persistence exception je prekinuo obradu (uvek praćen bačenim
  exception-om). Status sam po sebi ne razlikuje ova dva slučaja — samo
  prisustvo/odsustvo propagiranog exception-a to čini.

---

## D-026: Offline fixture-only ranking-page import — source, CLI/environment i exit-code ugovor

**Datum:** 2026-08-21
**Status:** dokumentovano nakon implementacije Checkpoint C grane
`feature/trader-ranking-import` (commit `d739e4c`), bez izmene koda —
formalizuje postojeći, testovima potvrđen ugovor

**Odluka (zapis postojećeg, testovima potvrđenog ponašanja):**

- Postoji tačno jedan kanonski, potpuno sintetički fixture —
  `resources/fixtures/etoro/rankings.json` (premešten sa
  `tests/Fixtures/Etoro/rankings.json`, bez kopije, bez app→tests
  zavisnosti). `App\Etoro\FixtureSources\RankingFixtureSource` je jedini
  čitalac; nije generički transport framework — `load(): array` nema
  parametara, ne prihvata `RankingQuery`, ne poziva `EtoroClient`, ne
  koristi `Http::fake()` kao runtime mehanizam niti mutira `etoro.*`
  config. Fail-closed za missing/unreadable/read-failure (jedan
  `SourceUnavailable` reason), invalid JSON, i ne-object top-level shape —
  poruka nosi samo reason kategoriju, nikad putanju ili sadržaj fajla.
- `App\Application\Imports\ImportRankingPageFromFixture` orkestrira
  `RankingFixtureSource → RankingsMapper → ImportRankingPage`. Nakon
  mapiranja, PRE poziva `ImportRankingPage::handle()`, poredi mapiranu
  `RankingPagination` sa prosleđenim `RankingQuery::page`/`pageSize`;
  mismatch baca istu `RankingFixtureException` (reason
  `PaginationMismatch`) — nula `ImportRun`/`Trader` upisa. Use case sam ne
  hvata nijedan exception.
- CLI signature (`etoro:import-ranking-page {period}`) ima tačno jedan
  argument — `period`, trimovan, simulirana `RankingQuery` metadata bez
  mrežnog dejstva. `page`/`pageSize` su hardkodovani na `1`/`3` (fixture-ove
  jedine stvarne vrednosti); `sort`/`country` uvek `null`. Environment
  guard (`app()->environment(['local', 'testing'])`) se proverava KAO PRVI
  red u `handle()` — pre input parsing-a, pre fixture I/O-a, pre bilo kog
  DB upisa; van tih okruženja komanda vraća `Command::FAILURE` sa
  statičnom porukom, bez čitanja config/env fajla ili kredencijala.
- Komanda nema direktnu zavisnost ni od `RankingFixtureSource`/
  `RankingsMapper`/`ImportRankingPage`/`EtoroClient`, niti od bilo kog
  `App\Etoro` exception tipa (`RankingFixtureException`,
  `EtoroMappingException`, ...) — zavisi isključivo od
  `ImportRankingPageFromFixture`. Postoji tačno jedan `catch (Throwable)`
  blok koji pokriva svaki fatalni fixture/decode/shape/mapping/pagination/
  persistence slučaj jednom potpuno statičnom porukom ("Offline fixture
  ranking-page import failed.") — nikad `$exception->getMessage()`, path,
  payload ili identitet.
- Exit-code ugovor koristi tačno tri Symfony `Command` konstante plus jednu
  dokumentovanu privatnu: `0` (`SUCCESS`) — `ImportRun` sa
  `failure_count===0`; `1` (`FAILURE`) — environment guard odbijen ILI bilo
  koji fatalni `Throwable` iz use case-a; `2` (`INVALID`) — prazan/
  whitespace-only `period`, pre bilo kog I/O-a; `3` — privatna
  `EXIT_IMPORT_WITH_REJECTIONS` konstanta kad `ImportRun` postoji ali
  `failure_count>0` (Partial ili Failed status). Nema `--live` opcije niti
  bilo kog mehanizma da se ovaj tok preusmeri na pravi eToro poziv.

---

## D-027: Live multi-page ranking discovery — orkestracija, pacing, lineage ugovor

**Datum:** 2026-08-21
**Status:** dokumentovano nakon implementacije Checkpoint E grane
`codex/milestone-2-discovery-and-ui` (commit `8a3204b`), bez izmene koda —
formalizuje postojeći, testovima potvrđen ugovor
`App\Application\Imports\DiscoverEtoroTraders`

**Odluka (zapis postojećeg, testovima potvrđenog ponašanja):**

- `DiscoverEtoroTraders::handle(DiscoverEtoroTradersRequest):
  DiscoverEtoroTradersResult` kreira TAČNO JEDAN `rankings_discovery`
  agregatni `ImportRun` (source `etoro`, status `Running`) PRE prvog HTTP
  poziva — transportni/mapping/paginacioni/neočekivani failure ostaje
  vidljiv u import istoriji čak i kad nijedna stranica nikad ne uspe.
- `DiscoverEtoroTradersRequest` je centralni, autoritativni value object:
  `period` (trimovan, ne-prazan), `startPage>=1`, `maxPages` između 1 i 20
  (`MAX_PAGES_CEILING`), opcioni `sort`/`country` (prazan string posle
  trim-a → `null`). `PAGE_SIZE` je fiksna konstanta = 20, nikad
  korisnički konfigurabilna. Nikad ne veruje da je pozivalac (CLI,
  Filament) već validirao — sopstveni konstruktor je jedini izvor istine.
- Pipeline po stranici: `EtoroClient::rankings()` →
  `RankingsMapper` → `ImportRankingPage::handle($rankingPage,
  $rankingQuery, $aggregateRun->id)`. `Illuminate\Support\Sleep::sleep(2)`
  se poziva ISKLJUČIVO između fizički odvojenih poziva stranica — nikad
  pre prve niti posle poslednje stranice.
- `request_count` na agregatnom redu odražava STVARNE fizičke HTTP
  pokušaje, uključujući `EtoroClient`-ove sopstvene interne retry-je —
  nikad logičan broj stranica.
- Pre bilo kog write-a za stranicu, `rankingPage->pagination->page`/
  `pageSize` se poredi sa poslatim `RankingQuery` — mismatch zaustavlja
  tok (`PaginationMismatch` stop reason) sa nula write-ova za tu stranicu.
- `DiscoverEtoroTradersStopReason` enum: `NaturalCompletion`,
  `PageLimitReached`, `PaginationMismatch`, `ConfigurationError`,
  `RequestFailed`, `UnexpectedResponse`, `MappingFailed`,
  `UnexpectedFailure`. Status se određuje ovako: `NaturalCompletion` sa
  `pagesFetched>0` → `Completed` ako je `failureCount===0`, inače
  `Partial`; svaki drugi stop reason → `Partial` ako je `pagesFetched>0`,
  inače `Failed`.
- `import_runs.parent_import_run_id` (nullable self-FK, `nullOnDelete`)
  povezuje svaki per-page `rankings` child `ImportRun` nazad na agregatni
  red koji ga je pokrenuo. `ImportRankingPage::handle()` fail-closed
  odbija svaki `parentImportRunId` koji ne referencira postojeći
  `etoro`/`rankings_discovery`/`Running` agregatni red.
- Neočekivan `Throwable` tokom obrade prati isti "best effort, ne
  garantovano" recovery ugovor kao D-025: van bilo koje transakcije, kod
  pokušava da agregatni red markira `Failed` sa sanitizovanim,
  count-only `error_summary`-jem; ako TAJ recovery save uspe, originalni
  exception se ponovo baca nepromenjen; ako recovery save sam zakaže, ta
  nova greška se prosleđuje umesto originalne, a agregatni red može
  ostati ne-terminalan (npr. zaglavljen na `Running`).

---

## D-028: Row-level failure persistence — `import_run_failures` ugovor

**Datum:** 2026-08-21
**Status:** dokumentovano nakon implementacije Checkpoint F grane
`codex/milestone-2-discovery-and-ui` (commit `b1b06d6`), bez izmene koda —
formalizuje postojeći, testovima potvrđen ugovor

**Odluka (zapis postojećeg, testovima potvrđenog ponašanja):**

- `import_run_failures` tabela: `import_run_id` FK (`cascadeOnDelete`),
  `row_number` (1-based pozicija unutar stranice, čuva originalni
  server-vraćeni redosled), `external_cid`, `username`, `reason`,
  `unique(import_run_id, row_number)`.
- `ImportRunFailureReason` enum: `IdentityConflictWithinPage`,
  `IdentityConflictWithExistingTrader` — jedina dva scenarija koja
  `ImportRankingPage` trenutno razlikuje.
- Jedini writer je `ImportRankingPage`, i to ISKLJUČIVO protiv per-page
  `rankings` (ili fixture-only single-page) reda koji finalizuje — nikad
  protiv `rankings_discovery` agregatnog reda direktno. Write se dešava
  unutar ISTE transakcije kao trader write-ovi i finalizujući `ImportRun`
  save za tu stranicu — rollback te transakcije briše i pokušani
  `ImportRunFailure` red.
- `ImportRun::failures()` — `HasMany`, direktni failure-ovi OVOG reda.
- `ImportRun::childFailures()` — `HasManyThrough`, tačno JEDAN nivo
  dubine: svi `ImportRunFailure` redovi agregatnog reda DIREKTNIH
  `childRuns()`, bez duplikacije na sam agregat. Čisto integer FK join
  (bez collation-osetljivog string poređenja), radi identično na SQLite i
  MySQL.
- Ovo je činjenica o ponašanju trenutnog writer-a, ne DB/model-nivoa
  ograničenje — `import_run_id` FK sam po sebi ne ograničava koji `type`
  reda referencira.

---

## D-029: Trader profile lookup — identity/enrichment ugovor

**Datum:** 2026-08-21
**Status:** dokumentovano nakon implementacije Checkpoint G grane
`codex/milestone-2-discovery-and-ui` (commit `7efd3b1`) i korekcije
primenjene tokom Checkpoint H1 review-a (deo commit-a `c0c4d06`), bez
izmene van tih commit-a — formalizuje postojeći, testovima potvrđen ugovor

**Odluka (zapis postojećeg, testovima potvrđenog ponašanja):**

- `App\Application\Traders\TraderUsername` je centralni, immutable
  query/identity value object deljen između lokalnog i remote lookup-a:
  odbija NUL byte, trimuje samo whitespace charlist (`" \t\n\r\v\f"`),
  odbija prazan string. Exact-match semantika — bez wildcard/LIKE
  pretrage.
- `FindStoredTraderByUsername` — lokalni, read-only, exact lookup preko
  `traders.username`; rezultat se vraća samo ako je stvarni spremljeni
  username PHP-exact (`===`) jednak normalizovanom query-ju — fail-closed
  odbrana od case-insensitive MySQL collation-a (npr.
  `utf8mb4_unicode_ci`), bez oslanjanja na to da je `WHERE` klauzula sama
  po sebi exact.
- `traders` tabela ima šest nullable "observed profile" kolona
  (`profile_gcid`, `profile_is_popular_investor`, `profile_is_verified`,
  `profile_country_code`, `profile_language_iso_code`,
  `profile_synced_at`). `profile_gcid` nosi NULTU unique/index/identity
  semantiku — nikad se ne poredi sa niti koristi za pretragu po
  `external_cid`/ranking `cid`-u.
- `LookupEtoroTraderProfile::handle(TraderUsername):
  LookupEtoroTraderProfileResult` kreira TAČNO JEDAN `profile` `ImportRun`
  pre prvog HTTP poziva. Pipeline: `EtoroClient::userProfile()` →
  `TraderProfileMapper` → exact identity provera (mapirani
  `profile->username` MORA biti PHP-exact jednak query username-u, inače
  `ProfileIdentityMismatch` — `Failed`, bez mutacije) → opciono lokalno
  obogaćivanje preko `FindStoredTraderByUsername`.
- NIKAD ne kreira `Trader` iz profile odgovora — obogaćivanje mutira
  ISKLJUČIVO šest profile polja + `profile_synced_at` na VEĆ POSTOJEĆEM
  redu; `external_cid`, `username`, ranking polja i `status` ostaju
  netaknuti.
- Trader mutacija i uspešan (`Completed`) `ImportRun` finalize save dele
  JEDNU transakciju (korekcija primenjena tokom H1 review-a) — ako
  finalize save padne, trader mutacija se rollback-uje zajedno s njim, i
  slučaj pada u isti best-effort `UnexpectedFailure` recovery ugovor kao
  D-025/D-027.
- Ponovljeni lookup je idempotentan po broju `Trader` redova; svaki poziv
  pravi nov `profile` `ImportRun` audit red.

---

## D-030: Trader status tranzicije i discovery retry — eligibility/lineage ugovor

**Datum:** 2026-08-21
**Status:** dokumentovano nakon implementacije Checkpoint H1 grane
`codex/milestone-2-discovery-and-ui` (commit `c0c4d06`), bez izmene koda —
formalizuje postojeći, testovima potvrđen ugovor

**Odluka (zapis postojećeg, testovima potvrđenog ponašanja):**

- `App\Application\Traders\ChangeTraderStatus` je jedina poslovna ulazna
  tačka za promenu `TraderStatus` (`Candidate`/`Watched`/`Ignored`). Poziv
  na isti status je idempotentan no-op po ishodu.
- `import_runs.retry_of_import_run_id` (nullable self-FK, `nullOnDelete`)
  je ODVOJEN od `parent_import_run_id` — identifikuje NEPOSREDNO
  retry-ovani red, nikad koren lanca (C retry-uje B retry-uje A ⇒
  `C.retry_of_import_run_id = B.id`, NIKAD `= A.id`).
- `DiscoverEtoroTradersRequest` ima opcioni, poslednji `retryOfImportRunId`
  (`null` ili `>=1`) — source-compatible sa svim postojećim pozivaocima.
- Svaki terminalni `rankings_discovery` agregatni red sada nosi
  sanitizovanu retry-eligibility metadata: `retryable` (boolean, UVEK
  prisutan), `request_error_category` (enum vrednost, prisutna SAMO kad
  je stvarni request failure) i `retry_not_before` (ISO-8601, prisutan
  SAMO kad je isporučen pozitivan `Retry-After`). NIKAD request ID,
  transport detalj, URL, payload, kredencijal ili header.
- Retryable je ISKLJUČIVO kad je `stop_reason=request_failed` I kategorija
  ∈ {`server_error`, `connection_failed`, `rate_limited`} —
  Validation/Authentication/Authorization/NotFound i svaki ne-request
  stop reason nikad nisu retryable.
- `App\Application\Imports\RetryEtoroTraderDiscovery::canRetry()` i
  `::handle()` dele JEDAN privatni eligibility gate — nikad duplirana
  logika. Fail-closed PRE bilo kog HTTP poziva na: red nije persisted,
  pogrešan source/type/status, `retryable` nije striktno `true`,
  nekonzistentan `retryable`/`stop_reason`/`category` signal (nikad se ne
  veruje `retryable=true` izolovano), malformisana metadata (validirana
  preko `getRawOriginal()` + `json_decode(..., JSON_THROW_ON_ERROR)`
  protiv PERSISTED kolone, nikad protiv već-cast in-memory atributa — tako
  da eventualna in-memory mutacija koju pozivalac napravi pre poziva nikad
  ne može uticati na eligibility odluku), budući `retry_not_before`
  (strogo ISO-8601 round-trip parsiranje, nikad `Carbon::parse()`-ovo
  permisivno relativno parsiranje koje bi prihvatilo npr. "tomorrow").
- Retry NIKAD ne mutira niti ponovo otvara originalni red — retry je
  običan nov discovery poziv, povezan nazad isključivo preko sopstvenog
  `retry_of_import_run_id`.

---

## D-031: Filament read-only UI granica i profile freshness pravilo

**Datum:** 2026-08-21
**Status:** dokumentovano nakon implementacije Checkpoint H2 grane
`codex/milestone-2-discovery-and-ui` (commit `51b32e1`), bez izmene koda —
formalizuje postojeći, testovima potvrđen ugovor

**Odluka (zapis postojećeg, testovima potvrđenog ponašanja):**

- `App\Application\Traders\EvaluateTraderProfileFreshness` je JEDINO mesto
  gde se računa profile freshness (`never_synced`/`fresh`/`stale`) —
  Filament sloj ga nikad ne računa sam. Profil je stale STROGO POSLE 24h
  proteklog vremena od `profile_synced_at` (tačno 24h00m00s je i dalje
  `fresh`). `profile_synced_at` u budućnosti je UVEK `fresh`, bez obzira
  koliko daleko u budućnosti — "aged" znači isključivo proteklo prošlo
  vreme, nikad apsolutna/signed udaljenost (clock skew ne sme značiti
  "stariji podatak").
- `TraderResource`/`ImportRunResource`: samo List+View rute; nema
  Create/Edit/Delete/replicate/bulk-delete rute niti akcije;
  `canCreate()` vraća `false`.
- `TraderResource` red-akcije (Mark candidate/Watch/Ignore/Lookup profile)
  pozivaju ISKLJUČIVO `ChangeTraderStatus`/`LookupEtoroTraderProfile` —
  nikad direktnu mutaciju modela. Ignore zahteva potvrdu.
- `ImportRunResource` retry akcija vidljivost proverava ISKLJUČIVO preko
  `RetryEtoroTraderDiscovery::canRetry()` — nikad duplira eligibility
  logiku. Infolist je striktna whitelist po ključu (`data_get()` po
  imenu) — nikad renderuje sirovi `metadata` niz u celini.
- `DiscoverTraders` custom stranica: renderovanje NIKAD ne pravi HTTP
  poziv. Dve native Filament akcije (Run discovery / Lookup profile)
  konstruišu `DiscoverEtoroTradersRequest`/`TraderUsername` i pozivaju
  `DiscoverEtoroTraders`/`LookupEtoroTraderProfile` isključivo unutar
  sopstvenih `action()` closure-a. Notification status/tekst grana se po
  `stopReason` — nezavršen profile lookup nikad ne tvrdi match/no-match
  jezik, pošto mapping/identity-mismatch/unexpected-response failure
  takođe mogu nastati NAKON stvarnog HTTP odgovora (samo `Completed`
  proizvodi validiran, identity-matched mapirani profil).
- Svaka nova `App\Filament` klasa je arhitektonski testirana da dokaže
  odsustvo `EtoroClient`/`Http`/`DB`/`config`/`env`/`Storage`/`Log`/`Queue`
  zavisnosti i odsustvo duplirane freshness/retry-eligibility logike.

---
