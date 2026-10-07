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

## D-032: Izvor performance podataka za Milestone 3 — v2 gain time-series, jedinice i konvencije

**Datum:** 2026-10-05
**Status:** usvojeno (Milestone 3, Checkpoint A, grana
`codex/milestone-3-performance-analytics`)

**Kontekst:** Zvanični OpenAPI (v1.385.0) dokumentuje dva relevantna
endpointa:

- v1 `GET /api/v1/user-info/people/{username}/gain` — opisan kao „gain
  percentages“, bez eksplicitnih jedinica u šemi (`gain: number`);
- v2 `GET /api/v2/portfolios/{username}/gain/{granularity}`
  (`getGainHistory`) — `granularity ∈ {daily, monthly, yearly}`, opcioni
  `minDate`/`maxDate`/`count (1..1000)`, eksplicitno: „Gain values are
  decimal fractions: 0.06 = 6%“; `totalGain` = složeni gain serije;
  dokumentovan `403` = „Target user has opted out of portfolio exposure“.

**Dokaz (live, vlasnik odobrio jedan GET):** jedan poziv v2 `monthly`
(`count=1000`) za istog tradera čiji je v1 `/gain` odgovor privatno
snimljen 2026-07-31. Upoređeni su isključivo agregatni brojevi:

- 80 preklapajućih meseci; medijana odnosa v1/v2 = **100.000**; 79/80
  meseci se poklapa tačno (|v1 − 100·v2| ≈ 0); jedini izuzetak je mesec
  koji je u trenutku v1 snimka još trajao.
- `totalGain` = Π(1 + gain) − 1 sa razlikom ~2.6e-7 (zaokruživanje).
- `gain` vrednosti imaju najviše 4 decimale; datumi su `YYYY-MM-DD`,
  strogo rastući, jedinstveni.
- Prva tačka može imati datum koji nije 1. u mesecu (delimičan prvi mesec
  — početak aktivnosti); poslednja tačka je TEKUĆI, nezavršen mesec
  (month-to-date).
- Odgovor vraća `RateLimit-Limit`/`RateLimit-Remaining` header-e
  (60/59) — bez `X-` prefiksa.

**Odluka:**

1. **v2 gain time-series je jedini izvor za performance analitiku.** v1
   `/gain` se ne koristi za kalkulacije; v1 `gain` je u **procentnim
   poenima** (`PerformancePoint`/`PerformanceHistoryMapper` docblock
   ispravljen; v1 fixture eksplicitno označen kao ne-unit-faithful).
2. v2 `gain` je **decimalni udeo**. Na granici mapiranja se ne množi/deli
   float-om; vrednost se prevodi u tačnu decimalnu reprezentaciju (string,
   BCMath) za kalkulacije.
3. **Delimični periodi se eksplicitno označavaju**, ne odbacuju ćutke:
   prvi period čiji datum nije početak perioda je `partial_start`;
   poslednji period koji obuhvata trenutak sinhronizacije je
   `in_progress`. Statistike po završenim mesecima (npr. positive-month
   ratio, streak-ovi) po default-u isključuju `in_progress` period;
   kumulativni prinos ga uključuje i to se prikazuje.
4. `EtoroClient::userGainHistory(username, GainGranularity, ?count)` je
   novi typed GET metod (bez date-range parametara dok ih ne zatreba
   konzument); `etoro:doctor --only=gain-history` je nova proba koja NIJE
   deo punog `--live` runa (pun run ostaje 7 proba).
5. `EtoroClient` sada hvata i dokumentovane `RateLimit-*` header-e
   (pored `X-RateLimit-*`). Ovo objašnjava zašto M1 nije video rate-limit
   header-e. Dokumentovana kvota je 60 zahteva / 60 s, deljena između
   svih endpointa bez posebnog limita; aplikacioni budžet ostaje 45/min.
6. `daily` granularnost ima istu dokumentovanu šemu, ali **još nije
   live-potvrđena** — mapper/import za `daily` čeka posebno odobrenje za
   live probu.

## D-033: Performance kalkulatori — formule, delimični periodi i preciznost

**Datum:** 2026-10-05
**Status:** usvojeno (Milestone 3, Checkpoint B)

**Odluka:**

- Ulaz za sve kalkulatore je `App\Analytics\Data\ReturnSeries`: strogo
  rastući `PeriodReturn` niz jedne granularnosti
  (`ReturnPeriodGranularity` daily/monthly/yearly). Samo prvi period sme
  biti `isPartialStart`, samo poslednji `isInProgress`; prinos ≤ −100% se
  odbija (equity bi postao ≤ 0).
- `GainHistoryReturnSeriesAdapter` (App\Etoro → App\Analytics) označava:
  partial start = prvi mesečni/godišnji period čiji datum nije početak
  perioda; in progress = poslednji period čiji kalendarski ključ (UTC)
  sadrži trenutak snimanja (`asOf`).
- **Sve tačke** (uključujući delimične) ulaze u: složeni prinos, equity
  krivu i drawdown — to je stvarno kretanje kapitala.
- **Samo završeni periodi** ulaze u: prosek, medijanu, pozitivne/
  negativne/ravne periode i njihov odnos, streak-ove, volatilnost,
  najbolji/najgori period, prinos bez najboljeg/tri najbolja perioda i
  trailing 12/24 meseca.
- Formule: kumulativni prinos Π(1+r)−1; equity₀ = 1; drawdown =
  equity/peak − 1 (peak uključuje početni equity 1.0); max drawdown je
  nenegativna magnituda; volatilnost = uzoračka standardna devijacija
  (n−1) po periodu, a godišnja (× √12) samo za mesečne serije; prinos 0
  je „flat“ i prekida oba streak-a; trailing 12/24 samo za mesečne serije
  i samo kad postoji dovoljno završenih meseci (inače `null`).
- Preciznost: BCMath sa 18 decimala interno (`App\Analytics\Support\ReturnMath`),
  rezultat se zaokružuje half-up (od nule za negativne) na ceo ppb
  (`Percentage`) tek na kraju. Bez float-a; bez `bcround()` (PHP ^8.3).
- Svaki rezultat nosi `methodologyVersion` (`performance-v1`,
  `drawdown-v1`, `consistency-v1`) i granularnost; mesečni max drawdown
  se nikad ne prikazuje kao dnevni/intraday.
- v2 `totalGain` se čuva samo radi unakrsne provere; aplikacija sama
  računa složeni prinos (fixture pipeline test dokazuje slaganje unutar
  zaokruživanja API-ja).

## D-034: Performance persistence i queued sinhronizacija

**Datum:** 2026-10-05
**Status:** usvojeno (Milestone 3, Checkpoint C; vlasnik odlučio: queued
jobs odmah)

**Odluka:**

- Tabela `performance_points`: `trader_id` (FK, cascade), `granularity`,
  `period_start` (DATE, datum iz API-ja), `gain_ppb` (signed BIGINT, tačan
  decimalni udeo u ppb — isto kao `Percentage`; bez DECIMAL/float), `source`
  (`etoro_v2_gain`), `synced_at`; unique
  `(trader_id, granularity, period_start, source)` (PROJECT.md §11/§16).
  `period_start` namerno NIJE Eloquent date cast — svi upisi čuvaju isti
  `Y-m-d` string (SQLite bi inače dobio i vreme).
- Partial-start/in-progress se NE čuvaju — izvode se iz `period_start` i
  `synced_at` (D-032/D-033), da se ne bi zastareli.
- `traders.performance_synced_at` (poslednji USPEŠAN sync) i
  `traders.performance_visibility` (`available|private|not_found`).
- `App\Application\Traders\SyncTraderPerformance`: jedan `performance`
  `ImportRun` po pozivu, kreiran pre HTTP poziva; poziva samo
  `EtoroClient::userGainHistory(monthly, 1000)`; zahteva tačno isti
  username u odgovoru; sačuvana mesečna serija se **zamenjuje** odgovorom
  (upsert + brisanje perioda koji više nisu vraćeni) u istoj transakciji
  sa finalize-om ImportRun-a → idempotentno.
- Ishodi: 403 → `not_visible` + `private` (postojeća istorija se čuva,
  bez retry-ja); 404 → `not_found`; 429/5xx/transport →
  `temporarily_unavailable` (jedini retryable, nosi Retry-After);
  mapping/granularity/identity greške → fail closed, ništa se ne upisuje.
  `error_summary` je statičan tekst.
- `App\Jobs\SyncTraderPerformanceJob`: `ShouldBeUnique` po trader-u (1h),
  `RateLimited('etoro-api')` middleware, `retryUntil` 6h (rate-limit
  release-ovi se broje kao pokušaji), release samo za retryable ishod
  (Retry-After ili 60 s × pokušaj, max 900 s). Rate limiter `etoro-api` =
  `ETORO_REQUESTS_PER_MINUTE`/min, deljen za sve eToro job-ove (eToro
  default kvota je deljena između endpointa).
- `php artisan etoro:sync-performance {username?} {--watched} {--now}`:
  podrazumevano queue-uje; `--now` izvršava sinhrono i prikazuje samo
  trader ID, ishod, broj tačaka i ImportRun ID. Nikad ne kreira Trader.
  Kad je integracija isključena, ništa ne queue-uje.
- Scheduler: `etoro:sync-performance --watched` dnevno u 03:00 UTC
  (PROJECT.md §16), `withoutOverlapping`. Radi samo uz `schedule:run`
  cron i aktivan queue worker.
- Samo mesečna granularnost; dnevna čeka live potvrdu (D-032 tačka 6).

## D-035: Dnevna serija — live potvrda i sinhronizacija

