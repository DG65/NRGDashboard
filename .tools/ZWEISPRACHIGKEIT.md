# Zweisprachigkeit (DE/EN) für ein Modul der Bibliothek – Anleitung für Umsteller

Pilot und Vorbild: `NRGDashboardTopology` (Commit "NRGDashboardTopology zweisprachig"). Regeln aus SUITE.md,
Konventionen Punkt 9 und Store-Punkt 25. Deutsch bleibt die Referenz; Englisch ist echtes Fachenglisch, kein Wort-für-Wort.
"Symcon", nie "IP-Symcon". Nur das eigene Modul anfassen (und `libs/locale/<Modul>.json`).

## Aufbau

- `libs/NRGDASH_Text.php` – Klasse `NRGDASH_Text`: `setzen()`, `t()`, `tabelle()`, `kachelHtml()`. Merkliste-Cache (Stolperstein 24).
- `libs/locale/gemeinsam.json` – Texte, die mehrere Module gleich nutzen (Lizenz, Forum-Hinweis, Wozu-Panel, Tour-Knöpfe,
  Formular-Aufbau, FormStatus, Standalone-Schalter). **Zuerst hier nachsehen, ob der Text schon steht – den englischen Quelltext
  dann wortgleich übernehmen.**
- `libs/locale/<Modulname>.json` – nur die Texte dieses Moduls.
- `<Modulname>/locale.json` – Build-Ergebnis (gemeinsam + Modul), nicht von Hand ändern, wird eingecheckt.

## Schritt für Schritt

1. **Vorher lesen:** SUITE.md Punkt 9, Pilot-Commit, `libs/FormLayout.php` (Kopfkommentar), dieses Dokument.
2. **Modul einbinden:** `require_once __DIR__ . '/../libs/NRGDASH_Text.php';` und in `Create()` und `ApplyChanges()` direkt nach `parent::…()`:
   `NRGDASH_Text::setzen(fn (string $text): string => $this->Translate($text));` (steht in den sechs Modulen schon als Übergang drin –
   beim Umstellen die Kommentarzeile beibehalten oder entfernen, die Zeile selbst nicht doppeln).
3. **Panels mit `name` versehen** (form.json): jedes wählbare Fachpanel und das Doku-Panel (`PanelDoc`), z. B. `PanelSource`, `PanelStyle`.
   `FORM_LAYOUT_PANELS` wird `['PanelSource' => 'source', …]` – die Schlüssel rechts (`source` …) bleiben unverändert, sie stehen in der
   Property `FormLayout`. Jede Suche nach Panels über Caption-Text (`str_contains($el['caption'], 'Dokumentation')`) auf `name` umstellen.
   Vorher im Modul greppen: `str_contains`, `str_starts_with`, `=== 'aktiv'`, Vergleiche mit Anzeigetexten, `switch` über Texte.
4. **form.json auf Englisch** (caption/label/suffix/prefix, Optionen, Spaltenköpfe, `status`). Das Formular übersetzt die **Konsole selbst**
   über locale.json (Store-Punkt 25) – keine eigene Übersetzungsschleife. Das gilt auch für feste `'caption' => 'English'` in PHP.
   Am schnellsten: Skript, das aus der alten form.json englisch/deutsch-Paare erzeugt (Muster: Pilot), damit das Deutsche nie neu getippt wird.
5. **Selbst übersetzen (`NRGDASH_Text::t(...)`)**, was die Konsole nicht sieht: Texte mit eingesetzten Werten (die Konsole findet
   `"… 3 Partner …"` nicht), **Listenzellen** (`values`), Statuszeilen, `echo`/Rückgabetexte, `UpdateFormField`-Texte mit Werten,
   Variablen-/Profilnamen, Debug-Texte, Kachel-Texte.
   - Ganze Sätze mit `%s`/`%d`, nicht Satzteile kleben. Einzahl/Mehrzahl = zwei vollständige Quelltexte (zwei `t()`-Aufrufe mit
     Literal, **kein** Ternary im Argument – das Prüfwerkzeug findet es sonst nicht).
   - Anführungszeichen gehören in die Vorlage (`„%s“` deutsch, `“%s”` englisch), nicht in den Code.
   - Ein englischer Quelltext = genau eine deutsche Fassung; Quelltexte eindeutig („Open“ vs. „Opened“).
   - Emojis/Aufzählungspunkte dürfen vor dem Text stehen (`'• ' . t($zeile)`), sonst Emoji in den Quelltext.
