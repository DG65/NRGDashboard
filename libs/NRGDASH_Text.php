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
     * Zusaetzlich der Einstiegspunkt: Translate() liefert je nach Aufrufer (Konsole/Formular, Kachel, WebHook-Seite) nicht
     * dieselbe Sprache (live gemessen: WebHook-Seite immer Englisch). Die Merkliste gilt deshalb nur je Modul UND Einstiegspunkt.
     *
     * @return array{0:string,1:string} [Modul-Klasse, Einstiegspunkt]
     */
    private static function kontext(): array
    {
        $klasse = '';
        $einstieg = '';
        $objekt = null;
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $k = $frame['class'] ?? '';
            if ($k !== '' && strpos($k, 'NRGDashboard') === 0) {
                if ($klasse === '') {
                    $klasse = $k;
                    $objekt = $frame['object'] ?? null;
                }
                // aeusserster Modul-Frame = Einstiegspunkt (GetConfigurationForm, GetVisualizationTile, ProcessHookData, Timer ...)
                $einstieg = (string) ($frame['function'] ?? '');
            }
        }
        return [$klasse, $einstieg, $objekt];
    }

    /**
     * Translate() der aufrufenden Modul-Instanz, ohne gemeinsamen Zustand. Der Symcon-Prozess fuehrt Aufrufe auf
     * verschiedenen Ausfuehrungsstraengen aus; statische Variablen gelten nur je Strang. Eine in ApplyChanges per setzen()
     * eingetragene Kette stand deshalb in Kachel-, WebHook- und Formular-Aufrufen oft NICHT zur Verfuegung (Englisch statt
     * Deutsch, je nach Strang zufaellig; live gemessen 15.11.2026). Darum wird Translate() hier in jedem Aufruf von der
     * Instanz geholt, die im Aufrufstapel steht.
     */
    private static function vomObjekt(?object $objekt, string $text): ?string
    {
        if ($objekt === null) {
            return null;
        }
        try {
            $uebersetze = \Closure::bind(function (string $t): string {
                return (string) $this->Translate($t);
            }, $objekt, get_class($objekt));
            self::$anfragen++;
            return $uebersetze($text);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Uebersetzer eines Moduls eintragen: Symcons Translate() einer Instanz. Mehrere Instanzen EINES Moduls laufen im
     * selben Prozess; wird die Instanz geloescht, deren Translate() gerade zustaendig war, springt die naechste desselben
     * Moduls ein (sonst erschiene ploetzlich Englisch in der deutschen Oberflaeche).
     * Das Setzen leert die Merkliste dieses Moduls, damit ein Sprachwechsel beim naechsten Uebernehmen greift.
     */
    public static function setzen(?callable $translate): void
    {
        [$ctx] = self::kontext();
        foreach (array_keys(self::$gemerkt) as $schluessel) {
            if (strpos($schluessel, $ctx . '::') === 0) {
                unset(self::$gemerkt[$schluessel]);
            }
        }
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
        [$ctx, $einstieg, $objekt] = self::kontext();
        $memo = $ctx . '::' . $einstieg;
        if (isset(self::$gemerkt[$memo][$text])) {
            return self::$gemerkt[$memo][$text];
        }
        $antwort = self::vomObjekt($objekt, $text);
        if ($antwort !== null) {
            return self::$gemerkt[$memo][$text] = $antwort;
        }
        // Rueckfall (Aufruf ausserhalb eines Moduls, z.B. Pruefstand): eingetragene Kette, NICHT merken - sonst wuerde eine
        // fremde locale.json die Antwort fuer dieses Modul festschreiben.
        $kette = (self::$kette[$ctx] ?? []) !== [] ? self::$kette[$ctx] : array_merge(...array_values(self::$kette ?: [[]]));
        foreach ($kette as $translate) {
            try {
                self::$anfragen++;
                return (string) $translate($text);
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
        $tabelle = self::tabelle($schluessel);
        $json = json_encode($tabelle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        return str_replace('<!--NRGDASH_TEXTS-->', '<script>window.NRGDASH_TEXTS = ' . $json . ';</script>', $html);
    }
}