**Datum:** 2026-10-05
**Status:** usvojeno (Milestone 3, Checkpoint C2; live poziv iz dnevnog
pool-a, 2/10)

**Dokaz (jedan GET, `daily`, `count=1000`, isti trader kao D-032; samo
agregati):** HTTP 200; ista šema kao mesečna (`username, granularity,
totalGain, gains[]`); **1001** tačka (count + 1) bez rupa — svaki
kalendarski dan, uključujući vikende (≈21% tačaka je tačno 0); gain je
decimalni udeo sa ≤ 4 decimale; `totalGain` = složena serija (razlika
~1.5e-7). Dnevne vrednosti složene po mesecu poklapaju se sa mesečnom
serijom (medijana razlike 0.008 pp; 1/32 meseci > 0.1 pp — posledica
zaokruživanja dnevnih vrednosti na 4 decimale). Poslednja tačka nije
današnji dan.

**Odluka:**

- Sync po trader-u: prvo `monthly`, pa `daily` (svaki svoj `performance`
  ImportRun sa `query.granularity`). Konačan neuspeh mesečnog (npr.
  privatan) preskače dnevni zahtev; privremena greška bilo kog release-uje
  ceo job (oba su idempotentna).
- Zamena serije važi samo **unutar vraćenog opsega datuma** — dnevni
  prozor od ~1000 dana klizi, pa se stariji sačuvani dani nikad ne brišu.
  Za mesečnu (puna istorija) ovo je isto kao potpuna zamena.
- Dnevni drawdown se prikazuje kao „dnevni, poslednjih N dana“ sa
  eksplicitnim opsegom; dublja dnevna istorija bi zahtevala
  `minDate`/`maxDate` straničenje (nije implementirano). Mesečna
  statistika (konzistentnost, trailing) i dalje koristi mesečnu seriju —
  dnevna se ne agregira u mesečnu.
- Budžet: 2 zahteva po trader-u po sync-u; dnevni scheduled sync watched
  trader-a time troši 2 × broj watched trader-a zahteva kroz zajednički
  `etoro-api` limiter. Napomena: ovo su automatizovani produkcijski
  pozivi koje vlasnik pokreće uključivanjem worker-a/scheduler-a — ne
  troše agentov dnevni pool za razvoj.
- Novi sintetički fixture `tests/Fixtures/Etoro/gain-history-daily.json`
  (14 uzastopnih dana, vikend nule); leakage scan protiv raw snimka: 0
  preklapanja.

## D-036: Performance UI na stranici tradera

**Datum:** 2026-10-05
**Status:** usvojeno (Milestone 3, Checkpoint D)

**Odluka:**

- `App\Application\Traders\BuildTraderPerformanceReport` gradi read model
  isključivo iz SAČUVANIH `performance_points` (nikad HTTP pri
  renderovanju — nastavak D-031). Partial-start/in-progress izvodi
  `App\Analytics\Support\PeriodClassifier` (jedino mesto te logike; koristi
  ga i `GainHistoryReturnSeriesAdapter`), sa `asOf` = najnoviji
  `synced_at` serije. Registrovan kao `scoped` singleton i memoizuje
  izveštaj po trader-u/sync timestamp-u, jer ga infolist i tri widget-a
  čitaju u istom zahtevu.
- `ViewTrader`: sekcije „Performance sync“ (vidljivost, poslednji uspešan
  sync), „Performance — monthly“ i „Performance — daily“ (sakrivene dok
  nema podataka); svaka brojka nosi granularnost i posmatrani period;
  delimični/tekući periodi su eksplicitno označeni; mesečni max drawdown
  je eksplicitno „not intraday“. Footer widget-i: mesečni equity index,
  drawdown kriva (dnevna kad postoji, inače mesečna — naslov kaže koja) i
  tabela mesečnih prinosa godina × mesec.
- Akcija „Sync performance“ ide isključivo kroz
  `App\Application\Traders\QueueTraderPerformanceSync` (Filament ne sme da
  dispatch-uje direktno — D-031 arch test); kad je integracija
  isključena, ništa se ne queue-uje. Komanda `etoro:sync-performance`
  koristi isti use case.
- Formatiranje: `App\Filament\Support\PercentageDisplay` — BCMath, half
  away from zero; float samo za Chart.js ose (prezentacija).
- Panel nema sopstvenu Filament temu, pa custom Blade koristi inline
  stilove sa Filament CSS varijablama boja (`--success-600`,
  `--danger-600`) umesto nekompajliranih Tailwind klasa.

## D-037: Live portfolio keš i metapodaci instrumenata

**Datum:** 2026-10-05
**Status:** usvojeno (Milestone 4, Checkpoint A; live pozivi 3–5/10 iz
dnevnog pool-a)

**Dokaz (samo agregati, bez identiteta/vrednosti pojedinačnih pozicija):**

- Live portfolio, dva uzorka istog tradera (2026-07-31 i 2026-10-05):
  Σ `investmentPct` + `realizedCreditPct` = 100.0000 i 99.999986. Šema
  pozicija nepromenjena; `socialTrades` prazan u oba.
  `unrealizedCreditPct` je bio blizu `realizedCreditPct` (0.0323 vs 0.0299;
  0.0002 vs 0.0002) — semantika nepotvrđena.
- `GET /api/v1/market-data/instruments?instrumentIds=…` → 200; svi traženi
  instrumenti vraćeni; polja `instrumentID` (int), `instrumentDisplayName`,
  `symbolFull`, `instrumentTypeID`, `exchangeID`, `stocksIndustryID`
  (OpenAPI piše `stocksIndustryId`), `priceSource`, `hasExpirationDate`,
  `isInternalInstrument`, `images[]` i nedokumentovano `distributionType`.
- `GET /api/v1/market-data/instrument-types` → 200; 10 tipova (Forex,
  Commodity, CFD, Indices, Stocks, ETF, Bonds, TrustFunds, Options,
  Crypto). Market-data endpointi imaju zasebnu deljenu kvotu 120/60s.

**Odluka:**

- `realizedCreditPct` je **keš (cash weight) u procentnim poenima** →
  `LivePortfolio::$cashWeight` (`Percentage`, nikad negativan; odsutno ⇒
  `null` = nepoznato, nikad 0). Radna interpretacija na 2 uzorka; ako
  budući snapshot odstupi (Σ pozicija + keš daleko od 100), to se
  prikazuje kao `unknown_weight`, ne skriva. `unrealizedCreditPct` se i
  dalje ne koristi. Arhitektonsko pravilo da `LivePortfolio`/
  `PortfolioPosition` ne nose sirova imena `realizedCreditPct`/
  `unrealizedCreditPct` ostaje — keš je modeliran kao imenovani
  `cashWeight`.
- Sintetički `live-portfolio.json` dobija `realizedCreditPct: 0`
  (pozicije već daju tačno 100), da fixture bude konzistentan sa D-037.
- `EtoroClient::instrumentDisplayData(list<int>)` (1–100 id-jeva po
  zahtevu, comma-separated) i `EtoroClient::instrumentTypes()`;
  `InstrumentMetadataMapper` — `instrumentID` obavezan, ostala polja su
  obogaćenje i degradiraju u `null`, nikad ne odbacuju red; prihvata obe
  varijante `stocksIndustryID/Id`.
- „Asset class“ u koncentraciji (PROJECT.md §13.6) = eToro instrument
  type (`instrumentTypeID` → opis iz kataloga). Sektor (`stocksIndustryID`)
  se čuva, ali se ne prikazuje dok nema kataloga industrija.

## D-038: Portfolio persistence — snapshot, pozicije, instrumenti i idempotentnost

**Datum:** 2026-10-05
**Status:** usvojeno (Milestone 4, Checkpoint B; bez live poziva)

**Kontekst:** PROJECT.md §11 predlaže `portfolio_snapshots`,
`portfolio_positions` i `instruments` sa decimal težinama i `raw_payload`
kolonama. D-037 je potvrdio šemu payload-a i odlučio šta domen nosi
(`LivePortfolio`/`PortfolioPosition` namerno bez `netProfit`, `openRate`,
`unrealizedCreditPct` i sirovog payload-a).

**Odluka:**

1. **Šema (odstupanja od §11):**
   - Težine su `*_ppb` signed BIGINT (tačan decimalni udeo u ppb, isto
     kao `performance_points.gain_ppb`, D-034) umesto DECIMAL:
     `cash_weight_ppb` (nullable — odsutan `realizedCreditPct` je
     „nepoznato“, nikad 0), `invested_weight_ppb` (Σ pozicija, NOT NULL),
     `portfolio_positions.weight_ppb`.
   - **Bez `raw_payload` kolona** ni u jednoj tabeli: nijedna druga tabela
     ne čuva sirove odgovore (D-017; raw capture je samo opt-in fajl), a
     domen ih namerno ne nosi.
   - **Bez `open_rate` i `net_profit`**: D-037 — semantika nepotvrđena,
     nema konzumenta. `take_profit_rate`/`stop_loss_rate` su
     DECIMAL(30,10) (float iz JSON-a → fiksni decimalni string sa 10
     decimala, nikad float u bazi); dodat `trailing_stop_loss` jer ga
     domen nosi.
   - `external_position_id` je NOT NULL (mapper ga zahteva);
     `position_index` čuva redosled iz payload-a i jedinstven je po
     snapshot-u — duplikat `positionId` se čuva kako je primljen, jer ga
     coverage kalkulator prijavljuje kao data-quality upozorenje (isti
     ugovor kao `LivePortfolioCoverageAdapter`).
   - Snapshot dobija `position_count`, `social_trades_count` (socialTrades
     se samo broje, D-037) i `last_confirmed_at`.
   - `instruments`: `exchange` → `exchange_id` i dodati
     `instrument_type_id`, `stocks_industry_id`, `metadata_synced_at`, jer
     API vraća samo ID-jeve (D-037); `asset_class` = opis tipa iz
     kataloga. `instrument_id` na poziciji je nullable FK
     (`nullOnDelete`), ali importer uvek prvo napravi „golu“ instrument
     vrstu (`insertOrIgnore`), pa je link uvek popunjen.
   - `traders.portfolio_synced_at` + `traders.portfolio_visibility`
     (ponovo korišćen enum `PerformanceVisibility`: available/private/
     not_found — isti ishodi kao performance endpoint).
   - Kratka imena indeksa (`pf_snap_*`, `pf_pos_*`) zbog MySQL limita od
     64 znaka (bfd629a).
