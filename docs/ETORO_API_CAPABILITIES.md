# ETORO_API_CAPABILITIES

Capability report za eToro Public API, zasnovan isključivo na stvarnim
rezultatima izvršenih live proba (ne na dokumentaciji ili pretpostavkama).
Vidi PROJECT.md §20 (Milestone 1) za metodologiju i `docs/DECISIONS.md`
D-013/D-015 za detalje o istraživanju dokumentacije.

**Bez request ID-jeva, identiteta tradera, account ID-jeva ili drugih
vrednosti iz odgovora u ovom fajlu** — samo klasifikacija, HTTP status i
struktura (nazivi polja).

---

## Run #1 — 2026-07-30/31

Komanda: `php artisan etoro:doctor --live` (bez `--capture-raw`).
Autentikacija: `x-api-key` + `x-user-key` + `x-request-id` — **bez**
`Authorization` header-a.

| # | Sposobnost | Metod | Path | HTTP status | Klasifikacija |
|---|---|---|---|---|---|
| 1 | Authenticated profile | GET | `/api/v1/me` | 200 | works |
| 2 | Investor rankings | GET | `/api/v2/portfolios/rankings` | 200 | works |
| 3 | Public trader profile | GET | `/api/v1/user-info/people` | 200 | works |
| 4 | Trader gain/performance | GET | `/api/v1/user-info/people/{username}/gain` | 200 | works |
| 5 | Trader live portfolio | GET | `/api/v1/user-info/people/{username}/portfolio/live` | — | temporarily_unavailable (transport failure, nakon bounded retry-ja) |
| 6 | Real account P&L | GET | `/api/v1/trading/info/real/pnl` | 200 | available |
| 7 | Demo account P&L | GET | `/api/v1/trading/info/demo/pnl` | 200 | available |

### Ključni nalazi

- **Autentikacija je uspela bez `Authorization: Bearer` header-a** — samo
  `x-api-key` + `x-user-key` + `x-request-id` je dovoljno za sve endpointe
  koji su vratili odgovor. Ovo razrešava deo dokumentacione nekonzistentnosti
  iz D-013 za ove konkretne endpointe.
- **Nijedan endpoint nije vratio 401, 403, 404, 429 ili 5xx.**
- **Rate-limit response header-i nisu vraćeni** ni na jednom od 7 poziva
  (`Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining` — svuda
  odsutni).
