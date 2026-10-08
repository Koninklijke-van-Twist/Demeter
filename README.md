# Demeter

Webapp voor werkorders / kostenplaatsen uit Business Central op sleutels.kvt.nl/demeter.

## Structuur

- `web/index.php` — UI (werkorders per kostenplaats)
- `web/project_finance.php` — kosten/opbrengsten/facturen
- `web/bc_fetch/` — OData-fetches (werkorders, kostenplaatsen, caches)
- `web/nightly.php` — nachtelijke cache-verversing (cron)
- `web/odata.php` — OData-client, lokale filecache, optionele Mímir-proxy
- `web/auth.php` — credentials (niet in git)

## Mímir (optioneel)

Zet in `web/auth.php` (niet in git):

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

Met `$mimirApi` gezet proberen company-discovery en alle OData-fetches (`odata_get_all` / `ProjectFinanceService` / `bc_fetch/*` / nightly, zowel web als CLI) eerst Mímir. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Demeter dezelfde data op via het directe Business Central-pad van vóór de Mímir-migratie (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-proces over. Laat die BC-gegevens in `auth.php` naast `$mimirApi` staan; ontbreken ze, dan komt de oorspronkelijke Mímir-fout terug. Zonder `$mimirApi` blijft het bestaande directe BC-pad ongewijzigd.

**max_age-beleid**

| Soort fetch | `max_age` naar Mímir |
| --- | --- |
| `nightly.php` | `DEMETER_NIGHTLY_MAX_AGE` (**14400**, 4u) |
| `hourly.php` | niet aanwezig in Demeter |
| UI / on-demand | bestaande TTLs — o.a. `index.php` **12 uur** (`$hour * 12`) |

Tim moet `$mimirApi` (en optioneel `$mimirBase`) lokaal/op de server zetten; `auth.php` wordt niet gecommit. Zie [Mímir Implementatie](https://wiki.kvt.nl/books/mimir/page/implementatie).

## auth.php

Geen `auth.php` in deze repository (staat in `.gitignore`). Lokaal/op de server `$mimirApi` (en optioneel `$mimirBase`) én de Business Central-credentials (`$baseUrl`, `$auth`, `$auth_list`, `$environment`) naast elkaar zetten. Die BC-credentials zijn de automatische fallback als Mímir uitvalt, ook voor `nightly.php` en andere CLI/cron-scripts.

## Schermcache: wijzigingen uit BC bij het openen (page-open delta)

De schermcache is per week opgebouwd. Gesloten weken worden normaal niet opnieuw gelezen. Daarom haalt de pagina na de catch-up van de huidige week **één keer** op wat er sinds de laatste sync in BC veranderd is (`index.php?action=sync_changes`, code in `web/bc_fetch/workorder_delta.php`).

- **Wanneer**: alleen bij het openen van de pagina, en alleen als de laatste geslaagde sync ouder is dan 180 s (`DEMETER_WORKORDER_DELTA_MAX_AGE_SECONDS`). Er draait geen timer en geen polling. Verder alleen voor een complete cache van de huidige versie, en niet als er een (her)bouw of andere load loopt.
- **Bronnen** (alleen lezen, harde timeout van 15 s per request (`DEMETER_WORKORDER_DELTA_REQUEST_TIMEOUT`), geen retries, normaal 3–5 calls):
  - `ProjectPosten` met `Entry_No` > laatst gezien (nieuwe en achteraf gedateerde posten);
  - `ChangeLogEntries` van tabel 11332939 *Work Order* met `Entry_No` > laatst gezien: status, startdatum, documentstatus en nieuwe werkorders. `Werkorders` heeft geen SystemModifiedAt in OData;
  - `Werkorders` met `Created_Date_Time` > laatste sync (vangnet voor nieuwe werkorders);
  - de gewijzigde werkorders zelf (op `No`, 20 per call, hooguit 200 per sync).
- **Effect**:
  - Een statuswijziging van een werkorder die al in beeld staat, komt direct in de display-rij (status, documentstatus, startdatum).
  - De week van een post met een boekdatum vóór vandaag, of van een nieuwe of nog niet getoonde werkorder met een startdatum vóór vandaag (ook in een gesloten week), krijgt de markering *opnieuw laden*. Een verzette startdatum markeert ook de nieuwe week.
  - De browser leest de gemarkeerde weken daarna één voor één via `load_month` met `force_full=1&resume=1`. Niet-gemarkeerde weken slaat de server over.
  - Na afloop herlaadt de pagina één keer; hooguit één automatische herlaadbeurt per 3 minuten.
  - Posten en werkorders van vandaag of later leest de catch-up van de huidige week.
- **Checkpoint**: staat in `<state>.delta.json` naast de werkorder-state: hoogste `Entry_No` van posten en logboek, `created_since`, `synced_at` en de gemarkeerde weken. Een (her)bouw wist dit bestand niet, en de nightly leest het niet.
  - De eerste keer wordt alleen het checkpoint gezet.
  - Een geslaagde (her)lading van een week haalt de markering weg.
  - Bij een timeout, 409 of andere fout verandert er niets en schuift het checkpoint niet op. De volgende page-open probeert het opnieuw.
  - Per sync hooguit 5000 posten/logregels (`DEMETER_WORKORDER_DELTA_MAX_ROWS`); de rest volgt bij de volgende keer openen.
- **Uitzetten**: `define('DEMETER_WORKORDER_DELTA_ENABLED', false)`.
- **Bekende grenzen**:
  - Een werkorder met een lege kop-kostenplaats die nog niet in beeld staat, komt alleen mee via een post (anders bij de volgende herbouw).
  - Een wijziging van de kostenplaats van een werkorder die al in beeld staat, haalt hem niet uit de lijst.

## Werkorder-store (fase 1, shadow)

Per bedrijf staat er één store-bestand in `web/cache/workorder_store/` (werkorders op `No`, kosten per werkorder uit ProjectPosten en de sync-status). Dat bestand wordt atomisch geschreven (temp + rename) onder een bedrijfslock (flock). Het scherm leest er in fase 1 nog **niet** uit.

- **nightly.php**: volledige snapshot per bedrijf. Die wordt alleen ingewisseld als de BC-counts per afdeling × status kloppen; anders blijft de oude store staan. Daarna vergelijkt de nightly de vorige store met de nieuwe snapshot (reconciliatie) en schrijft de samenvatting naar de nightly-output. Uitzetten: `define('DEMETER_STORE_NIGHTLY', false)`.
- **hourly**: zet in de bestaande `hourly.php` op de server `require_once '<demeter>/web/hourly_hook.php'; demeter_hourly_run();`. Dan worden alle niet-afgesloten werkorders ververst, plus nieuwe ProjectPosten.
- **Pagina openen**: is de store ouder dan 180 s, dan draait één delta-sync onder lock, ná het versturen van de pagina. De sync doet het volgende:
  - counts per afdeling × status
  - bij een verschil inzoomen op Start_Date
  - nieuwe werkorders
  - nieuwe posten op Entry_No
  Uitzetten: `define('DEMETER_STORE_SHADOW_PAGE_SYNC', false)`.
  - Elke BC-call in deze sync (en bij `fresh=1` in de API) heeft een harde timeout van 20 s (`DEMETER_STORE_PAGE_SYNC_REQUEST_TIMEOUT`) en wordt niet herhaald. Daarbovenop geldt een totaalbudget van 25 s.
  - Bij een timeout, 409 of andere fout breekt de sync netjes af. Afgeronde stappen (posten, nieuwe werkorders, complete groepen) blijven bewaard; het afgebroken deel blijft ongewijzigd.
  - De afbreking komt in `last_sync.aborted`. `synced_at` schuift niet op, dus de volgende page-open probeert het opnieuw.
- **CLI**: `php web/tools/store_cli.php snapshot|sync|hourly|reconcile|status "<bedrijf>" [afdeling]`
  - `reconcile` doet een volledige BC-fetch en vergelijkt rij voor rij: velden en kosten.
- **Afgesloten statussen**: Closed, Completed, Cancelled, Invoiced (ook de NL-captions). Checked en Signed tellen als open.

## Werkorder-API (read-only)

`GET /demeter/api/workorders.php` levert werkorders uit de store als JSON. De API pollt BC **nooit**, met één uitzondering: met `fresh=1` draait dezelfde begrensde delta-sync als bij het openen van de pagina, en alleen als de store ouder is dan 180 s.

**Authenticatie** (anders `401`):
- Persoonlijke login-API-key van sleutels.kvt.nl: dezelfde validatie als in Asclepius.
  - Header `X-API-Key` (of `api_key`), plus `X-User-Oid` (of `oid`) en `X-User-Email` (of `user_email`).
  - De key moet `sha256(oid|dd-mm-jjjj)` zijn van vandaag of gisteren (UTC).
  - De gebruiker moet in Demeters `$allowedUsers` staan.
- Of een ingelogde sessie van een toegestane gebruiker.
- Aanroepen vanaf de server zelf (localhost) zijn vertrouwd.

**Parameters**:

| Parameter | Betekenis |
|---|---|
| `company` | verplicht, bv. `Koninklijke van Twist` |
| `afdeling` | één of meer, kommagescheiden (kop-kostenplaats `Job_Dimension_1_Value`); `-` = lege kop |
| `status` | één of meer (NL/EN), bv. `Open,Checked` |
| `open=1` | alles behalve Closed/Completed/Cancelled/Invoiced |
| `start_from`, `start_to` | Start_Date-bereik (JJJJ-MM-DD) |
| `end_from`, `end_to` | End_Date-bereik (JJJJ-MM-DD) |
| `no` | werkordernummer of lijst (max 1000) |
| `job` | projectnummer of lijst |
| `customer` | klantnummer (exact) of deel van de klantnaam |
| `limit`, `offset` | paginering; standaard 500, maximaal 5000 |
| `fresh=1` | eerst verversen als de store ouder is dan 180 s |

**Response**: `{ok, company, shadow, synced_at, full_snapshot_at, age_seconds, sync, last_reconciliation: {at, total_diffs, per_afdeling: {afd: {bc, store, diffs}}}, last_verification, total, limit, offset, rows: [{No, Status, ..., Is_Closed, Costs: {cost, usage_amount, sale_amount, entries}}]}`.

**Fouten**: `400` (ongeldige parameter), `401`, `404` (nog geen store), `405`, `500`. Altijd als JSON en zonder interne details.

```bash
curl -s -G 'https://sleutels.kvt.nl/demeter/api/workorders.php' \
  -H "X-API-Key: $LOGIN_API_KEY" -H "X-User-Oid: $OID" -H "X-User-Email: linda@kvt.nl" \
  --data-urlencode 'company=Koninklijke van Twist' -d afdeling=70 -d open=1 -d limit=100
```