2. **`source_hash`** = sha256 nad kanonskim JSON-om normalizovanog
   sadržaja tačno onako kako se upisuje: verzija normalizacije
   (`portfolio-v1`), `cash_weight_ppb`, `social_trades_count` i pozicije
   **u redosledu iz payload-a** (id, instrument, ppb težina, otvaranje,
   smer, leverage, TP/SL kao decimal string, trailing). Polja koja se ne
   modeluju (`netProfit`, `openRate`, `unrealizedCreditPct`, …) ne utiču
   na hash. Promena normalizacije zahteva novu verziju.
3. **Idempotentnost = poređenje sa POSLEDNJIM snapshot-om tradera**, ne
   `unique(trader_id, source_hash)`: isti sadržaj kao poslednji → nema
   novog reda, samo `last_confirmed_at` (i `traders.portfolio_synced_at`)
   ide napred. Portfolio A → B → A daje tri snapshot-a (treći je novo
   posmatranje, vremenska linija se ne gubi). Indeks
   `(trader_id, source_hash)` je zato običan, ne unique; trka dva
   paralelna importa istog tradera sprečena je `lockForUpdate` na redu
   tradera unutar transakcije (plus `ShouldBeUnique` job).
   Napomena: `investmentPct` se menja sa tržištem, pa realno skoro svaki
   sync pravi novi snapshot — retencija još nije definisana.
4. **`App\Application\Traders\SyncTraderPortfolio`**: jedan `portfolio`
   `ImportRun` po pozivu, pre HTTP-a; poziva samo
   `EtoroClient::userLivePortfolio`. Ishodi isti kao D-034: 403 →
   `not_visible` + `private` (sačuvani snapshot-i ostaju), 404 →
   `not_found`, 429/5xx/transport → `temporarily_unavailable` (jedini
   retryable, nosi Retry-After), mapping greška → fail closed, ništa se
   ne upisuje. Live portfolio payload nema username, pa provera
   identiteta (kao u D-034) ovde nije moguća. `error_summary` je statičan
   tekst; metadata nosi samo ID-jeve i brojeve.
5. **Obogaćivanje je best-effort** (`EnrichInstrumentMetadata`, posle
   commit-a snapshot-a): traže se samo instrumenti bez metapodataka ili
   stariji od 7 dana; `instrumentDisplayData` u batch-evima ≤ 100 id-jeva,
   pa jednom `instrumentTypes` (samo ako je stigao bar jedan red).
   eToro request/response/mapping greške se ne propagiraju: snapshot
   ostaje, ImportRun je `partial` (`success_count` 1, `failure_count` =
   broj neuspelih metadata zahteva, statičan `error_summary`).
   Instrument dobija `metadata_synced_at` tek kad ima validan
   `instrumentTypeID` I katalog sadrži taj tip (asset class razrešen) —
   inače ostaje kandidat za sledeći import, a run je `partial` (i kad
   katalog uspešno stigne, ali ne poznaje tip, ili instrument nema/ima
   neispravan type ID). Klasifikacija (`instrument_type_id`,
   `asset_class`, `metadata_synced_at`) upisuje se kao celina, tako da
   `asset_class` uvek opisuje sačuvani tip: razrešen tip zamenjuje celinu;
   nerazrešen, ali nepromenjen tip (npr. katalog privremeno nedostupan)
   zadržava prethodnu celinu, bez pomeranja timestamp-a; nerazrešen
   promenjen ili nedostajući/neispravan tip upisuje novi type ID i briše
   `asset_class` i `metadata_synced_at` (nepoznato, nikad pogrešna stara
   klasa). U oba nerazrešena slučaja run je `partial`. Neočekivane
   (ne-eToro) greške i dalje obaraju run kao `unexpected_failure`.
6. **Queue/komanda:** `SyncTraderPortfolioJob` (unique po trader-u 1h,
   deljeni `etoro-api` limiter, `retryUntil` 6h, release samo za
   retryable ishod portfolio zahteva — metapodaci se ne retry-uju kroz
   job). `QueueTraderPortfolioSync` je jedini ulaz za UI/konzolu.
   `php artisan etoro:sync-portfolio {username?} {--watched} {--now}` —
   isti ugovor kao `etoro:sync-performance`; ne štampa pozicije, težine ni
   instrumente. Budžet: 1 zahtev (portfolio) + do ⌈n/100⌉ + 1 market-data
   zahteva kad treba obogaćivanje; market-data ima zasebnu kvotu 120/60s
   (D-037). Rate limiting je po HTTP pokušaju, ne po job-u — vidi D-039
   (zamenjuje raniji `RateLimited('etoro-api')` middleware).
7. **Scheduler: namerno NIJE dodat.** PROJECT.md §16 predviđa sync na 6 h,
   a unos bi bio trivijalan, ali launchd scheduler/worker su već aktivni
   (f38c109): unos bi po merge-u odmah pokrenuo automatske live pozive i
   (tačka 3) gomilanje snapshot-a bez politike retencije. Uključiti posle
   review-a i odluke o retenciji (test potvrđuje da unos ne postoji).
8. **Live:** nijedan poziv. Šema i semantika su već potvrđene u D-037;
   end-to-end provera bi trošila 3 GET-a (portfolio + instruments +
   types), više od odobrenog limita. MySQL putanja je proverena lokalno
   (`migrate` na `trade_ledger` + import sa `Http::fake` u transakciji
   koja je vraćena — bez trajnih upisa).

## D-039: eToro rate limiting po HTTP pokušaju (transportni sloj)

**Datum:** 2026-10-05
**Status:** usvojeno (Milestone 4, Checkpoint B review; zamenjuje
job-level `RateLimited('etoro-api')` iz D-034/D-038)

**Kontekst:** `RateLimited('etoro-api')` middleware je trošio jednu
dozvolu po job-u, a performance sync šalje 2 zahteva (D-035), portfolio
sync 1 + ⌈n/100⌉ + 1, a interni retry-ji `EtoroClient`-a (do 3 pokušaja)
dodatno. Sinhrono izvršavanje (`--now`) i `etoro:doctor` limiter su
potpuno zaobilazili.

**Odluka:**

1. `App\Etoro\EtoroRequestThrottle` troši **jednu dozvolu pre svakog
   HTTP pokušaja** u `EtoroClient::get()` — uključujući retry-je posle
   5xx/transport greške. Važi za svaki ulaz (queue, `--now`, doctor,
   discovery), jer svi idu kroz `EtoroClient`.
2. **Dva limitera:** `etoro-api` (`etoro.requests_per_minute`,
   ETORO_REQUESTS_PER_MINUTE, default 45 od eToro 60/60 s) za sve
   ne-market-data endpointe, i `etoro-market-data`
   (`etoro.market_data_requests_per_minute` = 90 od eToro 120/60 s,
   D-037) za `/api/v1/market-data/*`. Zasebna kvota zaslužuje zaseban
   budžet: inače bi obogaćivanje metapodataka trošilo deljeni budžet
   portfolio/gain zahteva, a ne bi štitilo market-data kvotu ništa
   bolje. Fail closed: nedostajući limiter = 1/min.
3. **Kad nema dozvole: bez čekanja** (revidirano u drugoj rundi review-a;
   prvobitno ograničeno blokirajuće čekanje od 20 s po pozivu
   `acquire()`). Granica po `acquire()` nije granica po job-u: sa do 3
   HTTP pokušaja, backoff-om i više zahteva u portfolio enrichment-u,
   čekanje se sabiralo i moglo da pređe worker timeout (60 s) i
   `retry_after` (90 s) — worker ubijen, ImportRun ostaje `running`, a
   sinhrone Filament akcije (profile lookup, discovery) bi visile.
   Sada `acquire()` odmah baca
   `EtoroRequestException::localBudgetExhausted()` — kategorija
   `RateLimited`, bez HTTP statusa, `locallyThrottled = true`,
   `retryAfterSeconds` = preostali prozor limitera (1–60 s); zahtev se NE
   šalje. `attemptCount` je broj pokušaja istog poziva koji su već
   poslati (npr. 1 kad je posle 503 lokalno odbijen retry) i `requestId`
   poslednjeg poslatog, pa `ImportRun.request_count` ostaje tačan.
   Throttle tako doprinosi 0 s trajanju bilo kog job-a ili web zahteva;
   use case-ovi ga mapiraju u `temporarily_unavailable` i ImportRun
   završavaju kao `failed` (nikad `running`): queued job se release-uje
   sa Retry-After, Filament akcija odmah prikazuje „did not complete“ sa
   linkom na run, a `--now` komande ne čekaju nego posle tabele ispisuju
   upozorenje da se ponovi kasnije ili koristi queue. Obogaćivanje
   metapodataka ga tretira kao neuspeo metadata zahtev (`partial`,
   ponovni pokušaj na sledećem importu). Odbačena alternativa: jedan
   ukupni deadline po job-u — zahteva provlačenje deadline-a kroz sve use
   case-ove i i dalje drži worker zauzetim čekajući tuđi saobraćaj, dok
   release vraća slot odmah. `etoro.rate_limit_max_wait_seconds` je
   uklonjen. Preostalo trajanje je samo HTTP vreme (timeout po pokušaju,
   `etoro.timeout_seconds`), nezavisno od ovog limitera.
