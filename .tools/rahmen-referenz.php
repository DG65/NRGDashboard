<?php

declare(strict_types=1);

// ===========================================================================
// Referenzlauf fuer den Umbau "gemeinsamer Modulrahmen" (0.13.0, 19.11.2026).
//   php .tools/rahmen-referenz.php <Wurzelordner der Bibliothek> <Ausgabeordner>
// Schreibt je Modul und Sprache die Ausgabe des Szenario-Laufs (.tools/szenarien-*.php) und je Modul die Liste der
// oeffentlichen Methoden (so heissen die von Symcon exportierten Funktionen NRGDASH*_<Name>; Timer und Formularknoepfe
// rufen sie als Text). Vorher und nachher laufen lassen und die Ordner mit `diff -r` vergleichen.
// ===========================================================================

$root = rtrim($argv[1] ?? dirname(__DIR__), '/');
$out = rtrim($argv[2] ?? sys_get_temp_dir() . '/rahmen-ref', '/');
@mkdir($out, 0777, true);
$mods = ['Tile', 'Map', 'Topology', 'PVMonitor', 'WPMonitor', 'HeatSchema', 'Forecast', 'Ventilation'];

foreach ($mods as $m) {
    $script = __DIR__ . '/szenarien-' . strtolower($m) . '.php';
    foreach (['de', 'en'] as $lang) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($root) . ' ' . $lang . ' 2>&1';
        $txt = (string) shell_exec($cmd);
        // Laufzeitabhaengiges maskieren (Uhrzeiten und Unixzeiten), damit zwei Laeufe desselben Stands byte-gleich sind
        $txt = (string) preg_replace(['/\b\d{1,2}:\d{2}(:\d{2})?\b/', '/\b1[78]\d{11}\b/', '/\b1[78]\d{8}\b/'], ['HH:MM', 'UNIXZEIT_MS', 'UNIXZEIT'], $txt);
        file_put_contents("$out/szenarien-$m-$lang.txt", $txt);
        echo str_pad("Szenarien $m $lang", 28) . strlen($txt) . " Bytes\n";
    }
}

// Oeffentliche Methoden je Modulklasse (Reflection in einem eigenen Prozess, damit die Klassen sich nicht stoeren)
$refl = <<<'PHP'
<?php
class IPSModule {}
$root = $argv[1]; $m = $argv[2];
require_once $root . "/NRGDashboard$m/module.php";
$cls = null;
foreach (get_declared_classes() as $c) { if (is_subclass_of($c, 'IPSModule')) { $cls = $c; } }
$r = new ReflectionClass($cls);
$names = [];
foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $mm) { if ($mm->class === $cls || in_array($mm->class, $r->getTraitNames(), true) || true) { $names[] = $mm->getName() . '(' . $mm->getNumberOfRequiredParameters() . '/' . $mm->getNumberOfParameters() . ')' . ($mm->hasReturnType() ? ':' . $mm->getReturnType() : ''); } }
sort($names);
echo $cls . "\n" . implode("\n", $names) . "\n";
PHP;
$tmp = $out . '/_refl.php';
file_put_contents($tmp, $refl);
foreach ($mods as $m) {
    $txt = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($root) . ' ' . $m . ' 2>&1');
    file_put_contents("$out/methoden-$m.txt", $txt);
    echo str_pad("Methoden $m", 28) . (substr_count($txt, "\n") - 1) . "\n";
}
unlink($tmp);
