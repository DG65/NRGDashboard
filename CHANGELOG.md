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

## 0.9.121-beta.1 (2026-09-25)

- Tile: Quotenmünze (Autarkie-/Selbstverbrauchsquote) skalierte fix bei 190px in CSS, unabhängig von der tatsächlichen Kachelgröße - Forum-Fund sirkentucky (iPhone/iPad SymconApp): dort ist das Container-Seitenverhältnis anders als im Browser-WebFront, dadurch wirkte die Münze im Verhältnis zum Energiefluss viel zu groß. Münze bekommt ihre Pixelgröße jetzt in `updateViewBox()` dynamisch aus derselben Referenz wie die Flow-Knoten (2×DESIGN_R relativ zur tatsächlichen SVG-Höhe) - skaliert jetzt bei jeder Kachelgröße/jedem Gerät analog zum Energiefluss.

## 0.9.120-beta.1 (2026-09-25)

- HeatSchema: neue Speicherart "Kombispeicher" (1 Tank, 3 Abgänge statt Puffer + getrennter WW-Speicher) - Forum-Wunsch Christian "kollaps", gemeldet über die WPHub-Sitzung. Einstellung "Speicherart" (WebFront, Doppelpfeil), vier neue manuelle Sensor-Zuordnungen (WW oben/unten, Wärmespeicher, Rücklauf) im Formular. Im Schema: dritter Abgang oben (Warmwasser) am bestehenden Puffer-Tank, darunter fünf Info-Chips (WW oben, WW unten, ΔT Schichtung, Rücklauf, Wärmespeicher). Additiv auf der bestehenden, fein austarierten Puffer-Tank-Geometrie aufgebaut, keine Parallelstruktur - der klassische Puffer/WW-getrennt-Pfad bleibt unverändert.

## 0.9.119-beta.1 (2026-09-24)

- WPMonitor/HeatSchema: neue Wärmepumpen-Quelle WPBsbLan (BSB-LAN-Adapter, Siemens RVS/LMU-Regler, erste Anlage Fujitsu Waterstage) in HEATPUMP_SOURCES aufgenommen - Meldung der WPHub-Sitzung, bereits im Store veröffentlicht und an echter Hardware bestätigt. Gleicher heatpump-Vertrag (contractVersion 1.15) wie die übrigen Quellen.

## 0.9.118-beta.1 (2026-09-24)

- WPMonitor "Verlauf": Ø24h-Linie war glatt, "Außentemp." und "Ø1h" zeigten weiterhin sichtbare Knicke an den stündlichen Übergängen (Dietmar). Doppelter Glättungs-Durchgang (nähert sich einem Dreiecks-/Gauss-Kern an) statt einem einzelnen - rundet deutlich stärker, ohne das Mittelungsfenster selbst zu vergrößern.

## 0.9.117-beta.1 (2026-09-23)

- WPMonitor "Verlauf": zwei zusätzliche Mittelwert-Linien für die Außentemperatur, Ø1h und Ø24h (Dietmar). Für eine echte 24h-Mittelung reichen die Punkte des angezeigten Tages allein nicht - der Vortag steckt bereits im selben Payload (days[idx+1]) und wird für die Berechnung vorne angehängt, danach auf den sichtbaren Tag zurückgeschnitten. Kein Backend-Umbau nötig.

## 0.9.116-beta.1 (2026-09-23)

- WPMonitor "Verlauf": spline allein rundete nur die Ecken zwischen Punkten, änderte aber nichts an den groben Treppenstufen selbst (Außentemperatur aktualisiert offenbar nur stündlich). Zusätzlich gleitender Mittelwert (12 Punkte ≈ 1 Stunde bei 5-Minuten-Raster) vor dem Zeichnen - aus Sprüngen wird eine echte Rampe.

## 0.9.115-beta.1 (2026-09-23)

- WPMonitor "Verlauf": Außentemperatur-Linie wirkte eckig/treppig (Dietmar: "als Bezier oder schön geschwungene Linie wesentlich hübscher"). Als geglättete Kurve gerendert (ECharts smooth:true / Highcharts spline), nur bei der Außentemperatur - El./Therm. Leistung und Vorlauf/Rücklauf bleiben scharfkantig, da dort die schnellen Wechsel (Start/Stopp der Wärmepumpe) die eigentliche Information sind.

## 0.9.114-beta.1 (2026-09-23)

