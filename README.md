# NRGDashboard

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.6.0--beta.1-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGDashboard/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGDashboard/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Teil des **NRG-Stack** (DG65) — welche Modulstände zusammenpassen, steht im
internen Kompatibilitäts-Manifest des NRG-Stack.

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

## Szenario-Vertrag (Abstimmung mit NRGSzenariorechner)

**Umgesetzt:** `SZR_GetAvailableScenarios()` steht (NRGSzenariorechner
0.3.1-beta.1, `ems-integration`) — liefert je Typ `type`/`label`/`function`
(Funktionsname ohne `SZR_`-Präfix)/`contractVersion`/`available`/`reason`.
Ein viertes Szenario (Förderende/Solarspitzengesetz, pausiert bis zur
Netztransparenz.de-Registrierung) erscheint hier automatisch, sobald es
steht — kein Anpassungsbedarf bei uns. Konsum und Renderer je `type`
(Phase 3, noch nicht gebaut — Priorität liegt aktuell auf Phase 2).

Ursprüngliche Anfrage von NRGSzenariorechner (25.07.2026), zur
Nachvollziehbarkeit stehen gelassen, zu
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
  `category`) und cacht das Ergebnis in `DeviceCache`. Jeder Partnerzugriff
  steht hinter `function_exists()`; fehlt ein Modul, bleibt dessen Anteil
  einfach leer (Verbund-Grundregel).