- **Real account P&L (#6) je trajao ~22 sekunde** u ovom pojedinačnom run-u
  — znatno sporije od ostalih proba (sve ostale < 1.2s). Jedan uzorak;
  nedovoljno za zaključak o tipičnoj latenciji.
- **Trader live portfolio (#5) je jedina proba koja nije dobila HTTP
  odgovor** u ovom run-u — transport (konekciona) greška, ne HTTP status
  greška, nakon ugrađenog bounded retry-ja (do 3 pokušaja). Razrešeno u
  Run #2 ispod: pokazalo se da je bila privremena transportna smetnja, ne
  sistemski problem.

### Struktura odgovora (samo nazivi polja, bez vrednosti)

- **`/api/v1/me`**: `gcid, realCid, demoCid, username, firstName,
  middleName, lastName, playerLevel, gender, language, dateOfBirth,
  avatarUrl, scopes[]` (16 scope-ova)
- **`/api/v2/portfolios/rankings`**: `results[]` (5 stavki, `pageSize=5`
  test parametar), `pagination{page,pageSize,totalItems,hasNext}`
- **`/api/v1/user-info/people`**: `users[]` (1 stavka)
- **`/api/v1/user-info/people/{username}/gain`**: `monthly[]` (80 stavki),
  `yearly[]` (8 stavki)
- **`/api/v1/trading/info/real/pnl`** i **`/api/v1/trading/info/demo/pnl`**
  (identična šema): `clientPortfolio{positions, unrealizedPnL, mirrors,
  accountCurrencyId, credit, orders, stockOrders, entryOrders, exitOrders,
  ordersForOpen, ordersForClose, ordersForCloseMultiple, bonusCredit}`

---

## Run #2 — 2026-07-31 (ciljana proba: `--only=live-portfolio`)

Komanda: `php artisan etoro:doctor --live --only=live-portfolio` (bez
`--capture-raw`). Poziva isključivo rankings (dependency radi izbora
username-a) i live-portfolio — ništa drugo.

| Sposobnost | Metod | Path | HTTP status | Klasifikacija |
|---|---|---|---|---|
| Investor rankings (dependency) | GET | `/api/v2/portfolios/rankings` | 200 | works |
| **Trader live portfolio** | GET | `/api/v1/user-info/people/{username}/portfolio/live` | **200** | **works** |

### Struktura odgovora (samo nazivi polja/broj stavki)

- **`/api/v1/user-info/people/{username}/portfolio/live`**:
  `realizedCreditPct, unrealizedCreditPct, positions[], socialTrades[]`

Obe probe u ovom run-u su bile neuobičajeno spore (rankings ~42s,
live-portfolio ~21s) u odnosu na Run #1 (rankings ~1.1s) — ukazuje na opšte
usporenje mreže/eToro API-ja u tom trenutku, ne na problem specifičan za
live-portfolio endpoint.

---

## Zaključak: Trader live portfolio

**Klasifikacija: `works`.**

Endpoint `GET /api/v1/user-info/people/{username}/portfolio/live` radi.
Autentikacija zahteva samo `x-api-key` + `x-user-key` + `x-request-id` —
`Authorization: Bearer` header **nije potreban**. Odgovor sadrži:
`realizedCreditPct`, `unrealizedCreditPct`, `positions[]`, `socialTrades[]`.

Neuspeh u Run #1 je bio **privremena transportna smetnja** (transport
failure nakon bounded retry-ja), ne sistemski problem endpointa niti pitanje
autentikacije/scope-a — potvrđeno uspešnim Run #2 istog endpointa, istom
metodom autentikacije, bez ikakve izmene koda ili pristupa.

### Poklapanje sa PROJECT.md §8

Svi testirani path-ovi poklopili su se sa onima navedenim u PROJECT.md §8
(`/api/v1/me`, `/api/v2/portfolios/rankings`, `/api/v1/user-info/people`,
`/api/v1/user-info/people/{username}/gain`,
`/api/v1/user-info/people/{username}/portfolio/live`,
`/api/v1/trading/info/{real,demo}/pnl`) — nema odstupanja u path-ovima za
ove endpointe u odnosu na plan.

### Sledeći koraci (istorijski, iz Milestone 1)

Selektivni capture javnih dataset-a (rankings, public profile, performance
history, live portfolio) je **završen lokalno** — bez `/me` i bez
Real/Demo P&L payload-a. Raw fajlovi ostaju privatni i Git-ignorisani
(`storage/app/private/etoro/raw/`).

Na osnovu posmatrane šeme napravljeni su schema-faithful, **potpuno
sintetički** fixtures u `tests/Fixtures/Etoro/` (leakage scan protiv svih
raw fajlova prošao pre commit-a).

---

## Development status (ažurirano 2026-08-06)

Na grani `milestone/2-etoro-domain-model` (Checkpoint A–E, vidi
`docs/DECISIONS.md` D-018/D-019) implementirano je, isključivo nad
sintetičkim fixture-ima iz sekcije iznad — bez ijednog novog live API
poziva:

- sanitized fixture mapping za live portfolio (`LivePortfolioMapper`);
- sanitized fixture mapping za performance history
  (`PerformanceHistoryMapper`);
- sanitized fixture mapping za rankings (`RankingsMapper`);
- sanitized fixture mapping za public profile (`TraderProfileMapper`);
- exact copy-coverage calculator (`CopyCoverageCalculator`, BCMath
  aritmetika);
- `LivePortfolioCoverageAdapter` (čista translacija eToro domain podataka
  u Analytics request DTO-e — bez poziva calculator-a, bez sortiranja,
  filtriranja ili deduplikacije pozicija);
- fixture pipeline kroz calculator (JSON → mapper → adapter → calculator),
  potvrđen na $200/$500/$1000 budžetima (vidi `docs/WORKLOG.md`
  2026-08-06).

### Bezbednosne i epistemološke ograde (i dalje važe)

- `investmentPct` je jedino opaženo polje koje se trenutno koristi kao
  allocation weight — ovo je radna/eksperimentalna interpretacija zasnovana
  na ograničenom uzorku, **ne** potvrđen API ugovor.
- `optimalCopyPosSize`, `avgPosSize`, realized/unrealized credit i druga
  nejasna polja nisu korišćena ni u jednom mapperu ni u calculator-u.
- `socialTrades` sadržaj nije modeliran — samo se broj prenosi kao
  `unmodeledEntryCount` (vidi `LivePortfolioCoverageAdapter`).
- Ovo nije finansijski savet.
- Nijedan write API tok nije uveden.
- Live API šema se i dalje smatra opaženom, ne zvanično potvrđenom ili
  garantovano stabilnom — dokumentovana su samo dva live runa iz Milestone
  1 (iznad); domain-model sloj je rađen isključivo nad sintetičkim
  fixture-ima izvedenim iz tih runova, bez ijednog dodatnog live poziva.

### Sledeći razvojni korak

1. Otvoriti i pregledati PR za završeni domain-model stream
   (`milestone/2-etoro-domain-model` → `main`).
2. Nakon merge-a dizajnirati application orchestration sloj (koji povezuje
   `EtoroClient`, mappere, adapter i calculator) odvojeno od pure domain
   sloja — vidi `docs/DECISIONS.md` D-019. Dodatni live capture nije
   planiran osim ako se tokom te implementacije otkrije konkretna schema
   rupa koja zahteva dodatni uvid u stvarni odgovor.