4. `RateLimited` middleware je **uklonjen iz oba job-a** — nad istim
   limiterom bi dvostruko trošio dozvole, a kao grubi throttle job-ova
   ne dodaje zaštitu koju transportni limiter već ne daje.
5. Nove config vrednosti nemaju env varijable (fiksne u
   `config/etoro.php`), da se ne bi menjao `.env.example`; promena
   zahteva izmenu config-a.

**Posledica:** budžet je sada stvarni broj HTTP pokušaja. Brojač je u
cache store-u; atomičnost između paralelnih worker-a je ona koju daje
`RateLimiter::attempt` (moguće je malo prekoračenje pri trci — eToro
kvota ima rezervu 15/30 zahteva).

## D-040: Timeout queued sync job-ova i zatvaranje prekinutih ImportRun-ova

**Datum:** 2026-10-05
**Status:** usvojeno (Milestone 4, Checkpoint B review; dopunjuje D-034,
D-038, D-039)

**Kontekst:** `SyncTraderPerformanceJob` i `SyncTraderPortfolioJob` nisu
imali `$timeout` ni `failed()`. Launchd worker radi sa
`queue:work --tries=1 --max-time=3600` (podrazumevani timeout 60 s),
`retry_after` je 90 s (`config/queue.php`, database/redis/beanstalkd;
lokalni `.env` nije čitan — ako ga menja, uslov `timeout < retry_after`
treba ponovo proveriti). Najgori HTTP slučaj po zahtevu je 3 pokušaja ×
20 s (`etoro.timeout_seconds`) + backoff ≈ 61 s; performance šalje 2
zahteva (≈ 122 s), portfolio 1 + ⌈n/100⌉ + 1 (≥ 3 → ≈ 183 s). Throttle
doprinosi 0 s (D-039 t. 3). Ubijen pokušaj ostavljao je ImportRun
zauvek `running`; zbog `retryUntil()` (6 h) Laravel ga posle timeout-a ne
markira kao failed nego ponavlja posle `retry_after`.

**Odluka:**

1. **`public int $timeout = 80`** na oba job-a: ispod `retry_after` (90 s)
   sa 10 s rezerve za timeout handler i `failed()`, pa isti pokušaj nikad
   ne preuzme drugi worker; iznad worker-ovih 60 s. Najgori slučaj ne
   staje u 80 s i namerno se ne pokušava uklopiti (manje pokušaja/kraći
   HTTP timeout bi oslabio normalan oporavak od kratkih 5xx): uobičajen
   zahtev traje < 2 s, a 80 s se dostiže tek uz ≥ 4 zaglavljena pokušaja
   od 20 s — eToro „visi“, što nije stanje za brzo ponavljanje.
2. **`public bool $failOnTimeout = true`**: timeout završava job kao
   failed umesto ponavljanja u `retryUntil()` prozoru (zaglavljen
   endpoint bi se inače ponavljao na svakih 90 s satima i trošio budžet);
   unique lock se oslobađa, sledeći dispatch/scheduler pokušava ponovo.
   Brzi 429/5xx i dalje idu na `release()` sa Retry-After (D-034).
3. **Identifikacija run-ova:** job prosleđuje `$this->job->uuid()` use
   case-u (`SyncTraderPerformance::handle(..., $queueJobUuid)`,
   `SyncTraderPortfolio::handle(..., $queueJobUuid)`), koji ga upisuje u
   `import_runs.metadata.queue_job_uuid`. UUID je isti kroz sve
   release/retry pokušaje istog queued job-a, a različit od svakog drugog
   job-a i sinhronog (`--now`, Filament) run-a, koji ga nemaju.
4. **`App\Application\Imports\FailInterruptedImportRuns`** zatvara samo
   run-ove tog tipa sa tim UUID-om koji su još `running`: `failed`,
   `failure_count` 1, `finished_at`, statički `error_summary`, metadata
   `stop_reason = interrupted` i `interruption` = `timeout`
   (`TimeoutExceededException`), `max_attempts`
   (`MaxAttemptsExceededException`, npr. hard-kill posle isteka
   `retryUntil()`), `job_failed` (ostalo) ili `attempt_interrupted`.
   Završen run se nikad ne dira. Poziva se iz `failed(?Throwable)` oba
   job-a (radi i iz worker-ovog timeout handler-a — `Job::fail()` pre toga
   vraća otvorene transakcije na nivo 0, pa upis ne nestaje sa ubijenim
   procesom) i **na početku svakog `handle()`** za run-ove prethodnog
   pokušaja ubijenog bez `failed()` (SIGKILL, OOM, worker bez pcntl).
5. **`ImportRunFailureReason` nije proširen:** taj enum opisuje odbijene
   pojedinačne ranking unose (`import_run_failures`, konflikt identiteta),
   ne ishod celog run-a. Ishod run-a se i dalje beleži kroz `status`,
   `error_summary` i `metadata.stop_reason`, kao i svi ostali ishodi sync
   use case-ova; `interrupted` je nova vrednost tog polja.

**Posledica:** nijedan run koji je queued job otvorio ne ostaje trajno
`running` posle timeout-a, failed-a ili ponovljenog pokušaja. Preostalo:
run ubijenog pokušaja koji se nikad više ne pokrene i čiji `failed()`
nije pozvan (npr. job ručno obrisan iz `jobs` tabele) ostaje `running`.

## D-041: Koncentracija i leverage izloženost — osnovica težina, sektor i nepoznati podaci

**Datum:** 2026-10-06
**Status:** usvojeno (Milestone 4, Checkpoint C; bez live poziva)

**Kontekst:** PROJECT.md §13.6 (HHI, effective positions, largest, top 3
po instrumentu, asset class-u i sektoru „kad postoje podaci“) i §13.7
(weighted leverage, leveraged weight, max leverage, broj leveraged
pozicija). Sačuvani snapshot (D-038) nosi težine u ppb kao udeo CELOG
portfolija (keš uključen), `cash_weight_ppb` (null = nepoznato), leverage
po poziciji (nullable) i `instruments.asset_class` (null dok tip nije
razrešen). Sektor (`stocks_industry_id`) se čuva, ali nema katalog (D-037).

**Odluka:**

1. **Osnovica težina = invested-only** (`ExposureWeightBasis::InvestedOnly`):
   wᵢ = gᵢ / W, gde je W zbir upotrebljivih težina pozicija. Koncentracija
   opisuje raspodelu uloženog kapitala; keš nije pozicija i ne „razblažuje“
   HHI (portfolio 50% keš + 1 pozicija ima HHI = 1, ne 0.25). Ovo je
   eksplicitni „invested-only“ prikaz iz §12.5, pa rezultat uvek nosi i
   osnovicu: `investedWeight` (Σ pozicija, osnovica celog portfolija),
   `cashWeight` (null ⇒ nepoznato) i `unaccountedWeight` = 1 − invested −
   cash (§12.5 `unknown_weight`; null kad je keš nepoznat, može biti
   negativan). Leverage koristi istu osnovicu.
2. **Formule tačno po §13.6:** HHI = Σwᵢ², effective = 1/HHI, largest =
   max wᵢ, top 3 = zbir tri najveća wᵢ (sa < 3 grupe — zbir svih). Po
   instrumentu se pozicije istog `external_instrument_id` sabiraju pre
   kvadriranja. Računa se tačno na celim ppb brojevima (BCMath):
   HHI = Σgᵢ² / W², effective = W² / Σgᵢ²; zaokruživanje half-up samo na
   kraju (težine u ppb kao `Percentage`, effective/weighted leverage kao
   decimal string sa 9 decimala — broj, ne udeo). Grupe su sortirane
   opadajuće po težini, izjednačenja po ključu (nepoznato poslednje).
3. **Nepoznato nikad nije 0 i nikad ne nestaje:**
   - pozicija bez težine (null) ili sa negativnom težinom ne ulazi ni u
     jednu ponderisanu metriku, ali se broji (`missingWeightCount`,
     `negativeWeightCount`) i diže upozorenje;
   - upotrebljiva težina bez asset class-a ide u eksplicitnu „unknown“
     grupu (`key = null`), koja ulazi u metrike kao JEDNA grupa (stvarna
     uložena težina); dimenzija je tada `partial` i nosi
     `classifiedWeight`/`unclassifiedWeight` — HHI je aproksimacija i UI
     mora da ga prikaže uz klasifikovani udeo. Kad ništa nije klasifikovano
     → `unavailable` (`no_data`), grupe i udeli i dalje vidljivi;
   - prazan portfolio i portfolio samo sa kešom → sve dimenzije
     `unavailable` (`no_invested_weight`), bez metrika.
4. **Sektor se NE računa** (`unavailable`, `classification_not_supported`):
   `stocksIndustryID` nema katalog (D-037), nije potvrđeno da je to sektor
   (a ne finija industrija), a ne-akcijski instrumenti ga nemaju, pa se ne
   zna da li je null „nepoznato“ ili „nije primenljivo“. HHI po ID-ju bi
   bio broj bez potvrđenog značenja. Kalkulator je generičan
   (`PortfolioHoldings::$sectorClassificationAvailable`, unknown grupa i
   upozorenje `sector_unknown` rade isto kao za asset class), pa se sektor
   uključuje samo promenom adaptera kad katalog i semantika budu potvrđeni.