- ✅ **Phase 2 — Energiefluss-Diagramm.** Erster Eigenentwurf (vier feste
  Kategorie-Anker) wurde nach Nutzer-Feedback (27.07.2026, "wirkt wie ein
  naiver Erstversuch" im Vergleich zu InverterHub) **verworfen**. Die
  Visualisierung ist jetzt eine direkte Adaption von
  `InverterHubTile/module.html` (Referenz-Kachel des Verbunds): Münz-Look
  (Corona-Glow, Kantenanschliff, Glanzlichter als Vektor-Verläufe statt
  `filter:blur()`), Fluss-Animationen (laufende Dreiecke, Teslaspulen-Blitze,
  Reichweiten-Aura zum Nachbarknoten) und dieselbe Safari-Fallen-Vermeidung
  1:1 übernommen — das ist geräteartenneutrale Layout-/Effekt-Engine, keine
  InverterHub-Spezifik. Alle Geräte (außer einem expliziten `house`-Gerät,
  das nur das Zentrum speist) werden gleichmäßig radial um die Hauslast
  verteilt (`computeLayout()`/`ensureNodes()`), statt in feste Kategorien
  gruppiert zu werden — entspricht dem Verhalten der Referenz-Kachel bei
  variabler Verbraucherzahl. Farbe/Icon je `function`-Typ über
  `FUNCTION_STYLE` (pv=gold/Sonne, battery=blau, grid=grün/rot nach
  Bezug/Einspeisung, charger/vehicle=violett/Auto, heatpump=orange,
  unbekannt=grau/Stecker-Icon). Werte werden bei jedem Rendern frisch aus der
  `powerID`-Referenz gelesen (`resolvePowerValue()` in `module.php`), nie
  gecacht. Status-Ampel/Zeitstempel und das Diagnostik-Panel sind eigene,
  nicht aus InverterHubTile übernommene Ergänzungen (gleiche Plaketten-Optik).
  Bewusst noch nicht übernommen: die datengetriebene Grün/Rot-Einfärbung
  einzelner Verbraucherknoten nach ihrem Netzbezugsanteil (InverterHubTile
  berechnet das aus Sankey-Daten, die uns in Phase 2 noch fehlen) — unsere
  Verbraucherknoten tragen vorerst eine feste Funktions-Farbe.
  **Nachbesserung (27.07.2026, Dietmars direkter Kachel-Vergleich):**
  1) Echter Bug gefunden und behoben — `IHUB_GetFunctions` (InverterHub)
  folgt NICHT dem `MHUB_GetFunctions`-Listenmuster, sondern liefert pro
  Instanz ein Objekt mit `pvPowerID`/`batPowerID`/`gridPowerID`/`socID`. Der
  ursprüngliche `discoverListContract()`-Aufruf hat das mangels `function`-
  Feld stillschweigend verworfen — PV/Batterie/Netz fehlten dadurch
  komplett. Neue eigene Methode `discoverInverterHub()` übersetzt jede
  vorhandene `*PowerID` in einen eigenen Geräte-Eintrag (analog zu einer
  MeterHub-Zuordnung), inkl. `socID` für den Batterie-Ladestand im Icon.
  2) Das Diagnostik-Panel wurde aus dieser Kachel wieder entfernt (Nutzer-
  Wunsch: reine Portierung von InverterHubTile ohne Zusatzelemente, die
  dort nicht existieren — "gehört wahrscheinlich woanders hin"). Das
  Backend (`GetDiagnostics()`/`discoverDiagnostics()`) bleibt bestehen für
  einen künftigen, noch zu bestimmenden Konsumenten.
  3) Feste Node-Labels korrigiert — `discoverInverterHub()` hatte für
  PV/Batterie/Netz überall `IPS_GetName($id)` (den Instanznamen) statt
  fester Rollen-Labels verwendet. Jetzt: `Solar`/`Batterie`/`Netz`, exakt
  wie InverterHubTiles eigene `NODE_DEFS_LEAD`/`TAIL`-Labels — bewusst KEIN
  Instanzname bei diesen drei festen Kernknoten.
  4) **Vollständige `CONSUMER_TYPES`-Tabelle von InverterHub übernommen**
  (auf deren ausdrücklichen Wunsch, 1:1, nicht neu interpretiert — Details
  per `send_message` mit Code-Zeilenangaben aus `InverterHubTile/module.php`
  geliefert): alle 20 Verbraucherarten (`wallbox`/`heatpump`/`ac`/`poolheat`/
  `poolpump`/`sauna`/`boiler`/`dryer`/`washer`/`dishwasher`/`oven`/`stove`/
  `fridge`/`kitchen`/`heater`/`vent`/`light`/`it`/`workshop`/`garage`/
  `other`) mit exakt denselben Icons, Farben und deutschen Labels als
  `CONSUMER_TYPES`-Konstante in `module.html`. `CONSUMER_TYPE_MAP` übersetzt
  die bei uns tatsächlich vorkommenden `function`-Werte dorthin (ChargerHub
  `charger`→`wallbox`, Tessie `vehicle`→`wallbox`, MeterHub `wallbox1..5`/
  `hotwater`/`aircon`/`ventilation`/`pool` → jeweilige Kategorie) — analog zu
  `MHUB_TYPE_MAP` bei InverterHub. `resolveStyle()` prüft zuerst die drei
  festen Kernknoten (`FUNCTION_STYLE`), dann `CONSUMER_TYPE_MAP`/
  `CONSUMER_TYPES`, sonst `DEFAULT_STYLE` (grau, Stecker-Icon).
  Netz-Knoten-Farbe war entgegen der ersten Fehlvermutung bereits korrekt
  bedingt (grün bei Einspeisung, rot bei Bezug) — das gemeldete Fehlen war
  ein alter Browser-Stand, kein Farb-Bug.

  **Zweite Nachbesserungsrunde (27.07.2026, direkter Kachel-Vergleich):**
  1) **Positions-Bug behoben:** `pv`/`battery`/`grid` wurden in Entdeckungs-
  reihenfolge gerendert statt in InverterHubTiles fester `NODE_DEFS_LEAD`/
  `TAIL`-Reihenfolge (Solar, Batterie, …Verbraucher…, Netz) — die radiale
  Verteilung hängt direkt an dieser Reihenfolge, dadurch landete Netz an
  einer völlig anderen Position. `buildItems()` sortiert jetzt explizit in
  `LEAD_ORDER`/`TAIL_ORDER`.
  2) **Vorzeichen `gridPowerID` verifiziert, kein Bug:** InverterHub
  bestätigt `gridPowerID` ist immer `+Einspeisung/−Bezug`, identisch zur
  eigenen Kachelfarblogik — unsere `color:(w)=>w>=0?GRÜN:ROT` war schon
  korrekt. Live nachgeprüft (-6944 W → korrekt Rot). Der ursprünglich
  gemeldete Rot/Grün-Unterschied war ein Zeitpunkt-Unterschied zwischen
  zwei Screenshots, kein Vorzeichenfehler.
  3) **"Verluste"-Knoten bewusst nicht nachgebaut:** InverterHub berechnet
  ihn rein kachel-intern aus einer eigenen Bilanz
  (`lossW = max(0, pvW − gridW + batW − realHouseW)`, optional gegen einen
  externen Hauslastzähler) — kein Vertragswert, den `IHUB_GetFunctions`
  hergibt oder herausgeben sollte (hängt von unserer eigenen, hier noch
  nicht existierenden Bilanzrechnung ab). Bleibt offen für eine spätere
  eigene Bilanz, kein Getter dafür geplant.
  4) **Echte Lücke geschlossen: `IHUBTILE_GetConsumers($id)`** (neu bei
  InverterHubTile, Commit `0f09445`) liefert die komplette, bereits von
  ihrer Kachel gerenderte Verbraucherliste — inkl. **manuell** in ihrer
  eigenen `Consumers`-Property eingetragener Geräte (z. B. eine Klimaanlage
  ohne jeden Hub-Bezug), die über keinen bisherigen `*_GetFunctions`-Vertrag
  sichtbar waren. `discoverInverterHubTileConsumers()` konsumiert das
  bevorzugt; ist eine InverterHubTile-Instanz vorhanden, entfällt die
  eigene direkte MeterHub-/HeishaMon-Abfrage (sonst Dubletten, laut
  InverterHub enthält deren Liste beides bereits). ChargerHub und Tessie
  bleiben eigenständige Quellen (nicht in `IHUBTILE_GetConsumers` enthalten).
  Der `type`-Schlüssel des neuen Vertrags entspricht bereits 1:1 unseren
  `CONSUMER_TYPES`-Schlüsseln — keine weitere Übersetzung nötig.

  **Dritte Runde (27.07.2026): strukturelle Bugs, keine Stilfragen mehr.**
  Zwei echte Datenfehler, keine Optik-Diffs:
  1. **Update-Rhythmus.** InverterHubTile ist ereignisgesteuert
  (`RegisterMessage($vid, VM_UPDATE)` auf jede Quellvariable, sofortiger
  Push bei jeder Änderung) — wir hatten `UpdateVisualizationValue()` nur
  einmal je 5-Minuten-Timer aufgerufen. Dietmar sah dadurch bis zu 5 Minuten
  alte Werte und hielt es zurecht für einen Bug. Jetzt behoben:
  `subscribeToDeviceVariables()` registriert nach jedem `Discover()` eine
  `VM_UPDATE`-Nachricht auf jede `powerID`/`socID`; `MessageSink()` pusht bei
  jeder Änderung sofort einen frischen Payload. Der 5-Minuten-Timer bleibt
  nur noch für `Discover()` selbst (neue/entfernte Geräte erkennen).
  2. **Hauslast-Berechnung.** Statt InverterHubs echter Bilanz
  (`houseBalanceW = pvW − gridW + batW`) hatten wir nur die Summe der
  bekannten Einzelverbraucher (Wallboxen/Wärmepumpe) gebildet — das
  ignoriert jede unsichtbare Grundlast (Kühlschrank, Standby-Geräte usw.)
  komplett und lag deshalb strukturell zu niedrig (33 W statt 239 W bei
  identischem PV-Wert). Jetzt: `houseW = pvW − gridW + batW`, wenn eine
  InverterHub-Instanz vorhanden ist (pv+grid als Voraussetzung); die
  Verbraucher-Summe bleibt nur Rückfall für reine MeterHub-Setups ohne
  Wechselrichter.
  Die Netz-Farbdifferenz aus der vorigen Runde war dagegen tatsächlich kein
  Bug (Vorzeichen von InverterHub bestätigt, live nachgeprüft) — nur ein
  Zeitpunkt-Unterschied zwischen zwei Screenshots, verstärkt durch genau
  den Update-Rhythmus-Bug oben (unsere Werte hinkten der Realität hinterher).

  **Vierte Runde (27.07.2026): Netzfarbe doch ein echter Bug, live belegt.**
  Nach den Fixes oben blieb Netz bei identischer Anlage weiterhin
  gegensätzlich gefärbt zu InverterHubTile (rot bei uns, grün bei ihnen,
  gleicher Betrag). Live geprüft: Instanz-Property `MeterInvert` steht auf
  `true`, aber der rohe `gridPowerID`-Wert war trotzdem nicht korrigiert
  (-5636 W, während InverterHubTile für dieselbe Anlage zeitgleich Export
  zeigte) — entgegen der zuvor erhaltenen Zusicherung, der Vertragswert sei
  MeterInvert-unabhängig immer kanonisch. **Provisorischer Workaround**
  (`discoverInverterHub()`): liest `MeterInvert` selbst per
  `IPS_GetConfiguration($id)` und negiert `gridPowerID` entsprechend (Feld
  `sign` je Geräte-Eintrag, angewendet in `resolvePowerValue()`). Live
  nachgeprüft: Hauslast dadurch von absurden 13 kW auf plausible ~0 W
  korrigiert. **Bewusst als Übergang markiert** — liest eine interne
  InverterHub-Property, kein Vertragsfeld, daher fragil. Rückmeldung mit
  Beweis an InverterHub raus; Zielzustand bleibt ein bereits korrigierter
  `gridPowerID`, dann entfällt der Workaround wieder.

  **Workaround wieder entfernt (27.07.2026, noch selber Tag):** InverterHub
  fand den eigentlichen Bug (Commit `96349f1`) — `MeterInvert` wird bereits
  beim SCHREIBEN von `gridPowerID` angewendet (kanonisch, wie ursprünglich
  zugesichert); ihre eigene Kachel hatte einen Doppel-Invert-Bug (Property
  zusätzlich beim Lesen nochmal angewendet), der sich durch die zweite
  Inversion "zufällig" richtig anfühlte. Unser `MeterInvert`-Workaround
  hätte auf dem jetzt korrigierten Stand GENAU DENSELBEN Doppel-Invert-Fehler
  bei uns reproduziert — eine zweite Korrektur auf einem bereits korrigierten
  Wert. Deshalb sofort wieder entfernt, `gridPowerID` wird jetzt unverändert
  gelesen. Bleibt eine Restdiskrepanz an Dietmars konkreter Instanz (#52838):
  dort steht `MeterInvert` laut InverterHub inhaltlich falsch — ein
  Konfigurationsfehler auf ihrer Seite, kein erneuter Vertragsbruch; liegt
  außerhalb unserer Zuständigkeit.

  **Fünfte Runde (27.07.2026): echte Hauslast-Quelle statt Näherung.**
  Netz/Solar/Batterie stimmten nach der MeterInvert-Klärung überein, Hauslast
  weiterhin nicht (10.315 W bei uns / berechnete Bilanz vs. 1.468 W bei
  InverterHub / echter Zähler). Ursache, gefunden auf Dietmars Hinweis, die
  InverterHubTile-Konfiguration selbst anzusehen: die Kachel hat eine
  `HouseLoadID`-Property (echter Hauslast-Zähler), die laut ihrem eigenen
  Code Vorrang vor der Bilanzformel hat — ein Feld, das nirgends in
  `IHUB_GetFunctions` auftaucht, weil es reine Tile-Konfiguration ist, keine
  Eigenschaft der InverterHub-Kerninstanz. Neuer Vertrag von InverterHub:
  `IHUBTILE_GetHouseLoad($id)` → `{'contractVersion':'1.0','houseLoadID':int}`,
  `0` wenn kein echter Zähler konfiguriert ist (dann bilanzieren beide
  Seiten identisch). `discoverInverterHubTileHouseLoad()` hängt bei
  `houseLoadID > 0` ein `'house'`-Gerät ein — `module.html` bevorzugt ein
  vorhandenes `'house'`-Gerät gegenüber der `pv−grid+bat`-Näherung ohnehin
  bereits (siehe Phase-2-Eintrag oben), keine weitere Änderung an der
  Darstellung nötig.

  **Sechste Runde (27.07.2026): Doppel-Präfix-Bug, Konstanten, plugged-Fall.**
  1) `IHUBTILE_GetConsumers`/`GetHouseLoad` liefen bei InverterHub bisher
  unter dem falschen Namen `IHUBTILE_IHUBTILE_Get*` (Praefix versehentlich
  doppelt vergeben) — bei uns lief das dadurch bisher immer auf den
  MeterHub/HeishaMon-Fallback bzw. gar keine Hauslast-Quelle. Von
  InverterHub gefixt (Commit `6377ed5`), keine Änderung auf unserer Seite
  nötig (Funktionsnamen waren bei uns schon korrekt).
  2) `GLOW_MAX_W`/`AURA_REACH_W`/`FLOW_REF_W` exakt auf InverterHubTiles
  Originalwerte umgestellt (40000/25000/10000 statt unserer vorherigen
  Hausanlagen-Anpassung 12000/8000/6000) — laut InverterHub fest
  einprogrammiert und rein empirisch, keine bewusste Differenzierung
  nötig; für optische Konsistenz zwischen beiden Kacheln übernommen.
  3) **Wallbox-Sonderfall nachgezogen:** "eingesteckt, aber nicht ladend"
  (0 W) gilt als aktiv (volle Farbe), nicht ausgegraut. `plugStateID` kam
  aus `CHUB_GetFunctions` unverändert durch (`normalizeEntry()` entfernt
  keine Felder), wurde aber nie aufgelöst — `buildPayload()` liest jetzt
  `plugStateID` zu `plugged` auf, `buildItems()` übernimmt es (der
  Renderer selbst prüfte `item.plugged` schon vorher korrekt, nur das
  Datenfeld fehlte).
  Node-Reihenfolge/-Winkel, Interaktionslosigkeit (keine Klicks/Tooltips)
  und die Inaktiv-Skalierung (Scale 44/56) waren bei uns bereits identisch
  zur Referenz — keine Änderung nötig, nur bestätigt.

  **Siebte Runde (27.07.2026): Konfigurationsformular auf volle Parität
  gebracht.** Klarstellung Dietmars: "Bewusst schlicht gehalten" war keine
  Vorgabe, der Anspruch ist "so wie in InverterHub". Übernommen: die
  komplette "Darstellung"-Sektion aus `InverterHubTile/form.json` 1:1 —
  `ColorBackground` (SelectColor, −1 = Systemstandard), `FontFamily`
  (ValidationTextBox), `TransitionMs` (NumberSpinner 0–5000ms),
  `FlowRefW` (NumberSpinner 500–100000W) plus der Button
  "Stil zurücksetzen" (`ResetStyle()`, nur `UpdateFormField` — Store-Review-
  Regel, kein `IPS_SetProperty`+`ApplyChanges` im Button). `buildPayload()`
  liefert `bg`/`font`/`transMs`/`flowRefW` jetzt genauso wie InverterHubTile,
  `handleMessage()` wendet sie identisch an (`--font`/`--trans`-CSS-
  Variablen, `FLOW_REF_W`-Override) — dieselbe Rendering-Engine, dieselben
  Stellschrauben.
  **Bewusst nicht übernommen, mit Begründung statt Auslassung:** die
  Panels "Datenquelle"/"Manuelle Datenpunkte"/"Weitere Verbraucher" (feste
  `SourceInstance`-Wahl, `ManualPvID`/`ManualGridID`/etc., `Consumers`-
  Listeneditor) — das ist InverterHubTiles Bindungsmodell für GENAU EINEN
  Wechselrichter mit optional manuell zugewiesenen Variablen. Unser Modell
  ist strukturell ein anderes: automatische Discovery über ALLE
  installierten Partnermodule gleichzeitig (das war der ursprüngliche
  Auftrag — feste IPS-View-Variablen-IDs sollten genau NICHT wiederkommen).
  Ein Pendant dazu wäre kein Darstellungs-Parität-Thema mehr, sondern eine
  zweite, konkurrierende Konfigurationsebene zum Discovery-Mechanismus.

  **Achte Runde (27.07.2026): IHUBTILE-Signaturbruch + Dubletten-Fix.**
  1) `IHUBTILE_GetConsumers`/`GetHouseLoad` verlangten nach einem
  Modul-Reload plötzlich 2 Parameter statt 1 (InverterHub hatte einen
  überflüssigen `$id`-Parameter in der Methode selbst deklariert, obwohl die
  Instanz schon über `$this` gebunden ist) — das ließ `Discover()` bei
  jedem Timer-Tick und Button-Klick mit einem Fatal Error abstürzen. Sofort
  mit `try`/`catch` defensiv abgefangen (sauberer Fallback + Log-Warnung
  statt Absturz), InverterHub hat die Signatur danach korrigiert (Commit
  `644bb16`).
  2) **Echte Dublette gefunden:** Dietmar hat dieselben zwei Wallboxen
  sowohl manuell in InverterHubTiles `Consumers`-Property eingetragen
  (eigene Variablen-IDs) als auch über ChargerHub direkt verfügbar — ohne
  Entdopplung erschienen "WB 1"/"WB 2" zweimal als getrennte Knoten. Kein
  gemeinsamer Schlüssel vorhanden (unterschiedliche `powerID`s je Quelle),
  daher Entdopplung per Label-Abgleich (klein geschrieben, getrimmt):
  ein ChargerHub-Eintrag entfällt, wenn `IHUBTILE_GetConsumers` bereits
  einen Eintrag mit demselben Label geliefert hat — die Tile-Quelle
  gewinnt (das ist die bereits von Dietmar sichtgeprüfte Referenz-Kachel).
  Nach beiden Fixes: `house`-Gerät korrekt mit dem echten Zähler
  (`powerID=33142`) gefunden, keine Dubletten mehr, kein Absturz.
  **Neunte Runde (27.07.2026): manuelle Konfiguration als Ergänzung.**
  Dietmar: "Da man diese Kachel auch ohne InverterHub-Instanz laufen lassen
  können sollte" (InverterHubTiles Grundprinzip) — 1:1 übernommen als
  ZUSÄTZLICHE Quelle, nicht als Ersatz der automatischen Discovery:
  - "Manuelle Datenpunkte": `ManualPvID`/`ManualGridID`/`ManualBatID`/
    `ManualSocID`/`ManualHouseID` + Invert-Schalter für Netz/Batterie
    (`discoverManualCore()`).
  - "Weitere Verbraucher": frei editierbare `Consumers`-Liste (alle 21
    `CONSUMER_TYPES`-Arten, Leistungs-Variable, optional Wallbox-
    "eingesteckt"-Erkennung über Verbunden-Variable+Bedingung+Vergleichswert
    — `resolvePluggedCondition()` deckt `truthy`/`eq`/`ne` ab).
  Bewusst NICHT übernommen (Umfang begrenzt): Einheit-Auswahl je Feld
  (Automatisch/W/kW/MW — wir erwarten Watt direkt) und die Fahrzeug-Zuordnungs-
  tabelle für Wallbox-SOC-Anzeige (Zeit-Korrelations-Algorithmus). Beides bei
  Bedarf nachrüstbar.
  **Umgesetzt (27.07.2026, noch selber Tag):** Dietmars eigener Vorschlag
  statt Neueintragung — Panel "Automatisch gefundene Geräte" listet den
  letzten Discovery-Stand mit einer Ein-/Ausblenden-Checkbox je Zeile
  (`DeviceToggles`-Liste, Muster: `TessieVehicle`s `VisibleVars`-Ansatz).
  `deviceKey()` (Quelle+Instanz+Rolle+Bezeichnung, bewusst NICHT `powerID`,
  da die sich bei manchen Quellen ändern kann) macht die Auswahl über
  Discovery-Läufe hinweg stabil. Store-Review-konform:
  `loadValuesFromConfiguration: false`, die Anzeigespalten (`Geraet`/
  `Quelle`) werden bei jedem Formular-Öffnen frisch aus `GetDevices()`
  befüllt statt aus der Property gelesen — nur `Enabled` wird per `Key`
  übernommen. Ausblenden filtert erst bei der Anzeige (`buildPayload()`),
  nicht schon bei der Discovery — ein ausgeblendetes Gerät bleibt in der
  Liste wählbar, verschwindet nicht spurlos.

  **Architektur-Korrektur, noch selber Tag:** Beim Testen der Toggle-Liste
  fiel auf, dass 5 von 8 Geräten die Quelle "inverterhubtile" trugen.
  Dietmars entscheidender Hinweis: **InverterHubTile wird verschwinden**
  ("wir brauchen keine 2 gleichen Panels") — `IHUBTILE_GetConsumers`/
  `GetHouseLoad` durften deshalb nie eine Dauerabhängigkeit sein, nur ein
  Übergangs-Lückenfüller. Korrigiert: MeterHub/ChargerHub/HeishaMon laufen
  jetzt IMMER direkt (nicht mehr nur als Rückfall, wenn keine
  InverterHubTile-Instanz gefunden wird) — Wärmepumpe kommt jetzt direkt
  von HeishaMon, Wallboxen direkt von ChargerHub, unabhängig davon, ob
  InverterHubTile je existiert hat. `IHUBTILE_GetConsumers` liefert nur noch
  Einträge, die durch KEINE der permanenten Quellen abgedeckt sind (z. B.
  eine manuell in der Kachel eingetragene Klimaanlage ohne eigenes Hub-
  Modul) — Label-Abgleich gegen alle bereits gefundenen Geräte, nicht mehr
  nur gegen ChargerHub. Ebenso bei `IHUBTILE_GetHouseLoad`: nur noch
  Lückenfüller, wenn nicht schon eine manuelle `ManualHouseID` gesetzt ist.
  **Konsequenz für den Nutzer:** Wer eine Klimaanlage oder einen echten
  Hauslastzähler nur in InverterHubTiles Konfiguration eingetragen hat,
  sollte das bei Gelegenheit in NRGDashboards eigene "Weitere Verbraucher"/
  "Manuelle Datenpunkte" übertragen, bevor InverterHubTile gelöscht wird —
  sonst verschwindet der Eintrag mit der Instanz.

  **MeterHub-Bug gefunden, noch selber Tag:** Dietmar fragte, warum 5 echte
  Zähler (4× Shelly Pro 3EM, 1× Siemens PAC2200) nicht auftauchten. Live
  geprüft: `MHUB_GetFunctions($id)` direkt aufgerufen lieferte pro Instanz
  anstandslos 8 Einträge — der Vertrag selbst funktioniert. Der Fehler lag
  bei uns: `MHUB_GetFunctions` ist als `: string` deklariert und liefert ein
  JSON-**kodiertes** Array, während z. B. `CHUB_GetFunctions` `: array`
  direkt zurückgibt. `discoverListContract()` prüfte nur `is_array($entries)`
  und verwarf dadurch **jedes** MeterHub-Ergebnis stillschweigend — die
  Log-Meldung "liefert aber keine auswertbaren Geräte" war technisch korrekt,
  aber irreführend (klang nach einem MeterHub-seitigen Problem, war aber
  unser eigener Parsing-Fehler). Behoben: `is_string($entries)` prüfen und
  bei Bedarf `json_decode()` anwenden, bevor der Array-Check greift.

  **Zweiter Strukturfehler, direkt im Anschluss gefunden:** Selbst nach dem
  JSON-Fix blieb die Meldung bestehen. Live-Vergleich aller 6 MeterHub-
  Instanzen zeigte: `MHUB_GetFunctions` ist ein **Objekt**-Vertrag
  (Instanz-Metadaten wie `meter`/`measureMode`/`latency`/`authority` PLUS ein
  `assignments`-Array mit den eigentlichen Zuordnungen), kein flacher
  Listen-Vertrag wie `CHUB_GetFunctions`. `discoverListContract()` behandelte
  bisher das ganze Objekt faelschlich als Liste. Behoben: `assignments`
  gezielt herausziehen, wenn vorhanden. **Wichtige Nebenerkenntnis dabei:**
  von den 6 MeterHub-Instanzen hat nur "Netzanschluß - Inexogy" tatsächlich
  eine konfigurierte Funktionszuordnung (1 Eintrag) — die 4 Shelly Pro 3EM
  und der Siemens PAC2200 haben `assignments: []` (leer). Das ist eine
  **echte Konfigurationslücke bei MeterHub selbst** (dort muss je Zähler
  eine Funktion wie `grid`/`house`/`wallboxN` zugeordnet werden, bevor er in
  `GetFunctions()` erscheint), keine Lücke bei uns — unser Fix behebt nur,
  dass eine VORHANDENE Zuordnung (wie bei Inexogy) jetzt tatsächlich
  ankommt.
  **Zehnte Runde (27.07.2026): Tabelle voll, Variablen-ID sichtbar,
  Umbenennen inline.** Dietmar zur "Automatisch gefundene Geräte"-Liste:
  Spaltenbreite soll das ganze ExpansionPanel füllen, die Variablen-ID des
  Geräts soll sichtbar sein, und ein Klick auf die Zeile soll die Bezeichnung
  direkt änderbar machen. Umgesetzt:
  - `DeviceToggles`-Spalten erweitert um `ID` (Variablen-ID, `powerID`) und
    eine editierbare `Name`-Spalte ("Bezeichnung (leer = Vorgabe)",
    `ValidationTextBox`, gleiches Muster wie in "Weitere Verbraucher"); die
    reine Info-Spalte `Quelle` bekommt `width: "auto"`, damit sie den
    verbleibenden Platz füllt und die Tabelle die Panel-Breite ausnutzt.
  - `deviceVisibilityMap()` zu `deviceOverrideMap()` erweitert (liefert je
    Schlüssel `enabled` UND `name`), `injectDeviceToggleValues()` befüllt
    beide neuen Spalten aus dem aktuellen Discovery-Stand.
  - `buildPayload()`: Reihenfolge ist entscheidend — `deviceKey()` wird VOR
    einer eventuellen Umbenennung berechnet (der Schlüssel basiert auf dem
    ursprünglichen, discovery-stabilen Label; würde man ihn erst nach dem
    Überschreiben von `label` bilden, ginge der Bezug zur gespeicherten
    Einstellung beim nächsten Rendern sofort wieder verloren). Erst danach
    wird `label` durch die Nutzer-Bezeichnung ersetzt (falls gesetzt) und die
    Sichtbarkeit gefiltert.
  **Direkt im Anschluss gefunden, echter Bug:** Live-Test des Umbenennens
  zeigte `IPS_SetProperty(...,'DeviceVisibility',...)` scheitert mit
  „Eigenschaft nicht gefunden". Ursache: das Formularelement hieß
  `DeviceToggles`, registriert war aber nur die Property `DeviceVisibility`
  (`RegisterPropertyString`) — bei einer `List` muss der Formular-`name`
  exakt der gespeicherten Property entsprechen, sonst landen Bearbeitungen
  (Enabled/Name) beim Speichern nirgends. Behoben: Liste in `form.json` auf
  `"name": "DeviceVisibility"` umbenannt, Treffer-Check in
  `injectDeviceToggleValues()` entsprechend angepasst. **Nebenbefund:**
  Diese schon länger existierende Instanz hatte die Property
  `DeviceVisibility` selbst noch nie registriert bekommen (bekannte
  Symcon-Einschränkung: `RegisterPropertyX` in `Create()` wirkt bei
  Bestandsinstanzen erst nach einem Symcon-Neustart, nicht schon durch
  `ApplyChanges()`) — bis zum nächsten Neustart blieb das Ein-/Ausblenden
  und Umbenennen dadurch wirkungslos (Lesezugriff über
  `readStringProperty()` fing das ab, der Schreibzugriff beim Formular-
  Speichern aber nicht).
  **Elfte Runde (27.07.2026): veraltete Werte nach Symcon-Neustart.**
  Dietmar bemerkte nach einem geplanten Neustart (wegen der Property oben),
  dass die Kacheln "längere Zeit Differenzen" zeigten und erst nach einem
  manuell angestoßenen `NRGDASH_Discover()` wieder stimmten. Ursache:
  `ApplyChanges()` stellte bisher nur den 5-Minuten-Timer, rief `Discover()`
  selbst aber nicht auf. `RegisterMessage`-Abonnements (VM_UPDATE) sind rein
  im Arbeitsspeicher und überleben einen Kernel-Neustart grundsätzlich
  nicht — bis zum ersten Timer-Tick blieb die Kachel dadurch bis zu 5
  Minuten lang ohne aktive Abos und zeigte nur den zuletzt vor dem Neustart
  gecachten Stand. Behoben: `ApplyChanges()` ruft jetzt selbst `Discover()`
  auf (IPS ruft `ApplyChanges()` bei jedem Kernel-Start für jede Instanz
  auf — der richtige Ort, nicht nur der Timer).
  **Zwölfte Runde (27.07.2026): Diagnose-Panel wieder eingebaut.** InverterHub
  übergab proaktiv den vollständigen `IHUBMON_GetDiagnostics()`-Vertrag
  (drei Typen: `yield_vs_forecast`, `mppt_string_compare`, `riso` - Details
  siehe Abschnitt "Diagnostik-Vertrag"). Anders als beim ersten Anlauf (Zweite
  Nachbesserungsrunde) diesmal auf Dietmars ausdrücklichen Wunsch als
  eigenständiges Overlay eingebaut, nicht als InverterHubTile-Kopie:
  - `resolveDiagnostics()` (`module.php`) löst die gecachten Referenzen
    (`measuredPowerID`/`measuredID`/`stringPowerIDs`) live über
    `resolveVariableValue()` auf - bewusst ungeglättet, wie InverterHubMonitor
    selbst. `level`/`threshold`/`reason` werden unverändert durchgereicht,
    NICHTS wird hier neu bewertet.
  - Neues `diagnostics`-Feld in `buildPayload()`.
  - `module.html`: dezentes Badge oben rechts (nur sichtbar, wenn Einträge
    vorliegen, Farbe = schlechtestes `level`), Klick öffnet ein Detail-Panel
    mit Wert, Bewertung und Begründung je Eintrag. Type-neutral wie schon in
    der ersten Fassung - ein künftiger vierter Diagnose-Typ eines anderen
    Partnermoduls braucht keine Änderung an `renderDiagnostics()`, solange er
    dem Grundschema folgt.
  **Dreizehnte Runde (27.07.2026): Instanzbezeichnung in der Geräte-Tabelle.**
  Dietmar zum Screenshot der "Automatisch gefundene Geräte"-Liste: bei
  mehreren gleichartigen Partnerinstanzen (z. B. zwei MeterHub-Zähler mit
  Rolle "grid") war anhand von Rolle/Quelle allein nicht erkennbar, welche
  Zeile zu welcher physischen Instanz gehört. Neue Spalte "Instanz" (Wert
  = `IPS_GetName($instanceID)`, reine Anzeige wie Rolle/ID/Quelle) - "Quelle"
  bekam dafür eine feste Breite (130px), "Instanz" füllt den Rest der
  Panel-Breite.
  **Vierzehnte Runde (27.07.2026): Spaltenreihenfolge.** Auf Dietmars Vorgabe
  umsortiert: Anzeigen · Variablen-ID · Instanz · Bezeichnung · Rolle ·
  Quelle (`Key` bleibt verborgen am Ende). `Quelle` übernimmt jetzt die
  auto-Breite (letzte sichtbare Spalte), `Instanz` bekommt eine feste
  Breite (220px, gleich mit `Bezeichnung`).
  **Fünfzehnte Runde (27.07.2026): Bezeichnung ging nach "Übernehmen"
  wieder verloren - echter Bug.** Dietmar bemerkte, dass eine über die
  Geräte-Tabelle vergebene Bezeichnung nach dem Speichern verschwindet.
  Ursache im Vergleich mit `TessieVehicle`s bewährter `VisibleVars`-Liste
  gefunden: IP-Symcon speichert bei einer `List` standardmäßig nur
  Spalten mit `"edit"`-Definition zurück in die Property - eine
  nicht-editierbare Spalte braucht dafür explizit `"save": true` (siehe
  dort die `Ident`-Spalte). Unsere versteckte `Key`-Spalte (die einzige
  nicht-editierbare Spalte, die wir beim nächsten Laden tatsächlich aus
  der Property zurücklesen - Rolle/ID/Quelle/Instanz werden bewusst immer
  frisch aus `GetDevices()` erzeugt, nicht gespeichert) hatte das nicht -
  beim Speichern fiel der Schlüssel weg, `deviceOverrideMap()` konnte die
  gespeicherte Bezeichnung keiner Zeile mehr zuordnen und verwarf sie
  beim nächsten Formular-Öffnen. Behoben: `"save": true` bei `Key` ergänzt.
  **Sechzehnte Runde (27.07.2026): Diagnose-Badge unklickbar.** Das Badge
  oben rechts lag exakt unter dem WebFront-Doppelpfeil zum Vollbild-
  Umschalten und war dadurch nicht anklickbar. Verschoben nach rechts
  unten (analog zum Status-Overlay links unten), Detail-Panel öffnet jetzt
  nach oben statt nach unten.