- PVMonitor "PV & Einstrahlung": Hauptursache der Erwartungs-Abweichung gefunden (Dietmar: Einstrahlungssensor ist eine horizontal montierte Ecowitt-Wetterstation) - eine horizontale Messung entspricht NICHT der Einstrahlung auf einer geneigten Modulfläche, das bisherige Modell nahm das aber implizit an. Neue Transposition (GHI→POA, Erbs-Dekomposition + isotropes Himmelsmodell, Standard-PV-Ertragsmodellierung) rechnet die horizontale Messung anhand Sonnenstand und Modul-Neigung/-Ausrichtung auf die tatsächliche Modulebene um. Neue Instanz-Einstellung "Einstrahlungssensor horizontal montiert" (Standard: an) - abschaltbar für POA-montierte Sensoren. Live geprüft: an Dietmars Anlage (27°/-14°) steigt die Erwartung bei einem Testpunkt von 2,80 kW auf 3,29 kW (Ist 4,52 kW) - deutliche, physikalisch begründete Annäherung, kein Vollausgleich (verbleibender Rest vermutlich PR-Kalibrierung, nicht Ziel dieser Änderung).

## 0.9.113-beta.1 (2026-09-23)

- PVMonitor "PV & Einstrahlung": "PV erwartet" nahm bisher senkrechten Lichteinfall an, dadurch wuchs die Abweichung zur echten Erzeugung im Winter (Dietmar). Neue Einfallswinkel-Korrektur (IAM, ASHRAE-Näherung) anhand Sonnenstand (eigene NOAA-Solar-Position-Berechnung) und Modul-Neigung/-Ausrichtung (aus PVF_GetGenerators()) - physikalisch begründet, keine Anpassung an den Ist-Wert, funktioniert automatisch bei jeder Anlage. Live gegen Dietmars echte Anlagengeometrie (27° Neigung, -14° Azimut) und Koordinaten verifiziert; Azimut-Konvention (Open-Meteo Süd-basiert vs. Astronomie Nord-basiert) dabei als Stolperfalle gefunden und umgerechnet. Ohne Standort oder Modul-Geometrie unverändertes bisheriges Verhalten (Faktor 1,0).

## 0.9.112-beta.1 (2026-09-23)

- Tile Quoten-Panel: nochmals verbreitert (640px → 720px), etwas mehr Luft an den Rändern.

## 0.9.111-beta.1 (2026-09-23)

- Tile Quoten-Panel: "Batterie"-Spalte lief bei 520px rechts aus dem Panel raus, lange "Gesamt"-Werte (z.B. "Einspeisung: 18892,2 kWh") brauchten mehr Platz als der Spaltenkopf selbst (Dietmar). Panel auf 640px verbreitert, lokal mit den tatsächlichen Werten aus Dietmars Screenshot verifiziert.

## 0.9.110-beta.1 (2026-09-23)

- Tile Quoten-Panel: Netzbezug/-einspeisung und Batterie-Laden/Entladen als zwei weitere Spalten je Zeitraum ergänzt (Dietmar: "PV-Tagesproduktion, den Netzbezug und die Lieferung, das gleiche für die Batterie"). Backend liefert dafür neu batteryChargeKWh/batteryDischargeKWh (dieselbe Vorzeichen-Konvention wie beim Netz, "+ = Entladen"). Panel auf 520px verbreitert, Spaltenabstände ergänzt (liefen bei fünf Spalten ohne Padding ineinander).

## 0.9.109-beta.1 (2026-09-23)

- PVMonitor: Tagesplan zeigte die ins Netz eingespeiste Energie fälschlich als Last (Dietmar). Ursache: die Ist-Kurve nutzte den manuell verknüpften, abrechnungsgenauen aber "latency":"delayed" Inexogy-Zähler als Netzquelle - dieser stand an dem Tag durchgehend bei ~6,8 W fest, ohne funktionierenden Netzwert schrieb die Last-Formel den kompletten PV-Überschuss fälschlich der Last zu, sobald die Batterie voll war. Neue Funktion `LiveGridPowerID()`: bevorzugt für die heutige Ist-Kurve den Echtzeit-MeterHub-Zähler (authority "auxiliary"/"realtime", bei Dietmar der PAC2200), fällt erst zurück auf die konfigurierte Quelle (Inexogy), wenn kein Echtzeit-Zähler zugeordnet ist. Andere Stellen (Bilanz-Charts etc.) bleiben bewusst bei der abrechnungsgenauen Quelle. Live gegen echte Archivdaten verifiziert (0-2,5 kW plausible Lastwerte statt 5-7 kW PV-Spiegelung).

## 0.9.108-beta.1 (2026-09-23)

- Tile: Quoten-Panel (öffnet sich beim Klick auf die Münze) überdeckte beim Öffnen die noch sichtbare Münze selbst - top-Position lag mitten in deren Bereich. Panel jetzt unterhalb der Münze positioniert.

