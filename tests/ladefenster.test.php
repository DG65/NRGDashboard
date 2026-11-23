<?php

declare(strict_types=1);

// Prüfstand für die Ladefenster je Wallbox im Tagesplan (PV-Monitoring, 23.11.2026): NRGDashboardPVMonitor::chargeSegments(),
// clipPlanWindows(), WallboxLanes() und die Kachel-Hilfen planLanesForDay()/wallboxLaneSeries()/planLaneLines(). Aufruf: php tests/ladefenster.test.php
require_once __DIR__ . '/../libs/NRGDASH_Text.php';
require_once __DIR__ . '/../libs/ForeignDiscovery.php';
$src = file_get_contents(__DIR__ . '/../NRGDashboardPVMonitor/module.php');
$a = strpos($src, '    /** Wallbox-Quellen fuer die Ladefenster im Tagesplan');
$b = strpos($src, '    private function BuildDayPlan(): array');
if ($a === false || $b === false || $b < $a) { fwrite(STDERR, "Methoden nicht gefunden\n"); exit(2); }
$code = substr($src, $a, $b - $a);

$GLOBALS['inst'] = [];
$GLOBALS['mods'] = [];
function IPS_GetInstanceListByModuleID($g) { return $GLOBALS['inst'][$g] ?? ($GLOBALS['mods'][$g]['inst'] ?? []); }
function IPS_InstanceExists($id) { return true; }
function IPS_GetName($id) { return 'Inst' . $id; }
function IPS_GetModuleList() { return array_keys($GLOBALS['mods']); }
function IPS_GetModule($g) { return $GLOBALS['mods'][$g] ?? false; }
function IPS_GetInstance($id) { return ['InstanceStatus' => 102]; }
function CHUB_GetFunctions($id) { return [
    ['function' => 'charger', 'label' => 'WB 1', 'powerID' => 11, 'calibrating' => true, 'measuredMaxPowerW' => 11040.4,
     'planWindows' => [['from' => 1000, 'to' => 4600, 'powerW' => 7360], ['from' => 9000, 'to' => 9000], ['from' => 'x', 'to' => 5], ['from' => 200000, 'to' => 210000]]],
    ['function' => 'charger', 'label' => 'WB 2', 'powerID' => 12],
    ['function' => 'charger', 'label' => 'WB 3', 'powerID' => 13, 'duplicateOf' => 'x'],
    ['function' => 'charger', 'label' => 'ohne alles', 'powerID' => 14],
]; }
function OHUB_GetFunctions($id) { return [['function' => 'charger', 'label' => 'WB 1 (OCPP)', 'powerID' => 11]]; } // gleiche Leistungsvariable wie CHUB: Doppel
function EXT_GetFunctions($id) { return [['contractType' => 'charger', 'contractVersion' => '1.13', 'label' => 'Easee', 'powerID' => 21, 'planWindows' => [['from' => 2000, 'to' => 3000, 'powerW' => 3700]], 'deviceSerial' => 'EH1']]; }
$GLOBALS['inst'] = ['{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}' => [101], '{81D3E328-9E12-43A9-825A-F7888530868C}' => [102]];
$GLOBALS['mods'] = ['g-ext' => ['ModuleType' => 3, 'Prefix' => 'EXT', 'inst' => [201]]];

eval('final class LfStub {
    use NRGDashForeignDiscovery;
    public array $series = [];
    public array $debug = [];
    private function SendDebug($a, $b, $c): void { $this->debug[] = $b; }
    private function ArchiveID(): int { return 5; }
    private function DaySeries(int $aid, int $vid, int $start, int $end): array { return $this->series[$vid] ?? []; }
' . $code . '
    public static function seg(array $s): array { return self::chargeSegments($s); }
    public static function clip($w, int $f, int $t): array { return self::clipPlanWindows($w, $f, $t); }
    public function lanes(int $f, int $t, int $a, bool $p): array { return $this->WallboxLanes($f, $t, $a, $p); }
}');

$fail = 0;
function check(string $n, bool $ok): void { global $fail; echo ($ok ? '  ok    ' : '  FEHLER ') . $n . "\n"; if (!$ok) { $fail++; } }