- ✅ **Phase 3 — Zeitreihen-Charts, abgeschlossen (27.-28.07.2026).** InverterHub
  übergab proaktiv die vollständige Spezifikation der eigenen
  `InverterHubMonitor`-Kachel (Übergabeziel: Diagnose-Logik bleibt bei
  InverterHub, die Zeitreihen-**Darstellung** wandert zu NRGDashboard).
  Neues Modul **`NRGDashboardPVMonitor`** (eigene GUID/Prefix `NRGDASHMON`,
  library.json auf 0.3.0-beta.1/Build 3 angehoben):
  - **Erste Ausbaustufe:** nur Reiter „PV & Einstrahlung“, nur Ansicht
    „Tag (Verlauf)“ - bewusst nicht die komplette Kachel in einem Schritt,
    um jede Stufe live verifizieren zu können (Arbeitsweise dieser
    Sitzung). MPP-Tracker/Batterie/Strompreis-Reiter sowie Woche/Monat/
    Jahr/Gesamt-Ansichten folgen in weiteren Runden.
  - **Chart-Engine-Entscheidung:** InverterHubMonitor lädt ECharts/
    Highcharts zur Laufzeit von einem CDN nach - das widerspricht dem in
    dieser Datei ursprünglich formulierten Prinzip "kein externer
    CDN-Zugriff, WebFront-Kacheln müssen offline funktionieren". Nach
    Abstimmung mit Dietmar: **ECharts (Apache-2.0) wird lokal eingebettet**
    statt vom CDN geladen (Standard-Engine, kein Internetzugriff nötig).
    **Highcharts bleibt wie in der aktuell installierten InverterHub-Kachel
    vom CDN nachgeladen** (`code.highcharts.com`) - eine Einbettung wäre bei
    Highcharts' proprietärer Lizenz für redistribuierte Software (ein
    Modul, das andere Nutzer installieren) nicht abgedeckt, anders als bei
    Apache-2.0-lizenziertem ECharts. Nutzer, die Highcharts wählen,
    brauchen dafür Internetzugriff und eine eigene gültige Lizenz - das ist
    in der Formularbeschriftung vermerkt.
  - **Zwei Bugs direkt beim ersten Live-Test gefunden, noch selber Tag:**
    1) Der volle ECharts-Bundle (`dist/echarts.min.js`, ~1 MB) sprengte
    allein schon IP-Symcons 1-MB-Ausgabepuffer für Kacheln (Fehlermeldung
    live: "Output-Buffer exceeds Limit (1048576 bytes)") - egal welche
    Engine gewählt war. Behoben mit einem eigenen, auf das Nötigste
    zugeschnittenen Bundle (`esbuild` über `echarts/core` +
    `LineChart`/`GridComponent`/`TooltipComponent`/`LegendComponent`/
    `CanvasRenderer`, keine Balken-/Kreisdiagramme, kein SVG-Renderer),
    das auf **~510 KB** schrumpft - lässt zusammen mit `module.html` und
    der Nutzlast reichlich Luft unter dem 1-MB-Limit. 2) Der Platzhalter
    für den eingebetteten Code wurde bislang UNABHÄNGIG von der gewählten
    Engine ersetzt - auch bei gewähltem Highcharts wurde der komplette
    ECharts-Bundle mitgeschickt und obendrauf geladen. Behoben:
    `GetVisualizationTile()` bettet ECharts nur ein, wenn diese Engine
    tatsächlich aktiv ist.
  - **Archiv-Muster 1:1 von InverterHubMonitor übernommen:** `AC_GetAggregatedValues`
    immer mit 6 Argumenten (Limit=0), Aggregationstyp 5=5-Minuten-Werte.
    Rollierendes 8-Tage-Fenster (`WINDOW_DAYS`) wird in EINEM Archivdurchlauf
    je Serie beim Kachel-Öffnen/Timer-Tick (5 Minuten) vollständig
    mitgeschickt - das Frontend navigiert rein clientseitig zwischen den
    mitgelieferten Tagen (Vor/Zurück/Datumsfeld/Vorgestern-Gestern-Heute),
    ohne bei jedem Klick erneut das Modul aufzurufen.
  - **Erwartungswert-Modell** (`PvfModel()`) 1:1 von InverterHubMonitor
    übernommen: `PVF_GetGenerators()` bevorzugt (kWp+PR je Generator),
    Fallback `IPS_GetConfiguration()` für alte Prognose-Stable-Versionen
    ohne Getter, PR-Default 0,85. **Bewusst NIE `PVF_GetForecast()`** (kann
    einen ratenbegrenzten Wetter-API-Aufruf auslösen) - die Diagnose
    vergleicht gemessene Einstrahlung × Generatorparameter, nicht die
    Wetterprognose, sonst würde ein Wetterfehler wie ein Anlagenfehler
    aussehen (InverterHub/Prognose-Absprache, unverändert übernommen).
    `expectedW = Einstrahlung(W/m²) × Gesamt-kWp × PR` - der scheinbare
    Faktor 1000 (W/m² ↔ kWp) kürzt sich numerisch weg.
  - **PV-Instanz-Erkennung:** automatisch über `IHUB_GetFunctions()`, aber
    NUR wenn genau eine InverterHub-Instanz installiert ist (kein Raten bei
    mehreren Wechselrichtern, Muster: `PvfInstanceID()`) - sonst muss die
    Variable im Formular explizit gewählt werden.
  - Datumssteuerung/Optik folgt der Verbund-Konvention aus InverterHubs
    `CLAUDE.md` ("Kacheln mit Datumssteuerung bedienen sich identisch"):
    Ansicht-Auswahl · ◀ · Datumsfeld · ▶ · Schnellwahl, gleiche CSS-Klassen.
  **Alle vier Reiter fertiggestellt, noch selber Tag (Dietmars Wunsch: „alles
  auf einen Rutsch" statt Runde für Runde):**
  - **MPP-Tracker:** Stränge über den bereits bestehenden Diagnostik-Vertrag
    `IHUBMON_GetDiagnostics()` (Eintrag `mppt_string_compare`,
    `stringPowerIDs`) - bewusst KEIN neuer Vertrag, InverterHub pflegt diese
    Zuordnung schon selbst. Nur bei genau einer InverterHubMonitor-Instanz
    automatisch (kein Raten bei mehreren).
  - **Batterie:** Leistung + SOC über `IHUB_GetFunctions()`
    (`batPowerID`/`socID`), gleiches Ein-Instanz-Muster wie bei PV. SOC
    geglättet über einen zentrierten gleitenden Mittelwert (Fenster 15,
    Muster `InverterHubMonitor::SmoothPoints()`) - reine Anzeigeglättung,
    kein Diagnostik-Wert.
  - **Strompreis:** `TIBBERGR_GetPriceCurve()` (Verbund-Vertrag, optionale
    Instanzwahl analog `PvfInstanceID()`). Bewusst NICHT Teil des
    navigierbaren Tage-Fensters - der Vertrag ist ein VORWÄRTS gerichteter
    Verlauf (jetzt + kommende Slots), keine archivierte Zeitreihe
    vergangener Tage. Die Tagesnavigation wird auf diesem Reiter daher ganz
    ausgeblendet (nicht nur inaktiv) statt einen wirkungslosen Tages-Regler
    zu zeigen; die Kurve wird als Treppenlinie dargestellt (`step`), da sie
    aus Intervallen (`start`/`end`/`price`) statt gleichmäßigen Messpunkten
    besteht.
  - **Bewusst zurückgestellt** (nicht Teil dieser Runde, siehe Übergabe-
    Nachricht von InverterHub 27.07.2026): die Ansichten Woche/Monat/Jahr/
    Gesamt (Energie-Balken statt Leistungslinie, Counter-Erkennung
    lifetime/dayReset, `SlotEnergyBars` für Netzbezug-Balken) - deutlich
    größerer eigener Baustein (5-Jahres-Archivdurchlauf je Serie,
    Zählertyp-Erkennung), der eine eigene Runde verdient statt in dieser
    mitzulaufen.
  **Live-Feedback direkt nach Freigabe (27.07.2026):** 1) Eigenes
  "Monitoring"-Titelfeld überlappte mit dem von IPS selbst gesetzten
  Kachel-Titel ("NRG Dashboard Monitoring") - InverterHubMonitor hat gar
  kein eigenes Titelfeld, verlässt sich vollständig auf den IPS-Titel.
  Entfernt. 2) ECharts zeigte beim allerersten Laden der Kachel nichts an
  (Highcharts war sofort in Ordnung) - der erste `handleMessage()`-Aufruf
  kommt aus einem `<script>`-Block direkt beim Laden, bevor das WebFront-
  Layout sicher fertig vermessen ist; `echarts.init()` auf einem noch
  0×0 großen Container liefert eine leere Zeichenfläche, die ein
  `resize()` 60 ms später nicht zuverlässig repariert. Behoben: der
  allererste Render-Aufruf wird einen `requestAnimationFrame`-Tick
  verzögert. 3) Einstrahlung/PV erwartet fehlten auf der Testinstanz -
  kein Bug, sondern schlicht keine Einstrahlungs-Variable im Formular
  gewählt (`IrradianceID=0`); ohne Einstrahlung kann auch kein
  Erwartungswert berechnet werden, beide Linien hängen an derselben
  fehlenden Einstellung.
  **Sonnenaufgang/-untergang-Zeitfenster (27.07.2026, Dietmars Wunsch):**
  Reiter "PV & Einstrahlung" zeigt jetzt nur noch Sonnenaufgang−1h bis
  Sonnenuntergang+1h statt des vollen Kalendertags - die Nachtstunden ohne
  jede Erzeugung nahmen sonst einen großen Teil der Diagrammbreite ein.
  `SunRange()` nutzt PHP-Kernfunktion `date_sun_info()` mit den Koordinaten
  aus IP-Symcons eigener "Location Control"-Instanz (JSON-Property
  `Location`) - derselben Quelle, aus der der Kernel selbst
  `IsDayStart`/`IsDayEnd` ableitet, keine eigene Konfiguration nötig.
  Fallback auf den vollen Tag, falls kein Systemstandort konfiguriert ist.
  Andere Reiter (MPP-Tracker/Batterie/Strompreis) zeigen weiterhin den
  vollen Tag.
  **Liniendicke halbiert (27.07.2026):** alle Kurven jetzt mit
  `lineWidth`/`lineStyle.width: 1` statt der Standarddicke (2px) beider
  Engines.
  **Linke Achse in kW statt W (27.07.2026):** nur im Reiter "PV &
  Einstrahlung" - PV-Erzeugung und PV erwartet werden im Frontend durch
  1000 geteilt, Einstrahlung (rechte Achse, W/m²) bleibt unverändert.
  MPP-Tracker/Batterie zeigen weiterhin W auf der linken Achse.
  **Feinschliff (27.07.2026):** linke Achse in "PV & Einstrahlung" fest auf
  1-kW-Schritte (`interval`/`tickInterval`), Liniendicke aller Kurven auf
  0,7px (statt zuvor 1px) reduziert.
  **Echter Bug gefunden (27.07.2026):** `tickInterval: 1` griff bei
  Highcharts trotzdem nicht (live: 1,2-kW-Schritte statt 1). Ursache:
  Highcharts' Standardeinstellung `alignTicks` (Default `true`)
  synchronisiert die Anzahl der Gitterlinien zwischen linker und rechter
  Achse - dabei wird ein explizites `tickInterval` auf einer Achse
  stillschweigend überschrieben, damit beide Achsen gleich viele
  Teilstriche bekommen. Live in einem lokalen Testaufbau reproduziert und
  nach dem Fix (`alignTicks: false` im Chart-Objekt) verifiziert (glatte
  0-11-kW-Schritte, unabhängig von der rechten W/m²-Achse).
  **Legenden-Sichtbarkeit bleibt erhalten (27.07.2026, Dietmars Wunsch):**
  Ausgeblendete Kurven bleiben jetzt auch nach dem nächsten Aufruf der
  Kachel ausgeblendet - Muster 1:1 von InverterHubMonitor übernommen:
  `vis`-Objekt je Kurvenname, gesichert in `localStorage` unter dem
  Schlüssel `nrgdashmon_vis_<InstanceID>` (Namensraum je Instanz, damit
  mehrere Monitoring-Kacheln sich nicht gegenseitig beeinflussen). ECharts:
  `chart.on('legendselectchanged', ...)` einmalig beim Erzeugen angehängt,
  `legend.selected` bei jedem `setOption()` aus dem gespeicherten Zustand
  gesetzt. Highcharts: da `Highcharts.chart()` bei uns bei jedem `render()`
  eine komplett neue Instanz erzeugt (kein `update()`), startet jede Serie
  mit `visible: isVisible(name)`, ein `legendItemClick`-Handler hält den
  gespeicherten Zustand bei späteren Klicks aktuell. Lokal verifiziert
  (Umschalten setzt sofort `localStorage`, ein Neuladen der Seite stellt
  den ausgeblendeten Zustand korrekt wieder her).
  **Live-Fehlerbild, noch selber Tag:** Bei Dietmar blieb die Auswahl
  innerhalb einer offenen Seite (Reiter-/Tageswechsel, 5-Minuten-Update)
  korrekt erhalten, ging aber nach einem echten Neuladen (Cmd+Shift+R)
  verloren - obwohl `localStorage` den korrekten Wert enthielt (per
  DevTools bestätigt: `{"Einstrahlung":false}`). Diagnosezeile direkt in
  der Kachel eingebaut (temporär) zeigte: nach dem Neuladen standen
  plötzlich ALLE Serien wieder auf `true` in `localStorage` - der
  korrekte, geladene Wert wurde also aktiv wieder überschrieben, nicht nur
  falsch gelesen. **Ursache gefunden:** ECharts feuert `legendselectchanged`
  nicht nur bei echten Klicks, sondern auch **synthetisch**, wenn per
  `setOption()` ein `legend.selected` gesetzt wird, das vom internen
  Default abweicht (genau der Fall beim Wiederherstellen einer gespeicherten
  Auswahl) - unser Handler hat das ununterscheidbar behandelt und die
  gerade geladene Auswahl sofort wieder mit dem synthetischen (falschen)
  Zustand überschrieben. Behoben mit einer Sperre (`suppressLegendEvent`),
  die während des `setOption()`-Aufrufs aktiv ist und danach sofort wieder
  aufgehoben wird - echte, asynchron ausgelöste Nutzerklicks bleiben davon
  unberührt. Von Dietmar am 28.07.2026 live verifiziert (Cmd+Shift+R-Test
  bestätigt fehlerfrei) - die temporäre Diagnosezeile (`#dbg`-Element +
  Ausgabe in `handleMessage()`) wurde danach wieder entfernt.

  **Lehre für den Verbund (betrifft `InverterHubMonitor` und jede künftige
  Kachel mit demselben `vis`/`localStorage`-Muster):** `InverterHubMonitor`
  hat strukturell **denselben** `chart.on('legendselectchanged', ...)`-
  Handler ohne Unterdrückung während `setOption()` - der Bug ist dort
  bisher nur deshalb nicht aufgefallen, weil er nie mit einer bereits
  vom Standard abweichenden gespeicherten Auswahl gegen einen echten
  Reload getestet wurde (von InverterHub selbst am 27.07.2026 bestätigt:
  "nie auf der echten WebFront-App gegengeprüft"). Jede Kachel, die
  `legend.selected`/`visible` beim Aufbau aus einem gespeicherten Zustand
  setzt, sollte das programmatische Setzen vor einer eventuell synthetisch
  ausgelösten Legenden-Ereignisbehandlung schützen (Muster: Sperrflag rund
  um den `setOption()`/`Highcharts.chart()`-Aufruf) - sonst wird die
  gespeicherte Auswahl beim nächsten Laden lautlos wieder verworfen.

  **Nachbesserung, noch selber Tag: Sperrflag zu früh aufgehoben.** Nach dem
  ersten Fix trat ein neues, verwandtes Symptom auf: eine wieder
  eingeblendete Kurve (echter Klick, korrekt gespeichert) verschwand erneut,
  sobald man den Reiter wechselte und zurückkehrte - ganz ohne Neuladen.
  Ursache: `suppressLegendEvent` wurde direkt nach der synchronen Rückkehr
  aus `setOption()` wieder aufgehoben, ein synthetisches
  `legendselectchanged` von ECharts kann aber auch noch einen Tick **später**
  (nächster Animationsframe) feuern - genau in diesem Fenster war die Sperre
  schon wieder offen und das verzögerte Ereignis überschrieb die gerade
  gesetzte Auswahl erneut. Behoben: Aufheben der Sperre um 50 ms verzögert
  (`setTimeout`), deckt auch nachlaufende synthetische Ereignisse ab, ein
  echter Klick kurz danach bleibt davon unberührt.

  **Weiterer echter Bug, unabhängig gefunden (Dietmar, 28.07.2026):** Die
  Uhrzeit im Tooltip/auf der Zeitachse stimmte bei Highcharts nicht -
  Punkte erschienen 2 Stunden zu früh (z. B. Tooltip "06:30" für einen
  tatsächlich um 08:30 Ortszeit gemessenen Wert). Ursache: Highcharts
  formatiert Zeitstempel standardmäßig in **UTC**, nicht in der Zeitzone
  des Browsers - bei Europe/Berlin im Sommer (UTC+2) ergibt das exakt die
  beobachtete 2-Stunden-Differenz. Unsere Zeitstempel sind echte
  Unix-Millisekunden; ECharts zeigt sie standardmäßig bereits korrekt in
  der Browser-Zeitzone, Highcharts brauchte dafür explizit
  `time: { useUTC: false }`. Lokal mit einem bekannten Referenz-Zeitstempel
  verifiziert (Datenpunkt korrekt zwischen den 08:00/09:00-Achsenmarkierungen
  statt bei 06:30).

  **Architektur-Korrektur (28.07.2026): eigene Legende statt Bibliotheks-
  Ereignissen.** Trotz der 50ms-Verzögerung blieb ein verwandtes Symptom:
  eine wieder EINgeblendete Kurve verschwand erneut, sobald man den Reiter
  wechselte und zurückkehrte - ganz ohne Neuladen. Die Ursache lag im
  Grundmuster selbst: `chart.on('legendselectchanged', ...)` bzw. Highcharts'
  `legendItemClick` sind Bibliotheks-Ereignisse, deren genaues Timing
  (synchron vs. verzögert, echt vs. synthetisch ausgelöst) sich nicht
  zuverlässig vorhersagen lässt - jede weitere Sonderfall-Sperre hätte nur
  das nächste Timing-Loch verschoben, nicht behoben. Stattdessen:
  **komplett eigene Legende** (`#legendRow`, `renderLegend()`) unterhalb des
  Diagramms, die `vis[]` direkt und ausschließlich über echte Klicks auf
  unsere eigenen `<span>`-Elemente ändert. Die native Legende beider
  Bibliotheken ist jetzt abgeschaltet (`legend:{show:false}`/
  `legend:{enabled:false}`), ausgeblendete Serien werden vor dem Bau der
  Chart-Optionen komplett aus dem `series`-Array gefiltert (`isVisible()`)
  statt nur über `legend.selected`/`visible` versteckt - kein
  Bibliotheks-Ereignis wird mehr abgehört, es gibt daher auch keinen
  Zeitpunkt mehr, an dem eine gerade gesetzte Auswahl durch ein verzögertes
  oder synthetisches Ereignis überschrieben werden könnte. Lokal Schritt für
  Schritt nachgestellt und verifiziert: Kurve ausblenden → Reiter wechseln
  → zurück (bleibt aus), Kurve wieder einblenden → Reiter wechseln → zurück
  (bleibt sichtbar) - genau die von Dietmar gemeldete Abfolge.

  **Tooltip-Fix, im selben Zug gefunden:** Dietmar bemerkte, dass der
  Tooltip nur den Wert einer einzelnen Kurve zeigte statt aller drei
  gleichzeitig. Ursache: Highcharts' Tooltip ist standardmäßig
  `shared: false` (nur die Kurve unter dem Mauszeiger), ECharts dagegen
  zeigt mit `trigger: 'axis'` bereits alle Kurven an der Zeitposition -
  der Unterschied fiel nur bei Highcharts auf. Behoben mit `tooltip:
  { shared: true }`. **Von Dietmar am 28.07.2026 live bestätigt** (Kurve
  aus-/wieder einblenden über Reiterwechsel hinweg, Tooltip zeigt alle
  drei Kurven) - beide Fixes halten.

  **Woche/Monat/Jahr/Gesamt/Benutzerdefiniert nachgeliefert (28.07.2026,
  Dietmars Wunsch).** Bislang bewusst zurückgestellt (siehe InverterHubs
  Übergabe-Nachricht) - jetzt umgesetzt, alle Reiter (PV & Einstrahlung/
  MPP-Tracker/Batterie) außer Strompreis (bleibt reiner Vorwärts-Vertrag
  ohne Zeitraumwahl).
  - **`DailyEnergyMap()`** (`module.php`): EIN Archivdurchlauf pro Serie
    über `SPAN_YEARS=5` Jahre (Tagesaggregation, `AGG_DAY`), liefert
    `['Y-m-d' => kWh]`. Da unsere Quellen (PV-/Batterie-/MPPT-Leistung)
    reine Leistungswerte ohne Zähler-Vertrag sind, wird kWh aus der
    Leistung hochgerechnet (`Avg × 24h / 1000`) - eine Näherung (nimmt an,
    der Tagesmittelwert hätte 24h angehalten), aber die einzige uns
    verfügbare Methode ohne `energyImportID`-Vertrag.
  - **Gruppierung komplett clientseitig** (`buildEnergyRows()`,
    `module.html`): das Frontend gruppiert die gelieferte Tages-kWh-Karte
    selbst in Woche (7 Tage)/Monat (Tage des Monats)/Jahr (12 Monate)/
    Gesamt (letzte 5 Jahre)/Benutzerdefiniert (freier Datumsbereich) - kein
    erneuter Archivzugriff je Ansichtswechsel, nur beim erstmaligen Laden
    bzw. dem 5-Minuten-Update.
  - Datumsauswahl bewusst vereinfacht: statt native `type="week"`/
    `type="month"`-Felder (browserübergreifend uneinheitlich, Safari
    unterstützt `type="week"` z. B. nicht) bleibt `#pick` immer
    `type="date"` - ein beliebiger Tag INNERHALB der gewünschten Woche/des
    Monats/Jahres reicht, Vor/Zurück verschiebt den Anker um die passende
    Einheit. Benutzerdefiniert zeigt zusätzlich `#pickTo` für das Bis-Datum.
  - **Echter Bug beim Bau gefunden:** Balken blieben zunächst unsichtbar,
    obwohl `chart.getOption()` die korrekten Werte zeigte. Ursache 1: das
    eigens gebaute ECharts-Bundle (siehe weiter oben) enthielt nur
    `LineChart`, kein `BarChart` - unbekannte Serientypen verwirft ECharts
    stillschweigend. Nachgezogen (`esbuild`-Bundle jetzt mit `BarChart`).
    Ursache 2, gravierender: `render()` leerte `el.innerHTML` VOR jedem
    Aufruf von `renderECharts()`/`renderBarECharts()`, auch wenn die
    bestehende ECharts-Instanz nur weiterverwendet wurde (kein
    Engine-/Typwechsel) - das riss den internen Canvas der Instanz aus dem
    DOM, ohne sie zu entsorgen: `setOption()` lief danach gegen einen
    abgehängten Canvas ins Leere. Behoben: `el.innerHTML` wird jetzt nur
    noch an der einen Stelle geleert, an der tatsächlich eine NEUE
    ECharts-Instanz entsteht (`if (!chart) { el.innerHTML = ''; ... }`),
    nicht mehr pauschal vor jedem Render-Aufruf. Lokal Schritt für Schritt
    verifiziert: Tag → Monat → Jahr → Gesamt → Benutzerdefiniert → zurück
    zu Tag, mit beiden Engines.

  **Nachbesserung, noch selber Tag: Einstrahlung/PV erwartet fehlten in
  den Energie-Ansichten.** Dietmar bemerkte auf der echten Instanz, dass
  "Woche" nur die PV-Erzeugung zeigt - die Tagesansicht hat aber drei
  Linien. Ursache: `energySeriesFor('solar')` lieferte bewusst (aber ohne
  das anzukündigen) nur die PV-Erzeugung, da Einstrahlung/PV erwartet im
  Tagesverlauf aus Leistungswerten kommen, für die es noch keine
  entsprechende Tages-kWh-Berechnung gab. Nachgezogen: `DailyEnergyMap()`
  wird jetzt auch auf die Einstrahlungs-Variable angewendet (liefert
  "Tages-kWh-Äquivalent" nach demselben `Avg×24/1000`-Muster), "PV
  erwartet" wird daraus mit demselben `× kWp × PR`-Kunstgriff wie im
  Tagesverlauf abgeleitet - alle drei Linien der Tagesansicht spiegeln
  sich jetzt auch in Woche/Monat/Jahr/Gesamt/Benutzerdefiniert.
  Reihenfolge links nach rechts auf Dietmars Wunsch: PV erwartet,
  PV-Erzeugung, Einstrahlung.

  **Datumsanzeige an die Ansicht angepasst (28.07.2026, Dietmars Wunsch):**
  Woche/Monat/Jahr zeigten bislang immer ein volles Datum
  („21.07.2026“) - für diese Ansichten verschleiert das eher die
  eigentlich gemeinte Einheit, als sie zu zeigen. Jetzt: `#periodLabel`
  ersetzt das Datumsfeld dort durch „KW 30, 2026“ / „Juli 2026“ /
  „2026“ - Vor/Zurück deckt die Navigation weiterhin vollständig ab, ein
  natives Datumsfeld ist für diese drei Ansichten nicht mehr nötig
  (bleibt weiterhin bei Tag/Benutzerdefiniert). `isoWeekNumber()` liefert
  die Kalenderwoche nach ISO-8601 (Donnerstag-Regel).

  **Tooltip im selben Zug nachgebessert (Dietmars Wunsch).** Der Tooltip-
  Kopf in den Energie-Ansichten zeigte bislang nur die kurze
  Achsenbeschriftung ("Mo 27.07", bloße Tageszahl, Monatskürzel) - jetzt
  zeigt er die volle Bezeichnung ("Montag, 27.07.2026" / "11. Juli 2026" /
  "Juli 2026" / Jahr) über einen eigenen `formatter` (ECharts) bzw.
  `tooltip.formatter` (Highcharts), gespeist aus einem parallel zu den
  Achsenkategorien mitgeführten `fullLabels`-Array in `buildEnergyRows()`.
  **Nachbesserung, noch selber Tag:** Bei Highcharts liefen alle Zeilen
  in einer Reihe statt (wie bei "Tag (Verlauf)") untereinander zu stehen.
  Ursache: der Tooltip-`formatter` gibt HTML-`<span>`-Farbpunkte zurück,
  ohne `useHTML: true` behandelt Highcharts das als reinen Text und
  rendert alles in eine Zeile. Behoben: `useHTML: true` gesetzt, jede
  Zeile zusätzlich in ein eigenes `<div>` gepackt.

  **Tooltip-Werte auf 2 Nachkommastellen begrenzt (Dietmars Wunsch,
  28.07.2026, mindestens für "PV & Einstrahlung", der Einfachheit halber
  überall angewendet).** Sowohl Tagesansicht als auch Energie-Ansichten
  (beide Engines) formatieren Tooltip-Werte jetzt über `Number(v).toFixed(2)`
  statt der vollen Fließkomma-Genauigkeit anzuzeigen. Für die Tagesansicht
  brauchte das einen eigenen `formatter` (vorher kein custom Tooltip dort);
  im selben Zug den Zeitstempel im Tooltip-Kopf ergänzt (Datum + Uhrzeit).

  **Direkt danach ein echter, eigener Fehler gefunden (Dietmar zu Recht
  scharf zurückgemeldet):** Der neu ergänzte Zeitstempel im Highcharts-
  Tooltip-Kopf nutzte `Highcharts.dateFormat()` - eine GLOBALE Funktion,
  die (anders als die Achse selbst) das chart-eigene `time: { useUTC:
  false }` ignoriert und auf UTC zurückfällt. Exakt dieselbe 2-Stunden-
  Abweichung wie beim ursprünglichen Achsen-Bug, diesmal im Tooltip statt
  auf der Achse - Tooltip-Zeit und Achsen-Position liefen dadurch
  auseinander. Behoben: natives JS-`Date` statt der globalen Highcharts-
  API, exakt wie an allen anderen Stellen im Code bereits gehandhabt.
  Live mit einem bekannten Referenz-Zeitstempel verifiziert (Tooltip zeigt
  "28.07.2026 08:30", identisch zur Achsenposition).

  **Verbundweite Selbstprüfung "eigene Anlage als Norm" (28.07.2026,
  ausgelöst durch einen EMS-Formularfehler bei Dietmar - Meldung
  verpflichtend mit konkretem Befund, nicht nur "passt schon").** Beide
  Formulare (`NRGDashboardTile`, `NRGDashboardPVMonitor`) durchgegangen gegen
  die drei Prüffragen (implizite Pflicht-Hardware? automatisch/manuell
  unklar? für Laien mit anderer Anlage verständlich?) plus Volltextsuche
  nach eigenen IDs/Instanz-Nummern/Standortdaten im Repo:
  - **Kein Fund** bei "implizite Pflicht-Hardware": alle Partnermodul-
    Referenzen sind bereits durchgängig als "optional - sonst automatisch
    über X" formuliert, kein Feld verlangt einen bestimmten Hersteller.
  - **Kein Fund** bei "automatisch/manuell unklar": jedes Datenquelle-Feld
    in beiden Formularen nennt bereits explizit die Automatik-Bedingung.
  - **Ein echter Fund** bei "für Laien mit anderer Anlage verständlich"/
    Vollständigkeit: der MPP-Tracker-Reiter hatte anders als PV/Batterie/
    SOC **keinen manuellen Rückfall** - ohne installierte
    InverterHubMonitor-Instanz gab es keinen Weg an MPPT-Daten zu kommen.
    Behoben: vier optionale `SelectVariable`-Felder (`Mppt1ID`…`Mppt4ID`),
    `MpptPowerIDs()` nutzt sie als Rückfall, wenn die Automatik nichts
    liefert. Außerdem zwei Formulierungen präzisiert, die "InverterHub"
    ohne Herstellerneutralitäts-Hinweis nannten (jetzt: "unterstützt jeden
    Wechselrichter-Hersteller, für den dort ein Treiber existiert").
  - Volltextsuche nach eigenen IDs/Instanznummern/Standortdaten: keine
    Treffer außer Code-Kommentaren, die Dietmar als Entscheidungsträger
    nennen (kein Nutzer-sichtbarer Text, keine Default-Werte).
  - Rückmeldung mit diesem konkreten Befund ging an EMS, wie von Dietmar
    verlangt.

  **Temperaturkorrektur für "PV erwartet" ergänzt (28.07.2026, Fund der
  Prognose-Sitzung).** Dietmar zeigte einen Screenshot: "PV erwartet"
  weicht ab Mittag zunehmend nach oben von der tatsächlichen "PV-
  Erzeugung" ab. Die Prognose-Sitzung hat das gegen Dietmars Live-Archiv
  verifiziert (24.07., 13:39 Uhr: 950 W/m² Einstrahlung, 29,1 °C
  Außentemperatur, 9,18 kWp, PR 0,85 → ohne Temperaturglied 7,41 kW, mit
  NOCT-Korrektur 6,58 kW, tatsächliche Erzeugung ~6,0 kW - die Korrektur
  schließt den Großteil der Lücke) und uns beauftragt, das zu übernehmen
  (nicht die Prognose selbst, um `PVF_GetForecast()` weiterhin bewusst
  NICHT zu konsumieren - siehe `PvfModel()`-Kommentar).
  - **`DerateFactor()`** - exakt dieselbe NOCT-Näherung wie
    `PVPrognose/module.php::fetchOpenMeteo()` (`tcell = ta + irr/800×20`,
    `derate = 1 + (tc/100)×(tcell-25)`, geclampt auf ≥0), damit Diagnose
    und Prognose physikalisch konsistent bleiben. Neue Felder
    `TemperatureID` (optional, `SelectVariable`) und `TempCoeff` (Default
    -0,40 %/K, identisch zu `PVF_TempCoeff`s eigenem Default - kein
    bestehender Vertrag liefert diesen Koeffizienten, `PVF_GetGenerators()`
    trägt nur `pr`/`totalKwp`, deshalb eigene Property statt Fremdzugriff).
    Fehlt die Temperatur-Variable, bleibt das Verhalten unverändert
    (`derate=1.0`, rückwärtskompatibel).
  - **Tagesansicht:** exakte Kopplung je 5-Minuten-Zeitstempel (Temperatur-
    und Einstrahlungsreihe laufen auf demselben Raster, daher per
    Zeitstempel-Map koppelbar statt nur per Index).
  - **Energie-Ansichten:** bewusst gröbere Näherung mit einem Tages-
    durchschnitt (`DailyAverageMap()`, neu - reiner Mittelwert ohne den
    Energie-Hochrechnungs-Kunstgriff von `DailyEnergyMap()`) statt einer
    echten 5-Minuten-Integration über 5 Jahre × mehrere Serien, das wäre
    für einen einzelnen Kachel-Aufbau zu teuer. Die dafür nötige
    Durchschnitts-Einstrahlung wird aus der bereits vorhandenen "Tages-
    kWh"-Reihe zurückgerechnet (`kwhEquivalent × 1000 / 24`).
  - Lokal mit den exakten Referenzwerten der Prognose-Sitzung
    nachgerechnet: `derate(29,1°C, 950 W/m², -0,40) = 0,8886`,
    `950 × 9,18 × 0,85 × 0,8886 = 6587 W` - deckungsgleich mit deren
    6,58 kW.

- **ECharts-Optik an Highcharts angeglichen (28.07.2026).** Dietmar meldete
  zunächst eine komplett leere Diagrammfläche bei ECharts (alles andere -
  Reiter, Legende, Datumsleiste - sichtbar). Lokale Nachstellung mit dem
  exakt aktuellen, live deployten Code (`module.html` + `echarts.min.js`)
  und synthetischen Daten zeigte das Diagramm korrekt - der Fehler war
  clientseitig nicht reproduzierbar. Zwischenzeitlich hat sich das Problem
  offenbar durch ein erneutes Laden der Kachel selbst gelöst (kein Fund
  auf unserer Seite nötig), Dietmar konnte anschließend drei echte,
  bleibende Optik-Unterschiede zwischen den Engines an Screenshots
  festmachen:
  - **Zeitachsen-Beschriftung unten abgeschnitten (nur ECharts).**
    `grid.bottom` war mit `12` zu knapp bemessen; auf `32` erhöht.
  - **Tooltip hell statt dunkel (nur ECharts).** Highcharts' `useHTML`-
    Tooltip wird vom Browser/OS automatisch dunkel dargestellt, ECharts'
    Tooltip ist standardmäßig hell - jetzt explizit
    `backgroundColor:'rgba(30,32,36,.92)'`, `textStyle.color:'#e8e8e8'`.
  - **Linien wirkten bei ECharts kräftiger/verwaschener als bei Highcharts**
    (identische `lineStyle.width: 0.7` in beiden Engines). Ursache:
    ECharts' Canvas-Renderer fällt ohne explizites `devicePixelRatio` auf
    `1` zurück, wenn der Browser es nicht selbst liefert - auf einem
    Retina-Display verwäscht das dünne Linien zu optisch dickeren, weichen
    Kanten. Highcharts' SVG-Rendering ist davon unabhängig immer scharf.
    Fix: `echarts.init(el, null, { renderer:'canvas', devicePixelRatio:
    window.devicePixelRatio || 2 })`. Lokal mit synthetischen Zickzack-
    Daten (Rauschen simuliert reale Wechselrichter-Sprünge) vor/nach
    verglichen - Linien danach sichtbar scharf und dünn statt breiig.

- **Weitere ECharts/Highcharts-Optikangleichungen, drei Runden (28.07.2026,
  im Anschluss an obige Runde).** Dietmar hat beide Engines nebeneinander
  verglichen und noch vier Detailunterschiede gefunden - alle lokal mit
  synthetischen Daten nachgestellt und verifiziert, bevor sie live gingen:
  1. **Zarte Hintergrund-Gitterlinien** für Zeit (x-Achse) und die linke
     Achse (PV-Leistung/kW) fehlten ganz; die rechte Achse (Einstrahlung)
     sollte bewusst *keine* eigenen bekommen (zwei Gitter mit
     unterschiedlicher Teilung überlagern sich sonst). ECharts:
     `splitLine` je Achse einzeln gesetzt/deaktiviert. Highcharts:
     `gridLineWidth`/`gridLineColor` je Achse (Standard zeigt sonst an
     beiden y-Achsen Gitterlinien).
  2. **ECharts zeigte Zeitachsen-Beschriftungen nur alle 2 Stunden**,
     Highcharts von sich aus stündlich - ECharts' automatische
     "schöne" Teilstrich-Wahl an einer Zeitachse ist nicht deckungsgleich
     mit Highcharts' Wahl. Fix: `xAxis.minInterval`/`maxInterval` auf
     exakt `3600*1000` (1 Stunde) erzwungen statt der automatischen Wahl
     zu vertrauen.
  3. **ECharts zeigt beim Hovern einen gestrichelten Cursor (`axisPointer`)
     über der Zeitachse, Highcharts von sich aus keinen** - Highcharts
     braucht dafür ein explizites `xAxis.crosshair` (hier:
     `{width:1,color:'rgba(255,255,255,.4)',dashStyle:'Dash'}`), sonst
     bleibt es beim reinen Tooltip ohne visuellen Zeitanker.
  4. **In den Energie-Ansichten (Woche/Monat/Jahr/Gesamt) zeigt ECharts'
     `axisPointer:{type:'shadow'}` beim Hovern eine flächige Aufhellung
     über der ganzen Balken-Kategorie, Highcharts nichts Vergleichbares.**
     Lösung ohne Zusatzaufwand: Highcharts zeichnet auf einer
     Kategorie-Achse (`xAxis.categories`, kein `type:'datetime'`) mit
     einem einfachen `xAxis.crosshair:{color:...}` von sich aus **ein
     flächiges Rechteck über die volle Kategoriebreite** statt einer
     Linie - exakt das gewünschte Pendant, kein Nachbau per zusätzlicher
     unsichtbarer Hilfsserie nötig.
  - Damit ist Phase 3 (Zeitreihen-Charts, alle vier Reiter × alle sechs
    Ansichten, beide Engines optisch angeglichen) aus Sicht von Dietmar
    und dieser Sitzung abgeschlossen.

## Lehren für den Verbund: ECharts/Highcharts-Fallstricke (28.07.2026)

Verbindlich für **jedes** Modul im NRG-Stack, das ECharts oder Highcharts
einbindet (bei uns: `NRGDashboardPVMonitor`; bekanntermaßen auch
`InverterHubMonitor`/`InverterHubEnergy`) - alle Punkte hier wurden live an
Dietmars Instanz gefunden, nicht nur theoretisch vermutet. Details/Code
jeweils in den Runden-Einträgen oben, hier nur die verdichtete Lehre:

1. **Eigene Legende statt Bibliotheks-Klickereignisse.** ECharts'
   `legendselectchanged` und Highcharts' `legendItemClick` feuern nicht
   nur bei echten Nutzerklicks, sondern auch **synthetisch**, wenn man
   selbst per `setOption()`/Neuaufbau eine vom internen Default
   abweichende Auswahl setzt (z. B. beim Wiederherstellen einer aus
   `localStorage` geladenen Sichtbarkeit) - und das Timing dieser
   synthetischen Ereignisse ist nicht zuverlässig vorhersagbar (mal
   synchron, mal einen Tick später). Jede Sperrflag-Lösung verschiebt das
   Problem nur. **Der tragfähige Fix: eine komplett selbst gebaute
   Legende** (eigene klickbare Elemente, native Legende abgeschaltet via
   `legend:{show:false}`/`legend:{enabled:false}`), die den
   Sichtbarkeits-Zustand direkt und ausschließlich selbst verwaltet.
2. **`el.innerHTML` nie leeren, wenn eine bestehende Chart-Instanz nur
   weiterverwendet wird.** Ein Leeren des Containers reißt bei ECharts den
   internen Canvas aus dem DOM, ohne die Instanz zu entsorgen - `setOption()`
   läuft danach gegen einen abgehängten Canvas ins Leere (leerer Wrapper-
   Div, keine sichtbaren Balken/Linien, obwohl `chart.getOption()` die
   Daten korrekt zeigt). Nur leeren, wenn tatsächlich `echarts.init()` neu
   aufgerufen wird.
3. **Ein selbst gebautes/reduziertes ECharts-Bundle muss JEDEN
   tatsächlich genutzten Serientyp enthalten** (`LineChart`, `BarChart`,
   ...) - ein fehlender Typ wird von ECharts **stillschweigend verworfen**
   (leeres `series`-Array in `getOption()`, keine Fehlermeldung).
4. **Highcharts formatiert Zeit standardmäßig in UTC**, nicht in der
   Zeitzone des Browsers - `time: { useUTC: false }` je Chart-Objekt
   nötig, ECharts macht das bereits von sich aus richtig.
5. **`Highcharts.dateFormat()` ist eine GLOBALE Funktion und ignoriert
   das chart-eigene `useUTC: false`** - derselbe UTC-Fehler kann so an
   zwei Stellen unabhängig auftreten (Achse UND Tooltip), einmal behoben
   heißt nicht überall behoben. Für Datumsformatierung in eigenen
   Tooltip-/Label-Formattern natives JS-`Date` verwenden, nicht die
   Highcharts-eigene Formatierungs-API.
6. **Highcharts' Tooltip ist standardmäßig `shared: false`** (zeigt nur
   die Serie unter dem Mauszeiger) - für einen Vergleich mehrerer Kurven
   an derselben Zeitposition (wie bei ECharts' `trigger:'axis'` bereits
   Standard) explizit `shared: true` setzen.
