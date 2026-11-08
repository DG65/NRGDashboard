<?php

declare(strict_types=1);

// Prüfstand für NRGDashboardTile::GridRewardWallboxes() (Grid-Rewards-Hinweis am Wallbox-Knoten). Aufruf: php tests/gridreward.test.php
$src = file_get_contents(__DIR__ . '/../NRGDashboardTile/module.php');
$a = strpos($src, '    private function GridRewardWallboxes(array $devices): array');
$b = strpos($src, "    /**\n     * Regelung und Plan je Wallbox fuer die Detailseite");
if ($a === false || $b === false) { fwrite(STDERR, "Methode nicht gefunden\n"); exit(2); }
$method = substr($src, $a, $b - $a);

$GLOBALS['tibber'] = [];
$GLOBALS['chub'] = [];
function TIBBERGR_GetActiveControls(int $id) { return $GLOBALS['tibber']; }
function CHUB_GetFunctions(int $id) { return $GLOBALS['chub'][$id] ?? []; }

eval('final class GrStub {
    public array $watt = [];
    public bool $demoIsolated = false;
    public int $tibberId = 1;
    private function readBoolProperty(string $n, bool $d = false): bool { return $n === "DemoIsolated" ? $this->demoIsolated : $d; }
    private function TibberInstanceID(): int { return $this->tibberId; }
    private function normalizeDeviceCategory(string $f): string { return strpos($f, "wallbox") === 0 ? "wallbox" : $f; }
    private function resolvePowerValue(array &$d): ?float { return $this->watt[(int) $d["powerID"]] ?? 0.0; }
' . $method . '
    public function run(array $devices): array { return $this->GridRewardWallboxes($devices); }
}');

$fail = 0;
function check(string $n, bool $ok): void { global $fail; echo ($ok ? '  ok    ' : '  FEHLER ') . $n . "\n"; if (!$ok) { $fail++; } }
$wb = function (int $pid, int $inst): array { return ['function' => 'wallbox' . $pid, 'powerID' => $pid, 'instanceID' => $inst, 'source' => 'chargerhub']; };
$active = [['type' => 'vehicle', 'name' => 'Model Y', 'reason' => 'Grid Reward aktiv (CHARGE) seit 03:30', 'since' => 1000, 'valid' => true]];

$t = new GrStub();
echo "kein Eingriff\n";
$GLOBALS['tibber'] = [];
check('Tibber steuert nichts: leer', $t->run([$wb(1, 10)]) === []);
$GLOBALS['tibber'] = [['type' => 'battery', 'name' => 'Akku', 'reason' => 'x', 'since' => 1, 'valid' => true]];
check('nur Batterie aktiv: leer (Hinweis gilt der Wallbox)', $t->run([$wb(1, 10)]) === []);
$GLOBALS['tibber'] = [['type' => 'vehicle', 'name' => 'Y', 'reason' => 'x', 'since' => 1, 'valid' => false]];
$t->watt = [1 => 5000.0];
check('Daten nicht gültig (valid=false): leer', $t->run([$wb(1, 10)]) === []);

echo "Zuordnung\n";
$GLOBALS['tibber'] = $active;
$GLOBALS['chub'] = [10 => [['powerID' => 1, 'controlOwner' => 'ems']], 20 => [['powerID' => 2, 'controlOwner' => 'tibber']]];
$t->watt = [1 => 0.0, 2 => 0.0];
$r = $t->run([$wb(1, 10), $wb(2, 20)]);
check('Regler Tibber gewinnt, auch ohne Ladeleistung: nur Wallbox 2', array_keys($r) === [2]);
check('Text enthält Fahrzeug und Grund', str_contains($r[2]['text'], 'Model Y') && str_contains($r[2]['text'], 'seit 03:30') && $r[2]['since'] === 1000);

$GLOBALS['chub'] = [10 => [['powerID' => 1, 'controlOwner' => 'ems']], 20 => [['powerID' => 2, 'controlOwner' => 'ems']]];
$t->watt = [1 => 7000.0, 2 => 0.0];
check('kein Tibber-Regler, genau eine lädt: diese', array_keys($t->run([$wb(1, 10), $wb(2, 20)])) === [1]);
$t->watt = [1 => 7000.0, 2 => 6000.0];
check('kein Tibber-Regler, zwei laden: keine (lieber nichts als die falsche)', $t->run([$wb(1, 10), $wb(2, 20)]) === []);
$t->watt = [1 => 0.0, 2 => 50.0];
check('keine lädt (unter 100 W): keine', $t->run([$wb(1, 10), $wb(2, 20)]) === []);
$GLOBALS['chub'] = [10 => [['powerID' => 1, 'controlOwner' => 'tibber']], 20 => [['powerID' => 2, 'controlOwner' => 'tibber']]];
check('beide mit Regler Tibber: beide', array_keys($t->run([$wb(1, 10), $wb(2, 20)])) === [1, 2]);

echo "Randfälle\n";
check('Gerät ohne Wallbox-Funktion wird ignoriert', $t->run([['function' => 'pv', 'powerID' => 9, 'instanceID' => 1, 'source' => 'inverterhub']]) === []);
$t->demoIsolated = true;
check('isolierter Demo-Modus: leer', $t->run([$wb(1, 10)]) === []);
$t->demoIsolated = false;
$t->tibberId = 0;
check('keine Tibber-Instanz: leer', $t->run([$wb(1, 10)]) === []);
$t->tibberId = 1;
$GLOBALS['tibber'] = [$active[0], ['type' => 'vehicle', 'name' => 'Zoe', 'reason' => 'Grid Reward aktiv seit 04:10', 'since' => 500, 'valid' => true]];
$r = $t->run([$wb(1, 10)]);
check('zwei aktive Fahrzeuge: frühester Beginn, beide Namen im Text', $r[1]['since'] === 500 && str_contains($r[1]['text'], 'Model Y') && str_contains($r[1]['text'], 'Zoe'));

echo $fail === 0 ? "\nAlles bestanden.\n" : "\n$fail Prüfung(en) fehlgeschlagen.\n";
exit($fail === 0 ? 0 : 1);
