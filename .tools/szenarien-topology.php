<?php

declare(strict_types=1);

// ===========================================================================
// Szenario-Lauf fuer NRGDashboardTopology mit nachgebautem IPS (Stubs). Schreibt jeden sichtbaren Text als JSON-Zeilen.
//   php .tools/szenarien-topology.php <Wurzelordner der Bibliothek> <de|en>
// Wird von .tools/deutsch-vergleich.php zweimal gestartet (alter Stand aus git, aktueller Stand) und die Ausgaben werden
// verglichen; mit "en" laesst es sich nach dem Formular in englischer Sprache lesen.
// Der Uebersetzer ist die gebaute locale.json des Moduls (so wie Symcons Translate()); was die Symcon-Konsole selbst
// uebersetzt (caption/label/... im Formular), wird hier nachgebaut (konsole()). Fehlt locale.json (alter Stand): nichts davon.
// ===========================================================================

$root = $argv[1] ?? dirname(__DIR__);
$lang = $argv[2] ?? 'de';
$GLOBALS['LANG'] = $lang;
$locFile = $root . '/NRGDashboardTopology/locale.json';
$GLOBALS['DE'] = is_file($locFile) ? (json_decode((string) file_get_contents($locFile), true)['translations']['de'] ?? []) : [];

const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const EMS_GUID_T = '{31C61A7B-28C4-4F97-9651-1A64B3469E3C}';
const SELF_GUID_T = '{28EEBB3F-A735-4CE6-B879-6408A0EC4A68}';

/** Zustand der nachgebauten Anlage, je Szenario neu gesetzt */
$GLOBALS['W'] = [];
$GLOBALS['LOG'] = [];