## 0.9.107-beta.1 (2026-09-23)

- Tile Quoten-Münze: gefunden, warum die Ring-Fase nur in Chrome intakt war, in Firefox aber kaputt (Dietmar). Ursache: `gradientUnits="userSpaceOnUse"` kombiniert mit dem `transform="rotate(-90)"` der Ring-Kreise wird von Chrome und Firefox unterschiedlich interpretiert (die Spec ist an dieser Stelle uneindeutig). Ersetzt durch den Standard-Mechanismus (objectBoundingBox, keine eigenen Koordinaten) - dieselbe seit Monaten browserübergreifend zuverlässige Technik wie beim bestehenden bevelGrad, rechnerisch identisches Ergebnis.

## 0.9.106-beta.1 (2026-09-23)

- Tile Quoten-Münze: Rückbau auf den Build-230-Stand (Dietmar: "die Ringe waren zuletzt bei Build 230 intakt") - sowohl die Kontrastanhebung (0.9.104) als auch der Blend-Mode-Versuch (0.9.105) wurden wieder verworfen. Funktional identisch zu Build 230 verifiziert (Diff gegen den damaligen Commit).

## 0.9.105-beta.1 (2026-09-23)

- Tile Quoten-Münze: der letzte Kontrast-Anlauf (dunkle Randstops auf 0.75/0.78) hat als reine Alpha-Überlagerung fast die gesamte Ringfarbe verdeckt - übrig blieb nur ein schmaler heller Streifen, der Rest verschmolz mit dem dunklen Münzenhintergrund und wirkte wie ein zerbrochener/fehlender Ring (Dietmar: "komplett kaputt"). Umgestellt auf mix-blend-mode:overlay statt reiner Deckkraft - Hell/Dunkel wird gegen die Ringfarbe darunter verrechnet statt sie zu verdecken, die Farbe bleibt in jedem Fall sichtbar.

## 0.9.104-beta.1 (2026-09-23)

- Tile Quoten-Münze: 3D-Wölbung der Ringe war nach der letzten Abschwächung fast verschwunden. Kontrast über dunklere Randstops statt einer breiten hellen Fläche wieder angehoben - Lichtkante bleibt schmal und mäßig hell.

## 0.9.103-beta.1 (2026-09-23)

- Tile Quoten-Münze: Lichtkante der Ring-Fase schmaler und weniger deckend (0.6 → 0.3 Deckkraft). Münzrand probeweise komplett entfernt (Dietmar: "lass uns mal probieren").

## 0.9.102-beta.1 (2026-09-23)

- Tile Quoten-Münze: der lineare Fasen-Verlauf hellte immer nur EINE Himmelsrichtung entlang des Rings auf (zuletzt die 12-Uhr-Spitze) - keine echte Wölbung, sondern eine gerichtete Beleuchtung, die an der hellsten Stelle "ausgewaschen" wirkte (Dietmar: "physikalisch oben rund"). Ersetzt durch einen radialen Verlauf vom Ringmittelpunkt aus - dadurch an JEDEM Winkel gleich gewölbt (Rand dunkler, Bandmitte heller, Rand wieder dunkler), ein echter Tubus-Querschnitt statt gerichteten Lichts. Vor dem Ausrollen lokal mit DOM-Check und Sichtprüfung (3,5-fach vergrößert) verifiziert.

## 0.9.101-beta.1 (2026-09-23)

- Tile Quoten-Münze: die Fase wirkte an der Ringspitze (12-Uhr, wo der Datenbogen beginnt) weiterhin flach, weil dort genau der Mittelpunkt des diagonalen bevelGrad-Verlaufs lag. Eigener Verlauf für die Ringe entlang der lokalen X-Achse (wird durch die Ring-Rotation zur Bildschirm-Aufwärts-Richtung) - jetzt an der Ringspitze am hellsten statt am Mittelpunkt. Vor dem Ausrollen diesmal lokal mit echtem DOM-Parent-Check UND Sichtprüfung verifiziert.

## 0.9.100-beta.1 (2026-09-23)

- Tile: ZWEITER Wurzelfehler gefunden und behoben - der Rollback-Kommentar aus 0.9.99 (der den ersten Wurzelfehler erklärte) enthielt dabei selbst wörtlich das schließende Kommentar-Zeichenmuster als Beispieltext, wodurch der Kommentar sofort dort endete und der restliche Fließtext (inklusive der darin wörtlich genannten defs-Tags) erneut als echtes Markup geparst wurde - drei defs-Blöcke statt zwei, der komplette Energiefluss landete wieder unsichtbar im letzten davon. Diesmal per echtem DOM-Parent-Check (nicht nur Text-Matching) lokal verifiziert: Energiefluss (Haus, alle Knoten, Animationen) rendert wieder vollständig, zusammen mit der Quoten-Münze.