7. **Ein eigener HTML-Tooltip-`formatter` in Highcharts braucht
   `useHTML: true`**, sonst werden `<span>`/`<div>`-Tags als reiner Text
   ausgegeben und alle Zeilen laufen in eine Reihe statt (wie gewünscht)
   untereinander zu stehen.
8. **Werte in Tooltips/Anzeigen bewusst auf 2 Nachkommastellen begrenzen**
   (`Number(v).toFixed(2)`) statt der vollen Fließkomma-Genauigkeit -
   sonst zeigen Rundungsartefakte lange, bedeutungslose Nachkommastellen.
9. **ECharts' Canvas-Renderer braucht ein explizites `devicePixelRatio`**
   (`echarts.init(el, null, { renderer:'canvas', devicePixelRatio:
   window.devicePixelRatio || 2 })`) - ohne das fällt es auf `1` zurück,
   wenn der Browser es nicht selbst liefert, und dünne Linien verwaschen
   auf Retina-Displays zu optisch kräftigeren, weichen Kanten. Highcharts'
   SVG-Rendering ist davon unabhängig immer scharf - bei einem direkten
   Vergleich beider Engines mit identischer `lineWidth` fällt der
   Unterschied sofort auf.
10. **Gitterlinien je Achse explizit setzen, nicht auf Standardwerte
    verlassen** - beide Engines zeigen sonst an beiden y-Achsen (z. B.
    kW UND W/m²) eigene, unterschiedlich geteilte Gitter, die sich
    optisch überlagern. ECharts: `splitLine` pro Achse. Highcharts:
    `gridLineWidth`/`gridLineColor` pro Achse.
