<?php

declare(strict_types=1);

// ===========================================================================
// Szenario-Lauf fuer NRGDashboardVentilation mit nachgebautem IPS (Stubs). Schreibt jeden sichtbaren Text als Zeilen.
//   php .tools/szenarien-ventilation.php <Wurzelordner der Bibliothek> <de|en>
// Wird von .tools/rahmen-referenz.php gestartet (Vergleich vorher/nachher); mit "en" laesst es sich nach dem Formular in englischer Sprache lesen.
// ===========================================================================

$root = $argv[1] ?? dirname(__DIR__);
$lang = $argv[2] ?? 'de';
$GLOBALS['LANG'] = $lang;
$locFile = $root . '/NRGDashboardVentilation/locale.json';
$GLOBALS['DE'] = is_file($locFile) ? (json_decode((string) file_get_contents($locFile), true)['translations']['de'] ?? []) : [];

const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
$GLOBALS['W'] = [];
$GLOBALS['LOG'] = [];
$GLOBALS['mods'] = [];
$GLOBALS['vars'] = [];

function ips_log(string $what, $data): void { $GLOBALS['LOG'][] = $what . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_GetModuleList() { return array_keys($GLOBALS['mods']); }
function IPS_GetModule($g) { return $GLOBALS['mods'][$g] ?? false; }
function IPS_GetInstanceListByModuleID($g)
{
    if ($g === '{B2D6F316-7D73-439F-BF31-98468254B043}') { return $GLOBALS['W']['siblings'] ?? []; }
    return $GLOBALS['mods'][$g]['inst'] ?? [];
}
function IPS_GetInstance($id) { return ['InstanceStatus' => 102, 'ModuleInfo' => ['ModuleID' => '']]; }
function IPS_InstanceExists($id) { return ($id >= 100 && $id < 1000) || in_array($id, $GLOBALS['W']['siblings'] ?? [], true) || $id === 1000; }
function IPS_GetName($id) { return 'Inst' . $id; }
function IPS_GetLibrary($guid) { return ['Version' => '0.15.0', 'Build' => 1]; }
function IPS_GetProperty($id, $n) { return '[]'; }
function IPS_SetProperty($id, $n, $v) {}
function IPS_ApplyChanges($id) {}
function IPS_VariableExists($id) { return isset($GLOBALS['vars'][$id]); }
function GetValue($id) { return $GLOBALS['vars'][$id][0]; }
function GetValueFormatted($id) { return $GLOBALS['vars'][$id][1]; }
function NRGDASHVENT_AckNewsApply($id) { throw new RuntimeException('Geschwister defekt'); }

class IPSModule
{
    public $InstanceID = 1000;
    public $props = [];
    public $attrs = [];
    public function __construct() {}
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
    public function SetStatus($s) { ips_log('SetStatus', $s); }
    public function UpdateVisualizationValue($v) { ips_log('UpdateVisualizationValue', json_decode($v, true)); }
    public function UpdateFormField($f, $k, $v) { ips_log('UpdateFormField ' . $f . '.' . $k, $v); }
    public function SendDebug($m, $t, $f) { ips_log('SendDebug ' . $m, $t); }
    public function Translate($t) { return $GLOBALS['LANG'] === 'de' ? ($GLOBALS['DE'][$t] ?? $t) : $t; }
}

/** Nachbau der Konsole: uebersetzt feste Formulartexte (nicht Listenzellen) */
function konsole($n)
{
    if (!is_array($n)) { return $n; }
    $o = [];
    foreach ($n as $k => $v) {
        if ($k !== 'values' && is_string($v) && in_array($k, ['caption', 'label', 'suffix', 'prefix'], true)) {
            $o[$k] = $GLOBALS['LANG'] === 'de' ? ($GLOBALS['DE'][$v] ?? $v) : $v;
        } else {
            $o[$k] = is_array($v) && $k !== 'values' ? konsole($v) : $v;
        }
    }
    return $o;
}

require_once $root . '/NRGDashboardVentilation/module.php';

function RLT_GetFunctions($id) { return [[
    'contractType' => 'ventilation', 'contractVersion' => '1.1', 'Caption' => 'Robatherm',
    'outsideTempID' => 501, 'supplyTempID' => 502, 'extractTempID' => 0, 'co2ID' => 503, 'heatRecoveryEfficiencyID' => 504,
    'fan1FlowID' => 505, 'fan2FlowID' => 506, 'fanFlowUnit' => 'm3/h', 'filterWarningID' => 507, 'faultSummaryID' => 508, 'filterRuntimeHoursID' => 509,
]]; }
function WPISM8_GetFunctions($id) { return [
    ['contractType' => 'heatpump', 'contractVersion' => '1.19', 'label' => 'Wolf CHA', 'powerID' => 700],
    ['contractType' => 'ventilation', 'contractVersion' => '1.1', 'label' => 'Wolf CWL', 'fan1FlowID' => 601, 'fanFlowUnit' => '%', 'filterWarningID' => 602],
]; }
$vars = [501 => [21.4, '21,4 °C'], 502 => [19.8, '19,8 °C'], 503 => [650.0, '650 ppm'], 504 => [78.0, '78 %'], 505 => [120.0, '120'], 506 => [55.0, '55 m³/h'],
    507 => [true, 'Ja'], 508 => [false, 'Nein'], 509 => [1500.0, '1500 h'], 601 => [18.0, '18'], 602 => [false, 'Nein']];
$mods = ['g-rlt' => ['ModuleType' => 3, 'Prefix' => 'RLT', 'inst' => [201]], 'g-ism8' => ['ModuleType' => 3, 'Prefix' => 'WPISM8', 'inst' => [202]]];

function szenario(string $name, array $welt, array $props, array $attrs, callable $aktion): void
{
    $GLOBALS['W'] = $welt;
    $GLOBALS['mods'] = $welt['mods'] ?? [];
    $GLOBALS['vars'] = $welt['vars'] ?? [];
    $GLOBALS['LOG'] = [];
    $m = new NRGDashboardVentilation();
    $m->Create();
    foreach ($props as $k => $v) { $m->props[$k] = $v; }
    foreach ($attrs as $k => $v) { $m->attrs[$k] = $v; }
    $m->ApplyChanges();
    ob_start();
    try { $aktion($m); } catch (Throwable $e) { echo 'AUSNAHME ' . get_class($e) . ': ' . $e->getMessage(); }
    $aus = (string) ob_get_clean();
    echo "=== $name\n";
    foreach ($GLOBALS['LOG'] as $l) { echo $l . "\n"; }
    if ($aus !== '') { echo "OUT $aus\n"; }
}
function formular(NRGDashboardVentilation $m): void
{
    echo 'FORM ' . json_encode(konsole(json_decode($m->GetConfigurationForm(), true)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}

szenario('keine Anlage, Standard', [], [], [], function ($m) { formular($m); $m->Render(); });
szenario('zwei Anlagen', ['mods' => $mods, 'vars' => $vars], [], [], function ($m) { $m->Render(); formular($m); });
szenario('eine Instanz gewaehlt', ['mods' => $mods, 'vars' => $vars], ['VentInstance' => 202], [], function ($m) { $m->Render(); });
szenario('alles weggeklickt', ['mods' => $mods, 'vars' => $vars], [], ['SeenNews' => '0.15.0', 'PurposeIntroGone' => true, 'ReviewHintDismissed' => true], function ($m) { $m->Render(); formular($m); });
szenario('Aufbau: Stil ausgeblendet', ['mods' => $mods, 'vars' => $vars], ['FormLayout' => json_encode([['Key' => 'style', 'Hidden' => true, 'Pos' => 1], ['Key' => 'source', 'Closed' => true, 'Pos' => 2]])], [], function ($m) { formular($m); });
szenario('Aufbau zuruecksetzen', [], [], [], function ($m) { echo $m->ResetFormLayout() . "\n"; });
szenario('Hinweise bestaetigen, Geschwister defekt', ['siblings' => [1000, 1001]], [], [], function ($m) { $m->AckNews(); $m->AckPurposeIntro(); $m->DismissReviewHint(); });
szenario('Standalone-Seite', ['mods' => $mods, 'vars' => $vars], [], [], function ($m) { $_GET = ['json' => '1']; @$m->ProcessHookData(); });

$GLOBALS['W'] = ['mods' => $mods, 'vars' => $vars];
$GLOBALS['mods'] = $mods; $GLOBALS['vars'] = $vars;
$m = new NRGDashboardVentilation();
$m->Create();
$m->ApplyChanges();
$html = $m->GetVisualizationTile();
preg_match('/window\.NRGDASH_TEXTS = (\{.*?\});<\/script>/s', $html, $mm);
echo "=== Kachel-Texttabelle\n" . ($mm[1] ?? 'FEHLT') . "\n";
