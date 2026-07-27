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
- 🚧 **Phase 3 — Zeitreihen-Charts, gestartet (27.07.2026).** InverterHub
  übergab proaktiv die vollständige Spezifikation der eigenen
  `InverterHubMonitor`-Kachel (Übergabeziel: Diagnose-Logik bleibt bei
  InverterHub, die Zeitreihen-**Darstellung** wandert zu NRGDashboard).
  Neues Modul **`NRGDashboardMonitor`** (eigene GUID/Prefix `NRGDASHMON`,
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
  - Restliches Konzept (Strompreis-Reiter nur Tagesansicht, Counter-
    Erkennung lifetime/dayReset, Vorzeichen-Konventionen `meter_total`)
    bleibt für die nächsten Ausbaustufen dokumentiert, siehe Übergabe-
    Nachricht von InverterHub (27.07.2026) - noch nicht umgesetzt.

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