11. **Eine Zeitachse braucht `minInterval`/`maxInterval`, wenn ein festes
    Beschriftungsraster (hier: stündlich) gewünscht ist** - ECharts'
    automatische Teilstrich-Wahl an einer Zeitachse muss nicht mit der
    von Highcharts übereinstimmen, auch bei identischem Datenbereich.
12. **Highcharts zeichnet `xAxis.crosshair` auf einer Kategorie-Achse
    (`categories`, kein `type:'datetime'`) automatisch als flächiges
    Rechteck über die volle Kategoriebreite**, nicht als Linie - das
    ist bereits das Pendant zu ECharts' `axisPointer:{type:'shadow'}` in
    Balkendiagrammen, ganz ohne Nachbau per zusätzlicher Hilfsserie.

**Nicht-Chart-Lehre, aber im selben Zeitraum gefunden, ebenfalls
verbindlich:** Eine versteckte, nicht-editierbare `List`-Spalte (z. B.
ein interner Schlüssel zur Zeilen-Identifikation über einen Neuaufbau
hinweg) braucht explizit `"save": true` im Formular - IP-Symcon speichert
bei einer `List` standardmäßig nur Spalten mit `"edit"`-Definition zurück
in die Property.

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

## Beitrag zum gemeinsamen Zielbild (SUITE.md, 27.07.2026)

