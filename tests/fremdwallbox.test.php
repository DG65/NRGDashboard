<?php

declare(strict_types=1);

// Prüfstand für NRGDashboardTile::discoverForeignChargers() (Wallboxen anderer Autoren, 0.14.1). Aufruf: php tests/fremdwallbox.test.php
$src = file_get_contents(__DIR__ . '/../NRGDashboardTile/module.php');
$a = strpos($src, '    private function discoverForeignChargers(array $known): array');
$b = strpos($src, '    private function discoverListContract(string $moduleGUID, string $function, string $source): array');
if ($a === false || $b === false || $b < $a) { fwrite(STDERR, "Methode nicht gefunden\n"); exit(2); }
$method = substr($src, $a, $b - $a);

$GLOBALS['mods'] = [
    'g-easee' => ['ModuleType' => 3, 'Prefix' => 'EASEE', 'inst' => [101]],
    'g-chub'  => ['ModuleType' => 3, 'Prefix' => 'CHUB', 'inst' => [102]],
    'g-sig'   => ['ModuleType' => 3, 'Prefix' => 'SIG', 'inst' => [103]],
    'g-boom'  => ['ModuleType' => 3, 'Prefix' => 'BOOM', 'inst' => [104]],
    'g-io'    => ['ModuleType' => 1, 'Prefix' => 'IOMOD', 'inst' => [105]],
    'g-off'   => ['ModuleType' => 3, 'Prefix' => 'OFF', 'inst' => [106]],
    'g-v2'    => ['ModuleType' => 3, 'Prefix' => 'NEU', 'inst' => [107]],
    'g-str'   => ['ModuleType' => 3, 'Prefix' => 'STR', 'inst' => [108]],
    'g-noprefix' => ['ModuleType' => 3, 'Prefix' => '', 'inst' => [109]],
];
function IPS_GetModuleList() { return array_keys($GLOBALS['mods']); }
function IPS_GetModule($g) { return $GLOBALS['mods'][$g] ?? false; }
function IPS_GetInstanceListByModuleID($g) { return $GLOBALS['mods'][$g]['inst'] ?? []; }
function IPS_GetInstance($id) { return ['InstanceStatus' => $id === 106 ? 104 : 102]; }
function EASEE_GetFunctions($id) { return [
    ['contractType' => 'charger', 'contractVersion' => '1.13', 'label' => 'Easee Carport', 'powerID' => 5001, 'deviceSerial' => 'EH123'],
    ['contractType' => 'charger', 'contractVersion' => '1.13', 'label' => 'Easee Doppel', 'powerID' => 5002, 'deviceSerial' => 'EH123'],
    ['contractType' => 'charger', 'contractVersion' => '1.13', 'label' => 'Easee Dublette', 'powerID' => 5003, 'duplicateOf' => 'CHUB#1'],
    ['contractType' => 'charger', 'contractVersion' => '2.0', 'label' => 'Zu neu', 'powerID' => 5004],
    ['contractType' => 'charger', 'contractVersion' => '1.13', 'label' => 'ohne Leistung', 'powerID' => 0],
    ['contractType' => 'meter', 'contractVersion' => '1.0', 'label' => 'Zähler', 'powerID' => 5005],
    ['function' => 'charger', 'contractVersion' => '1.2', 'label' => 'nur function', 'powerID' => 5006],
]; }
function CHUB_GetFunctions($id) { return [['contractType' => 'charger', 'contractVersion' => '1.13', 'label' => 'eigene', 'powerID' => 9001]]; }
function SIG_GetFunctions($id, $von, $bis) { return []; }
function BOOM_GetFunctions($id) { throw new RuntimeException('kaputt'); }
function IOMOD_GetFunctions($id) { return [['contractType' => 'charger', 'contractVersion' => '1.0', 'powerID' => 9002]]; }
function OFF_GetFunctions($id) { return [['contractType' => 'charger', 'contractVersion' => '1.0', 'powerID' => 9003]]; }
function NEU_GetFunctions($id) { return json_encode(['contractType' => 'charger', 'contractVersion' => '1.1', 'label' => 'Einzeleintrag als JSON', 'powerID' => 5007]); }
function STR_GetFunctions($id) { return 'kein json'; }

eval('final class FwStub {
    public array $debug = [];
    private function SendDebug(string $a, string $b, int $c): void { $this->debug[] = $b; }
    private function normalizeEntry(array $e, string $source, int $id): array { $e["source"] = $source; $e["instanceID"] = $e["instanceID"] ?? $id; return $e; }
' . $method . '
    public function run(array $known): array { return $this->discoverForeignChargers($known); }
}');

$fail = 0;
function check(string $n, bool $ok): void { global $fail; echo ($ok ? '  ok    ' : '  FEHLER ') . $n . "\n"; if (!$ok) { $fail++; } }
$t = new FwStub();
$r = $t->run([['deviceSerial' => 'XYZ']]);
$labels = array_map(fn ($e) => $e['label'], $r);
check('gefunden: Easee Carport, nur-function-Eintrag, Einzeleintrag als JSON', $labels === ['Easee Carport', 'nur function', 'Einzeleintrag als JSON']);
check('gleiche Seriennummer innerhalb der Fremdsuche nur einmal', !in_array('Easee Doppel', $labels, true));
check('Quelle ext:<Präfix>', $r[0]['source'] === 'ext:EASEE' && $r[2]['source'] === 'ext:NEU');
check('Instanz-ID des Aufrufs', $r[0]['instanceID'] === 101);
check('function ergänzt', $r[0]['function'] === 'charger');
$known = [['deviceSerial' => 'EH123']];
$r2 = (new FwStub())->run($known);
check('Seriennummer schon bekannt: Easee-Einträge entfallen (Doppel)', !in_array('Easee Carport', array_map(fn ($e) => $e['label'], $r2), true) && !in_array('Easee Doppel', array_map(fn ($e) => $e['label'], $r2), true));
check('eigenes Präfix CHUB wird nicht gesucht', !in_array('eigene', $labels, true));
check('duplicateOf entfällt', !in_array('Easee Dublette', $labels, true));
check('Vertrags-Hauptversion 2 entfällt', !in_array('Zu neu', $labels, true));
check('Eintrag ohne Leistungsvariable entfällt', !in_array('ohne Leistung', $labels, true));
check('anderer Vertragstyp entfällt', !in_array('Zähler', $labels, true));
check('Modul mit anderer Signatur wird übersprungen (kein Fehler, kein Debug-Eintrag)', count(array_filter($t->debug, fn ($m) => strpos($m, 'SIG') !== false)) === 0);
check('werfendes Modul: übersprungen und vermerkt', count(array_filter($t->debug, fn ($m) => strpos($m, 'BOOM #104') === 0)) === 1);
check('kein Gerätemodul (IO) wird nicht gesucht', !in_array(9002, array_column($r, 'powerID'), true));
check('deaktivierte Instanz (104) wird übersprungen', !in_array(9003, array_column($r, 'powerID'), true));
check('Muell-Antwort (kein JSON) wird übersprungen', count($r) === 3 && count($t->debug) === 1);
echo $fail === 0 ? "Alles bestanden.\n" : "$fail Fehler.\n";
exit($fail === 0 ? 0 : 1);
