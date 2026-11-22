<?php

declare(strict_types=1);

// Prüfstand für NRGDashboardVentilation (Lüftungskachel, 0.15.0). Aufruf: php tests/lueftung.test.php
// Prüft die Nutzlast: je Eintrag nach Vertragstyp 'ventilation', nicht gelieferte Felder ausgeblendet, Einheit der Ventilator-Volumenströme,
// Filterwarnung/Störung, Frische, Filter auf eine Instanz.
const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
$GLOBALS['mods'] = [];
$GLOBALS['vars'] = [];
$GLOBALS['now'] = time();
function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_GetModuleList() { return array_keys($GLOBALS['mods']); }
function IPS_GetModule($g) { return $GLOBALS['mods'][$g] ?? false; }
function IPS_GetInstanceListByModuleID($g) { return $GLOBALS['mods'][$g]['inst'] ?? []; }
function IPS_GetInstance($id) { return ['InstanceStatus' => 102]; }
function IPS_InstanceExists($id) { return $id >= 100 && $id < 1000; }
function IPS_GetName($id) { return 'Inst' . $id; }
function IPS_GetLibrary($g) { return ['Version' => '0.15.0', 'Build' => 1]; }
function IPS_VariableExists($id) { return isset($GLOBALS['vars'][$id]); }
function GetValue($id) { return $GLOBALS['vars'][$id][0]; }
function GetValueFormatted($id) { return $GLOBALS['vars'][$id][1]; }
class IPSModule
{
    public $InstanceID = 1000;
    public $props = [];
    public $attrs = [];
    public $log = [];
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyInteger($n, $v) { $this->props[$n] = $v; }
    public function RegisterPropertyString($n, $v) { $this->props[$n] = $v; }
    public function RegisterPropertyBoolean($n, $v) { $this->props[$n] = $v; }
    public function RegisterAttributeString($n, $v) { $this->attrs[$n] = $v; }
    public function RegisterAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    public function RegisterAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    public function ReadPropertyInteger($n) { return $this->props[$n]; }
    public function ReadPropertyString($n) { return $this->props[$n]; }
    public function ReadPropertyBoolean($n) { return $this->props[$n]; }
    public function ReadAttributeString($n) { return $this->attrs[$n]; }
    public function ReadAttributeBoolean($n) { return $this->attrs[$n]; }
    public function ReadAttributeInteger($n) { return $this->attrs[$n]; }
    public function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    public function RegisterTimer($n, $i, $s) {}
    public function SetTimerInterval($n, $i) {}
    public function SetVisualizationType($t) {}
    public function RegisterMessage($a, $b) {}
    public function SetStatus($s) { $this->log[] = 'SetStatus ' . $s; }
    public function UpdateVisualizationValue($v) { $this->log[] = 'UVV'; }
    public function UpdateFormField($f, $k, $v) {}
    public function SendDebug($m, $t, $f) { $this->log[] = 'Debug ' . $t; }
    public function Translate($t) { return $t; }
}
require_once __DIR__ . '/../NRGDashboardVentilation/module.php';

$fail = 0;
function check(string $n, bool $ok): void { global $fail; echo ($ok ? '  ok    ' : '  FEHLER ') . $n . "\n"; if (!$ok) { $fail++; } }
function payload(NRGDashboardVentilation $m): array { $r = new ReflectionMethod($m, 'buildPayload'); return $r->invoke($m); }