echo "Ladevorgänge aus der Leistung\n";
$M = 60000;
check('zwei getrennte Ladevorgänge, Ende = letzter Punkt + 5 min', LfStub::seg([[0, 7000.0], [5 * $M, 7000.0], [10 * $M, 50.0], [200 * $M, 3000.0]]) === [[0, 10 * $M], [200 * $M, 205 * $M]]);
check('Lücke bis 15 min gehört zum selben Vorgang', LfStub::seg([[0, 5000.0], [15 * $M, 5000.0]]) === [[0, 20 * $M]]);
check('Lücke über 15 min trennt', count(LfStub::seg([[0, 5000.0], [25 * $M, 5000.0]])) === 2);
check('unter 100 W ist kein Laden, leere Reihe ist leer', LfStub::seg([[0, 99.0], [5 * $M, 100.0]]) === [] && LfStub::seg([]) === []);

echo "Geplante Fenster (planWindows)\n";
$w = [['from' => 1000, 'to' => 4000, 'powerW' => 7360.4], ['from' => 3500, 'to' => 9000], ['from' => 5, 'to' => 5], ['from' => 'a', 'to' => 9], 'kaputt'];
check('auf den Zeitraum zugeschnitten, in Millisekunden, mit Leistung', LfStub::clip($w, 2000, 8000) === [[2000000, 4000000, 7360], [3500000, 8000000, null]]);
check('keine Liste oder kaputte Einträge: leer', LfStub::clip(null, 0, 10) === [] && LfStub::clip('x', 0, 10) === [] && LfStub::clip([['from' => 9, 'to' => 3]], 0, 100) === []);
check('Fenster außerhalb des Zeitraums entfällt', LfStub::clip([['from' => 100, 'to' => 200]], 300, 400) === []);

echo "Streifen je Wallbox\n";
$t = new LfStub();
$t->series = [11 => [[0, 6000.0], [300000, 6000.0]], 12 => [[0, 3000.0]], 21 => [], 13 => [[0, 9000.0]]];
$lanes = $t->lanes(0, 100000, 100000, true);
$names = array_column($lanes, 'label');
check('WB 1 (Plan + Archiv), WB 2 (nur Archiv), Easee (nur Plan); Doppel, duplicateOf und leere Wallboxen entfallen', $names === ['WB 1', 'WB 2', 'Easee']);
check('WB 1: Plan zugeschnitten, geladen aus dem Archiv, kalibriert, gemessene Maximalleistung gerundet', $lanes[0]['plan'] === [[1000000, 4600000, 7360]] && $lanes[0]['actual'] === [[0, 600000]] && $lanes[0]['calibrating'] === true && $lanes[0]['maxPowerW'] === 11040);
check('WB 2: kein Plan, kein calibrating-Feld = null', $lanes[1]['plan'] === [] && $lanes[1]['calibrating'] === null && $lanes[1]['maxPowerW'] === null);
check('Fremdmodul (EXT): Plan, ohne Archivreihe', $lanes[2]['plan'] === [[2000000, 3000000, 3700]] && $lanes[2]['actual'] === []);
$past = $t->lanes(0, 100000, 100000, false);
check('Rückblick (ohne Plan): nur das Geladene', array_column($past, 'label') === ['WB 1', 'WB 2'] && $past[0]['plan'] === []);
$t2 = new LfStub();
check('ohne Archivdaten und ohne Plan kein Streifen', array_column($t2->lanes(0, 100000, 0, true), 'label') === ['WB 1', 'Easee']);

