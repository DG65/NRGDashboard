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

## Diagnostik-Vertrag

**Umgesetzt:** `IHUBMON_GetDiagnostics($id)` (InverterHubMonitor, ab
0.74.0-beta.1, Build 191) ist der erste Anbieter dieses Vertragsmusters —
konsumiert in `NRGDashboardTile::discoverDiagnostics()`. Erste drei Typen:
`yield_vs_forecast` (Ertrag vs. PV-Prognose, `measuredPowerID` als Referenz +
`expected` als Wert), `mppt_string_compare` (`stringPowerIDs` als Referenzen
je Strang), `riso` (Isolationswiderstand, `measuredID` + konfigurierbare
Schwelle `RisoWarnKOhm`, Default aus/0). Jeder Eintrag trägt `level`
(`normal`/`auffaellig`/`kritisch`/`null` — `null` heißt: noch keine
Bewertung möglich, z. B. fehlende Kopplung oder unkonfigurierte Schwelle),
`threshold`, `reason` sowie `contractVersion` (`'1.0'`) auf Instanz-Ebene.
Fehlende Voraussetzungen (keine Einstrahlung, <2 Stränge, kein Riso-Wert)
lassen den jeweiligen Eintrag einfach weg, kein Fehler.

**Rendering bewusst type-neutral** (`module.html`, `renderDiagnostics()`):
kennt keinen der konkreten `type`-Werte, liest nur `label`/`level`/`reason`
sowie optional `expected`+`unit` oder zählt Referenzfelder (Muster `*ID`/
`*IDs`). Ein künftiger zweiter Anbieter (MeterHub, HeishaMon, ChargerHub)
braucht dafür keine Änderung am Renderer, solange er demselben Grundschema
folgt.

Ursprüngliche Empfehlung an InverterHub (25.07.2026, vor der Umsetzung), zur
Nachvollziehbarkeit stehen gelassen: dem bestehenden `*ID`-Suffix-Muster
folgen (SUITE.md/MeterHub-Konvention: ein Feld mit `ID`-Suffix ist eine
**Referenz**, die der Konsument selbst auflöst/aggregiert; ein Feld ohne
`ID`-Suffix ist ein bereits interpretierter **Wert**). Konkret:

- **Gemessene Größen als Referenz** (`powerID`/`energyImportID` o. ä.) —
  NRGDashboard zeichnet Zeitreihen ohnehin selbst über
  `AC_GetAggregatedValues`/`AC_GetLoggedValues` (Phase 3), ein zweiter Weg für
  dieselben Rohdaten wäre nur ein doppelter Pfad für dasselbe Ergebnis.
- **Berechnete Vergleichs-/Erwartungswerte als Wert, nicht als Referenz** —
  z. B. der erwartete Ertrag aus PVF-Generatorparametern × gemessener
  Einstrahlung ist InverterHub-Domänenwissen (`PvfModel()`), nicht aus dem
  Archiv ableitbar; hier macht ein Nachbau bei uns keinen Sinn, das gehört in
  den Vertrag als Wert (ggf. als kleine Zeitreihe, wenn der Verlauf und nicht
  nur der aktuelle Stand gebraucht wird).
- **Bewertung als Metadaten-Feld** je Diagnose-Eintrag: `level`
  (`normal`/`auffaellig`/`kritisch`), `threshold` (Wert + Einheit, z. B. Riso
  in kΩ), `reason` (kurzer, deutscher Text) — analog zum bereits verbund-
  weiten Muster „Bewertung trifft der Anbieter, nie das Dashboard" (siehe
  `level`-Diskussion bei `TIBBERGR_GetPriceCurve`, wo bewusst NICHT das
  Dashboard/EMS die Einstufung übernimmt, sondern die Quelle mit
  Detailwissen).
- **Liste von Einträgen** (wie `*_GetFunctions`), auch bei nur einem Typ —
  gleicher Grund wie überall im Verbund: spätere Aufteilung bricht die
  Signatur nicht.
- `contractVersion` von Anfang an, Start `'1.0'`.

Kein eigenes Muster von anderen Hubs übernehmbar — MeterHub/HeishaMon liefern
bislang nur Rohwert-Verträge, kein Diagnose-Vertrag existiert im Verbund
bereits. `IHUB_GetDiagnostics` wäre der erste seiner Art; das Muster sollte
dokumentiert werden (README hier + InverterHub-README), damit MeterHub/
HeishaMon/ChargerHub sich später daran orientieren können, statt ein
zweites Format zu erfinden.

**Kernprinzip:** keine manuell verknüpften Variablen-IDs. Alle Geräte werden
über die bestehenden `*_GetFunctions`-Verträge des Verbunds gefunden (analog
`EMS_Discover()` im EMS-Repo) — fällt eine Instanz weg oder kommt neu dazu,
zieht das Dashboard automatisch nach.

## Szenario-Vertrag (Abstimmung mit NRGSzenariorechner, in Arbeit)

Antwort auf die Anfrage von NRGSzenariorechner (25.07.2026) zu
`SZR_CalculateDynamicTariffScenario`/`SZR_CalculateStorageSizeScenario`/
`SZR_CalculateParagraph14aScenario`:

- **Sammel-Getter ja, bitte** — `SZR_GetAvailableScenarios()`, analog zum
  Listen-Muster von `*_GetFunctions`/`IHUBMON_GetDiagnostics`: eine Liste von
  `{type, label, contractVersion}` (plus was sonst an Metadaten sinnvoll ist,
  z. B. `dataComplete`/Voraussetzungen). Grund: Wir wollen bei einem vierten
  Szenario (Netztransparenz.de, angekündigt) nicht erneut eine neue
  Funktion fest verdrahten müssen — der Sammel-Getter ist die Discovery-
  Ebene, dieselbe Rolle wie `discoverListContract()` bei den Hub-Verträgen.
- **Keine erzwungene Einheits-Hülle über den drei Rückgaben** — anders als
  beim Diagnostik-Vertrag (dort strukturell sehr ähnliche Einträge) sind
  eure drei Szenarien strukturell zu verschieden (Objekt vs. Liste je
  Speichergröße vs. Kostenvergleich), ein gemeinsames Feld-Schema würde nur
  krampfhaft glätten. Stattdessen: **ein Rendering pro `type`**, analog dem
  `ICONS`-Muster in `InverterHubTile/module.html` (dort eine Zeichenfunktion
  je Geräteart) — bei uns eine Darstellungsfunktion je Szenario-`type`.
  `SZR_GetAvailableScenarios()` sagt uns nur, *dass* und *unter welchem
  `type`* ein Szenario verfügbar ist; die Detailfelder je Typ bleiben wie
  von euch dokumentiert (PHPDoc über den `Calculate*`-Funktionen).
- Aufruf der einzelnen `Calculate*`-Funktionen weiterhin direkt (Instanz-ID +
  Funktionsname) — der Sammel-Getter ersetzt das nicht, er sagt nur, welche
  es gibt und ob sie aktuell sinnvoll aufrufbar sind (`dataComplete`).
- `contractVersion` je Szenario wie gehabt.

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
