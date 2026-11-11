<?php

declare(strict_types=1);

// ===========================================================================
// Szenario-Lauf fuer NRGDashboardPVMonitor mit nachgebautem IPS (Stubs). Schreibt jeden sichtbaren Text als Zeilen.
//   php .tools/szenarien-pvmonitor.php <Wurzelordner der Bibliothek> <de|en>
// Wird von .tools/deutsch-vergleich-pvmonitor.php zweimal gestartet (alter Stand aus git, aktueller Stand); mit "en" laesst
// sich nach dem Formular und den Meldungen in englischer Sprache lesen. Der Uebersetzer ist die gebaute locale.json des Moduls
// (wie Symcons Translate()); was die Konsole selbst uebersetzt (caption/label/... im Formular), wird in konsole() nachgebaut.
// ===========================================================================

$root = $argv[1] ?? dirname(__DIR__);
$lang = $argv[2] ?? 'de';
$GLOBALS['LANG'] = $lang;
$locFile = $root . '/NRGDashboardPVMonitor/locale.json';
$GLOBALS['DE'] = is_file($locFile) ? (json_decode((string) file_get_contents($locFile), true)['translations']['de'] ?? []) : [];

const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const KL_WARNING = 103;
const VARIABLETYPE_INTEGER = 1;
const EMS_GUID_P = '{31C61A7B-28C4-4F97-9651-1A64B3469E3C}';
const IHUB_GUID_P = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';
const SELF_GUID_P = '{E1A674D1-F48F-492D-B172-F8B9390BFEB3}';

$GLOBALS['W'] = [];
$GLOBALS['LOG'] = [];
$GLOBALS['VARS'] = [];

function ips_log(string $what, $data): void
{
    $GLOBALS['LOG'][] = $what . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_GetInstanceListByModuleID($guid)
{
    $W = $GLOBALS['W'];
    if ($guid === EMS_GUID_P) {
        return array_keys($W['ems'] ?? []);
    }
    if ($guid === IHUB_GUID_P) {
        return $W['ihub'] ?? [];
    }
    if ($guid === SELF_GUID_P) {
        return $W['siblings'] ?? [];
    }
    return [];
}
function IPS_InstanceExists($id) { return isset($GLOBALS['W']['ems'][$id]) || in_array($id, $GLOBALS['W']['ihub'] ?? [], true) || in_array($id, $GLOBALS['W']['siblings'] ?? [], true) || $id === 1000; }
function IPS_GetInstance($id)
{
    $e = $GLOBALS['W']['ems'][$id] ?? ['status' => 102];
    return ['InstanceStatus' => $e['status'], 'ModuleInfo' => ['ModuleID' => EMS_GUID_P]];
}
function IPS_GetName($id) { return $GLOBALS['W']['ems'][$id]['name'] ?? 'Inst' . $id; }
function IPS_ObjectExists($id) { return IPS_InstanceExists($id) || isset($GLOBALS['VARS'][$id]); }
function IPS_VariableExists($id) { return isset($GLOBALS['VARS'][$id]); }
function IPS_GetLibrary($guid) { return ['Version' => '0.10.12', 'Build' => 135]; }
function IPS_GetProperty($id, $n) { return '[]'; }
function IPS_SetProperty($id, $n, $v) {}
function IPS_ApplyChanges($id) {}
function IPS_GetObjectIDByIdent($i, $p) { return false; }
function IPS_VariableProfileExists($n) { return true; }
function IPS_CreateVariableProfile($n, $t) {}
function IPS_SetVariableProfileAssociation($n, $v, $name, $icon, $color) { ips_log('Profil ' . $n . ' ' . $v, $name); }
function IPS_GetChildrenIDs($id) { return []; }
function IPS_GetObject($id) { return ['ObjectIdent' => '', 'ObjectName' => 'x', 'ObjectType' => 2]; }
function IPS_GetVariable($id) { return ['VariableType' => 2, 'VariableProfile' => '', 'VariableCustomProfile' => '']; }
function AC_GetLoggingStatus($a, $v) { return true; }
function GetValue($id) { return 0; }
function EMS_GetCurrentDecision($id) { return $GLOBALS['W']['decision'] ?? null; }
function NRGDASHPVMON_AckNewsApply($id) { throw new RuntimeException('Geschwister defekt'); }

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
    public function RegisterPropertyFloat($n, $v) { $this->props[$n] = $v; }
    public function RegisterAttributeString($n, $v) { $this->attrs[$n] = $v; }
    public function RegisterAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    public function RegisterAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    public function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { ips_log('RegisterVariableInteger ' . $i, $n); }
    public function EnableAction($i) {}
    public function SetValue($i, $v) {}
    public function GetValue($i) { return 0; }
    public function ReadPropertyInteger($n) { return $this->props[$n] ?? 0; }
    public function ReadPropertyString($n) { return $this->props[$n] ?? ''; }
    public function ReadPropertyBoolean($n) { return $this->props[$n] ?? false; }
    public function ReadPropertyFloat($n) { return $this->props[$n] ?? 0.0; }
    public function ReadAttributeString($n) { return $this->attrs[$n] ?? ''; }
    public function ReadAttributeBoolean($n) { return $this->attrs[$n] ?? false; }
    public function ReadAttributeInteger($n) { return $this->attrs[$n] ?? 0; }
    public function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    public function RegisterTimer($n, $i, $s) {}
    public function SetTimerInterval($n, $i) {}
    public function SetVisualizationType($t) {}
    public function RegisterMessage($a, $b) {}
    public function SetStatus($s) {}
    public function UpdateVisualizationValue($v) { ips_log('UpdateVisualizationValue', 'Payload'); }
    public function UpdateFormField($f, $k, $v) { ips_log('UpdateFormField ' . $f . '.' . $k, $v); }
    public function SendDebug($m, $t, $f) { ips_log('SendDebug ' . $m, $t); }
    public function LogMessage($m, $t) { ips_log('LogMessage', $m); }
    public function Translate($t) { return $GLOBALS['LANG'] === 'de' ? ($GLOBALS['DE'][$t] ?? $t) : $t; }
}