5. **Leverage (§13.7)** nikad ne pretpostavlja 1x: nedostajući ili
   nevalidan (< 1) leverage ide u `unknownLeverageWeight`, status
   `partial`. `weightedLeverage` = tačno §13.7 Σ(wᵢ × Lᵢ) na invested-only
   osnovici i postoji samo kad je `unknownLeverageWeight` = 0; čim
   ponderisana pozicija nema (validan) leverage, `weightedLeverage = null`
   (nije utvrdiv — ni pretpostavka 1x, ni renormalizacija na poznati deo,
   jer bi to tiho promenilo osnovicu). Umesto toga se uvek (osim
   `no_invested_weight`) izlaže `knownLeverageContribution` = Σ(wᵢ × Lᵢ)
   samo nad poznatim, wᵢ na CELOJ invested osnovici, i `knownLeverageWeight`
   = Σwᵢ poznatih (primer 50%×1, 30% nepoznato, 20%×3 → weighted = null,
   doprinos = 1.1, poznata težina = 70%). Donja granica (nepoznato kao 1x)
   se ne izlaže — UI je može izvesti kao doprinos + unknown weight, uz
   eksplicitnu oznaku granice. `leveragedWeight` (L > 1) +
   `unleveragedWeight` (L = 1) + `unknownLeverageWeight` = 1 na
   invested-only osnovici. `maxLeverage` i brojači (leveraged/known/
   missing/invalid) koriste sve pozicije, i one bez težine.
   **Pozicija bez upotrebljive težine** (null ili negativna) je po
   definiciji van invested osnovice (tačka 1), isto kao u koncentraciji:
   leverage rezultat je broji (`missingWeightCount`, `negativeWeightCount`,
   isto značenje kao u `ConcentrationResult`) i status je `partial`, jer
   ponderisana izloženost te pozicije nije poznata. `weightedLeverage`
   pritom OSTAJE vrednost nad poznatom invested osnovicom (kao HHI u
   koncentraciji, koji se računa uprkos `missingWeightCount` > 0): za
   razliku od nepoznatog leverage-a, koji je deo osnovice W pa Σ(wᵢ × Lᵢ)
   nije utvrdiv, pozicija bez težine menja samu osnovicu, ne utvrdivost
   zbira nad njom. Primer: 60% × 1x + pozicija nepoznate težine × 5x +
   negativna težina × 2x → weighted = 1, `partial`, missing/negative = 1/1.
6. **Slojevi:** čisti `ConcentrationCalculator` (`concentration-v1`) i
   `LeverageExposureCalculator` (`leverage-v1`) nad
   `PortfolioHoldings`/`PortfolioHolding` (App\Analytics, bez Laravel-a);
   tanak `App\Application\Traders\BuildPortfolioExposureReport` čita samo
   sačuvani snapshot + pozicije + instrumente (nikad eToro API) i vraća
   `PortfolioExposureReport`; `latestForTrader()` uzima poslednji
   `captured_at`. **Bez nove tabele:** rezultat je jeftina, deterministička
   funkcija sačuvanog snapshot-a; keš/persistencija ima smisla tek ako
   Compare (M5) bude računao za mnogo tradera odjednom.

## D-042: Copy simulator — sačuvane simulacije, metodologija i reproducibilnost

**Datum:** 2026-10-06
**Status:** usvojeno (Milestone 4, Checkpoint D; bez live poziva)

**Kontekst:** PROJECT.md Flow D, §11 `copy_simulations`, §12 i §20 M4
(objašnjenje svake preskočene pozicije, preseti $200/$500/$1,000, targeti
90/95/99/100%, rezultat reproducibilan iz sačuvanog snapshot-a).
`CopyCoverageCalculator`, `LivePortfolioCoverageAdapter`,
`EvaluateTraderCopyCoverage`, `FindTraderMinimumCopyAmountForCoverage` i
`etoro:copy-target` već računaju coverage nad LIVE portfolijom (D-019 do
D-023); snapshot-i se čuvaju od D-038.

**Odluka:**

1. **Šema `copy_simulations` (odstupanja od §11):**
   - Novac u **integer centima** (`*_cents`, unsigned BIGINT), ne DECIMAL:
     isti oblik kao `Money` (§9 dozvoljava integer minor units), bez
     konverzije na granici. Težine u **ppb** (signed BIGINT), kao D-038:
     `eligible_weight_ppb`, `skipped_weight_ppb`, `cash_weight_ppb`
     (nullable — nepoznat keš), `target_coverage_ppb` (nullable).
   - **`analysis_profile_id` izostavljen**: tabela ne postoji, a kolona bez
     FK-a bi bila nevezan broj bez značenja. Uvodi se migracijom (sa FK-om)
     zajedno sa `analysis_profiles`.
   - Dodat **`platform_minimum_copy_amount_cents`** (default 20_000): ulazi
     u minimum za target (§12.2 korak 3), pa mora biti sačuvan da bi red
     ostao reproducibilan i kad se podrazumevana vrednost promeni.
   - `eligible_positions_count`/`skipped_positions_count`/težine/
     `minimum_target_amount_cents` su sažetak `result`-a za upite.
     `minimum_target_amount_cents` je *effective* minimum (sa platformskim
     minimumom); null bez targeta ili bez pozitivne težine.
   - FK-ovi `trader_id` i `portfolio_snapshot_id` sa `cascadeOnDelete`
     (simulacija bez snapshot-a nije reproducibilna). Kratka imena indeksa
     (`copy_sim_trader_calc_idx`, `copy_sim_snap_method_idx`), MySQL limit.
2. **Adapter bez dupliranja:** `StoredPortfolioCoverageAdapter` vraća
   sačuvani snapshot u domain `LivePortfolio` (redosled `position_index`,
   tačne ppb težine, keš, broj socialTrades; TP/SL ostaju null — ne
   vraćaju se iz decimal stringa u float) i predaje ga postojećem
   `LivePortfolioCoverageAdapter`-u. Live i sačuvani put tako dele jedno
   pravilo prevođenja. Test poredi sačuvani put sa živim use case-ovima
   (`EvaluateTraderCopyCoverage`, `FindTraderMinimumCopyAmountForCoverage`)
   nad istim payload-om (fixture i „irregular“ varijanta: keš, socialTrades,
   duplikat id-ja, negativna i nulta težina) — rezultati su `toEqual`.
3. **Slojevi:** `CopyCoverageCalculator` ostaje jedini vlasnik eligibility-ja,
   razloga preskakanja, breakpoint-a i target minimuma (javni API
   nepromenjen). Novi čisti `App\Analytics\Calculators\CopySimulationCalculator`
   samo dodaje: pozicije u redosledu snapshot-a (spajanjem eligible/skipped
   lista), procenjeni iznos floor(A × wᵢ) u centima (§12.1; floor je
   konzistentan sa eligibility-jem jer je M ceo broj centi), coverage
   relativno prema pozitivnoj težini (floor, kao `achievedRatio`, D-022) i
   §12.5 podelu keš / `unknown_weight` = 1 − Σ pozicija − keš (null kad je
   keš nepoznat). `App\Application\Traders\SimulateCopyAmount` upisuje red,
   `BuildCopySimulationMatrix` vraća matricu (ništa ne upisuje); oba čitaju
   samo sačuvani snapshot, nikad eToro API. Tekstovi objašnjenja su u
   application sloju (engleski, kao UI).
4. **Upozorenja simulatora** (`CopySimulationWarning`): calculator-ov
   `observed_weight_not_whole` se NE prenosi kao upozorenje — pozicije
   nikad nisu 100% kad postoji keš (§12.5). Umesto njega: `cash_weight_unknown`
   i `unaccounted_weight` (|unknown| > 100_000 ppb = 0.01 p.p.; manje je
   zaokruživanje izvora — live uzorak 99.999986, D-037; tačna vrednost se
   uvek prikazuje). Ostala: `empty_snapshot`, `no_positive_weight`,
   `duplicate_position_id`, `negative_weight_ignored`,
   `unmodeled_portfolio_entries_present`, `copy_amount_below_platform_minimum`.
   **`is_estimate`** (§12.2 korak 6) = duplikat, negativna težina,
   nemodelovani unosi, nepoznat keš ili neobjašnjena težina. Prazan /
   nulti snapshot je „kompletan ali nepokriv“ (D-022), a iznos ispod
   platformskog minimuma je problem ulaza — ni jedno nije procena. Sirova
   calculator upozorenja ostaju u `result.calculator_warnings`.
5. **Razlozi preskakanja** (`skip_reason` = `PositionSkipReason`):
   `below_minimum` (objašnjenje: iznos A, težina, procenjeni iznos, M i
   iznos od kog se pozicija kopira), `zero_weight`, `negative_weight`.
   Iznos ispod $200 nije razlog preskakanja pozicije, nego upozorenje na
   nivou simulacije; eligibility se računa isto.
6. **Preseti i targeti na jednom mestu:** `CopyAmountPreset` ($200/$500/
   $1,000 u centima), `CoverageTargetPreset` (90/95/99/100% u ppb;
   `isInformational()` samo za 100% — §12.3), `CopySimulationSettings`
   (M = $1, platformski minimum $200, `METHODOLOGY_VERSION`). Target 100%
   je tačno §12.3: max($200, ceil(M / najmanja pozitivna težina)) — računa
   se uvek, i kad je ekonomski besmislen (test: težina 1 ppb → $1
   milijarda), bez gornje granice. Bez pozitivne težine target nije
   dostižan: svi iznosi null (N/A, `is_reachable = false`), nikad 0.
