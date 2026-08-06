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
