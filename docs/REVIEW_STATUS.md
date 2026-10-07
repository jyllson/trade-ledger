# REVIEW_STATUS — TradeLedger

**Trenutni implementation stream:** Milestone 5 — implementacija završena
na grani `codex/milestone-5-trader-comparison`; čeka PR/merge u `main`.
**Poslednje ažuriranje:** 2026-10-07 (Milestone 5, Checkpoint D — zatvaranje)

## ✅ Product Milestone 5 — COMPLETE na grani (poređenje tradera)

Grana `codex/milestone-5-trader-comparison`, PR još nije otvoren/merge-ovan.

| Checkpoint | Commit | Sadržaj | Odluke |
|---|---|---|---|
| A | `5264be9` | read model poređenja (`BuildTraderComparison`): §14 dimenzije sa statusom metrike, periodi posmatranja, completeness, zastarelost, neuspeli sync-ovi | D-047 |
| B | `0ed565d` | `analysis_profiles` (default iz migracije), transparentni filteri pass/fail/unknown, netežinski sažetak, Filament Analysis profiles | D-048 |
| C | `df6d388` | stranica `/admin/compare-traders`, bulk akcija „Compare“, upozorenja, equity grafici, CSV export | D-049 |
| D | (ovaj, necommit-ovan) | dokumentacija zatvaranja (README, REVIEW_STATUS, WORKLOG) | — |

**`PROJECT.md` §20 M5 acceptance:**

1. *Compare 2–10 traders* — `BuildTraderComparison` odbija <2, >10,
   duplikate i nepostojeće ID-jeve sa razlogom
   (`BuildTraderComparisonTest` „rejects fewer than two, more than ten,
   duplicate, and unknown traders“); UI: `CompareTradersTest` („opens the
   comparison from the Traders bulk action only for 2–10 traders“,
   „rejects an invalid URL selection…“, „disables adding a trader once 10
   are selected“).
2. *No hidden overall score* — nema ukupnog/kombinovanog skora u read
   modelu ni u filterima (testovi refleksijom: `BuildTraderComparisonTest`
   „has no overall or combined score anywhere in the read model“,
   `EvaluateProfileFiltersTest` „has no score anywhere in the filter
   result“, „derives the verdict by fixed precedence fail > unknown >
   pass, without weights“); UI: `CompareTradersTest` („never shows an
   overall score, rank or winner“, „…the summary as not a score“).
3. *Differing observation periods clearly shown* — read model daje period
   svakog tradera i zajednički period, sa zastavicom kad se razlikuju
   (`BuildTraderComparisonTest` „shows the common period and flags
   differing observation periods“, „does not flag identical observation
   periods“); UI: istaknuto upozorenje sa periodom svakog tradera
   (`CompareTradersTest` „warns prominently when observation periods
   differ…“), periodi i u CSV-u („writes machine-readable CSV values…
   and the period rows“).

Deliverables §20 (comparison page, default analysis profile, transparent
filters, data-quality warnings, CSV export) pokriveni su u checkpoint-ima
B–C; `CompareTradersTest` „shows data-quality warnings…“ i „exports the
comparison as CSV“.

**Vizuelni QA (Checkpoint C):** headless Chrome nad izolovanom
sintetičkom SQLite bazom; screenshot-ovi u `/private/tmp/tl-qa-m5c/` —
light, dark, dark sa drugim profilom, prazno stanje, nevalidan URL;
različiti periodi, privatan trader sa zastarelim snapshot-om i failed
run-ovima, trader bez podataka.

**Review runde (ukratko):**

- A: N+1 uklonjen (43 upita umesto 101 za 10 tradera); completeness samo
  nad prikupljivim proverama (nepodržan izvor ne obara skor); null
  observation periodi (trader bez podataka); testovi UTC granice.
- B: zaštita od brisanja default profila pod lock-om; `NoPositiveWeight`
  kod procene (nepotpun snapshot) → unknown, ne fail; default profil
  kreira migracija umesto upisa pri GET-u.
- C: bez nalaza.

**Live provera 2026-10-07** (dev baza `trade_ledger`, read-only GET):
sync performansi za 3 tradera (6 poziva, zabeleženo u privatnom
ledger-u); poređenje ta 3 tradera radi bez grešaka — trader sa
portfolijem: 43/44 metrika dostupno; traderi bez portfolija:
copyability i risk koncentracija eksplicitno `unavailable`. U
dokumentaciji nema username-ova ni vrednosti.

**Checkpoint D verifikacija:** samo dokumentacija; `git diff --check`
čist; `vendor/bin/pint --test` passed; `php artisan test --compact`
1970 total, 1966 passed, 4 skipped, 1 poznato nepovezano upozorenje.