7. **Reproducibilnost:** `result` je čista funkcija sadržaja snapshot-a,
   ulaza i `METHODOLOGY_VERSION` (`copy-simulation-v1`) — bez vremena,
   metapodataka instrumenata i zastarelosti (staleness računa UI iz
   `captured_at`/`last_confirmed_at`), samo int/string/bool/null, bez
   float-a. `recalculate()` vraća dokument iznova; `reproduces()` ga
   poredi striktno po vrednostima, a ključeve objekata nezavisno od
   redosleda, jer MySQL JSON tip preuređuje ključeve pri upisu (provereno
   na `trade_ledger` u transakciji koja je vraćena; liste zadržavaju
   redosled). Promena bilo čega što oblikuje `result` (kalkulatori,
   adapter, oblik dokumenta, tekstovi) = nova verzija; stari redovi
   ostaju, a `recalculate()` za drugu verziju baca
   `UnsupportedCopySimulationMethodology`. Svaki poziv `handle()` dodaje
   novi red (bez deduplikacije); matrica za UI se ne čuva.
8. **CLI:** `php artisan etoro:simulate-copy {username} {amount}
   {--target=} {--minimum-position=1} {--snapshot=}` — iznosi u USD sa
   najviše 2 decimale, tačno parsirani u cente (bez float-a); `--target` po
   ugovoru D-023 (procentni poeni). Radi nad poslednjim ili zadatim
   snapshot-om tog tradera, bez HTTP-a (radi i sa `ETORO_ENABLED=false`),
   upisuje simulaciju i ispisuje preskočene pozicije sa objašnjenjem.
   `etoro:copy-target` / `etoro:copy-coverage` nepromenjeni.

## D-043: Portfolio i copy simulator UI na stranici tradera

**Datum:** 2026-10-06
**Status:** usvojeno (Milestone 4, Checkpoint E; bez live poziva)

**Odluka:**

1. **[SUPERSEDED — D-045, 2026-10-06, odluka vlasnika]** ~~Privatan /
   nepronađen portfolio skriva sačuvane snapshot-e u UI-ju.~~
   Kad je `traders.portfolio_visibility` `private` ili `not_found`, sekcija
   „Portfolio“ i simulator prikazuju prazno stanje sa razlogom i brojem
   sačuvanih snapshot-a (ostaju u bazi, D-038), umesto da stari snapshot
   predstave kao trenutni portfolio. Sačuvane simulacije ostaju vidljive
   (istorija, reproducibilne). CLI `etoro:simulate-copy --snapshot=` i
   dalje radi nad bilo kojim sačuvanim snapshot-om.
2. **Read model:** `App\Application\Traders\BuildTraderPortfolioReport`
   (scoped singleton, memoizovan po trader-u, kao D-036) vraća
   `TraderPortfolioReport` (poslednji vidljiv snapshot, pozicije sa
   instrumentima, `PortfolioExposureReport`); čita samo bazu.
3. **Simulator = `SimulateCopyAmount::preview()` + `BuildCopySimulationMatrix`.**
   `preview()` vraća isti `result` dokument koji `handle()` upisuje (bez
   upisa), pa „Save simulation“ čuva tačno ono što je prikazano;
   metodologija (`copy-simulation-v1`) se ne menja. Lista poslednjih 10
   sačuvanih simulacija pri renderovanju poziva `reproduces()` i prikazuje
   ishod („Yes“ / „NO — differs“ / „Not checked (other methodology)“).
4. **Parsiranje ulaza** (USD sa ≤ 2 decimale, target u procentnim
   poenima — D-023) je izdvojeno u `CopySimulationInput` i deli ga CLI;
   UI i CLI odbijaju minimum pozicije od $0, a UI i iznos od $0.
5. **Prikaz:** Filament komponente su `TraderPortfolioSection` (infolist
   „Portfolio sync“), footer widget-i `TraderPortfolio` i
   `CopyAmountSimulator` (Livewire + Filament akcija `saveSimulation`) i
   header akcija „Sync portfolio“ kroz `QueueTraderPortfolioSync` (isti
   obrazac i uslovi kao „Sync performance“). Osnovica je uvek imenovana:
   težine pozicija = udeo celog portfolija; koncentracija/leverage =
   invested-only (D-041); coverage = udeo pozitivne težine pozicija
   (D-022). Vremena u Blade prikazu su `Europe/Malta` sa oznakom zone
   (§9); postojeći infolist `dateTime()` unosi su ostali nepromenjeni
   (prošireno na ceo UI u D-046).
   Opcija „use visible cash allocation“ iz §15 nije dodata — keš se uvek
   prikazuje odvojeno, a kalkulator nema taj ulaz.
6. **Gornja granica unosa i rezultat van opsega.** Iznos kopiranja i
   minimum pozicije su u UI-ju i CLI-ju ograničeni na
   `CopySimulationInput::MAXIMUM_AMOUNT_CENTS` = **$10,000,000** (10⁹
   centi; granica uključena). Izbor nije ekonomski nego aritmetički: za
   bilo koju pozitivnu težinu w ≥ 1 ppb breakpoint ceil(M × 10⁹ / w) ≤ 10¹⁸
   centi i procenjeni iznos floor(A × w / 10⁹) ≤ w — oba ispod
   `PHP_INT_MAX` — pa u granicama nijedna cifra simulatora ne može da
   izađe iz opsega `Money`. Odluka D-042 tačka 6 (target 100% bez gornje
   granice) ostaje: na granici, težina 1 ppb daje $10 kvadriliona.
   Bez obzira na granice (direktan poziv, oštećene težine čiji zbir
   prelazi `PHP_INT_MAX`), `CoverageCalculationException` se hvata u
   application sloju; `CopyCoverageCalculator` i rezultati za normalne
   vrednosti su nepromenjeni:
   - `BuildCopySimulationMatrix` za takav preset/target vraća eksplicitan
     status van opsega (`presetIsOutOfRange()`, `targetIsOutOfRange()`,
     `isOutOfRange()`); pristup takvoj stavci baca
     `CopySimulationOutOfRange`. Svi preseti računaju breakpoint svake
     pozicije, pa su van opsega zajedno; target-i pojedinačno.
   - `SimulateCopyAmount::preview()`/`handle()` bacaju
     `CopySimulationOutOfRange`; `handle()` tada ništa ne upisuje — u bazi
     nikad nema reda koji `recalculate()` ne bi mogao da ponovi.
   - UI i dalje renderuje unos iznad granice (uz grešku validacije) i
     takve ćelije prikazuje kao „Out of range — practically unreachable“,
     nikad 500. „Save simulation“ takav ulaz odbija validacijom (a
     preostali slučaj van opsega obaveštenjem „Nothing was saved“). CLI
     odbija ulaz iznad granice (`INVALID`), a rezultat van opsega
     prijavljuje greškom i `FAILURE`, bez upisa.
   - Lista sačuvanih simulacija hvata i `CopySimulationOutOfRange` iz
     `reproduces()` (red trenutne metodologije upisan mimo granica, npr.
     direktno u bazu) i prikazuje „Not reproducible — out of range“, nikad
     500.

## D-044: Live portfolio — razlomak sekunde u `openTimestamp` i dijagnoza `mapping_failed`

**Datum:** 2026-10-06
**Status:** usvojeno (Milestone 4, Checkpoint F; live pozivi 3–4/10 iz
dnevnog pool-a)

**Kontekst:** live `etoro:sync-portfolio --now` nad dev bazom vratio je
`mapping_failed` (HTTP uspeo, 1 zahtev, bez snapshot-a). ImportRun nije
čuvao razlog, pa je dijagnoza tražila live poziv.

**Dokaz (samo šema/tipovi, bez vrednosti i identiteta):**

- Poziv 3 (stari mapper): `EtoroMappingException` na
  `positions[i].openTimestamp`, razlog `malformed_timestamp`. Ostatak šeme
  identičan D-037: ista polja pozicija, tipovi očekivani, `socialTrades`
  prazan, Σ `investmentPct` + `realizedCreditPct` ≈ 100.
- Poziv 4 (popravljen mapper, bez upisa u bazu): oblici `openTimestamp`
  (cifre maskirane) — `9999-99-99T99:99:99.999Z`, `…99.99Z` i `…99.9Z`
  (razlomak sekunde 1–3 cifre, nule na kraju odsečene, uvek `Z`). Svih
  225 pozicija mapirano, nijedan `openedAt` null, keš poznat.
- Uzorci iz D-037 imali su samo cele sekunde (`…:SSZ`) — mapper je
  podržavao samo taj oblik, namerno strogo („nov format = eksplicitno
  proširenje“). Ovo je to proširenje; nije privatan/prazan portfolio ni
  smart-portfolio specifičnost.
- Uzgred (bez promene ponašanja): `takeProfitRate` je 0 u velikoj većini
  pozicija, `stopLossRate` povremeno 0 — verovatno „nije postavljen“;
  semantika nepotvrđena, vrednosti se i dalje čuvaju kakve jesu.

**Odluka:**

1. `LivePortfolioMapper` prihvata tačno dva oblika, oba UTC sa `Z`: cele
   sekunde i `.` + 1–7 cifara razlomka. Razlomak se dopunjuje/odseca na 6
   cifara (PHP `u`; 7. cifra, ispod mikrosekunde, se odbacuje), a striktna
   provera re-formatiranjem ostaje. I dalje se odbijaju: offset umesto
   `Z`, razlomak bez `Z`, `.` bez cifara, > 7 cifara, zarez, nevažeći
   datum, null bajt. Neparsiran timestamp se ne degradira u `null` —
   tiho gubljenje datuma otvaranja sakrilo bi budući drift formata.