function ips_log(string $what, $data): void
{
    $GLOBALS['LOG'][] = $what . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_GetInstanceListByModuleID($guid)
{
    $W = $GLOBALS['W'];
    if ($guid === EMS_GUID_T) {
        return array_keys($W['ems'] ?? []);
    }
    if ($guid === SELF_GUID_T) {
        return $W['siblings'] ?? [];
    }
    return [];
}
function IPS_InstanceExists($id) { return isset($GLOBALS['W']['ems'][$id]) || in_array($id, $GLOBALS['W']['siblings'] ?? [], true) || $id === 1000; }
function IPS_GetInstance($id)
{
    $e = $GLOBALS['W']['ems'][$id] ?? ['status' => 102];
    return ['InstanceStatus' => $e['status'], 'ModuleInfo' => ['ModuleID' => EMS_GUID_T]];
}
function IPS_GetName($id) { return $GLOBALS['W']['ems'][$id]['name'] ?? 'Inst' . $id; }
function IPS_ObjectExists($id) { return IPS_InstanceExists($id); }
function IPS_GetLibrary($guid) { return ['Version' => '0.10.12', 'Build' => 135]; }
function IPS_GetProperty($id, $n) { return '[]'; }
function IPS_SetProperty($id, $n, $v) {}
function IPS_ApplyChanges($id) {}
function EMS_GetFederationHealth($id) { return $GLOBALS['W']['health'] ?? null; }
function NRGDASHTOPO_AckNewsApply($id) { throw new RuntimeException('Geschwister defekt'); }

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

require_once $root . '/NRGDashboardTopology/module.php';

function szenario(string $name, array $welt, array $props, array $attrs, callable $aktion): void
{
    $GLOBALS['W'] = $welt;
    $GLOBALS['LOG'] = [];
    $m = new NRGDashboardTopology();
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
function formular(NRGDashboardTopology $m): void
{
    echo 'FORM ' . json_encode(konsole(json_decode($m->GetConfigurationForm(), true)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}

$entry = fn (string $mod, int $id, bool $healthy, int $status = 102, ?string $label = null) => ['module' => $mod, 'instanceID' => $id, 'healthy' => $healthy, 'status' => $status] + ($label !== null ? ['label' => $label] : []);
$health = ['summary' => '', 'total' => 3, 'healthyCount' => 2, 'missingCount' => 1,
    'entries' => [$entry('meterhub', 11, true), $entry('PVF', 12, true), $entry('tessie', 13, false, 0)],
    'missing' => [['module' => 'goodweet', 'instanceID' => 14]]];
$ems1 = [2000 => ['status' => 102, 'name' => 'EMS']];

szenario('keine EMS, Standard', [], [], [], function ($m) { formular($m); $m->Render(); echo $m->ResetTour() . "\n"; });
szenario('eine EMS aktiv, Daten', ['ems' => $ems1, 'health' => $health], [], [], function ($m) { $m->Render(); formular($m); });
szenario('eine EMS aktiv, ohne Partner (1 Partner / 0 Partner)', ['ems' => $ems1, 'health' => ['entries' => [$entry('meterhub', 11, true)], 'total' => 1, 'healthyCount' => 1]], [], [], function ($m) { $m->Render(); $m->AckNewsApply(); formular($m); });
szenario('EMS ohne Partner', ['ems' => $ems1, 'health' => ['entries' => []]], [], [], function ($m) { $m->Render(); formular($m); });
szenario('Funktion liefert nichts', ['ems' => $ems1, 'health' => null], [], [], function ($m) { $m->Render(); });
szenario('zwei EMS, keine gewaehlt', ['ems' => [2000 => ['status' => 104, 'name' => 'EMS test'], 2001 => ['status' => 102, 'name' => 'EMS prod']], 'health' => $health], [], [], function ($m) { formular($m); });
szenario('zwei EMS, eine gewaehlt', ['ems' => [2000 => ['status' => 104, 'name' => 'EMS test'], 2001 => ['status' => 102, 'name' => 'EMS prod']], 'health' => $health], ['EmsInstance' => 2001], [], function ($m) { formular($m); });
szenario('EMS nicht aktiv, automatisch', ['ems' => [2000 => ['status' => 104, 'name' => 'EMS']], 'health' => $health], [], [], function ($m) { formular($m); });
szenario('EMS nicht aktiv, gewaehlt', ['ems' => [2000 => ['status' => 104, 'name' => 'EMS']], 'health' => $health], ['EmsInstance' => 2000], [], function ($m) { formular($m); });
szenario('alles weggeklickt', ['ems' => $ems1, 'health' => $health], [], ['SeenNews' => '0.10.12', 'PurposeIntroGone' => true, 'ReviewHintDismissed' => true], function ($m) { formular($m); });
szenario('nur 0.6.6 neu', ['ems' => $ems1, 'health' => $health], [], ['SeenNews' => '0.6.5'], function ($m) { formular($m); });
szenario('Aufbau: zwei ausgeblendet, Position', ['ems' => $ems1, 'health' => $health], ['FormLayout' => json_encode([['Key' => 'style', 'Hidden' => true, 'Pos' => 1], ['Key' => 'details', 'Hidden' => true, 'Pos' => 2], ['Key' => 'source', 'Closed' => true, 'Pos' => 3]])], [], function ($m) { formular($m); });
szenario('Aufbau zuruecksetzen', ['ems' => $ems1], [], [], function ($m) { echo $m->ResetFormLayout() . "\n"; });
szenario('Hinweise bestaetigen, Geschwister defekt', ['ems' => $ems1, 'siblings' => [1000, 1001]], [], [], function ($m) { $m->AckNews(); $m->AckPurposeIntro(); $m->DismissReviewHint(); });

// Kachel: Texttabelle (nur neuer Stand) -- Payload steht oben in UpdateVisualizationValue
if (is_file($root . '/libs/NRGDASH_Text.php')) {
    $GLOBALS['W'] = ['ems' => $ems1, 'health' => $health];
    $m = new NRGDashboardTopology();
    $m->Create();
    $m->ApplyChanges();
    $html = $m->GetVisualizationTile();
    preg_match('/window\.NRGDASH_TEXTS = (\{.*?\});<\/script>/s', $html, $mm);
    echo "=== Kachel-Texttabelle\n" . ($mm[1] ?? 'FEHLT') . "\n";
    ob_start();
    @$m->ProcessHookData();
    $seite = (string) ob_get_clean();
    echo 'Standalone hat dieselbe Tabelle: ' . (isset($mm[1]) && strpos($seite, 'window.NRGDASH_TEXTS = ' . $mm[1] . ';') !== false ? 'ja' : 'NEIN') . "\n";
}
