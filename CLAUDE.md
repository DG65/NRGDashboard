# NRGDashboard — Hinweise für die Arbeit an diesem Repository

## Rolle im NRG-Stack

Reine **Darstellungsschicht**, kein eigener Datenvertrag. Langfristig die
**einzige Darstellungsfläche** im Verbund (Architekturentscheidung Dietmar,
25.07.2026) — auch für gerätespezifische Diagnose, die heute noch in
modul-eigenen Kacheln lebt. Die Diagnoselogik (Berechnung, Schwellenwerte,
Gerätewissen) bleibt beim jeweiligen Partnermodul; hier nur Rendering.

## Kernprinzipien (bereits entschieden, nicht neu diskutieren)

1. **Keine manuell verknüpften Variablen-IDs.** Alle Geräte werden über die
   `*_GetFunctions`-Verträge des Verbunds discovered — fällt eine Instanz weg
   oder kommt neu dazu, zieht das Dashboard automatisch nach. (Genau das war
   die Schwäche der abzulösenden Drittanbieter-App „IPS View".)
2. **Rendering type-neutral halten** (`renderDiagnostics()` in `module.html`
   als Referenz): nur `label`/`level`/`reason` + generische `*ID`/`*IDs`-
   Feldmuster lesen, nie konkrete `type`-Werte hart verdrahten — ein neuer
   Vertragsanbieter darf keine Renderer-Änderung erfordern.
3. **Bewertung trifft der Anbieter, nie das Dashboard** (`level`-Prinzip,
   siehe `TIBBERGR_GetPriceCurve`-Diskussion).
4. `ID`-Suffix-Konvention: Feld mit `ID`-Suffix = Referenz (Konsument löst
   selbst auf), ohne Suffix = bereits interpretierter Wert.

## Verträge, die dieses Repo konsumiert

Alle `*_GetFunctions`/`GetState`-Verträge des Verbunds, dazu
`IHUBMON_GetDiagnostics` (erster Diagnostik-Vertrag, Muster-Referenz) und
`SZR_GetAvailableScenarios` (Szenario-Discovery). Details, Feldregister und
aktuelle Vertragsstände: README.md hier (umfangreich, aktuell gepflegt —
primäre Detailquelle dieses Repos) und SUITE.md.

## Struktur

Mehrere Module in einer Bibliothek: `NRGDashboardTile` (Hauptkachel),
`NRGDashboardMap`, `NRGDashboardTopology`, `NRGDashboardPVMonitor`,
`NRGDashboardWPMonitor`, `NRGDashboardHeatSchema`. Achtung Stolperfalle
(SUITE.md, IPS-Punkt 10): eine „aufgezogene" Kachel zeigt NIE das eigene
Kachel-HTML — Bedienelemente brauchen echte Instanz-Variablen mit
`EnableAction()`.

## Feinheiten dokumentieren — Pflege-Pflicht (28.08.2026)

Dietmar: die eingebauten Details ("schöne Feinheiten die andere so
nicht haben") sollen dokumentiert und für die Community konserviert
werden. Bei jedem künftigen Detail-Feature deshalb DREI Orte pflegen:
1. **"📖 Dokumentation & Hilfe"-Panel** des betroffenen Moduls
   (✨-Feinheiten-Labels in form.json),
2. **README.md, Abschnitt "Feinheiten — konservierter Text für Doku &
   Community"** (paste-fähig für Forum/Store formuliert),
3. ggf. das News-Panel (bestehende Konvention).
Bei Unsicherheit, ob ein Detail erwähnenswert ist: Dietmar fragen
(sein expliziter Wunsch, nicht stillschweigend weglassen).

## Zwei Rendering-Engines — bei JEDER Diagramm-Untersuchung beide prüfen (27.08.2026)

Jedes Diagramm-Modul kann wahlweise mit **ECharts** oder **Highcharts**
rendern (Property `Engine`, je Instanz wählbar). Bei der Suche nach
einem gemeldeten Anzeigefehler nicht nur mit der eigenen Testdaten-
Annahme reproduzieren, sondern zuerst die TATSÄCHLICH aktive Engine der
betroffenen Instanz prüfen (z. B. `IPS_GetConfiguration($id)` per
`mcp__ips-automation__php_eval`) — sonst testet man am eigentlichen Bug
vorbei. Live passiert: die WPMonitor-Highcharts-Tooltip-Zeit-Falle
(Commit `1b75731`) wurde erst gefunden, nachdem Dietmar zweimal gezielt
auf Highcharts hinwies, weil der erste Reproduktionsversuch nur mit
ECharts (der falschen Annahme) getestet wurde. Ein in EINER Engine
gefundener/gefixter Bug (z. B. die Highcharts-`dateFormat()`-Falle) muss
außerdem in JEDEM Modul, das dieselbe Engine nutzt, gegengeprüft werden
- nicht nur in dem, wo er gemeldet wurde.

## Sommer-/Winterzeit (DST) — verbindliche Regel (27.08.2026)

Kalendertag-Grenzen **niemals** über eine feste Sekundenzahl berechnen
(`$start + 86400`, `Date.now() - N*86400000` o.ä.) — an den zwei
DST-Umstellungstagen im Jahr hat ein Kalendertag real 23 (März) oder 25
Stunden (Oktober), nicht 24. Eine solche Rechnung landet ab dem
nächsten DST-Wechsel dauerhaft eine Stunde neben der echten Mitternacht
(nicht nur am Umstellungstag selbst) — bei uns live gefunden in
`BuildDayData()`/`PriceDaySlots()`/`SunRange()`/`BuildBalancePeriod()`
(PVMonitor), identisch in WPMonitor, plus `readMeasured()`/
`measuredFine()` in Forecast (Commit `868f410`, verbundweite
DST-Prüfung).

**Stattdessen:**
- PHP: `strtotime('+1 day', $ts)` / `strtotime('-N day', $ts)` statt
  `$ts ± N*86400` für Kalendertag-Arithmetik (verifiziert für
  Europe/Berlin: 82800s im März, 90000s im Oktober).
- JS: `Date`-Feldmutation (`setDate()`/`setHours(0,0,0,0)`) statt
  `Date.now() ± N*86400000`.
- Reine Sekunden-Deltas zwischen zwei Unix-Timestamps (Cache-TTL,
  Staleness-Checks, Watchdog-Timeouts) sind NICHT betroffen — die
  brauchen keine Kalendertag-Bedeutung und bleiben wie gehabt.
- Vor jeder neuen Tages-/Wochen-/Monats-Auswertung (Archiv-Queries,
  Slot-Raster, Achsen-Grenzen) kurz prüfen: rechnet das über einen
  echten Kalendertag? Dann diese Regel anwenden.

Ausnahme: `NRGDashboardForecast/module.html`s durchgehende 24-Einheiten-
Stunden-Achse (`di*24`) ist eine bewusste 1:1-Kopie von Prognoses
eigenem Code (Dietmars expliziter Wunsch) und wird NICHT einseitig
gefixt — Fund an Prognose gemeldet, wird bei Bedarf im nächsten Sync
übernommen.

**Verwandte, aber andere Falle — Highcharts-Tooltip-Zeit (kein DST-Bug,
permanenter 2h-Offset):** `Highcharts.dateFormat()` ist eine GLOBALE
Funktion und ignoriert das chart-eigene `time: { useUTC: false }` — die
Achse selbst zeigt korrekt lokale Zeit, ein per `Highcharts.dateFormat()`
formatierter Tooltip-Text landet aber 2 Stunden (CEST-Offset) daneben.
Gefunden zuerst in PVMonitor (28.07.2026), wiedergefunden in WPMonitor
(27.08.2026, Commit `1b75731`). Fix: natives `new Date(this.x)` +
manuelle Formatierung statt `Highcharts.dateFormat()` im
Tooltip-Formatter jeder Highcharts-Instanz.

## Branch-Modell

Arbeitsbranch `ems-integration` (verbundweit identisch), Merge nach
`beta`/`main` erst nach Bewährung. Nutzersichtbares deutsch.

## Verbund-Manifest SUITE.md — Bezugsquelle (geändert 31.08.2026)

SUITE.md liegt seit 31.08.2026 NICHT mehr in einem GitHub-Repo (die
Modul-Repos sind öffentlich, SUITE.md enthält das komplette Architektur-/
Debugging-Know-how des Verbunds — Dietmars Entscheidung). Primärquelle ist
ausschließlich die lokale Datei `/Users/dietmar/Nextcloud/Claude/SUITE.md`
auf Dietmars Maschine, versioniert in einem eigenen lokalen Git-Repo ohne
Remote. Frühere Kopien dieses Dokuments wurden zusätzlich aus der Historie
aller Modul-Repos entfernt (`git filter-repo` + Force-Push). Kein
Fallback-Link mehr — ohne lokalen Zugriff auf Dietmars Maschine ist SUITE.md
nicht einsehbar.