Reflexion, nicht nur Beantwortung der Anfrage — die vier Verbund-Ziele
(Wirtschaftlichkeit, Netzdienlichkeit, Zuverlässigkeit ohne KI-Krücke,
Einfachheit für den Nutzer) angewandt auf NRGDashboard:

- **Zuverlässigkeit ohne KI-Krücke — bereits umgesetzt.** Der reale Vorfall
  vom selben Tag (IHUB_GetFunctions liefert ein Objekt, kein Listen-Vertrag —
  PV/Batterie/Netz fielen dadurch still aus der Anzeige) wäre einem
  Endnutzer ohne Live-Debugging gar nicht aufgefallen, nur als "Kachel zeigt
  weniger als erwartet". `checkSourceCoverage()` in `Discover()` meldet jetzt
  automatisch (Log + sichtbar), wenn ein installiertes Partnermodul
  Instanzen hat, aber keinen einzigen auswertbaren Geräte-Eintrag liefert —
  genau das Symptom eines sich geänderten Vertrags. Kein Ersatz für einen
  echten Testrahmen (wie `MeterHub/.tools/test-virtual.php`), aber eine
  billige erste Verteidigungslinie.
- **Einfachheit für den Nutzer.** Die Status-Ampel/Zeitstempel (siehe oben)
  dient demselben Ziel: der Nutzer sieht auf einen Blick, ob die Anzeige
  aktuell ist, ohne ins Log schauen zu müssen.
- **Netzdienlichkeit — Vorschlag, noch nicht umgesetzt.** Sobald Phase 3
  ansteht, läge es nahe, den StromGedacht-Ampelstatus (`SGW_GetState`) als
  kleines Badge direkt am Netz-Knoten zu zeigen, nicht nur als separate
  Zeitreihe — der Nutzer sieht dann am Energiefluss-Diagramm selbst, ob
  gerade eine netzdienliche Einschränkung greift, statt es aus einem
  separaten Chart erschließen zu müssen.
- **Wirtschaftlichkeit — Vorschlag, noch nicht umgesetzt.** Ebenso ließe sich
  der aktuelle Tibber-Preis (`TIBBERGR_GetPriceCurve`) als kleiner Wert am
  Netz-Knoten einblenden (nicht erst das volle Zeitreihen-Chart aus Phase 3)
  — würde die "lohnt sich Bezug/Einspeisung gerade"-Frage schon in der
  Grundansicht beantworten, nicht erst mit einem zusätzlichen Reiter.

