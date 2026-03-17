# AI Instructies

## Leidende Authoriteit Kosten/Opbrengsten
- Het bestand web/project_finance.php is de leidende authoriteit voor alle logica rond kosten, opbrengsten, resultaat en projectfacturen.
- Dergelijke data moet ALTIJD via functies in web/project_finance.php worden opgehaald.
- Het is NIET toegestaan om kosten/opbrengst/factuurdata buiten dit bestand om direct op te halen wanneer de data al binnen de scope van web/project_finance.php valt.

## Wijzigingsplicht
- Elke wijziging aan web/project_finance.php moet expliciet benoemd worden in communicatie en/of change notes.
- Bij zo'n melding moet expliciet vermeld worden dat dezelfde wijziging mogelijk ook doorgevoerd moet worden in andere implementaties in andere projecten die dit patroon kopieren.

## Views En CSV Export
- De applicatie ondersteunt minimaal deze uitwerkingen van dezelfde dataset:
- Tabel-view: standaard tabelweergave met kolommen per werkorderregel.
- Projectgroepen-view: gegroepeerde weergave op projectniveau met onderliggende werkorders.
- CSV export: export van zichtbare/actieve data op basis van kolomdefinities in de frontend.
- Plaatsingsregel kolommen:
- Werkorder-kolommen horen in tabel-view op de werkorderregel en in projectgroepen-view op de onderliggende werkorderregel.
- Project-kolommen mogen in tabel-view op elke werkorderregel zichtbaar zijn, maar horen in projectgroepen-view primair in de projectheader.
- Bij toevoegen/verwijderen/hernoemen van projectkolommen moet expliciet bepaald worden of de wijziging in de projectheader, werkorderregels of beide thuishoort.
- Elke wijziging aan kolommen (toevoegen, verwijderen, hernoemen, formattering of volgorde) moet in ALLE drie paden correct blijven werken: tabel-view, projectgroepen-view en CSV export.
- Een kolomwijziging is pas correct als rendering, sortering/filtering (waar van toepassing) en export-uitvoer onderling consistent zijn.
