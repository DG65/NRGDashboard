<?php

declare(strict_types=1);

// Prüfstand für die allgemeine Fremdsuche (libs/ForeignDiscovery.php) und ihre Nutzung in der Tile (Wallboxen 0.14.1, Wärmepumpen 0.14.2).
// Aufruf: php tests/fremdsuche.test.php
require_once __DIR__ . '/../libs/ForeignDiscovery.php';
$src = file_get_contents(__DIR__ . '/../NRGDashboardTile/module.php');
$a = strpos($src, '    private function discoverForeignChargers(array $known): array');
$b = strpos($src, '    private function discoverListContract(string $moduleGUID, string $function, string $source): array');
if ($a === false || $b === false || $b < $a) { fwrite(STDERR, "Methoden nicht gefunden\n"); exit(2); }
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
    'g-none'  => ['ModuleType' => 3, 'Prefix' => '', 'inst' => [109]],
    'g-ism8'  => ['ModuleType' => 3, 'Prefix' => 'WPISM8', 'inst' => [110]],
    'g-wphub' => ['ModuleType' => 3, 'Prefix' => 'WPHUB', 'inst' => [111]],
    'g-oldhp' => ['ModuleType' => 3, 'Prefix' => 'ALTWP', 'inst' => [112]],
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
// Eine Liste mit zwei Gerätearten (WPISM8): nur die heatpump-Einträge zählen
function WPISM8_GetFunctions($id) { return [
    ['contractType' => 'heatpump', 'contractVersion' => '1.19', 'label' => 'Wolf CHA', 'powerID' => 6001, 'deviceSerial' => 'WOLF1'],
    ['contractType' => 'ventilation', 'contractVersion' => '1.1', 'label' => 'Wolf CWL', 'powerID' => 6002],
    ['Type' => 'heatpump', 'contractVersion' => '1.15', 'Caption' => 'Altform', 'PowerID' => 6003],
    ['contractType' => 'heatpump', 'contractVersion' => '1.19', 'label' => 'Doppelt zu bekannt', 'powerID' => 6004],
]; }
function WPHUB_GetFunctions($id) { return [['contractType' => 'heatpump', 'contractVersion' => '1.19', 'label' => 'eigene WP', 'powerID' => 9100]]; }
function ALTWP_GetFunctions($id) { return ['Type' => 'heatpump', 'contractVersion' => '1.0', 'Caption' => 'Objektform', 'PowerID' => 6010]; }

eval('final class FsStub {
    use NRGDashForeignDiscovery;
    public array $debug = [];
    private function SendDebug(string $a, string $b, int $c): void { $this->debug[] = $b; }
    private function normalizeEntry(array $e, string $source, int $id): array { $e["source"] = $source; $e["instanceID"] = $e["instanceID"] ?? $id; return $e; }
    private function translateBaseEntry(array $e, int $id): array { $e["function"] = (string) $e["Type"]; $e["label"] = $e["Caption"] ?? "x"; $e["powerID"] = $e["powerID"] ?? ($e["PowerID"] ?? 0); return $e; }
' . $method . '
    public function chargers(array $known): array { return $this->discoverForeignChargers($known); }
    public function heatpumps(array $known): array { return $this->discoverForeignHeatpumps($known); }
    public function merge(array $e): array { return $this->mergeForeignHeatpumps($e); }
    public function raw(string $t): array { return $this->discoverForeignByType($t); }
}');

$fail = 0;
function check(string $n, bool $ok): void { global $fail; echo ($ok ? '  ok    ' : '  FEHLER ') . $n . "\n"; if (!$ok) { $fail++; } }
$lab = fn (array $r) => array_map(fn ($e) => $e['label'], $r);