## 0.9.99-beta.1 (2026-09-23)

- Tile: WURZELURSACHE aller "graue Kachel"-Meldungen seit 0.9.97 gefunden und behoben - ein Rollback-Kommentar endete versehentlich auf "*/" statt "-->" und blieb dadurch offen, bis er zufällig mit dem "-->" eines ganz anderen, weiter unten stehenden Kommentars verschmolz. Alles dazwischen (`</defs>`, der komplette Energiefluss-Aufbau) landete dadurch in einem nie geschlossenen `<defs>`-Block, dessen Inhalt SVG grundsätzlich nie rendert - daher die leere/graue Kachel in JEDEM Browser, nicht nur Firefox. Lokal mit dem echten Live-Payload nachgestellt und verifiziert (DOM vollständig, keine JS-Fehler mehr) bevor erneut ausgerollt wurde.

## 0.9.98-beta.1 (2026-09-23)

- Tile Quoten-Münze: dritter Anlauf für runde statt flache Datenringe - diesmal per Wiederverwendung der bereits bewährten Fasen-Technik der Münze selbst (`bevelGrad`, objectBoundingBox-Verlauf, KEIN Filter, KEINE eigenen `userSpaceOnUse`-Koordinaten), statt der beiden vorherigen, in Dietmars Firefox/Mac fehlgeschlagenen Ansätze (Verlauf-Stroke mit eigenen Koordinaten, eigener feSpecularLighting-Filter).

## 0.9.97-beta.1 (2026-09-23)

- Tile: Rollback von 0.9.96 - auch der zweite Anlauf für "runde" Quoten-Ringe (diesmal per eigenem SVG-Filter-Attribut statt CSS-Verlauf-Stroke) hat live erneut die ganze Kachel grau gerendert (Dietmar: "das gleiche wieder"). Zurück zum unveränderten `relief`-Filter. Nächster Anlauf erst nach lokaler Verifikation, kein weiterer Live-Schuss ohne Test.

## 0.9.96-beta.1 (2026-09-23)

- Tile Quoten-Münze: zweiter, sichererer Anlauf für runde statt flache Ringe - diesmal per SVG-Filter-Attribut (neuer Filter `ringRound`, stärker abgestimmter feSpecularLighting-Bump über die gesamte Ringbreite statt nur die Kanten), nicht mehr per CSS-Verlauf-Stroke (das hatte die ganze Kachel grau gerendert, siehe 0.9.95-Rollback).

## 0.9.95-beta.1 (2026-09-23)

- Tile: Rollback von 0.9.94 - der Versuch, die Quoten-Ringe per Farbverlauf (`stroke: url(#ringGrad...)` in CSS) "rund" wirken zu lassen, hat live die GESAMTE Kachel grau/leer gerendert (Dietmar: "jetzt kommt nichts mehr"). Zurück zu den Volltonfarben aus 0.9.90-0.9.93. Ursache (vermutlich Fragment-url()-Auflösung im HTMLBox-Dokumentkontext) noch nicht abschließend verifiziert - ein erneuter Anlauf für "runde" Ringe braucht eine andere Technik, keine CSS-`url()`-Stroke-Referenz.

## 0.9.94-beta.1 (2026-09-23)

- Tile Quoten-Münze: die beiden Datenringe wirkten flach statt rund (Dietmar). Stroke-Farbe durch einen vertikalen Verlauf (hell oben, dunkel unten, wie ein von oben beleuchteter Tubus) ersetzt statt einer flachen Volltonfarbe.

## 0.9.93-beta.1 (2026-09-23)

- Tile Quoten-Münze: Schlaglicht weiter reduziert (0.06 → 0.03).

## 0.9.92-beta.1 (2026-09-23)

- Tile Quoten-Münze: Schlaglicht weiter reduziert (0.125 → 0.06).

## 0.9.91-beta.1 (2026-09-23)

- Tile Quoten-Münze: Schlaglicht nochmals um 50% reduziert (0.25 → 0.125), weiterhin nur bei der Münze.

## 0.9.90-beta.1 (2026-09-23)

- Tile Quoten-Münze: Leerlauf-Kreisbahn (heller/dunkler Hintergrundring hinter den beiden Datenringen) komplett entfernt (Dietmar: "warum bildest Du die Kreisbahnen überhaupt ab? Lass sie einfach weg"). Schlaglicht (Glanzlicht) nur bei der Münze um 50% reduziert, restlicher Energiefluss unverändert.