2. Sačuvana pozicija i `source_hash` i dalje koriste `opened_at` na
   sekundu (`Y-m-d H:i:s`); `HASH_VERSION` ostaje `portfolio-v1` (za cele
   sekunde normalizovan sadržaj je nepromenjen).
3. `mapping_failed` ImportRun čuva `metadata.mapping_error` =
   `{mapper, field_path, reason, expected_type, actual_type}` iz
   `EtoroMappingException`, a `error_summary` postaje
   „… mapping_failed (<reason> at <field_path>).“. Izuzetak po ugovoru
   nosi samo statičke putanje polja (sa indeksom pozicije), kod razloga i
   imena tipova (`get_debug_type`) — nikad vrednost, payload ili
   identitet. Ključ postoji samo na `mapping_failed` run-ovima.
4. Testovi: sintetički `live-portfolio-fractional-timestamps.json` (ista
   struktura kao stvarni payload, izmišljene vrednosti, mešoviti oblici
   timestamp-a, pozitivan keš); unit testovi prihvatanja/odbijanja
   razlomka; feature testovi dijagnostike (bez curenja sentinel vrednosti)
   i sync-a sa razlomcima.

## D-045: Privatan / nepronađen portfolio — prikaz poslednjeg poznatog snapshot-a

**Datum:** 2026-10-06
**Status:** usvojeno (Milestone 4, Checkpoint F; odluka vlasnika; zamenjuje
D-043 tačku 1; bez live poziva)

**Kontekst:** D-043 tačka 1 je za `portfolio_visibility` `private` /
`not_found` skrivala sve sačuvane snapshot-e. Vlasnik je odlučio da je
poslednji poznati portfolio korisniji od praznog stanja, pod uslovom da je
jasno označen kao zastareo.

**Odluka:**

1. `BuildTraderPortfolioReport` uvek vraća poslednji sačuvani snapshot
   (`captured_at` desc, `id` desc), bez obzira na vidljivost.
   `TraderPortfolioReport::isStale()` = postoji snapshot ∧ vidljivost je
   `private`/`not_found` (`isNoLongerVisible()`).
2. Sekcija „Portfolio“ tada prikazuje pozicije, koncentraciju i leverage
   tog snapshot-a, a na vrhu Filament `callout` (`warning`, ikona,
   kompajlirani dark-mode stil): „Last known snapshot — may be outdated“ /
   „Portfolio is now private — showing the last known snapshot from
   <`last_confirmed_at`, Europe/Malta + zona>; the data may be outdated.“
   (za `not_found`: „This trader was not found on eToro by the last
   portfolio sync — …“). Datum je `last_confirmed_at` — poslednji trenutak
   kad je taj sadržaj stvarno viđen (D-038).
3. Copy simulator radi nad istim snapshot-om sa istim upozorenjem iznad
   unosa i `danger` badge-om „Stale snapshot — last known, not current“
   uz rezultat; „Save simulation“ je dozvoljen (simulacija je vezana za
   `portfolio_snapshot_id` i reproducibilna), a obaveštenje o čuvanju
   navodi da je snapshot zastareo. Metodologija i `result` dokument
   (`copy-simulation-v1`) se ne menjaju — zastarelost je osobina prikaza,
   ne izračunavanja.
4. Bez sačuvanog snapshot-a: prazno stanje kao do sada (sa razlogom
   private/not found). Javan portfolio: bez upozorenja.
5. Sync ne menja ponašanje: privatan/nepronađen ishod i dalje ažurira samo
   `portfolio_visibility` (ne `portfolio_synced_at`), snapshot-i ostaju.

## D-046: Prikaz vremena u UI-ju — Europe/Malta sa oznakom zone

**Datum:** 2026-10-06
**Status:** usvojeno (Milestone 4, Checkpoint F; odluka vlasnika)

**Odluka:**

1. **Čuvanje ostaje UTC.** `app.timezone` ostaje `UTC`: od njega zavise
   Eloquent serijalizacija, `now()`, scheduler (03:00 UTC), granice
   perioda i `source_hash` — promena bi pomerila upisane vrednosti, a ne
   prikaz. Podaci i migracije se ne menjaju.
2. **Jedno mesto za prikaz:** novi `config('app.display_timezone')` =
   `Europe/Malta` i `App\Filament\Support\DateTimeDisplay`
   (`FORMAT = 'Y-m-d H:i T'`, format uveden u Checkpoint E, npr.
   „2026-10-06 10:30 CEST“ / „2026-01-15 11:00 CET“).
   `DateTimeDisplay::configureFilament()` (poziva se iz
   `AppServiceProvider::boot()`) postavlja `FilamentTimezone` i
   podrazumevani date-time format za `Table` i `Schema`
   (`configureUsing`), pa SVI postojeći `->dateTime()` unosi i kolone
   (TraderResource tabela/infolist, „Performance sync“ / „Portfolio sync“
   „Last successful sync“, ImportRunResource tabela, infolist i relation
   manager-i) prikazuju Malta vreme bez izmene po polju. Custom Blade
   widget-i (portfolio, simulator, sačuvane simulacije) koriste
   `DateTimeDisplay::format()` umesto lokalnih `setTimezone()` poziva.
3. **Šta nije timestamp:** periodi performansi (`Y-m`, dnevni `Y-m-d`
   datumi i ose grafikona) su kalendarski periodi eToro serije (UTC dan /
   mesec, D-032), ne trenuci — ostaju nepromenjeni; konverzija bi pomerila
   ponoćni UTC dan na pogrešan datum. DiscoverTraders i notifikacije
   trenutno ne prikazuju vreme.
4. CLI izlaz (`etoro:*` komande) nije UI panela i ostaje kakav je.
5. Testovi: `tests/Feature/Filament/DisplayTimezoneTest.php` — helper kroz
   leto/zimu i oba DST prelaza (uklj. 2026-10-25 02:59 CEST → 02:00 CET),
   UTC čuvanje, TraderResource tabela i stranica tradera, ImportRun
   tabela i stranica.

## D-047: Read model za poređenje tradera — dimenzije, statusi, periodi i nove formule

**Datum:** 2026-10-07
**Status:** usvojeno (Milestone 5, Checkpoint A, grana
`codex/milestone-5-trader-comparison`; bez UI-ja, bez live poziva)

**Kontekst:** PROJECT.md Flow E, §14 (pet nezavisnih dimenzija, bez
ukupnog skora), §15 Compare page (2–10 tradera, upozorenje kad se periodi
razlikuju), §13.8 (completeness) i §20 M5 acceptance. Većina §14 metrika
već postoji u M3/M4 kalkulatorima (D-033, D-041, D-042); nedostaju
disperzija mesečnih prinosa, zavisnost od najboljeg meseca, completeness,
pragovi zastarelosti i brojanje neuspelih endpointa.

**Odluka:**

1. **`App\Application\Traders\Comparison\BuildTraderComparison::handle(list<int>, ?now)`**
   → `TraderComparison`. Ulaz: 2–10 **različitih, postojećih** trader ID-jeva
   (redosled = redosled prikaza). Inače `TraderComparisonRejected`
   (`InvalidArgumentException`) sa razlogom `too_few_traders` /
   `too_many_traders` / `duplicate_trader` / `unknown_trader` i spiskom
   spornih ID-jeva; duplikati se ne uklanjaju ćutke. Provere idu tim
   redom (broj se proverava nad ulazom sa duplikatima).
2. **Samo čitanje:** koristi postojeće `BuildTraderPerformanceReport`,
   `BuildTraderPortfolioReport` (poslednji snapshot, D-045) i
   `BuildCopySimulationMatrix` (ništa ne upisuje) — nikad eToro API, nikad
   upis (test: `Http::preventStrayRequests`, `Http::assertNothingSent`,
   `DB::listen` bez insert/update/delete). Semantika postojećih
   kalkulatora je nepromenjena.
3. **Svaka metrika je `ComparisonMetric`** (ključ `ComparisonMetricKey`,
   dimenzija i jedinica izvedene iz ključa): `status` available / partial /
   unavailable; invarijanta u konstruktoru — unavailable ⇔ `value = null`
   + `MetricUnavailableReason`; available/partial ⇔ vrednost bez razloga.
   **Nepoznato nikad nije 0.** `warnings` (`MetricWarning`) su ograde koje
   UI prikazuje uz vrednost; `details` nose prateće brojke (npr. potreban/
   dostupan broj perioda, osnovica težina, poznati doprinos leverage-a).
   `partial` = vrednost izračunata, ali deo ulaza nepoznat/procenjen
   (exposure `Partial`, isključene pozicije bez težine, copy `is_estimate`).
   **Nema ukupnog ni kombinovanog skora** (§14): `TraderComparison` i
   `TraderComparisonEntry` nemaju nijedno takvo polje/metod (test
   refleksijom); completeness je metrika kvaliteta podataka sa formulom
   uz sebe, ne ocena tradera.