echo "Kachel-Hilfen (node)\n";
$node = trim((string) @shell_exec('command -v node'));
if ($node === '') {
    echo "  Hinweis: node nicht gefunden, JS-Teil übersprungen.\n";
} else {
    $html = (string) file_get_contents(__DIR__ . '/../NRGDashboardPVMonitor/module.html');
    $x = strpos($html, '    function planLanesForDay(lanes, startMs, endMs) {');
    $y = strpos($html, '    function planSeries(dp) {');
    $js = substr($html, $x, $y - $x) . <<<'JS'

var tr = function (s) { return s; };
var sf = function (s) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return s.replace(/%[sd]/g, function () { return a[i++]; }); };
var DAY = 86400000, S0 = 1700000000000 - (1700000000000 % DAY), S1 = S0 + DAY;
var lanes = [
  { label: 'WB 1', calibrating: false, maxPowerW: 11000, plan: [[S0 + 3600000, S0 + 7200000, 7360], [S1 + 3600000, S1 + 5400000, null]], actual: [[S0 + 1000, S0 + 900000]] },
  { label: 'WB 2', calibrating: true, maxPowerW: null, plan: [], actual: [[S1 + 100, S1 + 200]] }
];
var heute = planLanesForDay(lanes, S0, S1);
var morgen = planLanesForDay(lanes, S1, S1 + DAY);
var ser = wallboxLaneSeries(heute);
var r = {};
r.heuteWallboxen = heute.map(function (l) { return l.label; });
r.heutePlan = heute[0].plan;
r.morgenWallboxen = morgen.map(function (l) { return l.label; });
r.morgenWB1Plan = morgen[0] && morgen[0].plan;
r.serienNamen = ser.map(function (s) { return s.name; });
r.serieLane = ser.every(function (s) { return s.lane === true && s.axis === 3; });
r.serienFarben = ser.map(function (s) { return s.color; });
r.punkte = ser[0].data;
r.zweiterStreifenHoeher = ser.length === 2 && ser[1].data[0][1] === ser[0].data[0][1];
var ser2 = wallboxLaneSeries(morgen);
r.reiheZweiteBox = ser2[ser2.length - 1].data[0][1] < ser2[0].data[0][1];
var dp = { wallboxes: heute };
r.tipp = planLaneLines(dp, S0 + 3600000 + 1800000).join(' | ').replace(/\d\d:\d\d/g, 'HH:MM');
r.tippGeladen = planLaneLines(dp, S0 + 60000).length;
r.tippLeer = planLaneLines(dp, S0 + 12 * 3600000).length;
console.log(JSON.stringify(r));
JS;
    $f = sys_get_temp_dir() . '/ladefenster-' . getmypid() . '.js';
    file_put_contents($f, $js);
    $o = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($f) . ' 2>&1'), true);
    unlink($f);
    if (!is_array($o)) { check('node-Lauf lesbar', false); }
    else {
        check('heute: nur WB 1 (WB 2 hat heute nichts), Plan auf den Tag zugeschnitten', $o['heuteWallboxen'] === ['WB 1'] && count($o['heutePlan']) === 1);
        check('morgen: beide Wallboxen, WB 1 mit Plan', $o['morgenWallboxen'] === ['WB 1', 'WB 2'] && count($o['morgenWB1Plan']) === 1);
        check('Serien heißen „WB 1 (planned)“ und „WB 1 (charged)“, liegen auf der versteckten Achse 3', $o['serienNamen'] === ['WB 1 (planned)', 'WB 1 (charged)'] && $o['serieLane'] === true);
        check('Farben: geplant türkis, geladen grün', $o['serienFarben'] === ['#26a69a', '#66bb6a']);
        check('Linienstück: Anfang, Ende und Lücke (null)', count($o['punkte']) === 3 && $o['punkte'][2][1] === null && $o['punkte'][0][1] === $o['punkte'][1][1]);
        check('beide Linien einer Wallbox auf gleicher Höhe, weitere Wallbox darunter', $o['zweiterStreifenHoeher'] === true && $o['reiheZweiteBox'] === true);
        check('Tooltip: geplantes Fenster mit Leistung', $o['tipp'] === 'WB 1: planned charging HH:MM–HH:MM (7.4 kW)');
        check('Tooltip: Geladenes erscheint, außerhalb nichts', $o['tippGeladen'] === 1 && $o['tippLeer'] === 0);
    }
}
echo $fail === 0 ? "Alles bestanden.\n" : "$fail Fehler.\n";
exit($fail === 0 ? 0 : 1);
