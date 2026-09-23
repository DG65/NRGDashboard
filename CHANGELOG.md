# Changelog

*Nachgetragen 15.09.2026 (Dietmars Fund: "Ich habe gerade gesehen, Du schreibst
dir da nichts auf!?") — zwischen `0.9.5-beta.3` (Build 71, 11.09.2026) und
`0.9.5-beta.3` (Build 135, 14.09.2026) wurden 64 Commits gepusht, ohne die
Versionsnummer je zu erhöhen oder ein Änderungsprotokoll zu führen. Diese
Datei fasst die Lücke rückwirkend zusammen (nach Tag gruppiert, nicht 1:1 pro
Commit — an einem Tag allein liefen z. B. 13 aufeinanderfolgende
Pixel-Korrekturen an einem einzigen Badge, die als EIN Punkt zusammengefasst
sind) und wird ab jetzt bei jedem Push gepflegt, `version` in `library.json`
inklusive.*

## 0.9.52-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: die kumulierte Jahressumme je Monatszelle stand untereinander mit dem Monatswert und kostete pro Zeile zu viel Höhe (Dietmars Korrektur direkt nach 0.9.51) - steht jetzt hintereinander in derselben Zeile, dezent kleiner dahinter.

## 0.9.51-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: jede Monatszelle zeigt ab Februar zusätzlich, dezent und kleiner darunter, die kumulierte Jahressumme bis zu diesem Monat (Dietmars Wunsch - auf einen Blick sehen, wo man im Jahr steht). Bricht bei der ersten Monatslücke im jeweiligen Jahr ab, statt darüber hinwegzutäuschen.

## 0.9.50-beta.1 (2026-09-23)

- PVMonitor: Reiter "Jahresvergleich" stand dauerhaft rot, obwohl die Tabelle Werte zeigte (Fund Dietmar). Die Reiterfarbe prüfte `lastData.energy.pv` - das ist die Tages-Energiereihe des PV-Reiters (Wochen-/Monatsansicht), nicht der Jahresvergleich; dieser Bezug war schon falsch, bevor der Reiter zuletzt robuster gemacht wurde. Prüft jetzt dieselbe Bedingung wie die Anzeige selbst (PV-Leistung aufgelöst oder Vorjahreswerte nachgetragen).

## 0.9.49-beta.1 (2026-09-21)

- Verbindungs-Statuszeilen: rein automatisch übernommene Zeilen (🔗) erscheinen grün (Label-Farbe 0x2E8B3D), gemischte und andere Zustände in Standardfarbe (Verbund-Konvention, EMS). Gilt für alle Formulare über `libs/FormStatus.php`.

## 0.9.48-beta.1 (2026-09-21)

- Quellenfelder nach der Verbund-Regel "Wert kommt automatisch: Eingabefeld ersetzen" (SUITE.md): Liefert eine Automatik einen Wert und ist das Feld leer, wird das Eingabefeld ausgeblendet und stattdessen eine Zeile "🔗 Größe: #ID Name (automatisch von Quelle)" gezeigt; eine eigene Angabe bleibt sichtbar (✏️), ohne Automatik erscheint das Feld mit ℹ️ "wird gebraucht". Der automatische Wert wird nie ins Feld geschrieben. Umgesetzt in PVMonitor (PV-/Batterie-/Netzleistung, Ladestand, PV-Prognose, Tibber), Forecast, Map, Topology, WPMonitor und Tile (PV-Prognose, Tibber). Bei mehreren Instanzen ohne Auswahl bleibt das Feld sichtbar (⚠️).

## 0.9.47-beta.1 (2026-09-21)

- Verbindungs-Statuszeilen (Verbund-Konvention) jetzt auch in PVMonitor (PV-/Batterie-/Netz-Leistung, Ladestand, EMS, PV-Prognose, Preiskurve - je Quelle mit Instanz/Variable, Herkunft und ⚠️/ℹ️-Folge, z. B. nicht im Archiv protokolliert), WPMonitor (Wärmepumpe mit übernommenen Werten, Heizkurven-/Bedienungsverfügbarkeit) und HeatSchema (erkannte Wärmepumpen).

## 0.9.46-beta.1 (2026-09-21)

- Formulare Forecast, Map, Topology und Tile (Tessie): live berechnete Verbindungs-Statuszeile nach der neuen Verbund-Konvention (SUITE.md "Verbund-Verbindungen im Formular sichtbar machen"). Sie nennt die gefundene Instanz (ID, Name, Zustand), ob ausgewählt oder automatisch erkannt, und die übernommenen Werte bzw. ⚠️/ℹ️ mit Grund und dem, was dann gilt. Bei mehreren Instanzen ohne Auswahl wird nicht geraten. Gemeinsame Hilfe `libs/FormStatus.php` sucht das Label rekursiv, auch innerhalb von Panels.

## 0.9.45-beta.1 (2026-09-21)

- PVMonitor Tagesplan: Der geplante Batterie-SOC wurde eine Viertelstunde zu früh gezeichnet - EMS liefert je Slot den SOC NACH dem Slot, das Dashboard hat ihn am Slot-Anfang eingetragen, die Linie stieg deshalb sichtbar vor dem Netzladen-Band (Fund Dietmar). Jetzt am Slot-Ende, auch im Szenarien-Reiter.
- PVMonitor Tagesplan: "Netz laden" (kräftiges Blau) und "Akku halten (Netzbezug)" (Limette) waren als Türkis/Blau kaum zu unterscheiden - neue, deutlich verschiedene Farben.

## 0.9.44-beta.1 (2026-09-20)

- PVMonitor Tagesplan: Tooltip zeigt den Restwert der Batterieenergie (EMS 0.60.0, `rw` je Slot, optional): "Akku geschont: Energie wird später bis 42 ct/kWh gebraucht, Bezug jetzt 22 ct/kWh". Nur bei Slots, die das Feld tragen.

## 0.9.43-beta.1 (2026-09-20)

- PVMonitor Tagesplan: Hinweis-Chip "Trockenlauf - Plan wird nicht ausgeführt" bzw. "Nur beobachtend" (EMS_GetCurrentDecision Vertrag 1.1: `dryRun`, `observeOnly`, `observeReason`; bei älterem EMS Rückfall auf den Statustext-Präfix), damit niemand glaubt, der Plan werde ausgeführt.
- PVMonitor Tagesplan: Ersparnis des Netzladens gegenüber dem Durchschnittspreis (EMS_GetDayPlan Vertrag 1.2, `windows[]`/`savingsEur`) - Summe je gewähltem Tag als Zeile unter dem Diagramm, je Ladefenster im Tooltip ("Ladefenster: ca. 0,63 EUR gespart ..."); Planzahl, kein Abrechnungswert, negativ als "Mehrkosten". Fehlt das Feld, erscheint nichts.

## 0.9.42-beta.1 (2026-09-20)

- WPMonitor als eigenständige Webseite (IPSView/Browser über den WebHook): Heizkurven- und Bedienungsdaten kamen nie an, weil dort das `requestAction()` des Symcon-Rahmens fehlt. Eigenes `requestAction()` fordert jetzt lesend über `?action=` nach (`heatingCurveLoad`, `operationsLoad`). Schreibende Aktionen (Heizkurve senden, Verschiebung, Bedienung) gehen bewusst nicht über den offenen WebHook - dort ist die Kachel nur Anzeige.

## 0.9.41-beta.1 (2026-09-20)

- PVMonitor als eigenständige Webseite (IPSView/Browser über den WebHook): Bilanz und Jahresvergleich blieben bei "Lade ..." stehen (Fund somm). Ursache: dort gibt es kein `requestAction()` des Symcon-Rahmens, alle nachgeforderten Daten (Bilanz, Jahresvergleich, Tagesplan, Energiebilanz je Zeitraum, weitere Tage, StromGedacht) kamen nie an. Die Seite bringt jetzt ein eigenes `requestAction()` mit, das über `?action=` am WebHook nachfordert. Bewusst nur lesend - Nachtragen/Konfiguration gehen nicht über den offenen WebHook.

## 0.9.40-beta.1 (2026-09-19)

- PVMonitor Jahresvergleich: Werte ab Sep 2025 fehlten komplett. Ursache: ein beschädigter Tagesdatensatz im Archiv (Zeitstempel mitten am Tag) ließ `AC_GetAggregatedValues` für jeden Zeitraum, der ihn enthält, FALSE liefern. Der Jahresvergleich fragt jetzt bei FALSE monatsweise, dann tageweise ab und rechnet einen einzeln scheiternden Tag aus den Rohwerten nach.

## 0.9.39-beta.1 (2026-09-19)

- PVMonitor: Jahresvergleich-Reiter ist jetzt immer sichtbar (wie der Strompreis-Reiter). Ohne aufgelöste PV-Leistungsvariable erklärt er den Grund und den Ausweg, statt zu verschwinden.

## 0.9.38-beta.1 (2026-09-19)

- PVMonitor: Jahresvergleich-Reiter blieb verschwunden, wenn die PV-Leistung nicht automatisch aufgelöst wurde (z. B. zweite InverterHub-Instanz neben der aktuellen). Die Automatik zählt jetzt nur Instanzen mit tatsächlich vorhandener PV-Leistungsvariable; genau ein Treffer genügt. Zusätzlich bleibt der Reiter sichtbar, sobald manuell nachgetragene Vorjahreswerte existieren.

## 0.9.37-beta.1 (2026-09-19)

- PVMonitor Tagesplan: Tooltip zeigt die geplante Schaltleistung je Viertelstunde (z. B. "Netz laden mit 24 kW"), aus `xsetW`/`gwModeLabel` von EMS_GetDayPlan (Vertrag 1.1, optional; fehlt es, bleibt alles wie zuvor).

## 0.9.36-beta.1 (2026-09-19)

- PVMonitor Tagesplan: kennt den neuen EMS-Plan-Modus op 8 "Akku halten (Netzbezug)" (türkis) - der frühere 0-W-Fall von op 5, bei dem nichts ins Netz geht und der lila "Einspeisen"-Balken irreführend war (Beobachtung Dietmar, EMS-Abstimmung). Zusätzlich bekommen die bisher namenlosen op 4 "Standby" und op 6 "Backup" einen Namen. Unbekannte op-Werte fallen unverändert auf grau "op N" zurück.

## 0.9.35-beta.1 (2026-09-19)

- WPMonitor: Verschiebe-Regler für die Heizkurve - senkrecht rechts neben dem Diagramm (Dietmars Vorgabe), -5 bis +5 K, gestrichelte Linie zeigt die verschobene Kurve. Nur im Verschiebe-Modus und nur für Heizen (im Direktmodus wäre derselbe Anlagenwert eine Vorlauf-Solltemperatur, dort wird nichts gesendet); Hinweis, wenn Regeln auf der HeishaMon-Platine den Wert überschreiben können (`boardRulesActive`). Rückmeldung per Nachlesen alle 3 s, nach 30 s "nicht bestätigt".
- WPMonitor: neue Seite "Bedienung" (HeishaMon-Vertrag 1.14, `HEISHA_GetOperations`/`HEISHA_SetOperation`): Flüsterbetrieb, Leistungsbetrieb, Urlaub (nur An/Aus - einen Urlaubstimer gibt es über HeishaMon nicht), Notbetrieb mit Bestätigungsdialog und rotem Punkt am Reiter, Warmwasser-Solltemperatur mit COP-Hinweis über 52 °C. Bedienelemente nur für Werte, die die Anlage tatsächlich meldet; Reiter bleibt ohne Vertrag ausgeblendet.

## 0.9.34-beta.1 (2026-09-18)

- WPMonitor: Die Reiterleiste blendet nach jeder Auswahl automatisch aus (Dietmars Wunsch); der Pfeil-Knopf holt sie zurück.

## 0.9.33-beta.1 (2026-09-18)

- WPMonitor Heizkurven-Editor, zwei Fehler beim Speichern (Fund Dietmar: "die Punkte werden beim Speichern nicht in die HeishaMon übertragen"): (1) Punktzuordnung war vertauscht - laut HeishaMon-Firmware gehört Vorlauf HOCH zu Außentemperatur TIEF (Beispiel target high 35/low 25, outside high 15/low -15 = (-15→35) und (15→25)), der Editor paarte tief/tief und sendete beim Ziehen die falschen Werte. (2) Nach dem Senden lud der Editor sofort neu und sprang auf die alten Werte zurück, weil HeishaMon Set-Befehle nicht quittiert und der neue Wert erst mit dem nächsten Datenzyklus zurückkommt - es wirkte, als wäre nichts übertragen worden. Jetzt zeigt er den gesendeten Stand, liest bis zu 6-mal im 4-Sekunden-Takt zurück und meldet "von der Wärmepumpe bestätigt" bzw. nach dem Zeitlimit "noch die alten Werte".

## 0.9.32-beta.1 (2026-09-18)

- WPMonitor/HeatSchema: Discovery kennt jetzt zusätzlich WPModbusHub, WPModbusHubGateway und SamsungEhs (heatpump-Vertrag 1.15, gleiche Form wie WPHub) - auf Bitte der WPHub-Sitzung, von Dietmar freigegeben. Beta-Quellen: nur SamsungEhs ist an echter Anlage bestätigt, WPModbusHub teilweise, das Gateway gar nicht. PowerID/EnergyID sind dort immer 0, es erscheinen nur Temperatur-/Statusfelder. Der Tile-Energiefluss bleibt unverändert (nur HeishaMon), der Heizkurven-Reiter weiter nur bei HeishaMon.

## 0.9.31-beta.1 (2026-09-18)

- WPMonitor Heizkurven-Editor: Außentemperatur-Bereich auf +45 bis -25 °C erweitert, damit auch Kühlen darstellbar ist; Vorlauftemperatur-Achse je Modus (Heizen 15-65 °C, Kühlen 0-40 °C), sonst lägen typische Kühl-Sollwerte unterhalb der Achse.

## 0.9.30-beta.1 (2026-09-18)

- WPMonitor Heizkurven-Editor: Außentemperatur-Achse horizontal gespiegelt (warm links, kalt rechts), Dietmars Wunsch.

## 0.9.29-beta.1 (2026-09-18)

- WPMonitor: Reitermenü jetzt exakt wie im PVMonitor - einklappbare Seitenleiste mit Pfeil-Knopf, Animationsstil (neue Instanzvariable "Reiterleisten-Animation"), Diagrammtitel aus dem aktiven Reiter, Reiter ohne Quelle ausgeblendet. Vorher nur eine schlichte Knopfleiste (Abweichung von Dietmars Vorgabe "exakt analog zum PV Monitor").
- WPMonitor Heizkurven-Editor: füllt jetzt die Kachel (statt kleiner Grafik in leerer Fläche), Gitter mit Achsentiteln, Flächenverlauf unter der Kurve, gestrichelte Verlängerung über die Ankerpunkte hinaus, Wertepillen an den Punkten; Heizkreis-/Heizen-Kühlen-Umschalter im Stil der PV-Monitor-Unterreiter. Ein periodischer Refresh holt die Verlauf-Ansicht nicht mehr in den Heizkurven-Reiter zurück.

## 0.9.28-beta.1 (2026-09-18)

- Tile/PVMonitor `PeriodEnergyCounter()`: Bei einer Archivlücke um Tagesbeginn suchte der Referenzpunkt-Fallback bisher ab Unix-Epoche 0 statt innerhalb des angefragten Tages - konnte dabei einen völlig veralteten Archivpunkt erwischen und einen Fantasiewert erzeugen (53,3 statt echter ~0,011 Mio. kWh bei Solarpark Albersboesch, dessen Archiv genau zwischen gestern Abend und heute 07:03 Uhr eine Lücke hatte). Sucht jetzt nur noch innerhalb des Tagesfensters, explizit chronologisch sortiert; fehlt auch das, bleibt der Tag ehrlich ohne Wert statt zu raten (Gegenprüfung MeterHub-Sitzung direkt am Rohzähler).

## 0.9.27-beta.1 (2026-09-18)

- Tile: `collapseToSingleGrid()` kollabiert nicht mehr pauschal jeden zweiten Netzknoten - nur noch verzögerte/Abrechnungs-Zweitmessungen desselben Anschlusses (`latency==='delayed'`/`authority==='billing'`). Bei Solarpark Hofweier verschwand dadurch "NAP Albersboesch" (ein zweiter, physisch eigenständiger Netzanschlusspunkt) komplett aus dem Energiefluss (Fund Dietmar, nach MeterHub-Bericht). Mehrere echte, unabhängige NAPs erscheinen jetzt gleichzeitig als eigene Knoten.

## 0.9.26-beta.1 (2026-09-18)

- Verbundweit (Tile/PVMonitor/WPMonitor/Forecast): Die Archiv-Plausibilitätsgrenze `IMPLAUSIBLE_POWER_W` war unausgesprochen selbst anlagenspezifisch (1 MW, gedacht für Heim-/Kleingewerbe-Anlagen) - bei Solarpark Hofweier (live legitim >1 MW) verwarf sie reihenweise echte Messwerte und ließ die Leistungskurve als Trapezform statt der echten, glatten Kurve erscheinen (Fund MeterHub-Sitzung). Auf 50 MW angehoben - fängt den ursprünglichen Defektwert (261.554.185 W, Modbus-TID-Bug) weiterhin klar ab, verwirft aber keine reale Anlage mehr.

## 0.9.25-beta.1 (2026-09-18)

- Tile Geräte-Detailseite: "Netzbezug/Einspeisung an diesem Tag" nutzt jetzt bevorzugt den echten kumulativen Energiezähler (energyImportID/energyExportID), statt die 5-Minuten-Leistungsreihe zu integrieren - bei Solarpark Hofweiers NAP-Instanz wich die reine Leistungsintegration um Faktor ~5,75 vom echten Zählerstand ab (787,8 statt 4.531,2 kWh, Fund MeterHub-Sitzung). Leistungsintegration bleibt Rückfall für Quellen ohne eigene Energiezähler-Felder (z. B. reine InverterHub-PV/Batterie).

## 0.9.24-beta.1 (2026-09-18)

- WPMonitor Heizkurven-Reiter: Statustext nach dem Übernehmen von "Übernommen" auf "Gesendet - Wärmepumpe bestätigt Sollwert-Änderungen nicht" korrigiert - HeishaMon quittiert MQTT-Set-Befehle nicht, `true` bedeutet nur "Nachricht raus", nicht "von der Anlage übernommen" (Klarstellung von HeishaMon).

## 0.9.23-beta.1 (2026-09-18)

- WPMonitor: neuer Reiter "Heizkurven" - grafischer Zwei-Punkt-Editor (Außentemp → Vorlauftemp) für bis zu 2 Heizkreise, getrennt nach Heizen/Kühlen, zum Ziehen im Diagramm mit "Übernehmen"-Knopf. v1 nur gegen HeishaMon (einziges der vier Wärmepumpen-Quellmodule mit geprüftem Lese-/Schreibweg, per Verbundabstimmung mit HeishaMon/WPHub festgelegt), defensiv per `function_exists()` geprüft - Reiter erscheint automatisch, sobald HeishaMon `HEISHA_GetHeatingCurve`/`HEISHA_SetHeatingCurve` liefert. Erster Schreibzugriff dieser Kachel überhaupt (neue `RequestAction()`).

## 0.9.22-beta.1 (2026-09-17)

- PVMonitor: Versteckter Szenarien-Reiter jetzt auch mit Highcharts nutzbar (Dietmars Normal-Engine), nicht mehr nur mit ECharts - Serienaufbau in `scenarioSeriesData()` engine-neutral herausgezogen, `renderScenariosHighcharts()` als Pendant ergänzt.

## 0.9.21-beta.1 (2026-09-17)

- PVMonitor: `EMS_SimulateDayPlanScenarios()`-Aufruf im versteckten Szenarien-Reiter fehlgeschlagen ("Too few arguments... exactly 2 expected") - der dokumentierte Default `$ibnDaten = []` griff live nicht, das leere Array muss explizit mitgegeben werden. Reiter blieb dadurch komplett leer.

## 0.9.20-beta.1 (2026-09-17)

- PVMonitor: Versteckter Entwickler-Reiter "Szenarien" (EMS_SimulateDayPlanScenarios, EMS 0.43.2) - vergleicht den simulierten Tagesplan unter verschiedenen hypothetischen Inbetriebnahme-Daten (unterschiedliche EEG-Rechtslage, z. B. Solarspitzengesetz) übereinandergelegt, mit Vergütung/Einspeisegrenze/§51-Pflicht je Szenario. Nur per Skript aktivierbare, NICHT im Formular sichtbare Property `DevScenarioCompare` - für Dietmars eigene Entwicklungsprüfung, kein Bestandteil des normalen Funktionsumfangs für andere Nutzer.

## 0.9.19-beta.1 (2026-09-17)

- PVMonitor: `DayBalanceCurve()` (Bilanz-Reiter) und `FlowComponents()` (Energiebilanz + Bilanz-Totale) lasen PV-/Batterieleistung bisher direkt aus `IHUB_GetFunctions()`, statt `PvPowerID()`/`BatPowerID()` aufzurufen - eine manuell gewählte Alternativ-Variable (z. B. SolarEdges "PV-Erzeugung (berechnet)" gegen die PV+Batterie-Vermischung des rohen Registers) wurde dadurch ignoriert. Zeigte sich als nächtliche Phantom-Erzeugung/-Direktverbrauch aus dem rohen PV+Batterie-Signal (Fund somm).

## 0.9.18-beta.1 (2026-09-16)

- PVMonitor: Der Strompreis-Reiter zeigte den Netzbezug (15-Minuten-Balken) bisher nur an, wenn zusätzlich eine Preiskurve (Tibber/Börsenpreis) vorlag - der Netzbezug kommt aber aus einer eigenen, preisunabhängigen Quelle (GridPowerID). Ohne Preisquelle erscheint jetzt trotzdem das Diagramm mit dem Netzbezug allein, statt des leeren Hinweistextes.

## 0.9.17-beta.1 (2026-09-16)

- PVMonitor: Der Strompreis-Reiter blieb bisher komplett unsichtbar, solange weder Tibber Grid Rewards noch NRG-Stack Börsenpreis installiert war - Nutzer wussten dadurch gar nicht, dass es das Feature gibt (Fund somm: "da fehlt mir wohl ein ganzes Modul"). Reiter ist jetzt immer sichtbar und zeigt ohne Preisquelle den bestehenden Hinweistext statt des Diagramms.

## 0.9.16-beta.1 (2026-09-16)

- Tile: `collapseToSingleGrid()` berücksichtigt jetzt die "Anzeigen"-Einstellung bei mehreren Netzknoten-Kandidaten. Vorher konnte ein vom Nutzer ausgeblendetes, aber "echtzeitfähig" bewertetes InverterHub-Netzfeld den Primärknoten-Platz vor einer sichtbaren manuellen EVU-Zähler-Variable gewinnen - der gewählte Primärknoten fiel dann selbst der Sichtbarkeitsfilterung zum Opfer, die Kachel zeigte am Ende gar keine Netz-Bubble mehr (Fund sirkentucky, zwei InverterHub-Instanzen + manueller EVU-Zähler).

## 0.9.15-beta.1 (2026-09-16)

- Tile: Der periodische Kosten-Ticker am Netz-Knoten (wechselt alle 4 s zwischen Watt und €/h) rief nach dem Textwechsel `fitTextWidth()` nicht mehr auf - die Breitenbeschränkung blieb vom letzten regulären Update stehen, der längere „€/h“-Text lief dadurch unbeschränkt breit, der kürzere „W“-Text danach sichtbar zusammengestaucht (Dietmars Fund: "verzieht die Schrift ... in die Breite und wird dann wieder schmäler").

## 0.9.14-beta.1 (2026-09-16)

- PVMonitor Strompreis-Reiter: Erklärtext für das rote Negativpreis-Band ergänzt - steht jetzt neben der Quellenangabe unter dem Diagramm ("rot hinterlegt: negativer Börsenpreis (§ 51 EEG, keine Einspeisevergütung)"), Dietmars Wunsch nach dem Label-Fix.

## 0.9.13-beta.1 (2026-09-16)

- PVMonitor Strompreis-Reiter: Börsenpreis nicht mehr auf 2 Nachkommastellen gerundet, bevor er ans Diagramm geht - eine winzig negative Viertelstunde (z. B. -0,001 ct/kWh) wurde dadurch als `-0.0` an JavaScript übergeben, wo `-0 >= 0` wahr ist, und fiel fälschlich aus der roten Negativpreis-Markierung (§ 51 EEG) raus (Fund Börsenpreis-Sitzung).
- PVMonitor Strompreis-Reiter (Highcharts): Der Erklärtext "Zeitbereich ohne Bezugsdaten!" landete an vergangenen Tagen mit negativen Börsenpreisen fälschlich auf dem roten Negativpreis-Band statt auf dem grauen Archiv-Nachlauf-Band, weil beide Bänder im selben Array standen und der Text immer auf das erste Element gezeichnet wurde (Fund Börsenpreis-Sitzung, nach Dietmars Screenshot vom 01.05.2026).

## 0.9.12-beta.1 (2026-09-15)

- NRGDashboardForecast: Hinweis im "Wozu dieses Modul?"-Panel ergänzt, dass die Kachel auf dem Prognose-Modul als Datenquelle basiert und dieses nicht deinstalliert werden sollte, solange die Kachel genutzt wird (spiegelbildlich zu Prognoses eigenem Hinweis auf NRGDashboard, abgestimmt nach einer Nutzerrückfrage im Forum, ob eines der beiden Module gelöscht werden könne).

## 0.9.11-beta.1 (2026-09-15)

- Feedback-Panel jetzt 1:1 wie bei MeterHub aufgebaut: eigener "Zum Forums-Thread"-Knopf (Button mit `echo`+`link`) statt eines reinen Link-Text-Labels.

## 0.9.10-beta.1 (2026-09-15)

- Forum-Thread ist live: der bisherige GitHub-Verweis im Feedback-Panel aller 7 Module zeigt jetzt auf den echten [Symcon-Forum-Thread](https://community.symcon.de/t/modul-nrg-stack-dashboard-energiefluss-kachel-3d-karte-verlaufs-charts-fuer-den-ganzen-verbund/144394).

## 0.9.9-beta.1 (2026-09-14)

- **Store-Checkliste Punkt 13:** `ResetTour()` gibt in allen 7 Modulen jetzt eine sichtbare Rückmeldung.
- Fix: Haus-Bilanz summierte bisher nur den ersten gefundenen PV-/Batterie-Knoten statt aller — bei unverschachtelten Mehrfach-Wechselrichtern (Solarpark) zeigte "Haus" dadurch teils mehrere Megawatt Fehlanzeige.
- **Verbund-Muster "geteiltes Ausblenden über Geschwister-Instanzen":** "Was ist Neu"/den Store-Review-Hinweis muss man bei mehreren Instanzen desselben Moduls nur noch einmal wegklicken, danach strukturell gegen Ping-Pong abgesichert (getrennte Apply-/Propagate-Methoden statt Laufzeit-Flag, `try/catch(\Throwable)` statt `@`).
- Knoten im Energiefluss/Topologie/Map werden innerhalb ihrer Gruppe jetzt natürlich nach Label sortiert.
- Bei Anlagen ohne echtes Hausverbrauchsgerät (z. B. reine Erzeugungsparks) steht "Netz" jetzt oben mittig statt an letzter Stelle.
- Die Mittelpille heißt "Saldo" (mit Delta-Symbol) statt "Haus", wenn kein echtes Hausgerät vorhanden ist; Wertetext bekommt dieselbe Breitenanpassung wie Name/Untertitel.
- **Store-Checkliste Punkt 12:** eine Namensnennung im News-Text wurde entpersonalisiert.
- Börsenpreis-Quellenangabe um die neuen Quellen `entsoe` (EPEX Spot/ENTSO-E) und `tibber` (Tibber-Preisübersicht) ergänzt.
- **Store-Checkliste Punkt 0:** neues "👋 Wozu dieses Modul?"-Panel ganz oben im Formular, einmalig dismissible, in allen 7 Modulen.
- EMS-Begründungs-Badge (Automatik-Statuszeile unten in der Energiefluss-Kachel) überarbeitet: Position/Breite mehrfach nachjustiert, Höhe und Optik an die beiden Nachbar-Badges (Status/Diagnose) angeglichen.
- **Store-Checkliste Punkte 4+5:** "🧡 Über dieses Modul" (Lizenz/Spenden) ergänzt, Forum/GitHub-Hinweis auf ein eigenes, dismissibles Panel umgestellt.
- Einführungs-Touren (PVMonitor Strompreis-Reiter, WPMonitor, HeatSchema) um fachlich von Börsenpreis/WPHub/HeishaMon geprüfte Erklärungen ergänzt (Day-Ahead-Mechanik, § 51 EEG, COP-Grenzen, Verdichterfrequenz, ΔT Vor-/Rücklauf).

## 0.9.8-beta.1 (2026-09-13)

- Veraltete Messungen (`lastSeenAt`) zeigen jetzt "Keine aktuelle Messung" statt einer eingefrorenen 0-W-Anzeige (Tile, WPMonitor, HeatSchema; Frische-Schwelle `max(900 s, 3× pollInterval)`).
- Einspeisevergütung wird automatisch aus `EMS_GetPlantInfo` übernommen.
- **Store-Regel 9b:** deutsche Datumsformate (TT.MM.JJJJ) und echte Umlaute statt ue/ae/oe/ss.
- **Store-Checkliste Punkt 9c:** alle `ReadProperty`/`ReadAttribute`-Lesezugriffe typsicher gecastet.
- Börsenpreis (`SPOT_GetPriceCurve`/`SPOT_GetPriceHistory`) im PVMonitor-Strompreis-Reiter integriert, negative Viertelstunden markiert.
- Ladesitzungen: Kosten je Sitzung in der Wallbox-Detailseite, mit echtem Bezugspreis (`EMS_GetPurchasePriceHistory`) und EMS-Einstandspreis für den Batterieanteil bewertet; bei Sammelknoten aus der Summe der Mitglieder; liest Netz/PV/Batterie aus der archivierten statt der live aktiven Quelle.
- Einträge mit `duplicateOf` (ChargerHub/OCPPHub-Vertrag 1.4) werden beim Discover verworfen.
- **Store-Regel 9g:** mehrtägige Archivabfragen laufen jetzt tageweise (Ladesitzungen) bzw. ab 2 Tagen über die Stundenstufe (PVMonitor-Energiebilanz) statt an der 50.000-Werte-Grenze zu reißen.

## 0.9.7-beta.1 (2026-09-12)

- Partnermodul-Erkennung (Tibber u. a.) findet bei mehreren installierten Instanzen automatisch die eine aktive, statt bei mehreren Treffern aufzugeben.
- Netzknoten zeigt Bezug als Kosten und Einspeisung als Erlös statt nur der reinen Leistung.
- MeterHub-Netzleistung wird auf die Kachel-Vorzeichenkonvention umgerechnet (+ = Einspeisung).
- Fluss-Tempo-Skalierung bis 100 MW, Leuchtschein skaliert mit.
- Fix: ein leerer Discover()-Fund direkt nach einem Modul-Reload überschrieb den Geräte-Cache nicht mehr fälschlich.
- MeterHub-Diagnose (`MHUB_GetDiagnostics` 1.0) im Gesundheits-Panel der Kachel.

## 0.9.6-beta.1 (2026-09-11)

- **Aufschachteln (Ebene 1):** Mitglieder eines Sammelknotens werden ausgeblendet statt doppelt gezählt; genau ein Netzknoten mit stabilem Ausblende-Schlüssel; Kernknoten unter einem gleichfunktionalen Sammelzähler werden ausgeblendet; Gruppen werden vor der Netzknoten-Zusammenführung aufgelöst; ein Sammelzähler wird nie als doppelte Messung gefaltet.
- Mitgliederzahl-Badge zeigt die echte Zahl auch ab Ebene 2 statt nur "›".
- Symbol des Sammelknotens erscheint in der Mittelpille, mit Namens-Rückfall.
- Fix: eine breite Mittelpille schrumpft im inaktiven Zustand nicht mehr gleichmäßig verzerrt.
- Aktivitätsschwelle auf 5 W statt 20 W gesenkt.
- Fix: rein dekorative Elemente fingen Klicks ab — Klickbereich ist jetzt exakt der sichtbare Kreis.
- Zuschachteln-Ring läuft jetzt gegen den Uhrzeigersinn.
- Kein Textauswahl-Kontextmenü mehr beim langen Druck auf Tablets.
