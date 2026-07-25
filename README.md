# NRGDashboard

Teil des **NRG-Stack** (DG65) — welche Modulstände zusammenpassen, steht im
[Kompatibilitäts-Manifest](https://github.com/DG65/EMS/blob/main/SUITE.md).

## Zweck

Automatisch generierte Energiefluss-Visualisierung (Haus-Stromlaufplan mit
Geräte-Icons und Flusslinien) plus ergänzende Zeitreihen-Charts (Strompreis,
PV-/Lastprognose, Leistung/Energie je Einzelgerät) — als Ablösung einer
bisher genutzten Drittanbieter-App ("IPS View"), deren Widget-Verknüpfungen
auf festen Variablen-IDs beruhen und beim Löschen der referenzierten Instanz
kommentarlos brechen.

**Zielrolle (Architekturentscheidung Dietmar, 25.07.2026):** NRGDashboard wird
langfristig die **einzige Darstellungsfläche** im Verbund — nicht nur für die
Anlagenübersicht, sondern auch für gerätespezifische Diagnose, die heute noch
in modul-eigenen Kacheln lebt (z. B. `InverterHubMonitor`: PV-Soll/Ist-
Vergleich, MPPT-Strangvergleich, Isolationswiderstand-Diagnose). Die
**Diagnoselogik** (Berechnung, Schwellenwerte, Gerätewissen) bleibt beim
jeweiligen Partnermodul; NRGDashboard übernimmt nur die **Darstellung**.
Konsequenz fürs Rendering: es muss generisch genug sein, um neben rohen
`*_GetFunctions`-Live-Werten auch vorberechnete Diagnose-/Vergleichsdaten
(Soll vs. Ist, Schwellenwert-Warnungen) aus einem künftigen Diagnostik-
Vertrag (z. B. `IHUB_GetDiagnostics($id)`, mit InverterHub abzustimmen)
darzustellen — kein reines Fluss-Icon-plus-Zeitreihen-Layout. Betrifft vor
allem Phase 2/3-Design: Panel-Typen generisch halten (Wert, Vergleich,
Warnung), nicht an ein Icon-Set gebunden.

**Kernprinzip:** keine manuell verknüpften Variablen-IDs. Alle Geräte werden
über die bestehenden `*_GetFunctions`-Verträge des Verbunds gefunden (analog
`EMS_Discover()` im EMS-Repo) — fällt eine Instanz weg oder kommt neu dazu,
zieht das Dashboard automatisch nach.

## Namenswahl

`NRGDashboard` statt z. B. `EMSDashboard`, weil das Modul **kein** EMS-Vertrag
ist und auch ohne EMS sinnvoll funktioniert (reine Anzeige der Hub-Verträge).
Passt an den bestehenden `NRG.*`-Profilpräfix an.

## Aufbau (geplant)

- **NRGDashboardTile** (dieses Verzeichnis): HTML-SDK-Kachel, Phase 1 bereits
  vorhanden.
- Weitere Module/Kacheln für die Zeitreihen-Charts (Phase 3) folgen als
  eigene Instanzen im selben Repo, sobald Phase 2 steht.

## Phasenstand

- ✅ **Phase 1 — Discovery.** `NRGDashboardTile::Discover()` durchsucht alle
  installierten Partnerinstanzen (InverterHub, MeterHub/MeterHubVirtual,
  ChargerHub, HeishaMon, Tessie), normalisiert deren Verträge auf ein
  gemeinsames Geräte-Schema (`function`/`label`/`powerID`/…/`source`/
  `category`) und cacht das Ergebnis in `DeviceCache`. `module.html` zeigt in
  dieser Phase nur eine nach Kategorie gruppierte Liste — kein Diagramm.
  Jeder Partnerzugriff steht hinter `function_exists()`; fehlt ein Modul,
  bleibt dessen Anteil einfach leer (Verbund-Grundregel).
- ⏳ **Phase 2 — Energiefluss-Diagramm.** Icon-Katalog je `function`-Typ
  (Referenzmuster: `InverterHubTile/module.html`, Objekt `ICONS`),
  automatische Anordnung in vier Kategorien (Erzeugung → Speicher →
  Verteilung → Verbraucher — bereits in `functionCategory()` vorbereitet),
  Live-Update über `UpdateVisualizationValue()`.
- ⏳ **Phase 3 — Zeitreihen-Charts.** Strompreis (`TIBBERGR_GetPriceCurve`),
  PV-/Lastprognose (`PVF_GetForecast`/`LFC_GetForecast`), Leistung/Energie je
  Gerät (`AC_GetAggregatedValues`/`AC_GetLoggedValues` auf `powerID`/
  `energyImportID`). Eigene, selbst eingebettete Chart-Lösung ohne externen
  CDN-Zugriff (WebFront-Kacheln müssen offline funktionieren) — Wahl der
  konkreten Bibliothek noch offen, siehe Koordinationshinweis unten.

## Verwendete Verträge

| Partner | Vertrag | GUID |
|---|---|---|
| InverterHub | `IHUB_GetFunctions($id)` | `{BBE2C593-1A91-426D-A714-29A9C7E87589}` |
| MeterHub | `MHUB_GetFunctions($id)` | `{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}` |
| MeterHubVirtual | `MHUBV_GetFunctions($id)` | `{ADF18291-2E60-4354-92F5-B96863C127C8}` |
| ChargerHub | `CHUB_GetFunctions($id)` | `{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}` |
| HeishaMon | `HEISHA_GetFunctions($id)` (Feldnamen übersetzt, siehe unten) | `{1919151A-3C0F-4C09-B906-291638EC1469}` |
| Tessie | `TESSIE_GetVehicleState($id)` | `{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}` |
| Tibber Grid Rewards | `TIBBERGR_GetPriceCurve($id)` (Phase 3) | `{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}` |
| StromGedacht | `SGW_GetState()`/`SGW_GetForecast()` (Phase 3, optional) | `{D5A8C3A1-2222-4A55-8888-123456789003}` |
| PVPrognose | `PVF_GetForecast($id)` (Phase 3) | `{257DD4E8-9705-462E-89FC-56D0A1038353}` |
| Lastprognose | `LFC_GetForecast($id)` (Phase 3) | `{DC5AD508-507F-40EA-8630-0959AED83050}` |

HeishaMon veröffentlichte seinen Vertrag vor der Verbund-Konvention und
verwendet abweichende Feldnamen (`Type`/`Caption`/`PowerID`/`EnergyID`/
`Measured`) — die Übersetzung auf `function`/`label`/`powerID`/
`energyImportID`/`measured` liegt bewusst auf der Konsumentenseite
(`discoverHeishaMon()`), der veröffentlichte Vertrag wird nicht umbenannt.

## Store-Review-Checkliste

Wie alle NRG-Stack-Module: keine Selbstpersistenz in Formular-Buttons, kein
`IPS_SetProperty`+`ApplyChanges` in `onClick`, `library.json` nur mit den
erlaubten Feldern, `vendor` bleibt leer (reines Softwaremodul), Idents/
Feldnamen sind API und werden nicht umbenannt.