// Variablen: [Wert, formatierter Text]
$GLOBALS['vars'] = [
    501 => [21.4, '21,4 °C'], 502 => [19.8, '19,8 °C'], 503 => [650.0, '650 ppm'], 504 => [78.0, '78 %'],
    505 => [120.0, '120'], 506 => [55.0, '55 m³/h'], 507 => [true, 'Ja'], 508 => [false, 'Nein'], 509 => [1500.0, '1500 h'],
    601 => [18.0, '18 °C'], 602 => [true, 'Ja'],
];
$GLOBALS['mods'] = [
    'g-rlt'  => ['ModuleType' => 3, 'Prefix' => 'RLT', 'inst' => [201]],
    'g-ism8' => ['ModuleType' => 3, 'Prefix' => 'WPISM8', 'inst' => [202]],
    'g-none' => ['ModuleType' => 3, 'Prefix' => 'ANDERS', 'inst' => [203]],
];
function RLT_GetFunctions($id) { return [[
    'contractType' => 'ventilation', 'contractVersion' => '1.1', 'Caption' => 'Robatherm',
    'outsideTempID' => 501, 'supplyTempID' => 502, 'extractTempID' => 0, 'co2ID' => 503, 'heatRecoveryEfficiencyID' => 504,
    'fan1FlowID' => 505, 'fan2FlowID' => 506, 'fanFlowUnit' => 'm3/h', 'filterWarningID' => 507, 'faultSummaryID' => 508,
    'filterRuntimeHoursID' => 509, 'lastSeenAt' => $GLOBALS['now'] - 30,
]]; }
function WPISM8_GetFunctions($id) { return [
    ['contractType' => 'heatpump', 'contractVersion' => '1.19', 'label' => 'Wolf CHA', 'powerID' => 700],
    ['contractType' => 'ventilation', 'contractVersion' => '1.1', 'label' => 'Wolf CWL', 'outsideTempID' => 0, 'supplyTempID' => 0, 'fan1FlowID' => 601, 'fanFlowUnit' => '%',
     'filterWarningID' => 602, 'lastSeenAt' => $GLOBALS['now'] - 4000],
]; }
function ANDERS_GetFunctions($id) { return [['contractType' => 'ventilation', 'contractVersion' => '2.0', 'label' => 'Zu neu']]; }

$m = new NRGDashboardVentilation();
$m->Create();
$p = payload($m);
check('zwei Lüftungsanlagen gefunden (RLT, WPISM8), Version 2 und heatpump nicht', $p['ok'] === true && count($p['units']) === 2);
$u = $p['units'][0];
check('Name aus Caption', $u['label'] === 'Robatherm');
$keys = array_column($u['rows'], 'key');
check('gelieferte Felder erscheinen, extractTempID 0 nicht', $keys === ['outside', 'supply', 'co2', 'wrg', 'fan1', 'fan2', 'filterHours']);
check('Texte vom Profil (Temperatur)', $u['rows'][0]['text'] === '21,4 °C' && $u['rows'][0]['value'] === 21.4);
check('Ventilator ohne Einheit im Profil: Einheit aus fanFlowUnit (m³/h)', $u['rows'][4]['text'] === '120 m³/h');
check('Ventilator 2 mit Einheit schon im Profil (55 m³/h) bleibt unverändert, nicht doppelt', $u['rows'][5]['text'] === '55 m³/h');
check('Filterwarnung true, Störung false', $u['filterWarn'] === true && $u['fault'] === false);
check('frisch (30 s): nicht veraltet', $u['stale'] === false);
$w = $p['units'][1];
check('WPISM8: nur Lüftungseintrag, Name aus label', $w['label'] === 'Wolf CWL');
check('WPISM8: Temperaturen 0 ausgeblendet, nur Ventilator 1', array_column($w['rows'], 'key') === ['fan1']);
check('Einheit % : "18 °C"-Profil enthält kein %, also angehängt', $w['rows'][0]['text'] === '18 %');
check('WPISM8: Störung nicht geliefert = null, Filterwarnung true', $w['fault'] === null && $w['filterWarn'] === true);
check('lastSeenAt älter als 10 min: veraltet', $w['stale'] === true);
check('Quelle ext:RLT', $u['source'] === 'ext:RLT');

$m->props['VentInstance'] = 202;
$p2 = payload($m);
check('Filter auf eine Instanz', count($p2['units']) === 1 && $p2['units'][0]['label'] === 'Wolf CWL');
$m->props['VentInstance'] = 0;
$GLOBALS['mods'] = [];
$p3 = payload($m);
check('keine Anlage: ok false mit Meldung', $p3['ok'] === false && $p3['units'] === [] && !empty($p3['error']));
$m->Render();
check('ohne Anlage Status 104', in_array('SetStatus 104', $m->log, true));
echo $fail === 0 ? "Alles bestanden.\n" : "$fail Fehler.\n";
exit($fail === 0 ? 0 : 1);