**Šta sledi:** commit Checkpoint-a D, PR i merge M5 u `main`; zatim
Milestone 6 (praćenje sopstvenog naloga) po odluci vlasnika.

---

## Istorija: Milestone 4 zapis

## ✅ Product Milestone 4 — COMPLETE (portfolio i copy simulator)

- **PR #10** (`7d020a5`, „feat: complete Milestone 4 portfolio and copy
  simulator“) — merge-ovan u `main`. Zapis ispod je stanje pri zatvaranju
  grane `codex/milestone-4-portfolio-simulator`.

| Checkpoint | Commit | Sadržaj | Odluke |
|---|---|---|---|
| A | `772950b` | domen live portfolija (keš), metapodaci instrumenata | D-037 |
| B | `7b488f2` | `instruments`, `portfolio_snapshots`, `portfolio_positions`, idempotentni importer, queued job + `etoro:sync-portfolio`, rate limit po HTTP pokušaju, timeout job-ova | D-038–D-040 |
| C | `00f2018` | koncentracija (HHI, efektivne pozicije, top-3) i leverage izloženost | D-041 |
| D | `3935842` | sačuvane copy simulacije, preseti, target matrica, `etoro:simulate-copy` | D-042 |
| E | `dabbf02` | Filament portfolio sekcija i Livewire Copy Amount Simulator | D-043 |
| live fix | `07eae04` | razlomak sekunde u `openTimestamp`, dijagnoza `mapping_failed` | D-044 |
| F | (ovaj, necommit-ovan) | privatan portfolio → poslednji poznati snapshot uz upozorenje; sva UI vremena u Europe/Malta; dokumentacija | D-045, D-046 |

**`PROJECT.md` §20 M4 acceptance:**

1. *Simulator objašnjava svaku preskočenu poziciju* — svaka pozicija nosi
   `skip_reason` (`below_minimum`/`zero_weight`/`negative_weight`) i
   rečenicu objašnjenja sa procenjenim iznosom i iznosom od kog se kopira;
   `SimulateCopyAmountTest` („explains every skipped position…“) i
   `TraderPortfolioViewTest` (UI tabela „Skipped positions“).
2. *$200/$500/$1,000 preseti* — `CopyAmountPreset`;
   `BuildCopySimulationMatrixTest` („defines the $200/$500/$1,000
   presets…“, „builds presets × eligible/skipped/coverage…“), UI test
   prebacivanja preseta bez HTTP poziva.
3. *90/95/99/100% target kalkulacije* — `CoverageTargetPreset` i matrica
   (§12.2/§12.3, 100% informativno); `BuildCopySimulationMatrixTest`
   (ručno izračunati iznosi, nedostižan target, granice opsega) i UI test
   target matrice.
4. *Rezultati reproducibilni iz sačuvanog snapshot-a* — `copy_simulations`
   sa `methodology_version` i `reproduces()`/`recalculate()`;
   `SimulateCopyAmountTest` („recalculates a stored simulation
   bit-identically…“), UI lista sačuvanih simulacija prikazuje ishod
   provere, CLI `--snapshot=`.

**Live verifikacija 2026-10-06** (dev baza `trade_ledger`, read-only GET):
`etoro:sync-portfolio --now` posle popravke D-044 — run `completed`,
225 pozicija, 66 instrumenata, svi sa asset class-om; ponovljen sync =
nepromenjen snapshot (samo potvrda) i 1 zahtev. Live pozivi danas: 8/10
iz dnevnog pool-a, zabeleženi u privatnom ledger-u. U dokumentaciji nema
username-ova ni vrednosti pozicija.

**Review runde (ukratko):**

- A: domen keša i metapodataka (D-037), bez review nalaza zabeleženih u WORKLOG-u.
- B: dve runde review-a (ispravke persistence/idempotentnosti; throttle
  prepravljen sa blokirajućeg čekanja na neblokirajući — D-039) + timeout
  guard za job-ove (D-040).
- C: jedan nalaz — leverage completeness (nepoznat leverage se nikad ne
  tretira kao 1x).
- D: bez review nalaza zabeleženih u WORKLOG-u.
- E: dve runde — prekoračenje opsega (`$10M` granica, D-043 tačka 6) i
  sačuvana simulacija van opsega u listi.
- Live fix: `mapping_failed` dijagnostikovan sa 2 live poziva (D-044).
- F: odluke vlasnika D-045 (zastareli snapshot) i D-046 (vremenska zona).

**Checkpoint F verifikacija:** `php artisan test --compact` 1820 total,
1816 passed, 4 skipped, 1 poznato nepovezano upozorenje;
`vendor/bin/pint --test` passed; `composer types:check` 0 errors; asseti
nisu menjani (`npm run build` nije potreban). Vizuelni QA: headless Chrome
nad izolovanom sintetičkom SQLite bazom (`/private/tmp/tl-qa-m4f/`),
light i dark, privatan portfolio sa upozorenjem — upozorenje vidljivo u
oba moda, vremena u CEST.