## Umstieg von InverterHubTile (Migration, manuell)

**Wichtig für den Go-Live, muss in die Release-Kommunikation (Forum-Post,
"Was ist Neu"-Panel), nicht stillschweigend voraussetzen** — InverterHub hat
Stand 27.07.2026 ca. 250 Installationen. Wer bisher InverterHubTile als
Kachel auf einem WebFront-Dashboard-Screen platziert hat, muss den Wechsel
**manuell** vornehmen — dafür gibt es keinen automatisierten Weg (geprüft,
keine Vermutung): eine WebFront-Kachel ist eine reine Platzierungs-Referenz
auf eine Instanz-ID im Dashboard-Editor, keine IPS-API biegt eine bereits
platzierte Kachel programmatisch auf eine andere Instanz um. `MigrationsHub`
deckt das ebenfalls nicht ab — das migriert Variablen samt Archivhistorie/
Referenzen bei Geräte-Ersatz, hat aber keinen Bezug zu Dashboard-Layouts.

**Nötige Schritte für den Nutzer:**
1. NRGDashboard-Instanz anlegen und konfigurieren (Discovery findet
   vorhandene Partnermodule automatisch, kein manuelles Verknüpfen nötig).
2. Im WebFront-Editor die alte InverterHubTile-Kachel vom Dashboard-Screen
   entfernen.
3. Die neue NRGDashboardTile-Instanz an derselben Stelle neu platzieren.

