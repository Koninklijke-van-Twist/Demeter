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

Met `$mimirApi` gezet zijn `$auth_list`, `$environment`, `$baseUrl` en `$auth` ongebruikt voor Business Central — company-discovery en alle OData-fetches (`odata_get_all` / `ProjectFinanceService` / `bc_fetch/*` / nightly) lopen via Mímir. Zonder `$mimirApi` blijft het bestaande directe BC-pad ongewijzigd.

**max_age-beleid**

| Soort fetch | `max_age` naar Mímir |
| --- | --- |
| `nightly.php` | `DEMETER_NIGHTLY_MAX_AGE` (**14400**, 4u) |
| `hourly.php` | niet aanwezig in Demeter |
| UI / on-demand | bestaande TTLs — o.a. `index.php` **12 uur** (`$hour * 12`) |

Tim moet `$mimirApi` (en optioneel `$mimirBase`) lokaal/op de server zetten; `auth.php` wordt niet gecommit. Zie [Mímir Implementatie](https://wiki.kvt.nl/books/mimir/page/implementatie).

## auth.php

Geen `auth.php` in deze repository (staat in `.gitignore`). Lokaal/op de server de Mímir-sleutel zetten zoals hierboven; legacy BC-credentials alleen nodig zonder `$mimirApi`.