**Šta sledi:** commit Checkpoint-a F, PR i merge M4 u `main`; zatim
Milestone 5 (poređenje tradera) po odluci vlasnika.

---

## Istorija: Milestone 3 zapis

## ✅ Product Milestone 3 — COMPLETE (performance analytics)

- **PR #8** (<https://github.com/jyllson/trade-ledger/pull/8>, "feat:
  complete Milestone 3 performance analytics") — **MERGED** u `main`,
  squash SHA `6d0cc3c7f796c234a4b30740d1bc248f54d26414`, mergedAt
  `2026-10-05T12:31:41Z`, CI `ci` SUCCESS (1m05s).
- Isporučeno (checkpoint-i A–D na grani
  `codex/milestone-3-performance-analytics`): v2 gain time-series kao izvor
  (D-032, D-035), kalkulatori prinosa/drawdown-a/konzistentnosti (D-033),
  `performance_points` + queued, rate-limited monthly/daily sync i
  scheduler (D-034, D-035), Filament prikaz sa grafikonima i akcijom
  „Sync performance“ (D-036).
- `PROJECT.md` §20 M3 acceptance: (1) sve formule testirane ručno
  izračunatim fixture-ima (nezavisno potvrđeno Python `Decimal`-om;
  nezavisan code review — 3 sitna nalaza ispravljena); (2) prikazani
  period i granularnost su eksplicitni svuda (mesečni drawdown označen
  „not intraday“).
- Vizuelni QA: headless-Chrome screenshot stranice tradera protiv
  izolovane sintetičke SQLite baze (`/private/tmp`) — sve sekcije,
  grafikoni i tabela renderovani; jedan kozmetički nalaz (svetle linije
  tabele u dark modu) ispravljen pre merge-a. Ovo zatvara i ranije
  neizvršen browser QA iz M2 za stranicu tradera.
- Live eToro pozivi: 2 (monthly + daily proba), zabeleženi u privatnom
  ledger-u; razvojna baza `trade_ledger` nije dirana; `.env` nije čitan.
- Verifikacija na tipu grane: 1538 testova (1534 passed, 4 skipped),
  PHPStan 0, Pint passed.

**Šta sledi:** po `PROJECT.md` §20 sledeći je **Milestone 4 (portfolio i
copy simulator)** — čeka odluku vlasnika. Za stvarno korišćenje M3 u
radu potrebni su `php artisan queue:work` i (za dnevni raspored)
`schedule:run` cron — operativni korak vlasnika.

---

## Istorija: Milestone 2 zapis (stanje pre M3)

## ✅ Product Milestone 2 — COMPLETE (implementacija, sva tri acceptance kriterijuma, i merge u main)

- **Implementacija (kod + testovi) product Milestone 2 iz `PROJECT.md` §20
  je kompletna** kroz Checkpoint E–H2: live multi-page ranking discovery,
  row-level failure persistence, trader profile lookup sa identity-match
  pravilom, candidate/watched/ignored triage, discovery retry, i read-only
  Filament UI (`TraderResource`, `ImportRunResource`, `DiscoverTraders`
  stranica).
- **Sva tri `PROJECT.md` §20 Milestone 2 acceptance kriterijuma su sada
  ispunjena** — dva od njih direktno potvrđena live pozivima, treći ostaje
  dokazan OFFLINE:
  1. **"najmanje 20 kandidata importovano"** — dokazano live pozivom (vidi
     "Live acceptance run — Checkpoint J" niže): 40 stvarnih kandidata.
  2. **"repeated import creates no duplicates"** — već je bilo
     deterministički dokazano OFFLINE (idempotent-rerun Pest testovi) i
     to ostaje na snazi; Checkpoint J dodatno potvrđuje isto ponašanje pod
     stvarnim ponovljenim live pozivom protiv iste izolovane baze (broj
     trader-a i distinct identity brojevi ostali nepromenjeni nakon drugog
     poziva).
  3. **"failed rows are visible"** — ostaje dokazano OFFLINE, istim
     namenskim Pest testovima kao ranije (row-level-failure/Partial-status
     testovi, `ImportRunResource` UI vidljivost testovi — vidi
     "Reproducibilan offline acceptance" niže). Live acceptance poziv nije
     sam proizveo neuspeli red, i to namerno nije izazivano (nije deo
     acceptance zahteva).
- **PR #6 je otvoren, pregledan, i MERGED u `main`** (squash SHA `d107f6e`,
  mergedAt `2026-08-24T08:15:42Z`, CI `ci` check SUCCESS) — integration
  korak je time takođe završen. Product Milestone 2 (`PROJECT.md` §20) je
  time formalno **COMPLETE** — vidi "Post-merge closeout — Checkpoint K"
  niže za pun detalj.