echo "Wallboxen\n";
$t = new FsStub();
$r = $t->chargers([['deviceSerial' => 'XYZ']]);
check('gefunden: Easee Carport, nur-function-Eintrag, Einzeleintrag als JSON', $lab($r) === ['Easee Carport', 'nur function', 'Einzeleintrag als JSON']);
check('gleiche Seriennummer innerhalb der Fremdsuche nur einmal', !in_array('Easee Doppel', $lab($r), true));
check('Quelle ext:<Präfix> und Instanz-ID', $r[0]['source'] === 'ext:EASEE' && $r[2]['source'] === 'ext:NEU' && $r[0]['instanceID'] === 101);
check('function ergänzt', $r[0]['function'] === 'charger');
check('Seriennummer schon bekannt: Easee-Einträge entfallen', array_diff($lab((new FsStub())->chargers([['deviceSerial' => 'EH123']])), ['nur function', 'Einzeleintrag als JSON']) === []);
check('eigenes Präfix CHUB wird nicht gesucht', !in_array('eigene', $lab($r), true));
check('duplicateOf, Version 2, ohne Leistung, anderer Typ entfallen', array_intersect($lab($r), ['Easee Dublette', 'Zu neu', 'ohne Leistung', 'Zähler']) === []);
check('Signaturfehler: kein Debug-Eintrag, werfendes Modul: genau einer', count(array_filter($t->debug, fn ($m) => strpos($m, 'SIG') !== false)) === 0 && count(array_filter($t->debug, fn ($m) => strpos($m, 'BOOM #104') === 0)) === 1);
check('kein Gerätemodul (IO), deaktivierte Instanz, Müll-Antwort entfallen', !array_intersect([9002, 9003], array_column($t->raw('charger'), 'powerID')));

echo "Wärmepumpen (WPISM8)\n";
$h = new FsStub();
$hp = $h->heatpumps([['function' => 'heatpump', 'powerID' => 6004]]);
check('nur heatpump-Einträge, nie ventilation', !in_array('Wolf CWL', $lab($hp), true));
check('Wolf CHA, Altform (Type/Caption) und Objektform gefunden, bekannte Leistungsvariable nicht doppelt', $lab($hp) === ['Wolf CHA', 'Altform', 'Objektform']);
check('Quelle ext:WPISM8 und function heatpump', $hp[0]['source'] === 'ext:WPISM8' && $hp[0]['function'] === 'heatpump' && $hp[1]['function'] === 'heatpump');
check('eigenes Präfix WPHUB wird nicht gesucht', !in_array('eigene WP', $lab($hp), true));
$rawv = $h->raw('ventilation');
check('Typ ventilation getrennt abrufbar (gehört nicht zur Freigabe, Suche liefert ihn nur auf Anfrage)', $lab($rawv) === ['Wolf CWL']);
check('rohe Einträge tragen _instanceID und _extPrefix', $h->raw('heatpump')[0]['_instanceID'] === 110 && $h->raw('heatpump')[0]['_extPrefix'] === 'WPISM8');
echo "Wärmepumpen in WPMonitor/HeatSchema (Zusammenführung)\n";
$mg = (new FsStub())->merge([['Type' => 'heatpump', 'Caption' => 'fest', 'PowerID' => 6004], ['function' => 'heatpump', 'label' => 'fest2', 'powerID' => 7001]]);
$ml = array_map(fn ($e) => $e['label'] ?? $e['Caption'], $mg);
check('feste Einträge bleiben vorn, fremde folgen (Wolf CHA, Altform, Objektform), Doppel zu 6004 entfällt', $ml === ['fest', 'fest2', 'Wolf CHA', 'Altform', 'Objektform']);
check('kein ventilation-Eintrag in der Liste', !in_array('Wolf CWL', $ml, true));
check('ohne Fremdmodule bleibt die Liste unverändert', (function () { $GLOBALS['mods'] = []; $in = [['function' => 'heatpump', 'powerID' => 1]]; return (new FsStub())->merge($in) === $in; })());
echo $fail === 0 ? "Alles bestanden.\n" : "$fail Fehler.\n";
exit($fail === 0 ? 0 : 1);
