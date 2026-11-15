<?php

declare(strict_types=1);

// ===========================================================================
// Uebersetzung fuer alle sieben Module der Bibliothek "NRG-Stack Dashboard".
// Die Quelltexte stehen auf Englisch im Code, die deutsche Fassung in locale.json
// (Symcons uebliches Muster). libs/locale.json ist die Quelle; php .tools/locale-bauen.php
// kopiert sie in die Modulordner (Symcon liest locale.json nur dort).
//
// Symcons Translate() liest bei JEDEM Aufruf die locale.json (SUITE.md Stolperstein 24).
// Jeder Text fragt Symcon deshalb hoechstens EINMAL je Prozess und merkt sich die Antwort;
// danach kostet er nur einen Array-Zugriff. Das Modul selbst liest locale.json nie.
//
// Einbinden je Modul: require_once __DIR__ . '/../libs/NRGDASH_Text.php';
//   in Create() und ApplyChanges():  NRGDASH_Text::setzen(fn (string $text): string => $this->Translate($text));
//   Texte:  NRGDASH_Text::t('English source %s', $wert)
//   Kachel: NRGDASH_Text::kachelHtml($html, self::TILE_TEXTS)  (siehe dort)
// Das Formular (caption/label/Optionen) bleibt englisch in form.json: die Konsole uebersetzt es selbst.
// Mit t() uebersetzt wird nur, was die Konsole nicht sieht: Texte mit eingesetzten Werten, Listenzellen,
// Meldungen, Kachel-Texte, Status-Zeilen.
// ===========================================================================

final class NRGDASH_Text
{
    /** @var array<string,callable[]> Uebersetzer je Modul-Klasse (Schluessel '' = Aufruf ausserhalb eines Moduls), neueste zuerst */
    private static $kette = [];
    /** @var array<string,array<string,string>> gemerkte Uebersetzungen je Modul-Klasse */
    private static $gemerkt = [];
    /** @var int Zaehler fuer den Pruefstand: wie oft wurde Symcon wirklich gefragt */
    public static $anfragen = 0;

    /**
     * Modul-Klasse des Aufrufers (NRGDashboardTile, NRGDashboardPVMonitor ...), auch ueber Traits und Closures hinweg.
     * Jedes Modul hat seine EIGENE locale.json, Symcons Translate() einer Instanz kennt nur deren Texte. Alle Module der
     * Bibliothek laufen im selben Prozess: ohne diese Trennung wuerde ein Text, der ueber das Translate() eines ANDEREN
     * Moduls gefragt wird und dort fehlt, englisch zurueckkommen und fuer alle gemerkt (Deutsch/Englisch-Mischung).
     */
    private static function kontext(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25) as $frame) {
            $klasse = $frame['class'] ?? '';
            if ($klasse !== '' && strpos($klasse, 'NRGDashboard') === 0) {
                return $klasse;
            }
        }
        return '';
    }

    /**
     * Uebersetzer eines Moduls eintragen: Symcons Translate() einer Instanz. Mehrere Instanzen EINES Moduls laufen im
     * selben Prozess; wird die Instanz geloescht, deren Translate() gerade zustaendig war, springt die naechste desselben
     * Moduls ein (sonst erschiene ploetzlich Englisch in der deutschen Oberflaeche).
     * Das Setzen leert die Merkliste dieses Moduls, damit ein Sprachwechsel beim naechsten Uebernehmen greift.
     */
    public static function setzen(?callable $translate): void
    {
        $ctx = self::kontext();
        self::$gemerkt[$ctx] = [];
        if ($translate === null) {
            self::$kette[$ctx] = [];
            return;
        }
        self::$kette[$ctx] = self::$kette[$ctx] ?? [];
        array_unshift(self::$kette[$ctx], $translate);
        self::$kette[$ctx] = array_slice(self::$kette[$ctx], 0, 8);
    }

    private static function uebersetzen(string $text): string
    {
        $ctx = self::kontext();
        if (isset(self::$gemerkt[$ctx][$text])) {
            return self::$gemerkt[$ctx][$text];
        }
        // Ohne eigenen Uebersetzer (z.B. Aufruf ausserhalb eines Moduls): irgendeiner, aber NICHT merken - sonst
        // wuerde eine fremde locale.json die Antwort fuer dieses Modul festschreiben.
        $eigene = isset(self::$kette[$ctx]) && self::$kette[$ctx] !== [];
        $kette = $eigene ? self::$kette[$ctx] : array_merge(...array_values(self::$kette ?: [[]]));
        foreach ($kette as $translate) {
            try {
                self::$anfragen++;
                $antwort = (string) $translate($text);
                if ($eigene) {
                    self::$gemerkt[$ctx][$text] = $antwort;
                }
                return $antwort;
            } catch (\Throwable $e) {
                // Instanz waehrend des Aufrufs geloescht: naechsten Uebersetzer versuchen.
            }
        }
        return $text;
    }

    /** Text uebersetzen; mit Argumenten wie sprintf (Platzhalter %s/%d, ein Prozentzeichen als %%). */
    public static function t(string $text, ...$args): string
    {
        $uebersetzt = self::uebersetzen($text);
        if ($args === []) {
            return $uebersetzt;
        }
        $aus = @vsprintf($uebersetzt, array_map(function ($a) {
            return is_scalar($a) || $a === null ? (string) $a : (string) json_encode($a);
        }, $args));
        return $aus === false ? $uebersetzt : $aus;
    }

    /**
     * Tabelle englischer Quelltext => uebersetzter Text fuer die Kachel (window.NRGDASH_TEXTS).
     * @param string[] $schluessel die englischen Quelltexte, die module.html mit tr('...') benutzt
     */
    public static function tabelle(array $schluessel): array
    {
        $aus = [];
        foreach ($schluessel as $s) {
            $aus[$s] = self::uebersetzen($s);
        }
        return $aus;
    }

    /**
     * Ersetzt die Marke <!--NRGDASH_TEXTS--> im Kachel-HTML durch die Tabelle. Dieselbe Funktion bedient die Kachel
     * (GetVisualizationTile) und die Standalone-Seite (ProcessHookData). Fehlt die Marke, bleibt das HTML unveraendert.
     */
    public static function kachelHtml(string $html, array $schluessel): string
    {
        $json = json_encode(self::tabelle($schluessel), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        return str_replace('<!--NRGDASH_TEXTS-->', '<script>window.NRGDASH_TEXTS = ' . $json . ';</script>', $html);
    }
}
