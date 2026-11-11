<?php

declare(strict_types=1);

// ===========================================================================
// Gegenprobe Zweisprachigkeit fuer NRGDashboardMap: Bleibt die deutsche Ausgabe unveraendert?
//   php .tools/deutsch-vergleich-map.php [Vergleichsstand, Standard: merge-base mit origin/beta]
// Laesst .tools/szenarien-map.php zweimal laufen -- einmal mit dem Stand des Vergleichs (git archive in ein Temp-Verzeichnis),
// einmal mit dem aktuellen -- und vergleicht jeden sichtbaren Text (Formular wie die deutsche Konsole es zeigt, Status-Zeilen,
// Rueckgaben, UpdateFormField, Payload). Zeiten werden vereinheitlicht; gewollte Strukturaenderungen (neue name-Felder
// PanelDoc/PanelSource/PanelDetails/PanelStyle) sind ausgenommen. Rueckgabe 1 bei Abweichung.
// ===========================================================================

$root = dirname(__DIR__);
$ref = $argv[1] ?? trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' merge-base HEAD origin/beta 2>/dev/null'));
$ref = $ref !== '' ? $ref : 'HEAD';
$tmp = sys_get_temp_dir() . '/nrgdash-deutsch-vergleich-' . getmypid();
mkdir($tmp, 0777, true);
shell_exec('git -C ' . escapeshellarg($root) . ' archive ' . escapeshellarg($ref) . ' | tar -x -C ' . escapeshellarg($tmp));
$lauf = function (string $wurzel) {
    $aus = (string) shell_exec('php ' . escapeshellarg(__DIR__ . '/szenarien-map.php') . ' ' . escapeshellarg($wurzel) . ' de 2>&1');
    $aus = preg_replace('/\d{2}:\d{2}:\d{2}/', 'T', $aus);
    $aus = preg_replace('/^.*"name": "(PanelDoc|PanelSource|PanelDetails|PanelStyle)",\n/m', '', $aus);
    $aus = preg_replace('/"name":"(PanelDoc|PanelSource|PanelDetails|PanelStyle)",/', '', $aus);
    $aus = preg_replace('/=== Kachel-Texttabelle.*\z/s', '', $aus); // nur im neuen Stand
    return explode("\n", $aus);
};
$alt = $lauf($tmp);
$neu = $lauf($root);
shell_exec('rm -rf ' . escapeshellarg($tmp));
echo 'Mitschrift: ' . count($alt) . ' Zeilen (' . substr($ref, 0, 8) . '), ' . count($neu) . " Zeilen (aktuell)\n";
$ok = $alt === $neu;
if (!$ok) {
    $n = 0;
    foreach ($alt as $i => $l) {
        if (($neu[$i] ?? null) !== $l) {
            echo "  Zeile $i\n    alt: " . mb_substr($l, 0, 400) . "\n    neu: " . mb_substr($neu[$i] ?? '(fehlt)', 0, 400) . "\n";
            if (++$n >= 8) {
                break;
            }
        }
    }
}
echo $ok ? "DEUTSCH-VERGLEICH BESTANDEN\n" : "DEUTSCH-VERGLEICH: Abweichungen\n";
exit($ok ? 0 : 1);
