<?php
/**
 * Baut je Modulordner eine locale.json (Symcon liest locale.json nur im Ordner des jeweiligen Moduls).
 *
 * Quellen (deutsche Fassung zu den englischen Quelltexten, Format {"translations":{"de":{"English":"Deutsch"}}}):
 *   libs/locale/gemeinsam.json      - Texte, die mehrere Module fast wortgleich nutzen (Lizenz, Forum-Hinweis, "Wozu dieses
 *                                     Modul?", Tour-Knoepfe, Formular-Aufbau, FormStatus ...)
 *   libs/locale/<Modulname>.json    - Texte nur dieses Moduls (optional, solange ein Modul noch nicht umgestellt ist)
 * Ergebnis je Modul: <Modulname>/locale.json = ALLE Schluessel aus gemeinsam.json + alle aus <Modulname>.json
 * (bewusst alle gemeinsamen, nicht nur die benutzten: gemeinsame Texte duerfen so in jedem Modul ohne neuen Eintrag verwendet
 * werden; ueberfluessige Zeilen kosten nichts). Die Datei <Modulname>/locale.json ist ein Build-Ergebnis und wird mit eingecheckt.
 *
 * Meldungen (Rueckgabe 1 bei Fehler):
 *   - ein englischer Quelltext steht mit UNTERSCHIEDLICHEM Deutsch in zwei Dateien (gemeinsam/Modul oder Modul/Modul): FEHLER
 *   - derselbe Quelltext mit gleichem Deutsch in gemeinsam.json und einer Modul-Datei: Hinweis (bitte aus der Modul-Datei entfernen)
 *   - ungueltiges JSON: FEHLER
 * Aufruf: php .tools/locale-bauen.php            (alle Module)
 *         php .tools/locale-bauen.php <Modulname> (nur dieses Modul bauen; die Pruefung ueber alle Dateien laeuft trotzdem)
 */
declare(strict_types=1);

const MODULE = ['NRGDashboardTile', 'NRGDashboardMap', 'NRGDashboardTopology', 'NRGDashboardPVMonitor', 'NRGDashboardWPMonitor', 'NRGDashboardHeatSchema', 'NRGDashboardForecast'];

$root = dirname(__DIR__);
$fehler = 0;

function laden(string $datei): array
{
    $raw = (string) file_get_contents($datei);
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['translations']['de']) || !is_array($j['translations']['de'])) {
        fwrite(STDERR, "FEHLER  $datei ist kein gueltiges locale-JSON\n");
        exit(1);
    }
    // doppelte Schluessel in derselben Datei sieht json_decode nicht: roh zaehlen
    preg_match_all('/^\s*("(?:[^"\\\\]|\\\\.)*")\s*:/m', $raw, $m);
    $keys = array_slice(array_map('json_decode', $m[1]), 2); // ohne "translations" und "de"
    if (count($keys) !== count($j['translations']['de'])) {
        fwrite(STDERR, "FEHLER  $datei: doppelte Schluessel (roh " . count($keys) . ', dekodiert ' . count($j['translations']['de']) . ")\n");
        exit(1);
    }
    return $j['translations']['de'];
}

$gemeinsam = laden($root . '/libs/locale/gemeinsam.json');
$proModul = [];
foreach (MODULE as $mod) {
    $f = $root . '/libs/locale/' . $mod . '.json';
    $proModul[$mod] = is_file($f) ? laden($f) : [];
}

// Pruefung ueber Dateigrenzen
$quellen = ['gemeinsam' => $gemeinsam] + $proModul;
$gesehen = [];
foreach ($quellen as $name => $tabelle) {
    foreach ($tabelle as $en => $de) {
        if (isset($gesehen[$en]) && $gesehen[$en][1] !== $de) {
            echo "  FEHLER  Quelltext mit unterschiedlichem Deutsch in {$gesehen[$en][0]} und $name: " . mb_substr($en, 0, 80) . "\n";
            $fehler++;
        } elseif (isset($gesehen[$en])) {
            echo "  Hinweis doppelt mit gleichem Deutsch in {$gesehen[$en][0]} und $name: " . mb_substr($en, 0, 80) . "\n";
        } else {
            $gesehen[$en] = [$name, $de];
        }
    }
}

$nur = $argv[1] ?? null;
foreach (MODULE as $mod) {
    if ($nur !== null && $nur !== $mod) {
        continue;
    }
    $alle = $gemeinsam + $proModul[$mod];
    ksort($alle, SORT_STRING);
    $json = json_encode(['translations' => ['de' => $alle]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    file_put_contents($root . '/' . $mod . '/locale.json', $json);
    echo "$mod/locale.json geschrieben (" . count($alle) . " Texte, davon " . count($proModul[$mod]) . " modul-eigene)\n";
}
exit($fehler === 0 ? 0 : 1);