6. **Logik nie am Anzeigetext.** Zustände intern halten (`formInstanceActive()` statt `formInstanceState() === 'aktiv'`).
7. **NEWS_VERSIONS** englisch, `newsBanner()` übersetzt jede Zeile mit `NRGDASH_Text::t($line)`. Keine neuen Einträge erfinden.
8. **Kachel (`module.html`)**: keine deutschen Literale. Im HTML englische Standardtexte, im Skript `tr('English source')`
   (+ `fmt()` für `%s`/`%d`), Marke `<!--NRGDASH_TEXTS-->` vor dem Hauptskript, in PHP `const TILE_TEXTS = [...]` (alle `tr()`-Schlüssel)
   und `NRGDASH_Text::kachelHtml($html, self::TILE_TEXTS)` – in `GetVisualizationTile()` **und** in `ProcessHookData()` (Standalone-Seite).
   Auch Tooltips (`title=`), Tour-Overlay und Fehlertexte lesen; Komma-/Datumsformate (`toLocaleString('de-DE')` o. ä.) prüfen.
   Texte, die ein Partnermodul liefert (z. B. EMS-`summary`), übersetzt das Partnermodul selbst.
9. **Texte in die JSON-Dateien:** neue Texte nach `libs/locale/<Modulname>.json` (gemeinsame nach `gemeinsam.json`), Format
   `{"translations":{"de":{"English":"Deutsch"}}}`. Dann `php .tools/locale-bauen.php <Modulname>`.
   Das Bau-Skript meldet gleiche Quelltexte mit unterschiedlichem Deutsch über Dateigrenzen hinweg.
10. **Prüfen (alles muss grün sein):**
    - `php .tools/locale-pruefen.php <Modulname>` – statisch; Gegenprobe der Form-/NEWS-Texte gegen den Stand vor der Umstellung.
    - Ein eigenes Szenario-/Vergleichsskript wie `.tools/szenarien-topology.php` + `.tools/deutsch-vergleich.php` für die PHP-Meldungen
      des Moduls (IPS-Stubs, deutsche Ausgabe alt gegen neu wortgleich; zusätzlich einmal englisch erzeugen und lesen).
    - `php tests/formlayout.test.php`, `php tests/gridreward.test.php`, `php -l` auf alle geänderten PHP-Dateien, JSON gültig.
    - `php /Users/dietmar/Nextcloud/Claude/.tools/migrationsvergleich.php . origin/main <branch>` → „kein Bruch“ (erst committen).
    - `git ls-files | grep -c "\.orig$"` = 0.
11. **CHANGELOG.md**: Eintrag unter „Unreleased“; Version in `library.json` nicht erhöhen; kein Push.

## Fallen aus dem Pilot (Topology)

- **Panel-Suche über die Caption** (`FORM_LAYOUT_PANELS`, Doku-Panel für die Versionszeile) bricht still nach der Übersetzung → `name`.
- **`formInstanceState() === 'aktiv'`** (Forecast) → `formInstanceActive()`.
- **Label mit eingesetztem Wert** (`%%HOOK%%` per `str_replace`): die Konsole findet den Text danach nicht → Platzhalter `%s` im Quelltext,
  Label mit `name`, in PHP `setFormCaption($elements, 'Name', NRGDASH_Text::t('… %s …', $wert))`. `setFormStatusLine` setzt zusätzlich
  eine Farbe (-1) und verändert dadurch die Formularstruktur; für feste Texte `setFormCaption` nehmen.
- **Listenzellen** übersetzt die Konsole nicht (z. B. Spalte „Bereich“ im Formular-Aufbau → `FormLayout` übersetzt selbst).
- **Ternary im `t()`-Argument** und **Satzteile** (`$wie = $x ? 'ausgewählt' : 'erkannt'`) → zwei vollständige Sätze.
- **Alter Stand für die Gegenprobe:** erst committen, dann gegen den Stand vor der Umstellung prüfen (Standard: merge-base mit origin/beta).
- **Anführungszeichen im Deutsch exakt kopieren** (in der Tour stand `„WB 1"` mit geradem Schlusszeichen): Deutsch nie neu tippen,
  per Skript aus dem alten Stand holen.
- **Sprachwechsel:** `setzen()` leert die Merkliste; ein Wechsel der Symcon-Sprache greift beim nächsten „Übernehmen“/Prozessstart.
- **Kein eigenes Lesen der locale.json** im Modulcode (Store-Prüfung, Stolperstein 24). Nur die Prüfskripte lesen sie.
