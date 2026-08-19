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

## Branch-Modell

Arbeitsbranch `ems-integration` (verbundweit identisch), Merge nach
`beta`/`main` erst nach Bewährung. Nutzersichtbares deutsch.

## Verbund-Manifest SUITE.md — Bezugsquelle (19.08.2026)

Primärquelle für alle Verbund-Konventionen ist `SUITE.md` im EMS-Repo
(https://github.com/DG65/NRGEMS — während der EMS-Integrationsphase ist der
Branch `ems-integration` der aktuellste Stand, nicht `main`). In diesem Repo
liegt eine automatisch synchronisierte READ-ONLY-Kopie als `SUITE.md` im
Repo-Root — dort lokal grep'en/lesen. NIEMALS die Kopie hier editieren:
Änderungen gehören ins EMS-Repo; der Sync (GitHub Action `sync-suite` im
EMS-Repo) überschreibt lokale Änderungen kommentarlos.

Fallback, falls die Kopie (noch) fehlt oder veraltet wirkt:
https://raw.githubusercontent.com/DG65/NRGEMS/ems-integration/SUITE.md
