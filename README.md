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