## 0.9.89-beta.1 (2026-09-23)

- Tile Quoten-Münze: Ringfarben gefielen weiterhin nicht - jetzt exakt das Grün, in dem der Netz-Knoten bei Einspeisung leuchtet (Autarkie), dazu ein dazu passendes Blau derselben Farbfamilie (Eigenverbrauch), statt einer eigenen, nur ähnlichen Farbwahl.
- Neu: Knoten im Energiefluss lassen sich per Ziehen neu anordnen (Dietmar: "kommt öfter vor, dass die automatische Anordnung nicht passt"). Neuer Knopf unten links ("⠿") schaltet den Anordnen-Modus um; die radiale/Pillen-Geometrie selbst bleibt unverändert, gespeichert wird nur die Reihenfolge der Geräte darin - neue Geräte hängen sich automatisch ans Ende, entfernte fallen raus. Vorerst nur auf der obersten Ebene, nicht innerhalb aufgeschachtelter Sammelknoten.

## 0.9.88-beta.1 (2026-09-23)

- Tile Quoten-Münze: "heute" hing immer noch im inneren Datenring, die leere Kreisbahn (Hintergrund der Ringe) war weder farbig noch dunkel wie der Münzengrund und wirkte dadurch wie eine dritte, unbeteiligte Farbe, die beiden Datenringe waren noch nicht kräftig genug (Dietmar). "heute" jetzt klar unterhalb des inneren Rings, Leerlauf-Kreisbahn dunkel ins Münzenmaterial eingelassen statt hellgrau, Ringfarben (Grün/Blau) deutlich kräftiger und mit voller statt gedämpfter Deckkraft.

## 0.9.87-beta.1 (2026-09-23)

- Tile Quoten-Münze: beide Prozentzahlen wirkten praktisch gleich weiß und ließen sich dadurch nicht erkennbar ihrem jeweiligen Ring zuordnen ("passt noch nicht wirklich zusammen") - jede Zahl trägt jetzt einen lesbaren Farbton ihres eigenen Rings (Grün/Blau) statt neutralem Weiß. "heute" etwas größer, fetter und kontrastreicher.

## 0.9.86-beta.1 (2026-09-23)

- Tile Quoten-Münze: die "heute"-Beschriftung saß fast genau auf dem Rand des inneren Datenrings und wirkte dadurch eingeklemmt/abgeschnitten (Dietmar). Textblock enger und etwas höher platziert, damit klar Luft zum Ring bleibt.

## 0.9.85-beta.1 (2026-09-23)

- Tile Quoten-Münze: der Münzrand (dünne äußere Kontur) war so breit wie bei den normalen Knoten und konkurrierte optisch mit den beiden echten Datenringen darin ("wirkt wie ein Ring mit Gehalt") - deutlich dünner, reine Kontur statt einer dritten Wertung.

## 0.9.84-beta.1 (2026-09-23)

- Tile Quoten-Muenze: "heute"-Beschriftung wirkte am unteren, dunkleren Rand des Verlaufs wie abgeschnitten (zu geringer Kontrast, nicht wirklich geclippt) - festere, hellere Textfarbe statt der theme-abhängigen Variable, minimal nach oben gerückt. Ringfarben nochmal angepasst ("klarer, deutlicher aber gedämpfter") - hellere, klar unterscheidbare Grundtöne (Waldgrün/Stahlblau) bei etwas höherer statt niedrigerer Deckkraft, kein Neon.

## 0.9.83-beta.1 (2026-09-23)

- Tile Quoten-Muenze: auf 190px vergrößert, um sich an die Größe der Fluss-Knoten anzunähern (Dietmar: "so groß wie die Knoten"; als fixes Pixel-Element kann sie nicht automatisch mit der SVG-Skalierung der Kachelgröße mitwachsen). Ringe zusätzlich mit reduzierter Deckkraft, damit sie trotz der größeren Fläche zurückhaltender wirken als die echten, voll deckenden Knoten-Ringe im Fluss.

## 0.9.82-beta.1 (2026-09-23)

- Tile Quoten-Muenze: von 104 auf 128px vergrößert; Ringfarben von leuchtenden Neontönen auf tiefere, gesättigte Farben umgestellt (Dietmar: "durch das Helle wird der Blick automatisch von der Hauptsache - Energiefluss - zum Quoten-Button gelenkt") - kräftig, aber ohne vom eigentlichen Energiefluss abzulenken.

## 0.9.81-beta.1 (2026-09-23)