## Post-merge closeout — Checkpoint K (2026-08-24)

**PR #6:** <https://github.com/jyllson/trade-ledger/pull/6> ("feat:
complete Milestone 2 trader discovery") — **MERGED** u `main`, squash
merge SHA `d107f6e21a2c5c9e122b783d782fee377bd59d69`, mergedAt
`2026-08-24T08:15:42Z`. PR CI check `ci`: **SUCCESS** (1m04s).
`origin/main` na početku ovog Checkpoint-a (pre bilo koje dokumentacione
izmene ovde) je bio potvrđen tačno na taj isti squash SHA, `d107f6e`
(`git rev-parse origin/main`) — grana `codex/milestone-2-merge-record` je
kreirana od tog tada-trenutnog `origin/main`. Ovo NIJE tvrdnja da je
`d107f6e` trajno/zauvek `origin/main` tip; za stvarno trenutni tip u bilo
kom kasnijem trenutku, uvek proveri `git rev-parse origin/main` direktno.

**Pre-PR lokalni final gate** (poslednji put pokrenut na grani
`codex/milestone-2-discovery-and-ui`, pre otvaranja PR-a):

- Ciljani offline testovi dokumentovanih putanja (discovery/exit-code/
  Partial/failure-visibility): **112/112 passed, 380 assertions**.
- Pun test suite: **1380 total, 1376 passed, 4 skipped, 4712 assertions,
  1 poznato nepovezano upozorenje**.
- `composer types:check` (PHPStan): **0 errors**.
- `composer lint:check` (Pint): **passed**.