4. **Mapiranje §14 → izvori:**
   - Performance (mesečna serija): cumulative (sve tačke, uklj. delimične —
     upozorenja `includes_partial_start_period` / `includes_in_progress_period`),
     trailing 12/24 (poslednjih 12/24 završenih meseci; manje → unavailable
     `insufficient_history` sa `required/available_complete_periods`),
     prosek/medijana/profitable-month ratio (završeni meseci, D-033).
   - Risk: dnevni i mesečni max drawdown (svaki iz svoje serije; mesečni
     nosi `monthly_granularity_not_intraday`), mesečna i godišnja (×√12)
     volatilnost iz `ConsistencyCalculator`-a; **risk score = uvek
     unavailable `not_provided_by_source`** (aplikacija ga ne prikuplja —
     nijedno polje/endpoint); largest position i top-3 = koncentracija **po
     instrumentu** (pozicije istog instrumenta sabrane, invested-only, D-041);
     weighted leverage tačno po D-041 t. 5: kad deo invested težine nema
     leverage → unavailable `leverage_not_determinable` sa
     `known_leverage_contribution` / `known_leverage_weight` /
     `unknown_leverage_weight` u details.
   - Consistency: positive-month ratio (isti broj kao profitable-month
     ratio — §14 ga navodi u dve dimenzije), longest losing streak
     (`longestNegativeStreak`, D-033: 0% prekida niz), disperzija i
     zavisnost od najboljeg meseca (tačka 5), prinos bez najboljeg / tri
     najbolja meseca (postojeći `ConsistencyCalculator`).
   - Copyability (poslednji sačuvani snapshot kroz `BuildCopySimulationMatrix`,
     M = $1, platformski minimum $200): coverage na $200/$500/$1,000 =
     udeo pozitivne težine pozicija (D-022; udeo celog portfolija u
     details), minimum za 90/95/99% i za sve vidljive pozicije (100%,
     `informational`, §12.3) = effective minimum; broj i težina (udeo celog
     portfolija) preskočenih pozicija po presetu. Van opsega →
     `out_of_range` (D-043); bez pozitivne težine → `no_positive_weight`;
     `is_estimate` → partial + `estimated_from_incomplete_snapshot`.
     Privatan/nepronađen portfolio sa snapshot-om → vrednosti postoje, uz
     `snapshot_no_longer_visible` na svakoj (D-045); bez snapshot-a →
     `no_stored_snapshot`.
   - Data quality: tačka 6.
5. **Nove formule (čisti kalkulatori, BCMath scale 18, half-up na ppb tek
   na kraju, samo ZAVRŠENI periodi — D-033):**
   - **Disperzija mesečnih prinosa** (`ReturnDistributionCalculator`,
     `return-distribution-v1`) = **interkvartilni raspon** IQR = Q(0.75) −
     Q(0.25), kvantili linearnom interpolacijom (Hyndman–Fan tip 7):
     sortirano x₀…xₙ₋₁, h = (n − 1)p, Q = x⌊h⌋ + (h − ⌊h⌋)(x⌊h⌋₊₁ − x⌊h⌋).
     Najmanje 4 završena meseca. Izabran IQR, a ne standardna devijacija,
     jer je ona već „volatility“ u Risk dimenziji — dve dimenzije ne treba
     da pokazuju isti broj; IQR je robustan na jedan ekstreman mesec.
     Q1/Q3 su u details.
   - **Zavisnost od najboljeg meseca** = udeo najboljeg meseca u složenom
     prinosu: (R − R₋best) / R, gde je R = Π(1+r) − 1 nad završenim
     mesecima, a R₋best isto bez jednog najboljeg meseca. Definisano samo
     za R > 0 (inače unavailable `non_positive_return`, a doprinos
     R − R₋best u details i dalje postoji); može biti > 1 (ostali meseci
     zajedno gube). Najmanje 2 meseca.
6. **Operational/data quality:**
   - **Last successful sync** = `traders.performance_synced_at` /
     `portfolio_synced_at` (null → unavailable `never_synced`); **source
     visibility** = `performance_visibility` / `portfolio_visibility`.
   - **Prag zastarelosti: 48 h** (`BuildTraderComparison::STALE_AFTER_HOURS`),
     isti za performance i portfolio; zastarelo strogo POSLE 48 h od
     poslednjeg uspešnog sync-a (tačno 48 h je sveže), budući timestamp je
     svež — ista konvencija kao `EvaluateTraderProfileFreshness`. Razlog:
     performance se sinhronizuje dnevno u 03:00 UTC (D-034), pa 48 h toleriše
     jedan propušten ciklus. Portfolio nema scheduler (D-038 t. 7), pa ručno
     sinhronizovan portfolio stariji od 2 dana namerno postaje „stale“ —
     to je tačno stanje, ne greška. `DataFreshness` po izvoru: `fresh` /
     `stale` / `no_longer_visible` (private/not found, ima prednost — D-045) /
     `never_synced`. **Stale-data warning** = bilo koji izvor nije `fresh`
     (oba stanja u details); metrike tog izvora nose `performance_stale` /
     `performance_no_longer_visible` / `snapshot_stale` /
     `snapshot_no_longer_visible`.
   - **Completeness score** (`DataCompletenessCalculator`,
     `completeness-v1`) = broj `present` provera / broj **prikupljivih**
     provera, **svaka prikupljiva provera težine 1**. Prikupljive (izvor
     aplikacija prikuplja): profile (`EvaluateTraderProfileFreshness`,
     24 h), ≥ 24 ZAVRŠENA mesečna perioda, dnevni podaci, live portfolio
     (snapshot) — `present` samo ako je izvor svež, inače `stale`; bez
     podatka `missing`. Asset history, exposure history, trade info i
     copier history aplikacija ne prikuplja → **ne ulaze u imenilac**;
     navedene su odvojeno kao „not supported by this application“ sa
     razlogom `not_collected_by_application`
     (`CompletenessUnsupportedReason`). To je ograničenje aplikacije, isto
     za svakog tradera, a ne mana tradera: trader sa svim svežim
     prikupljivim podacima ima **100%** (4/4). Details metrike:
     `formula`, `present_count`, `collectable_count`, `total_count` (8),
     `collectable_checks` (provera → stanje) i `not_supported_checks`
     (provera → razlog). Kad aplikacija počne da prikuplja neki od ta
     četiri izvora, on prelazi u prikupljive (promena imenioca = nova
     verzija metodologije). Kalkulator traži da je svaka od 8 provera u
     tačno jednoj od dve liste i bar jednu prikupljivu.
   - **Failed endpoint count** = broj `failed` ImportRun-ova tipa
     `performance` i `portfolio` sa `metadata.query.trader_id` = trader,
     čiji je `started_at` u **poslednjih 7 dana** ([now − 7 d, now],
     granice uključene). Details: po tipu, broj `partial` run-ova (npr.
     nepotpuno obogaćivanje metapodataka) i ukupno run-ova u prozoru.
     Profile lookup run-ovi su vezani za username, ne trader ID, i ne broje
     se. Jedan performance sync = 2 run-a (monthly + daily, D-035).
7. **Periodi posmatranja:** svaka metrika — i nedostupna — nosi
   `ObservationPeriod`; `ComparisonMetric::$observation` nije nullable,
   pa UI (Checkpoint C) prikazuje period svake vrednosti jednoobrazno
   (`basis`: `return_series` — od/do = prvi/poslednji početak perioda,
   uključivo, granularnost, broj tačaka, oznake partial/in-progress;
   `portfolio_snapshot` — `captured_at` → `last_confirmed_at`;
   `import_run_window`; `sync_record` — last successful sync i
   visibility (stanje koje je taj sync video); `evaluated_at` — stale
   warning, completeness i risk score (ne prikuplja se, ocenjen u
   trenutku poređenja); **`no_data`** — eksplicitan prazan period kad
   nema šta da se posmatra (nema serije, nema snapshot-a, nikad
   sinhronizovano, nema nijednog završenog meseca): `from = null`,
   `to` = trenutak poređenja, `pointCount = 0`). Rezultat poređenja
   nosi `ComparisonPeriods`: za mesečnu i dnevnu seriju `SeriesAlignment`
   (period svakog tradera, zajednički period = [najkasniji početak,
   najraniji kraj] samo kad SVI imaju podatke i preklapaju se, `differs`
   kad neko nema podatke ili prozori (od, do, broj tačaka) nisu
   identični, spisak tradera bez podataka) i `SnapshotAlignment`
   (najstariji/najnoviji `captured_at`, traderi bez snapshot-a).
   `observationPeriodsDiffer()` je signal za UI upozorenje (§15). Metrike
   se računaju nad celim periodom svakog tradera, ne nad zajedničkim —
   zajednički period je informacija za prikaz.
8. Rezultat nosi `methodologyVersion` `comparison-v1`, `generatedAt` i
   pragove (`staleAfterHours`, `failedRunWindowDays`); promena mapiranja,
   pragova ili definicija = nova verzija. Ništa se ne čuva.
9. **Upiti i vreme:** poslednji snapshot tradera (pozicije + instrumenti)
   čita se **jednom** — `BuildTraderPortfolioReport` prosleđuje učitane
   pozicije `BuildPortfolioExposureReport::fromPositions()`, a poređenje ih
   prosleđuje `BuildCopySimulationMatrix::handle(..., positions:)` →
   `StoredPortfolioCoverageAdapter::toLivePortfolio($snapshot, $positions)`
   (postojeći javni ulazi bez tog parametra rade kao ranije). Performance
   tačke svih izabranih tradera čitaju se jednim upitom
   (`BuildTraderPerformanceReport::handleMany()`; `handle()` sada čita obe
   granularnosti jednim upitom umesto dva), ImportRun-ovi u prozoru jednim
   upitom grupisanim po `metadata.query.trader_id`. Za 10 tradera sa
   serijama, snapshot-om i instrumentima: **43 upita** (1 traderi + 1
   tačke + 1 import run-ovi + 10 × 4: broj snapshot-a, poslednji snapshot,
   pozicije, instrumenti), ranije 101 (test to ograničava). Pun batching
   portfolija nije rađen (≤ 10 tradera, lična aplikacija). `now` se
   normalizuje na UTC; završeni meseci zavise samo od UTC granice
   (`synced_at` 23:59:59 UTC poslednjeg dana → mesec u toku; 00:00:00
   UTC 1. → završen), a display timezone (Europe/Malta, D-046) ne utiče
   ni na klasifikaciju, trailing metrike ni na prozor failed run-ova
   (testovi).