- Tile Quoten-Muenze: Ursache für "immer noch viel zu weit rechts" gefunden und behoben - sie steckte im Haupt-Flusscanvas mit seiner quadratischen viewBox, die bei einer breiten Kachel per Letterboxing mittig eingepasst wird und den echten linken Rand dadurch nie erreichen kann. Jetzt ein eigenständiges, pixelpositioniertes SVG-Element (wie ursprünglich), das dieselben Verlauf-Definitionen der Knoten weiterverwendet - 3D-Optik bleibt identisch, Position sitzt jetzt wieder am echten Kachelrand. Ringfarben kräftiger (leuchtendes Grün/Blau statt der blasseren Grundtöne).

## 0.9.80-beta.1 (2026-09-23)

- Tile Quoten-Muenze: naeher an den linken Rand geschoben (war "viel zu weit nach Rechts gerutscht"), die beiden Fortschrittsringe wirken jetzt erhaben (derselbe Relief-Filter wie Icons/Zahlen an den anderen Knoten, etwas dickerer Strich statt einer flachen Linie).

## 0.9.79-beta.1 (2026-09-23)

- Tile Quoten-Knopf: aus dem eigenständigen HTML-Kreis (grauer Kasten mit Rand) eine echte SVG-Münze im 3D-Plastik-Stil der übrigen Knoten geworden (Dietmar: "sieht neben den anderen Pillen wie ein Fremdkörper aus ... im Münzstil analog der anderen Pillen und Knoten") - nutzt dieselben Bausteine (Verlauf, Fase, Glanzlicht) wie jeder Geräte-Knoten und lebt jetzt im selben SVG-Koordinatensystem statt als eigenes HTML-Element obendrüber.

## 0.9.78-beta.1 (2026-09-23)

- Tile Quoten-Knopf: zwei weitere Plausibilitätslücken live gefunden und behoben. (1) Die direkte Hauslast-Integration lieferte bei Monat/Jahr/Gesamt teils einen kleineren Wert als der reine Netzbezug - physikalisch unmöglich (Hausverbrauch deckt immer mindestens den Netzbezug). Wirkt sie unplausibel klein, gilt jetzt die Energiebilanz (PV + Bezug − Einspeisung) als Ersatz. (2) Bei "Gesamt" konnte die Einspeisung rechnerisch größer als die PV-Erzeugung erscheinen (unterschiedlich lange Archivhistorien von PV- und Netzzähler über viele Jahre) - die betroffene Quote bleibt in so einem Fall jetzt leer statt eine falsche Prozentzahl zu zeigen.

## 0.9.77-beta.1 (2026-09-23)

- Tile: Fataler Fehler im Quoten-Knopf bei Monat/Jahr/Gesamt behoben - `self::AGG_DAY` war in Tile nie definiert (anders als in PVMonitor), der eben erst gebaute Rückfall in Build 203 stürzte dadurch sofort ab, sobald er griff. Live bei Dietmar gefunden und direkt nachgezogen.

## 0.9.76-beta.1 (2026-09-23)

- Tile Quoten-Knopf: Monat/Jahr/Gesamt zeigten teils 0 kWh bzw. implausibel kleine Werte - ein beschädigter Archiv-Zeitstempel (gleiche Ursache wie beim PVMonitor-Jahresvergleich, live bei Dietmar gefunden) ließ `AC_GetAggregatedValues()` für den gesamten betroffenen Monatsblock FALSE liefern, PowerToEnergy() wertete das bisher stillschweigend als 0 statt nachzufragen. Fällt jetzt bei FALSE tageweise, bei Bedarf sogar auf die 5-Minuten-Rohwerte zurück, wie schon bei PVMonitors Jahresvergleich.

## 0.9.75-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: Mouse-Over auf jedem Jahreswert zeigt jetzt den genauen Platz (Dietmars Idee) - Platz 1-3 mit Medaille (🥇🥈🥉), ab Platz 4 mit Ziffern-Emoji, aber nur, wenn die Spalte mindestens 30 vergleichbare Jahre hat; darunter bleibt es bei reinem Text ("Platz 4 von 14"). Gilt für Monatswerte, kumulierte Werte und die Summe.

## 0.9.74-beta.1 (2026-09-23)

- Map, Topology, PVMonitor, WPMonitor, HeatSchema, Forecast: dasselbe versionsweise "Was ist Neu"-Panel wie zuvor in Tile (0.9.73) - zeigt nur die Versionen zwischen der zuletzt gesehenen und der aktuell installierten, statt pauschal die ganze Historie oder gar nichts. Die bisherige, gewachsene Liste jedes Moduls bleibt als ein Block unter ihrer letzten Versionsnummer erhalten, neue Einträge ab jetzt bekommen ihre eigene Versionsnummer.