Wenige Klicks, aber ein bewusster Nutzereingriff — gehört explizit ins
"Was ist Neu"-Panel und in einen etwaigen Forum-Ankündigungstext, sobald
das Modul veröffentlicht wird (siehe auch `EMS/SUITE.md`, Abschnitt
„WebFront-Kachel-Wechsel InverterHubTile → NRGDashboardTile").

## Feinheiten — konservierter Text für Doku & Community (28.08.2026)

Dietmars Auftrag: die eingebauten Details sollen im "Dokumentation &
Hilfe"-Panel dokumentiert UND für die Modulbeschreibung in der Community
konserviert werden ("das sind schöne Feinheiten die andere so nicht
haben") — künftige Feinheiten hier NACHTRAGEN (Pflege-Pflicht analog zum
News-Panel; bei Unsicherheit, ob ein Detail erwähnenswert ist, Dietmar
fragen). Der folgende Text ist bewusst paste-fähig für Forum/Store:

**PV-Monitoring (`NRGDashboardPVMonitor`):**

- **Selbstaufräumende Reiterleiste:** Reiter, deren Datenquelle gar nicht
  existiert (keine Instanz/Variable konfiguriert oder discovered), werden
  komplett ausgeblendet — kommt die Quelle hinzu, erscheinen sie von
  selbst. Reiter mit Quelle, aber momentan ohne Daten, färben sich leicht
  rot (so sieht man sofort, was gerade schief läuft), versorgte Reiter
  leicht grün.
- **Reiterleiste als schwebendes Panel:** per Pfeil-Knopf (auf Höhe der
  Zeitsteuerung) ein-/ausblendbar, legt sich ÜBER das stehende Diagramm
  (kein Layout-Gezappel), klappt nach der Reiterwahl von selbst wieder zu;
  Zustand wird je Gerät gemerkt. Vier wählbare Animationen hinter dem
  Doppelpfeil — darunter ein per numerischer Physik-Simulation berechnetes
  "Einsaugen ins schwarze Loch" (20 Feder-Partikel, 1/r²-Gravitation,
  Spiral-Einfall; die Ecken fallen echt nacheinander ein).
- **Sonnenstand-Tattoos:** kleine Sonnen über der Zeitachse zwischen
  Auf- und Untergang (Höhe = echter Sonnenstand). Mit installiertem
  Prognose-Modul koppeln sich Größe und Helligkeit an die prognostizierte
  PV-Leistung — ein bewölkter Vormittag zeigt blasse Sonnen. Auch im
  Tagesplan.
- **Kontextsensitiver Reitername:** ohne Einstrahlungssensor heißt der
  Reiter schlicht "Photovoltaik" statt "PV & Einstrahlung".
- **Mitlaufende Legenden-Summen:** hinter PV-Erzeugung bzw. Netzbezug
  stehen Tages-/Monats-/Jahres-kWh, die sich auf den ANGEZEIGTEN Tag
  beziehen (Monat = Monatsanfang bis einschließlich dieses Tags) — beim
  Zurückblättern rechnen sie mit. Netzbezug bevorzugt echte
  Zählerstände des abrechnungsverbindlichen Zählers.
- **Ehrliches Archiv-Wasserzeichen:** bei verzögert archivierenden
  Abrechnungszählern (z. B. Inexogy, 15-45 Min. Nachlauf) markiert eine
  schraffierte Fläche "Zeitbereich ohne Bezugsdaten!" im
  Strompreis-Reiter, statt Lücken als Nullverbrauch erscheinen zu lassen.
- **Einheitliche, mittige Diagrammflächen** über alle Reiter (gleiche
  Plotränder, beide Engines) und **zwei Zeichen-Engines** (ECharts/
  Highcharts) zur Wahl.
- **IPSView/Browser-Betrieb:** jede Kachel läuft auch als eigenständige
  Webseite über einen automatisch registrierten WebHook (URL im
  Doku-Panel), mit 30-s-Selbstaktualisierung.

**Wärmepumpen-Monitoring (`NRGDashboardWPMonitor`):** startet direkt mit
der heutigen Tagesansicht samt Vorgestern/Gestern/Heute-Schnellwahl;
gleiche IPSView-Fähigkeit.

**Energiefluss-Kachel (`NRGDashboardTile`):**

- **Klickbare Geräte-Knoten:** ein Klick auf einen Verbraucher/Erzeuger
  in der Energiefluss-Darstellung öffnet dessen Details als eigene,
  kachelfüllende Seite über den WebHook der Kachel — bewusst
  außerhalb der Kachel, die im Dashboard-Grid für Diagramme oft zu klein ist.
  Gezeigt werden alle Vertragsfelder des jeweiligen Geräts: bei Zählern z. B.
  Spannung/Strom/cos φ/Frequenz, automatisch je Phase (L1–L3) gruppiert,
  bei Wallboxen/Fahrzeugen Ladezustand, Steckerstatus und zugeordnetes
  Fahrzeug — dazu ein Leistungsverlauf mit Tagesnavigation und die
  Energiebilanz der letzten 14 Tage als Balken (bevorzugt vom echten
  Zähler des Geräts, sonst aus der Leistung integriert, als solche
  gekennzeichnet). Komplett aus den vorhandenen `*_GetFunctions`-Verträgen
  erzeugt — kein Gerätetyp ist im Code verdrahtet, ein neuer
  Vertragsanbieter liefert automatisch dieselbe Detailansicht mit.
- **Archivierung selbst aktivieren:** war die Leistungs-Variable eines
  Geräts noch nicht protokolliert, aktiviert die Detailseite die
  Archivierung beim ersten Aufruf des Leistungsdiagramms automatisch
  selbst, statt nur "keine Archivdaten" zu melden und es dabei zu
  belassen — der erste Aufruf zeigt noch keine Historie, ab da sammelt
  sich der Verlauf von selbst.
- **Chip-Form ab vielen Verbrauchern:** der Haus-Knoten wechselt ab einer
  größeren Anzahl an Verbraucher-Knoten automatisch von der Kreis- in eine
  "Chip"-Form (Pille mit geraden Seiten) — so bleiben die einzelnen Knoten
  auch bei sehr vielen Geräten (Dietmars Anlage hat über 50 einzeln
  messbare Punkte) auf einer sinnvollen Größe, statt immer weiter zu
  schrumpfen. Die Breite wächst dabei linear mit der Verbraucherzahl statt
  wie beim Kreis nur mit dem Radius.
- **Echte Strompreise statt fester Werte:** die Kostenersparnis-Berechnung
  auf der Geräte-Detailseite nutzt automatisch Tibber Grid Rewards, falls
  installiert, sonst holt sich das Modul selbstständig (ohne API-Schlüssel)
  quartalsweise den BDEW-Haushaltsdurchschnittspreis von der offiziellen
  BDEW-Übersichtsseite — inklusive Aufschlüsselung der Ersparnis nach
  zeitvariablen Netzentgelten, dynamischem Tarif und Grid-Reward-Erlös
  gegenüber dem deutschen Standardtarif.
- **Preis-Vertrag für den ganzen Verbund:** `NRGDASH_GetPriceAt($ts)`/
  `NRGDASH_GetPriceSeries($from, $to)` geben den zu jedem Zeitpunkt
  gültigen Strompreis (echte Tibber-Slots, sonst aus unserer eigenen
  BDEW-Preishistorie rekonstruiert) an andere Module weiter, damit die
  Preisermittlung nicht in jedem Modul neu gebaut werden muss — Basis
  für Kostenauswertungen über beliebige Zeiträume (Tag/Monat/Jahr/
  Lebenszeit), nicht mehr nur den heutigen Tag.
- **Vorführmodus:** eine Instanz-Eigenschaft ("Vorführmodus") blendet die
  Wallbox-Steuerung (Freigabe/Limit/Start/Stopp) komplett aus - weder
  zeigt das Frontend Schalter, noch nimmt der WebHook Steuerbefehle
  entgegen (serverseitig zusätzlich abgesichert, nicht nur versteckt).
  Gedacht für Demo-/Vorstellungs-Instanzen, die alle Geräte/Werte zeigen
  sollen, aber garantiert keine Auswirkung auf echte Geräte haben dürfen.
  Erkennt zusätzlich automatisch, wenn OCPPHub selbst im eigenen
  Vorführmodus ist (`OHUB_IsDemoMode()`) und blendet die Steuerung dann
  auch bei ausgeschaltetem eigenem Vorführmodus für dieses Gerät aus —
  koordinierter Schutz statt zweier unabhängiger Mechanismen.
- **Ausreißer-Schutz bei Archivwerten:** ein einzelner defekter Messwert
  im Archiv eines Partnermoduls (real gefunden: 261.554.185 W nachts bei
  einer 9,18-kWp-Solaranlage, wodurch der Tagesbalken 2204,91 kWh
  statt eines plausiblen Werts zeigte) wird jetzt verworfen statt
  ungeprüft in Leistungsdiagramm, 14-Tage-Energiebalken, Geisterring,
  Autarkiegrad und PV-Prognose-Ring einzufließen — eine generische
  Implausibilitätsgrenze (1 MW), kein anlagenspezifischer Wert. Dieselbe
  Absicherung gilt für `NRGDashboardPVMonitor` (Tagesansicht,
  Jahresvergleich, Energiebilanz) und `NRGDashboardWPMonitor`
  (Tagesansicht, Energiebilanz) — beide teilen dasselbe Muster
  (`AC_GetAggregatedValues()`-Leistungswerte zu kWh hochgerechnet).
  Der Jahresvergleich prüft dabei bewusst auf TAGES-Granularität statt
  Monats-Granularität: verworfen wird nur der einzelne betroffene Tag,
  nicht der komplette Monat — bei häufigeren historischen Ausreißern
  (z. B. über einen längeren Zeitraum vor einem Partnermodul-Fix)
  bleibt so der Großteil eines Monats/Jahres real erhalten, statt
  komplett zu verschwinden.
- **Diagramm-Zoom (`NRGDashboardPVMonitor`):** ein „+“-Knopf oben rechts
  am Diagramm blendet Legende/Tabelle/Zusatzleisten desselben Reiters
  aus und lässt das Diagramm die volle Kachelgröße einnehmen — vor
  allem beim Jahresvergleich mit vielen Jahren hilfreich, wo die
  wachsende Legende das Diagramm sonst immer weiter schrumpfen lässt.
  Gilt reiterübergreifend, kein Sonderfall je Ansicht.
- **Automatisches Durchschalten (`NRGDashboardPVMonitor`):** die
  Instanz-Einstellung "Reiter automatisch alle 10 s weiterschalten"
  (Standard aus) versetzt die Kachel in einen Kiosk-/Vorführmodus, der
  selbstständig durch alle aktuell sichtbaren Reiter blättert (ausgeblendete
  Reiter ohne Datenquelle werden übersprungen) — über denselben echten
  Klick-Pfad wie ein manueller Reiterwechsel, damit alle Nebeneffekte
  korrekt mitlaufen. Gedacht für Demo-/Vorstellungs-Instanzen.
- **Diagrammtitel (`NRGDashboardPVMonitor`):** oben mittig über dem
  Diagramm steht der Name des gerade aktiven Reiters — 1:1 aus der
  Reiterleiste übernommen (keine zweite, separat zu pflegende
  Beschriftungsliste), bleibt auch beim Diagramm-Zoom sichtbar.
- **EMS-Begründung (`NRGDashboardTile`):** sofern das EMS-Modul den
  Vertrag `EMS_GetCurrentDecision()` bereitstellt, zeigt die Kachel
  unten ein kleines Feld mit der aktuellen Schaltentscheidung inkl.
  Klartext-Begründung (z. B. „Netzladen – Grid Rewards (Tibber)“) —
  rein informativ, ohne Rückwirkung.
- **Ladestand bei manueller Wallbox (`NRGDashboardTile`):** eine
  manuell eingetragene Wallbox („Weitere Verbraucher“) kann
  zusätzlich ein `SocID` tragen (direkt in der gespeicherten Liste,
  kein eigenes Formularfeld) — zeigt dann wie bei automatisch
  erkannten Fahrzeugen den Ladestand am Knoten.
- **Breite Kachel besser genutzt (`NRGDashboardTile`):** die
  Geräte-Anordnung passt sich jetzt der tatsächlichen Kachel-Breite
  an, statt immer in einem festen Quadrat zu bleiben — bei vielen
  Geräten (Chip-Form des Haus-Knotens) finden dadurch deutlich mehr
  davon nebeneinander Platz, ohne kleiner werden zu müssen. Bei
  extremer Breite wächst zusätzlich die Höhe des Haus-Tisches
  proportional mit, damit er ein rundliches Rechteck bleibt statt
  eines dünnen Balkens.
- **Beliebige Zusatzwerte (`NRGDashboardTile`):** eine manuell
  eingetragene Wallbox oder ein sonstiger Verbraucher kann zusätzlich
  beliebig viele eigene Wertfelder tragen (JSON-Zeile, Feldname endet
  auf `ID`/`IDs`, z. B. `vinID`) — erscheinen automatisch in der
  „Aktuelle Werte“-Tabelle der Detailseite, exakt wie bei automatisch
  erkannten Geräten.
- **Ruhigere Blitzbögen bei breiter Pille (`NRGDashboardTile`):** die
  Blitzbögen am Haus-Knoten passen Amplitude und Feinheit jetzt dem
  Seitenverhältnis der Pille an — bei vielen Geräten (breite, flache
  Pille) wirken sie dadurch fein statt zappelig, bei kreisrunder Form
  unverändert.
- **Aufschachteln (`NRGDashboardTile`):** Sammelknoten — virtuelle
  Zähler mit Unterzählern (MeterHubVirtual-Vertrag 1.3 `members`) oder
  manuelle Verbraucher mit verschachteltem `Members` — tragen ein
  Zähler-Badge. Kurzer Klick öffnet die nächste Ebene: der Knoten wird
  zur Mittelpille, seine Mitglieder ordnen sich darum an, mit allen
  Funktionen der ersten Ebene, beliebig tief. Zurück per Klick auf die
  Pille (eine Ebene) oder die Brotkrumen-Zeile (direkt). Langer Klick
  (500 ms, Füllring) öffnet die Detailseite — auch für Mitglieder ohne
  eigenes Gerät (synthetisch aus dem Mitgliedseintrag). Abgezogene
  Mitglieder (negativer Faktor) erscheinen gestrichelt mit Minus. Die
  Hierarchie kommt vom Anbieter (MeterHub liefert nur die eigene Ebene,
  die Rekursion löst das Dashboard über die Quell-Instanz der
  Mitglieds-Variable), die Kachel legt keine eigenen Gruppen an.
- **Automatische Vorführung (`NRGDashboardTile`):** Instanz-Eigenschaft
  (Standard aus). Bei Inaktivität öffnet die Kachel von selbst
  Detailseiten und schachtelt Sammelknoten auf und zu; jede Berührung
  pausiert das für zwei Minuten. Für Vorstellungs-Instanzen.
- **Schaltgruppen (`NRGDashboardTile`):** liefert MeterHubVirtual
  `switchID`/`switchStateID` (Vertrag 1.4), zeigt die Kachel einen
  kleinen Schalt-Knopf am Knoten — grau/gelb/grün für aus/teilweise/an.
  Ein einzelnes Mitglied lässt sich direkt schalten, eine Gruppe
  schaltet alle schaltbaren (nur positiven) Mitglieder auf einmal —
  über denselben transportneutralen RequestAction-Weg wie die
  Wallbox-Steuerung, respektiert also auch den Vorführmodus.
- **Simulation hydraulisch schlüssig (`NRGDashboardHeatSchema`):** im
  simulierten Warmwasserbetrieb stehen beide Heizkreise still — das
  Dreiwegeventil leitet den gesamten Volumenstrom in den Speicher, ein
  gleichzeitig laufender Heizkreis wäre physikalisch unmöglich. Der
  Vorlauf zeigt dabei die höhere Speicherlade-Temperatur statt der
  Heizkreis-Temperatur.
- **Heizstab nur wo er hingehört (`NRGDashboardHeatSchema`):** in der
  Simulation ist der Heizstab standardmäßig aus und erscheint nur noch
  im Abtaubetrieb — ein Zuheizer im Kühlbetrieb wäre fachlich falsch,
  in Heiz-/Warmwasserbetrieb bei einer gesunden Anlage die Ausnahme.
- **Isolierter Demo-Modus (`NRGDashboardTile`):** das Häkchen
  „Isolierter Demo-Modus“ schaltet jede automatische Geräte-Erkennung
  ab — Netz/PV/Batterie/Haus kommen dann ausschließlich aus den
  manuellen Kern-Feldern. Gedacht für eine reine Vorstellungs-Instanz
  mit erfundenen, aber in sich rechnerisch stimmigen Werten (eine
  Mischung aus echten Live-Messwerten und erfundenen Zusatz-
  verbrauchern geht sonst rechnerisch nicht auf).
- **Ausreißer-Schutz auch in `NRGDashboardForecast`:** derselbe
  Fehlermechanismus (ein defekter Archivwert verzerrt eine aus
  Leistungswerten berechnete Anzeige) betrifft dort `readMeasured()`
  (stündlicher Pfad über `AC_GetAggregatedValues()`) und
  `measuredFine()` (15/30-Minuten-Pfad über rohe Log-Punkte, dort ohne
  Max/Min einer Aggregationszeile — Rohwerte werden direkt beim
  Einlesen geprüft) — beide verwerfen jetzt unplausible Werte, statt
  sie in den "Ist"-Vergleich zur Prognose einfließen zu lassen.
- **Tour jederzeit selbst starten (alle sieben Kacheln):** ein "?"-Knopf
  unten links zeigt die Einführungs-Tour erneut, unabhängig vom
  gespeicherten "schon gesehen"-Status. Anlass: eine gemeinsam genutzte
  Demo-/Vorstellungs-Instanz mit einem geteilten Zugang für alle
  Besucher — sobald ein Besucher die Tour bestätigt, würde sie den
  nächsten Besuchern sonst gar nicht mehr angezeigt (der Status ist
  serverseitig geteilter Zustand, nicht pro Besucher). Rein
  client-seitig, ändert nichts am bisherigen automatischen
  Erstanzeige-Verhalten. Position bewusst unten links, nicht oben
  rechts — dort sitzt WebFronts eigenes, natives Doppelpfeil-Symbol
  (Kachel-Vollansicht), mit dem der Knopf sonst kollidiert (Fund
  01.09.2026: Dietmar konnte ihn dadurch nicht erreichen). Auffällig
  groß und deckend statt eines kaum sichtbaren kleinen Icons.
- **Safari-Fix bei Knotenbeschriftungen:** bei bestimmten Kombinationen
  aus Knotenposition und Beschriftungslänge brach die komplette
  Kachel-Darstellung in Safari ab (`Invalid value for <text> attribute
  textLength=""`), während Chrome/Firefox den fehlerhaften Wert
  stillschweigend ignorierten — ein Rundungsfehler bei `Math.sqrt()`
  nahe der Kreisgrenze (`fitTextWidth()`) wird jetzt explizit
  abgefangen, statt sich auf browserspezifisches Fehlerverhalten zu
  verlassen.
- **Vollständige Stromwerte-Tabelle je Gerät:** die Detailseite zeigt jetzt
  eine automatisch gruppierte Tabelle aller relevanten elektrischen Werte
  (z. B. auch je MPPT-Strang unter Solar oder je Batterie-Turm bei
  Mehrblock-Anlagen), erkannt rein am Namensmuster der Variablen — kein
  Gerätetyp ist dafür im Code verdrahtet.
- **SOC als Füllstand:** der Batterie-Knoten füllt sich zusätzlich wie
  ein Glas - von unten steigend, im Kreis des Knotens (zusätzlich zur
  genauen Prozentzahl im Icon).
- **Diagnose-Warnungen direkt am Knoten:** ein rotes/gelbes Warndreieck
  erscheint direkt am betroffenen Knoten, sobald eine Diagnose auffällig
  oder kritisch wird — vorher nur im separaten, leicht zu übersehenden
  Diagnose-Badge unten rechts.
- **Gestern-Geisterring:** ein gestrichelter Ring um jeden Knoten
  vergleicht die aktuelle Leistung mit dem Wert von gestern zur gleichen
  Uhrzeit (größer als der feste Ring = gestern mehr, kleiner = gestern
  weniger) — throttled aus dem Archiv, kalendertag-sicher auch an den zwei
  Zeitumstellungstagen im Jahr.
- **PV-Prognose-Fortschrittsring und Strompreis-Sparkline:** der
  Solar-Knoten trägt (bei installierter PV-Prognose) einen Fortschrittsring
  für den heutigen Ertrag gegenüber der Tagesprognose; der Netz-Knoten
  zeigt eine kleine Sparkline des Tagespreisverlaufs und wechselt alle paar
  Sekunden zwischen Watt und Kosten pro Stunde.
- **Autarkiegrad-Bogen am Haus-Chip:** ein grüner Bogen um den Haus-Knoten
  zeigt den Anteil des heutigen Verbrauchs, der nicht aus dem Netz kam.
- **Flussdicke und Tagesspitzen-Marker:** die Verbindungslinien werden mit
  der fließenden Leistung dicker (nicht nur schneller animiert) und tragen
  einen kleinen Querstrich an der Position der heutigen Tagesspitze.
- **Netzampel-Farbwäsche:** bei installierter StromGedacht-Instanz färbt
  ein sehr dezenter Farbverlauf im Hintergrund die aktuelle Netzampel-Stufe
  ein.
- **Anzeige-Feinheiten hinter dem Doppelpfeil:** ob inaktive Knotenpunkte
  aus- oder eingeblendet werden, ob Blitzbögen/Leuchtschein an die Leistung
  gekoppelt sind und wie stark diese Effekte wirken, stellt jeder Nutzer
  direkt im WebFront ein — die Kachel über den Doppelpfeil (Symbol zum
  Vollbild-Umschalten) aufziehen öffnet die Standard-Objektansicht der
  Instanz mit den entsprechenden Schaltern/Reglern, ganz ohne
  Konsolenzugriff.
- **Wallbox-Steuerung direkt aus der Detailseite:** wird eine Wallbox
  (ChargerHub oder OCPPHub) von keiner anderen Instanz geregelt (EMS/
  Tibber/§14a/etc.), zeigt die Geräte-Detailseite Schaltflächen für
  Ladefreigabe und Stromlimit — herstellerunabhängig über die
  gemeinsame Vertragsvariable (`chargeEnableID`/`currentLimitID`),
  ganz ohne modulspezifischen Code. Bei OCPP-Ladepunkten kommen
  zusätzlich echte Lade-Start/Stopp-Befehle und ein einmaliger
  Tages-Override ("heute trotzdem vollladen") dazu — die Kachel bleibt
  dabei reine Darstellungsschicht, die eigentliche Steuerungslogik
  liegt bei ChargerHub/OCPPHub selbst.
- **Stromlimit-Schieberegler mit echten Gerätegrenzen:** Ober-/Unter-
  grenze kommen ausschließlich aus dem Vertrag des jeweiligen Geräts
  (z. B. 5 A statt pauschal 6 A bei manchen Tesla-Wallboxen), nie ein
  fest verdrahteter Wert. Der Regler zeigt zusätzlich den Prozentwert
  der maximalen Ladeleistung, ein Tick-Raster und markiert die halbe
  Leistung als eigenen Punkt, damit auch bei größeren Wallboxen
  (z. B. 32 A/22 kW) sofort erkennbar ist, wo man sich auf der Skala
  befindet.
- **Rückmeldung bei Wallbox-Befehlen:** jeder Klick auf Freigabe/
  Limit/Start/Stopp zeigt sofort einen Sendestatus statt bei Erfolg
  komplett stumm zu bleiben. Bei Start/Stopp wird zusätzlich kurz
  danach die tatsächliche Leistung der Wallbox nachgeladen — bleibt
  sie nach "Laden starten" bei 0 W, erscheint ein Hinweis, dass die
  Wallbox den Befehl vermutlich abgelehnt hat (Beispiel aus der
  Praxis: ein OCPP-Ladepunkt mit mehreren Connectors lehnte
  `RemoteStartTransaction` ohne genaue Connector-Angabe kommentarlos
  ab — unser eigener Request meldete trotzdem `ok:true`, weil der
  PHP-Aufruf fehlerfrei durchlief). Liefert das Partnermodul über das
  optionale Vertragsfeld `blockReasonID` (additiv, contractVersion 1.2)
  einen konkreten Ablehnungsgrund als Klartext, zeigt die Detailseite
  ihn prominent direkt über den Steuer-Schaltflächen an — herstellerunabhängig,
  jedes Partnermodul kann das Feld optional befüllen.

## Formular-Konvention (SUITE.md "Einheitliche Formular-Optik")

Nachgezogen (27.07.2026, war zuvor komplett vergessen — berechtigte
Rückfrage von Dietmar): das Konfigurationsformular folgt jetzt derselben
Grundstruktur wie alle anderen NRG-Stack-Module (Referenz: InverterHub):

1. **"🆕 Neu in Version X.Y"** — aufgeklappt, pro Version dismissible
   (`newsBanner()`/`AckNews()`, Attribut `SeenNews`), keine Versionsnummer
   im Panel selbst.
2. **"📖 Dokumentation & Hilfe"** — eingeklappt, Versionsnummer wird dynamisch
   eingefügt (`injectVersionIntoDocPanel()`, aus `library.json`).
3. Fachpanels (Manuelle Datenpunkte, Weitere Verbraucher, Darstellung).
4. **GitHub-Hinweis** (noch kein Forum-Thread, Modul unveröffentlicht —
   Muster: ChargerHub vor der Forum-Veröffentlichung), einmalig dismissible
   (`DismissReviewHint()`, Attribut `ReviewHintDismissed`).

**Pflege-Pflicht ab jetzt beachten:** bei jedem künftigen Fix/Update prüfen,
ob etwas ins News-Panel gehört — Ergebnis darf „nichts Relevantes" sein,
die Prüfung selbst ist Pflicht. Aktuelle `NEWS_VERSION` = `0.2.0`.

## Store-Review-Checkliste

Wie alle NRG-Stack-Module: keine Selbstpersistenz in Formular-Buttons, kein
`IPS_SetProperty`+`ApplyChanges` in `onClick`, `library.json` nur mit den
erlaubten Feldern, `vendor` bleibt leer (reines Softwaremodul), Idents/
Feldnamen sind API und werden nicht umbenannt.