**Zaključak:** Product Milestone 2 (`PROJECT.md` §20) je time formalno
**COMPLETE** — implementacija (Checkpoint E–H2), sva tri acceptance
kriterijuma (Checkpoint J — dva direktno potvrđena live pozivima, treći
deterministički dokazan OFFLINE, vidi "Live acceptance run — Checkpoint J"
niže), i integration (PR #6 review + merge + zeleni CI) su svi završeni.
Development baza `trade_ledger` nije korišćena ni za implementaciju ni za
live acceptance u celom ovom implementacionom stream-u.

Interaktivni browser QA (vidi "Lokalni browser QA" niže) **ostaje
NEIZVRŠEN/NEVERIFIKOVAN** — ovo NIJE product failure i NIJE Milestone 2
acceptance kriterijum (`PROJECT.md` §20 ne navodi interaktivnu browser UI
proveru kao acceptance uslov), pa ne menja gornji zaključak; render/akcije
ponašanje ostaje dokazano autentifikovanim Filament Pest testovima.

Sledeći product milestone po `PROJECT.md` §20 je **Milestone 3
(performance analytics)** — NIJE započet ovim Checkpoint-om niti
implicitno planiran; čeka eksplicitnu odluku i prioritet vlasnika
projekta.

## Live acceptance run — Checkpoint J (2026-08-24)

**Odobrenje vlasnika:** vlasnik projekta je eksplicitno odobrio (a) live,
read-only eToro discovery poziv i (b) lokalni sintetički browser login
test, oba protiv izolovane, posebno kreirane acceptance baze — **razvojna
baza `trade_ledger` je izričito NIJE korišćena** ni u jednom od dva poziva.

**Izolovana baza:** privremena SQLite baza kreirana isključivo za ovu
svrhu, van repozitorijuma (`/private/tmp`); migracije primenjene. Pre bilo
kog live poziva baza je bila prazna od product podataka: 0 traders, 0
import_runs, 0 import_run_failures (jedan sintetički QA `User` red korišćen
isključivo za browser login test se ne računa kao product podatak).

**Env override (oba live discovery poziva — Run 1 i Run 2 — identičan):**
`APP_ENV=local`, `DB_CONNECTION=sqlite`,
`DB_DATABASE=<izolovana acceptance baza>`, `ETORO_ENABLED=true`,
`ETORO_ALLOW_WRITE=false`, `ETORO_STORE_RAW_RESPONSES=false`. Lokalni
eToro ključevi korišćeni isključivo kroz postojeću aplikacionu
konfiguraciju — nijedna vrednost ključa nije čitana ili prikazana od
strane implementatora u bilo kom trenutku. (Env override korišćen za
lokalni browser login pokušaj opisan je odvojeno niže — browser login
nije live eToro korak.)

**Komanda (identična oba puta):**

```bash
php artisan etoro:discover-traders lastYear --max-pages=2 --start-page=1
```

### Run 1

- Exit code `3` — ovo je dokumentovan `EXIT_DISCOVERY_WITH_REJECTIONS`
  signal (`App\Console\Commands\EtoroDiscoverTradersCommand`), koji se
  postavlja kad je agregatni `ImportRun` status `Partial`; `Partial` ovde
  potiče isključivo od `stop_reason=page_limit_reached` (dostignut
  `--max-pages=2` limit pre prirodnog kraja), NE od operational failure-a.
  Vidi `app/Application/Imports/DiscoverEtoroTraders.php`
  `determineStatus()` — `Partial` se vraća čim je `pagesFetched > 0` i
  stop reason nije `natural_completion`, nezavisno od `failureCount`.
- Rezultat: status `partial`, stop reason `page_limit_reached`, 2 stranice
  fetch-ovane, 2 fizička HTTP zahteva, 40 uspešnih redova, 0 odbačenih
  redova (40 = 2 stranice × fiksni `PAGE_SIZE=20`, vidi
  `DiscoverEtoroTradersRequest::PAGE_SIZE`).
- Stanje baze posle Run 1: 40 traders, 40 distinct `external_cid`, 40
  distinct `username`, svih 40 u statusu `candidate`, 0 grupa duplikata po
  CID-u, 0 grupa duplikata po username-u; 2 completed `rankings` child
  `ImportRun` reda + 1 partial `rankings_discovery` agregatni red.

### Run 2 (ponovljen, identičan poziv protiv iste baze)

- Identičan izlaz: status `partial`, stop reason `page_limit_reached`, 2
  stranice, 2 zahteva, 40 uspešnih, 0 odbačenih.
- Stanje baze posle Run 2: **i dalje tačno** 40 traders, 40 distinct CID,
  40 distinct username, svih 40 `candidate`, 0/0 grupa duplikata — bez
  ijednog novog trader reda. Kumulativno (Run 1 + Run 2): 4 completed
  `rankings` child redova + 2 partial `rankings_discovery` agregatna reda,
  80 ukupno uspešnih, 0 ukupno neuspešnih, 4 ukupno zahteva,
  `import_run_failures` i dalje 0 redova.

### Zaključak

Sva tri `PROJECT.md` §20 Milestone 2 acceptance kriterijuma su time
ispunjena: ≥20 stvarnih kandidata (40 > 20) i ponovljen import bez
duplikata/bez porasta trader broja su direktno potvrđeni ovim live
pozivima; vidljivost neuspelih redova ostaje dokazana deterministički
OFFLINE, namenskim Partial/failure i autentifikovanim `ImportRunResource`
testovima (nepromenjeno ovim Checkpoint-om). Live odgovor nije prirodno
proizveo row-level failure, i namerno izazivanje takvog ishoda nije
pokušano (nije deo acceptance zahteva).

### Lokalni browser QA (pokušano — ostaje NEIZVRŠENO/NEVERIFIKOVANO, ne product failure)

Vlasnik je odobrio lokalni sintetički browser login pokušaj protiv iste
izolovane baze — ovo **nije eToro live poziv**, isključivo lokalna Laravel
autentikaciona provera. Dokazano: sintetički QA `User` hash i
`Auth::attempt()` provera su potvrđeni kao `true` direktno protiv te baze.
Login kroz prvi pokrenut `artisan serve` child proces nije uspeo — ovo je
bilo konzistentno sa mogućim gubitkom DB env override-a u tom child
procesu, ali da li je override zaista izgubljen nije formalno
dijagnostikovano niti potvrđeno. Nakon toga, direktan PHP built-in server
je ponovo pokrenut sa eksplicitnim env override-om, ali ugrađena browser
URL safety politika je blokirala dalju interakciju sa `localhost`-om PRE
nego što je
autentifikovano UI stanje moglo biti potvrđeno kroz browser — nema
potvrde da je taj restartovani server zaista ispravno poslužio acceptance
bazu. Zaobilaženje ili alternativna browser automatizacija namerno **nije
pokušana**, u skladu sa tom politikom.

**Interaktivni browser QA time ostaje NEIZVRŠEN/NEVERIFIKOVAN** — ovo NIJE
product failure, i ne poništava acceptance: autentifikovani Filament
resource/page Pest testovi i pun test suite već deterministički dokazuju
render, akcije, i odsustvo HTTP poziva pri renderovanju (vidi D-031 i
"Reproducibilan offline acceptance" niže). Ovo se dokumentuje isključivo
kao nezavršena dodatna interaktivna provera — nikad kao izveden ili
prošao browser QA.

### Bezbednosna napomena i čišćenje

Privremeni lokalni server pokrenut za browser QA je ugašen po završetku.
Izolovana acceptance SQLite baza ostaje lokalno (van repozitorijuma, u
`/private/tmp`) kao dokaz do završetka PR-a/merge-a — **nije commit-ovana
niti uključena ni u jedan review artefakt**. Nijedan CID, username, request
ID, payload ili druga identifikaciona vrednost iz live odgovora nije
zapisan u ovom dokumentu, u `PROJECT.md`, u `docs/WORKLOG.md`, niti bilo
gde drugde u repozitorijumu — isključivo agregatni brojevi navedeni iznad.

## Checkpoint E–H2 sloj — završeno

- [x] Live, multi-page ranking discovery (Checkpoint E, `8a3204b`) —
      `App\Application\Imports\DiscoverEtoroTraders`,
      `DiscoverEtoroTradersRequest`/`Result`/`StopReason`, `php artisan
      etoro:discover-traders {period}` CLI; fiksan `PAGE_SIZE=20`, 2s
      pacing samo između stranica, agregatni `rankings_discovery`
      `ImportRun` kreiran pre prvog HTTP poziva — D-027
- [x] Row-level failure persistence (Checkpoint F, `b1b06d6`) —
      `import_run_failures` tabela, `ImportRunFailure`/
      `ImportRunFailureReason`, `ImportRun::failures()`/`childFailures()` —
      D-028
- [x] Trader profile lookup (Checkpoint G, `7efd3b1`) —
      `App\Application\Traders\{TraderUsername,
      FindStoredTraderByUsername, LookupEtoroTraderProfile}`, šest novih
      `traders.profile_*` kolona; nikad ne kreira `Trader` iz profile
      odgovora, nikad ne poredi `profile_gcid` sa `external_cid` — D-029
- [x] Trader status triage i discovery retry (Checkpoint H1, `c0c4d06`) —
      `App\Application\Traders\ChangeTraderStatus`,
      `import_runs.retry_of_import_run_id`, sanitizovana
      retry-eligibility metadata,
      `App\Application\Imports\RetryEtoroTraderDiscovery` — D-030
- [x] Read-only Filament UI (Checkpoint H2, `51b32e1`) —
      `App\Application\Traders\EvaluateTraderProfileFreshness`,
      `TraderResource` (`/admin/traders`), `ImportRunResource`
      (`/admin/import-runs`, četiri relation manager-a), `DiscoverTraders`
      stranica (`/admin/discover-traders`) — samo List+View, bez
      Create/Edit/Delete; renderovanje nikad ne pravi HTTP poziv — D-031
- [x] D-027–D-031 dodate u `docs/DECISIONS.md`

## Checkpoint E–H2 sloj — finalna verifikacija (na tip-u `51b32e1`)

- `php artisan test --compact`: **1380 total, 1376 passed, 4712
  assertions**, 0 failures, 4 skipped (isti izolovani MySQL collation
  testovi kao u prethodnom stream-u), 1 upozorenje (isto poznato,
  nepovezano upozorenje dokumentovano još od Milestone 1)
- `composer types:check` (PHPStan): 0 errors
- `composer lint:check` (Pint): prošao
- Development baza `trade_ledger` nije korišćena u Checkpoint E–H2
  implementaciono-testnom radu (svi testovi rade protiv izolovane
  `:memory:` SQLite baze); nijedan LIVE Milestone-2 ranking
  discovery/import poziv izvršen u samom E–H2 implementacionom
  stream-u — live acceptance je izvršen tek kasnije, kao poseban korak,
  van implementacije samog koda (vidi "Live acceptance run — Checkpoint
  J" na vrhu ovog dokumenta). Ranije, odvojene, odobrene one-off live
  probe iz Milestone 1/target-coverage stream-ova ostaju istorijska
  činjenica.

## Šta i dalje nije urađeno (posle Milestone 2 merge — Checkpoint K)

Product Milestone 2 (`PROJECT.md` §20) je formalno **COMPLETE** —
implementacija, sva tri acceptance kriterijuma, i PR #6 merge (vidi
"Post-merge closeout — Checkpoint K" na vrhu ovog dokumenta). Preostalo,
van Milestone 2 opsega:

- interaktivna browser QA provera Filament UI-ja protiv acceptance baze
  — pokušana, ali ostaje NEIZVRŠENA/NEVERIFIKOVANA: blokirana ugrađenom
  browser URL safety politikom nakon restarta lokalnog servera, PRE nego
  što je autentifikovano UI stanje moglo biti potvrđeno (vidi "Lokalni
  browser QA" gore); ovo NIJE Milestone 2 acceptance kriterijum i ne
  menja COMPLETE status, jer je render/akcije ponašanje već
  deterministički dokazano autentifikovanim Filament Pest testovima;
- scheduled/queued collection i dalje nije implementirana (van Milestone
  2 §20 opsega — vidi `PROJECT.md` §9);
- **Milestone 3 (performance analytics)**, sledeći product milestone po
  `PROJECT.md` §20, NIJE započet — čeka eksplicitnu odluku i prioritet
  vlasnika projekta.

## Checkpoint E–H2 sloj — sledeći korak (sve završeno)

1. ~~Vlasnik projekta odobrava i pokreće live, read-only discovery poziv
   protiv eksplicitno odobrene development ILI izolovane acceptance
   baze.~~ Završeno — Checkpoint J, vidi "Live acceptance run — Checkpoint
   J" niže.
2. ~~Potvrditi najmanje 20 stvarnih kandidata i ponovljen isti discovery
   bez duplikata protiv iste baze.~~ Završeno — Checkpoint J: 40
   kandidata, ponovljen poziv bez duplikata/bez porasta trader broja.
3. ~~Otvoriti i pregledati PR `codex/milestone-2-discovery-and-ui` →
   `main`.~~ Završeno — **PR #6, MERGED** kao `d107f6e`
   (`2026-08-24T08:15:42Z`), CI `ci` check SUCCESS — vidi "Post-merge
   closeout — Checkpoint K" na vrhu ovog dokumenta.
4. ~~Nakon merge-a, product Milestone 2 (`PROJECT.md` §20) se može
   formalno označiti kompletnim...~~ Završeno — Milestone 2 je formalno
   **COMPLETE**. Sledeći milestone (Milestone 3 — performance analytics)
   NIJE započet ovim Checkpoint-om; razmotriti u skladu sa stvarnim
   prioritetom vlasnika.

---

## Istorija: Trader-ranking import sloj (Checkpoint A–D, `feature/trader-ranking-import`)

**Status:** završeno, mergovano u `main` kao PR #5 ("feat: add trader
ranking import foundation", commit `cf5ac83`) — vidi sekciju "Merge u
`main`" u `docs/WORKLOG.md` 2026-08-21.
**Poslednje ažuriranje ove istorijske sekcije:** 2026-08-21

- [x] Persistence foundation (Checkpoint A, `f22bde8`) — `traders`/
      `import_runs` migracije, `Trader`/`ImportRun` Eloquent modeli,
      `TraderStatus` (candidate/watched/ignored) i `ImportRunStatus`
      (pending/running/completed/partial/failed) enum-i, factory-ji — D-024
- [x] `App\Application\Imports\ImportRankingPage` idempotentni persistence
      use case (Checkpoint B, `c6c7580`) — in-page identity ambiguity
      rezolucija pre bilo kog write-a, zatim per-entry live DB rezolucija;
      MySQL/MariaDB column-collation-aware i SQLite byte-exact equality
      putevi; jedna transakcija po page-u; sanitizovan, count-only
      `error_summary` — D-025
- [x] Offline fixture-only import lanac (Checkpoint C, `d739e4c`) —
      `App\Etoro\FixtureSources\RankingFixtureSource` →
      `App\Etoro\Mappers\RankingsMapper` → `ImportRankingPage`,
      orkestrirano kroz
      `App\Application\Imports\ImportRankingPageFromFixture` — D-026
- [x] `php artisan etoro:import-ranking-page {period}` CLI (Checkpoint C) —
      jedini argument, fiksan `page=1`/`pageSize=3`, `sort`/`country` uvek
      `null`; local/testing-only environment guard proveren PRE bilo kog
      fixture I/O-a ili DB upisa
- [x] Sanitizacija — komanda nema direktnu zavisnost ni od jednog
      `App\Etoro` exception tipa; jedan `catch (Throwable)` sa jednom
      statičnom porukom; nikad `getMessage()`, path, payload ili identitet
      u outputu
- [x] Exit-code ugovor — `0` potpun uspeh (`failure_count===0`); `1`
      fatalni fixture/decode/shape/mapping/pagination/persistence failure
      (uključujući environment guard); `2` invalid input
      (`Command::INVALID`); `3` dokumentovana privatna
      `EXIT_IMPORT_WITH_REJECTIONS` konstanta kad `ImportRun` postoji ali
      `failure_count>0`
- [x] Jedan kanonski, potpuno sintetički fixture —
      `resources/fixtures/etoro/rankings.json` (premešten sa
      `tests/Fixtures/Etoro/rankings.json`, bez duplikata, bez app→tests
      zavisnosti)
- [x] D-024 (persistence schema), D-025 (importer identity/collation/
      transaction contract), D-026 (offline fixture source + CLI/
      environment/exit-code contract) dodate u `docs/DECISIONS.md`

### Trader-ranking import sloj — finalna verifikacija (istorijski, na tip-u `d739e4c`)

- `php artisan test`: **912 passed / 3017 assertions**, 0 failures, 4
  skipped (izolovani MySQL collation testovi — nisu izvršeni bez
  `MYSQL_COLLATION_TEST_*` konekcije)
- `vendor/bin/phpstan analyse`: 0 errors
- `vendor/bin/pint --test`: prošao
- Development baza `trade_ledger` nije korišćena; nijedan live eToro poziv
  izvršen u ovom stream-u

### Šta NIJE implementirano u ovom stream-u (istorijski — sve stavke niže su otad rešene u Checkpoint E–H2, vidi sekciju na vrhu ovog dokumenta)

- ~~trader search~~ — implementirano, Checkpoint G (`7efd3b1`), D-029.
- ~~Filament `TraderResource` ili bilo koji drugi Filament/Livewire UI~~ —
  implementirano, Checkpoint H2 (`51b32e1`), D-031.
- ~~UI workflow za promenu `candidate`/`watched`/`ignored` statusa~~ —
  implementirano, Checkpoint H1/H2 (`c0c4d06`/`51b32e1`), D-030/D-031.
- ~~live ili multi-page discovery/import~~ — implementirano, Checkpoint E
  (`8a3204b`), D-027.
- ~~import history UI~~ — implementirano, Checkpoint H2 (`51b32e1`),
  `ImportRunResource`, D-031.
- ~~najmanje 20 stvarno importovanih kandidata~~ — dokazano live
  acceptance korakom, Checkpoint J (2026-08-24), vidi "Live acceptance
  run — Checkpoint J" na vrhu ovog dokumenta.
- scheduled/queued collection — i dalje nije implementirano (van
  Milestone 2 §20 opsega, vidi `PROJECT.md` §9).
- ~~generički `EtoroClient`-based live rankings import/orchestration~~ —
  implementirano, Checkpoint E (`8a3204b`).

### Trader-ranking import sloj — sledeći korak (istorijski)

1. ~~Otvoriti i pregledati PR `feature/trader-ranking-import` → `main`.~~
   Završeno — mergovano kao PR #5 (commit `cf5ac83`), vidi
   `docs/WORKLOG.md` 2026-08-21 "Merge u `main`".
2. ~~Nakon merge-a razmotriti sledeći korak (npr. trader search, Filament
   `TraderResource`, ili live/multi-page discovery) u skladu sa stvarnim
   prioritetom iz `PROJECT.md`.~~ Rešeno — sve navedeno implementirano na
   grani `codex/milestone-2-discovery-and-ui` (Checkpoint E–H2, vidi
   sekciju na vrhu ovog dokumenta). Live acceptance i dalje čeka.

---

## Istorija: eToro target-copy coverage sloj (`feature/etoro-target-coverage`)

**Status:** završeno, mergovano u `main` kao commit `144f1da` ("feat: add
eToro target copy coverage") — lokalno dokazano
(`git merge-base --is-ancestor 144f1da origin/main`); PR broj nije lokalno
dokaziv, pa se ne navodi.
**Poslednje ažuriranje ove istorijske sekcije:** 2026-08-21

### Target-copy coverage sloj — završeno

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

### Target-copy coverage sloj — verifikacija na kraju grane

- `php artisan test`: **819 passed / 2504 assertions**, 0 failures (1
  poznato, nepovezano upozorenje)
- `vendor/bin/phpstan analyse` (level 7): 0 errors
- `vendor/bin/pint --test`: prošao
- Read-only zaštite (`EtoroWriteGuard`, arch testovi protiv write metoda u
  `App\Etoro`) ostaju aktivne i pokrivene testovima

### Šta NIJE implementirano u ovom stream-u (istorijski)

- persistence, migracije, Eloquent modeli;
- Filament/Livewire target simulator UI;
- multi-trader comparison;
- scheduled collection;
- profile/performance/rankings application orchestration;
- write eToro operacije (namerno zabranjene read-only politikom projekta —
  vidi PROJECT.md §2/§6.2/§17 — ne backlog stavka koja čeka
  implementaciju);
- `--details` CLI opcija.

### Target-copy coverage sloj — sledeći korak (istorijski)

1. ~~Otvoriti i pregledati PR `feature/etoro-target-coverage` → `main`.~~
   Završeno — mergovano u `main` kao commit `144f1da`.
2. ~~Nakon merge-a razmotriti sledeći korak (npr. persistence sloj ili
   Filament/Livewire simulator UI) u skladu sa stvarnim prioritetom iz
   `PROJECT.md`.~~ Razrešeno — trader/ranking persistence foundation i
   manualni fixture-only ranking import implementirani na grani
   `feature/trader-ranking-import` (Checkpoint A–D, vidi sekciju na vrhu
   ovog dokumenta). **Ovo NIJE bila puna persistencija niti
   product-completion u tom trenutku** — product Milestone 2 iz
   `PROJECT.md` §20 tada je i dalje bio nekompletan. (Od tada rešeno:
   implementacija je zaokružena kroz Checkpoint E–H2, a sva tri §20
   acceptance kriterijuma su ispunjena kroz Checkpoint J, 2026-08-24 — vidi
   "Live acceptance run — Checkpoint J" na vrhu ovog dokumenta.)

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