## 0.9.73-beta.1 (2026-09-23)

- Tile: das "Was ist Neu"-Panel zeigt jetzt nur die Versionen an, die zwischen der zuletzt gesehenen und der aktuell installierten Version liegen, statt entweder pauschal die ganze Historie oder gar nichts (Dietmar: "ist so etwas machbar?"). NEWS_ITEMS ist dafür versionsweise strukturiert (`NEWS_VERSIONS`), das Panel erscheint nach jedem Update mit mindestens einem neuen Eintrag automatisch wieder.
- Tile: Einführungs-Tour erwähnt jetzt den neuen Quoten-Knopf (statt des entfernten Autarkiegrad-Bogens) und den ct/kWh-Kosten-Ticker.

## 0.9.72-beta.1 (2026-09-23)

- Tile: Quoten-Panel und Diagnose-Panel ("Einblendung des Zustands") schließen sich jetzt von selbst nach 30 s, statt offen stehen zu bleiben, bis man daneben klickt (Dietmar). Jede erneute Öffnung setzt die 30 s neu an.

## 0.9.71-beta.1 (2026-09-23)

- Tile Quoten-Knopf: Prozentzahlen im Ring verkleinert (15px -> 12px), damit genug Abstand zum inneren Ring bleibt (Dietmar).

## 0.9.70-beta.1 (2026-09-23)

- Tile Quoten-Knopf: Ring von 96 auf 132px vergrößert, damit die beiden Prozentzahlen mehr Platz im Inneren haben (Dietmar); Tabelle darunter entsprechend tiefer gesetzt.

## 0.9.69-beta.1 (2026-09-23)

- Tile Quoten-Knopf: der Ring rutscht 36px weiter nach unten, damit er nicht mehr mit dem von Symcon gezeichneten Kachel-Titel kollidiert (Dietmar: "muss weiter nach unten rutschen wegen der Überdeckung"). Aus den vier Umschalt-Knöpfen (Tag/Monat/Jahr/Gesamt einzeln anklicken) ist eine Tabelle mit allen acht Werten auf einen Blick geworden - Mini-Ringe je Zelle statt nur Text, alle drei fehlenden Zeiträume werden beim Öffnen in einem Rutsch nachgefordert statt Klick für Klick.

## 0.9.68-beta.1 (2026-09-23)

- Tile: den bisherigen Autarkiegrad-Ring am Haus-Knoten (nur "heute") entfernt - abgelöst durch den neuen Quoten-Knopf, der Tag/Monat/Jahr/Gesamt zeigt (Dietmar, 23.09.2026: "kannst Du damit eliminieren"). Zugehöriger Backend-Code (`AutarkyRatioToday()`, `AutarkyCache`) mit entfernt.

## 0.9.67-beta.1 (2026-09-23)

- Tile: der Kosten-Ticker am Netzanschluss zeigt jetzt den reinen Strompreis (ct/kWh, Bezugspreis bzw. Einspeisevergütung je nach Flussrichtung) statt der auf die aktuelle Leistung hochgerechneten Stundenkosten ("€/h") - Dietmars Wunsch, gerade bei dynamischen Tarifen aussagekräftiger als eine bei schwankender Last ohnehin ungenaue Momentan-Hochrechnung.

## 0.9.66-beta.1 (2026-09-23)

- Tile Energiefluss: neuer Quoten-Knopf oben links (Dietmar, 23.09.2026: "Autarkie- und Selbstverbrauchsquoten für Tag, Monat, Jahr und Gesamt") - zwei ineinanderliegende Ringe im Stil der Apple-Watch-Aktivitätsringe (außen Autarkiegrad grün, innen Eigenverbrauchsquote blau), zeigen direkt den heutigen Wert; Klick öffnet ein Panel mit den vier Zeiträumen (Tag/Monat/Jahr/Gesamt, Monat/Jahr/Gesamt laden bei Bedarf nach). Autarkiegrad = Anteil des Hausverbrauchs ohne Netzbezug, Eigenverbrauchsquote = Anteil der PV-Erzeugung, der nicht eingespeist wurde. Fehlt eine Quelle (PV-, Netz- oder Hauslast-Leistung), bleibt die jeweilige Quote leer statt eines erfundenen Werts. Auch in der eigenständigen Webseite (IPSView/Browser) nutzbar.

## 0.9.65-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: der "Kumuliert"-Toggle steht jetzt in derselben Zeile wie Gesamtertrag/Spezifischer Anlagenertrag statt in einer eigenen Zeile darunter, mit deutlich größerem Abstand zum Knopfpaar, damit er trotzdem klar als eigenständiger Schalter abgesetzt wirkt (Dietmars Wunsch).