/** Nachbau der Konsole: uebersetzt feste Formulartexte (nicht Listenzellen) */
function konsole($n)
{
    if (!is_array($n)) {
        return $n;
    }
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

require_once $root . '/NRGDashboardPVMonitor/module.php';

function szenario(string $name, array $welt, array $props, array $attrs, callable $aktion): void
{
    $GLOBALS['W'] = $welt;
    $GLOBALS['LOG'] = [];
    $m = new NRGDashboardPVMonitor();
    $m->Create();
    foreach ($props as $k => $v) { $m->props[$k] = $v; }
    foreach ($attrs as $k => $v) { $m->attrs[$k] = $v; }
    $m->ApplyChanges();
    ob_start();
    try {
        $aktion($m);
    } catch (Throwable $e) {
        echo 'AUSNAHME ' . get_class($e) . ': ' . $e->getMessage();
    }
    $aus = (string) ob_get_clean();
    echo "=== $name\n";
    foreach ($GLOBALS['LOG'] as $l) { echo $l . "\n"; }
    if ($aus !== '') { echo "OUT $aus\n"; }
}
function formular(NRGDashboardPVMonitor $m): void
{
    // Zeilen einzeln, damit ein Unterschied sofort auffaellt
    $f = konsole(json_decode($m->GetConfigurationForm(), true));
    echo 'FORM ' . json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}
function privat(object $m, string $name, ...$args)
{
    $r = new ReflectionMethod($m, $name);
    return $r->invoke($m, ...$args);
}

$ems1 = [2000 => ['status' => 102, 'name' => 'EMS']];

szenario('Standard ohne Partner', [], [], [], function ($m) { formular($m); echo $m->ResetTour() . "\n"; echo $m->ResetBalanceColors() . "\n"; });
szenario('eine EMS aktiv', ['ems' => $ems1], [], [], function ($m) { formular($m); });
szenario('EMS nicht aktiv', ['ems' => [2000 => ['status' => 104, 'name' => 'EMS']]], [], [], function ($m) { formular($m); });
szenario('zwei InverterHub', ['ihub' => [3000, 3001]], [], [], function ($m) { formular($m); });
szenario('alles weggeklickt', ['ems' => $ems1], [], ['SeenNews' => '0.10.17', 'PurposeIntroGone' => true, 'ReviewHintDismissed' => true], function ($m) { formular($m); });
szenario('nur 0.10.16 und 0.10.17 neu', ['ems' => $ems1], [], ['SeenNews' => '0.10.15'], function ($m) { formular($m); });
szenario('nur 0.10.17 neu', [], [], ['SeenNews' => '0.10.16'], function ($m) { formular($m); });
szenario('Hinweise bestaetigen, Geschwister defekt', ['ems' => $ems1, 'siblings' => [1000, 1001]], [], [], function ($m) { $m->AckNews(); $m->AckPurposeIntro(); $m->DismissReviewHint(); });
szenario('manueller Plan: keine EMS', [], [], [], function ($m) { echo json_encode(privat($m, 'BuildManualPlan'), JSON_UNESCAPED_UNICODE) . "\n"; });
szenario('manueller Plan: EMS ohne Funktion', ['ems' => $ems1], [], [], function ($m) { echo json_encode(privat($m, 'BuildManualPlan'), JSON_UNESCAPED_UNICODE) . "\n"; });
szenario('Entscheidungs-Flags', ['ems' => $ems1, 'decision' => ['dryRun' => true, 'observeOnly' => false, 'observeReason' => '']], [], [], function ($m) { echo json_encode(privat($m, 'BuildDecisionFlags', 2000)) . "\n"; });
szenario('Entscheidungs-Flags beobachtend', ['ems' => $ems1, 'decision' => ['dryRun' => false, 'observeOnly' => true, 'observeReason' => 'Grund']], [], [], function ($m) { echo json_encode(privat($m, 'BuildDecisionFlags', 2000)) . "\n"; });
szenario('Entscheidung ohne Felder (kein Textrueckfall mehr)', ['ems' => $ems1, 'decision' => ['reason' => 'Trockenlauf - x']], [], [], function ($m) { echo json_encode(privat($m, 'BuildDecisionFlags', 2000)) . "\n"; });

// Kachel: Texttabelle (nur neuer Stand)
if (is_file($root . '/libs/NRGDASH_Text.php')) {
    $GLOBALS['W'] = ['ems' => $ems1];
    $m = new NRGDashboardPVMonitor();
    $m->Create();
    $m->ApplyChanges();
    try {
        $html = $m->GetVisualizationTile();
    } catch (Throwable $e) {
        $html = '';
        echo 'Kachel-AUSNAHME ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
    preg_match('/window\.NRGDASH_TEXTS = (\{.*?\});<\/script>/s', $html, $mm);
    echo "=== Kachel-Texttabelle\n" . ($mm[1] ?? 'FEHLT') . "\n";
}
