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