## 0.9.64-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: Monatswert und kumulierte Summe stehen nicht mehr nebeneinander in oder neben derselben Zelle - nach mehreren gescheiterten Anläufen (Abstand, eigene Spalten) jetzt ein echter Schalter "Kumuliert", der pro Zelle umschaltet, welcher Wert angezeigt wird (Dietmars Wunsch). Bewusst als EIN/AUS-Toggle mit eigener Optik, nicht als zweites, sich ausschließendes Knopfpaar wie Gesamtertrag/Spezifischer Anlagenertrag.

## 0.9.63-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: Monatswert und kumulierte Spalte rücken enger zusammen (reduziertes Innen-Padding auf der Berührungsseite), der volle Zellenabstand bleibt nur zwischen zwei Monaten erhalten - vorher verschwamm die Monatsgrenze (Dietmars Fund). Monatsname im Tabellenkopf steht jetzt zentriert über beiden Spalten statt rechtsbündig fast nur über der Kumuliert-Spalte.

## 0.9.62-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: Monatswert und kumulierte Summe stehen jetzt in zwei eigenen Tabellenspalten statt als zwei Zahlen in derselben rechtsbündigen Zelle (Dietmars Wunsch, nachdem mehr Abstand allein die "Verformungen" nicht löste) - jede Spalte richtet sich unabhängig rechtsbündig aus, Januar bleibt ohne Extra-Spalte.

## 0.9.61-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: mehr Abstand zwischen Monatswert und kumulierter Zahl (Dietmar: "Verformungen bei der Rechtsbündigkeit der Monatswerte" - je nach Länge der kumulierten Zahl rückten beide unterschiedlich eng zusammen, das wirkte wackelig).

## 0.9.60-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: statt die Medaillen noch fetter zu machen (0.9.59, wieder zurückgenommen), sind jetzt die normalen Werte dünner (300) - Gold/Silber/Bronze bleiben bei 700, der Kontrast entsteht über die Differenz statt über immer stärkere Fettung (Dietmars Wunsch).

## 0.9.59-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: Fettung des Siegertreppchens (700) hob sich laut Dietmar zu wenig vom normalen Text (400) ab - auf 800 erhöht, gilt jetzt auch für die kumulierten Medaillenwerte (waren trotz Farbe noch im dünnen Grundgewicht).

## 0.9.58-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: Silber-Farbe im Siegertreppchen (#c7ccd1) war praktisch unsichtbar - lag fast auf Höhe der normalen Textfarbe, während Gold/Bronze als warme Töne deutlich abstachen (Fund Dietmar). Auf ein kühleres, deutlich sichtbareres Blaugrau geändert.

## 0.9.57-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: die Farbe des Siegertreppchens saß an der ganzen Zelle (`<td>`) statt am Wert selbst - die kumulierte Zahl (ein `<span>` innerhalb der Zelle) erbte dadurch die Rang-Farbe des Monatswerts, unabhängig von ihrem eigenen Ranking (Fund Dietmar: Feb 2022 wirkte bronze nur wegen des Monatswerts, Feb 2020 blieb trotz höherer Summe farblos). Beide Werte tragen jetzt ihre Rang-Farbe an einem eigenen Span, unabhängig voneinander.

## 0.9.56-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: Gold/Silber/Bronze-Markierung wieder da - diesmal nur als Farbe, ohne Emoji (Dietmars finale Fassung: "stört mehr wie dass sie bringen"). Getrennte Ranglisten für Monatswert und kumulierte Summe wie zuvor.

## 0.9.55-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: die Gold/Silber/Bronze-Markierung (0.9.53/0.9.54) wieder entfernt - störte laut Dietmar mehr, als sie brachte. Die kumulierte Jahressumme je Monatszelle (0.9.51/0.9.52) bleibt.

## 0.9.54-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: die kumulierte Jahressumme je Zelle bekommt ein EIGENES Gold/Silber/Bronze-Ranking, getrennt vom Ranking des reinen Monatswerts (ein Jahr kann im Monatswert vorn liegen und in der Summe zurückfallen, und umgekehrt - Fund Dietmar). Beim kumulierten Wert bewusst nur Farbe, keine Medaille.

## 0.9.53-beta.1 (2026-09-23)

- PVMonitor Jahresvergleich: die drei besten Jahre je Spalte (jeder Monat und die Summe) werden olympisch mit Gold/Silber/Bronze markiert - Farbe plus Medaillen-Symbol, damit es auch farbenblind erkennbar bleibt. Gleichstand teilt sich denselben Rang.

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
