<?php

declare(strict_types=1);
error_reporting(0);

// ===========================================================================
// Szenario-Lauf fuer NRGDashboardTile mit nachgebautem IPS (Stubs). Schreibt jeden sichtbaren Text als Zeilen.
//   php .tools/szenarien-tile.php <Wurzelordner der Bibliothek> <de|en>
// Wird von .tools/deutsch-vergleich-tile.php zweimal gestartet (alter Stand aus git, aktueller Stand) und die Ausgaben werden
// verglichen; mit "en" laesst es sich nach dem Formular in englischer Sprache lesen.
// Der Uebersetzer ist die gebaute locale.json des Moduls (so wie Symcons Translate()); was die Symcon-Konsole selbst
// uebersetzt (caption/label/... im Formular), wird hier nachgebaut (konsole()). Fehlt locale.json (alter Stand): nichts davon.
// ===========================================================================

$root = $argv[1] ?? dirname(__DIR__);
$lang = $argv[2] ?? 'de';
$GLOBALS['LANG'] = $lang;
$locFile = $root . '/NRGDashboardTile/locale.json';
$GLOBALS['DE'] = is_file($locFile) ? (json_decode((string) file_get_contents($locFile), true)['translations']['de'] ?? []) : [];

const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const KL_MESSAGE = 10201;
const KL_WARNING = 10204;
const SELF_G = '{6B2F8C41-9E3A-4D6B-8F1C-5A7D9E2B4C61}';

$GLOBALS['W'] = [];
$GLOBALS['LOG'] = [];
$GLOBALS['PROFILE'] = [];
$GLOBALS['NAMES'] = [];

function ips_log(string $what, $data): void
{
    $GLOBALS['LOG'][] = $what . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_GetInstanceListByModuleID($guid)
{
    $W = $GLOBALS['W'];
    if ($guid === SELF_G) {
        return $W['siblings'] ?? [];
    }
    if ($guid === '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}') {
        return $W['tibber'] ?? [];
    }
    if ($guid === '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}') {
        return $W['tessie'] ?? [];
    }
    if ($guid === '{43192F0B-135B-4CE7-A0A7-1475603F3060}') {
        return [4000];
    }
    return [];
}
function IPS_InstanceExists($id) { return in_array($id, $GLOBALS['W']['siblings'] ?? [], true) || in_array($id, $GLOBALS['W']['tessie'] ?? [], true) || in_array($id, $GLOBALS['W']['tibber'] ?? [], true) || $id === 1000 || $id === 4000; }
function IPS_GetInstance($id) { return ['InstanceStatus' => 102, 'ModuleInfo' => ['ModuleID' => '']]; }
function IPS_GetName($id) { return $GLOBALS['W']['names'][$id] ?? 'Inst' . $id; }
function IPS_ObjectExists($id) { return isset($GLOBALS['W']['vars'][$id]) || IPS_InstanceExists($id); }
function IPS_SetName($id, $n) { ips_log('IPS_SetName ' . $id, $n); }
function IPS_GetLibrary($guid) { return ['Version' => '0.10.12', 'Build' => 135]; }
function IPS_GetConfiguration($id) { return '{}'; }
function IPS_GetProperty($id, $n) { return '[]'; }
function IPS_SetProperty($id, $n, $v) {}
function IPS_ApplyChanges($id) {}
function IPS_VariableExists($id) { return isset($GLOBALS['W']['vars'][$id]); }
function IPS_GetVariable($id) { return ['VariableType' => VARIABLETYPE_FLOAT, 'VariableCustomProfile' => '', 'VariableProfile' => '']; }
function IPS_GetObject($id) { return ['ObjectIdent' => '', 'ParentID' => 0, 'ObjectName' => IPS_GetName($id)]; }
function IPS_GetObjectIDByIdent($i, $p) { return false; }
function IPS_GetChildrenIDs($id) { return []; }
function IPS_VariableProfileExists($p) { return isset($GLOBALS['PROFILE'][$p]); }
function IPS_CreateVariableProfile($p, $t) { $GLOBALS['PROFILE'][$p] = []; }
function IPS_GetVariableProfile($p) { return ['Associations' => array_map(fn ($v, $n) => ['Value' => $v, 'Name' => $n], array_keys($GLOBALS['PROFILE'][$p] ?? []), array_values($GLOBALS['PROFILE'][$p] ?? []))]; }
function IPS_SetVariableProfileAssociation($p, $v, $n, $i, $c) { if ($n === '') { unset($GLOBALS['PROFILE'][$p][$v]); } else { $GLOBALS['PROFILE'][$p][$v] = $n; } }
function IPS_SetVariableProfileValues($p, $a, $b, $c) {}
function IPS_SetVariableProfileText($p, $a, $b) {}
function IPS_SetVariableProfileDigits($p, $a) {}
function IPS_SetVariableProfileIcon($p, $a) {}
function GetValue($id) { return $GLOBALS['W']['vars'][$id] ?? 0; }
function GetValueFormatted($id) { return (string) GetValue($id); }
function SetValue($id, $v) {}
function AC_GetLoggingStatus($a, $v) { return false; }
function AC_GetAggregatedValues($a, $v, $g, $s, $e, $l) { return []; }
function AC_GetLoggedValues($a, $v, $s, $e, $l) { return []; }
function IPS_GetKernelDir() { return '/tmp/'; }
function EMS_GetFederationHealth($id) { return null; }

class IPSModule
{
    public $InstanceID = 1000;
    public $props = [];
    public $attrs = [];
    public $buffers = [];
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
    public function ReadPropertyInteger($n) { return $this->props[$n]; }
    public function ReadPropertyString($n) { return $this->props[$n]; }
    public function ReadPropertyBoolean($n) { return $this->props[$n]; }
    public function ReadPropertyFloat($n) { return $this->props[$n]; }
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
    public function RegisterVariableBoolean($i, $n, $p = '', $pos = 0) { ips_log('Variable ' . $i, $n); }
    public function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { ips_log('Variable ' . $i, $n); }
    public function RegisterVariableFloat($i, $n, $p = '', $pos = 0) { ips_log('Variable ' . $i, $n); }
    public function RegisterVariableString($i, $n, $p = '', $pos = 0) { ips_log('Variable ' . $i, $n); }
    public function EnableAction($i) {}
    public function GetMessageList() { return []; }
    public function UnregisterMessage($a, $b) {}
    public function MessageSink($t, $s, $m, $d) {}
    public function SetValue($i, $v) {}
    public function GetValue($i) { return 0; }
    public function SetBuffer($n, $v) { $this->buffers[$n] = $v; }
    public function GetBuffer($n) { return $this->buffers[$n] ?? ''; }
    public function SetStatus($s) { ips_log('SetStatus', $s); }
    public function LogMessage($m, $t) { ips_log('LogMessage', $m); }
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

require_once $root . '/NRGDashboardTile/module.php';

function szenario(string $name, array $welt, array $props, array $attrs, callable $aktion): void
{
    $GLOBALS['W'] = $welt;
    $GLOBALS['LOG'] = [];
    $GLOBALS['PROFILE'] = $welt['profile'] ?? [];
    $m = new NRGDashboardTile();
    $m->Create();
    foreach ($props as $k => $v) { $m->props[$k] = $v; }
    foreach ($attrs as $k => $v) { $m->attrs[$k] = $v; }
    ob_start();
    try {
        $aktion($m);
    } catch (Throwable $e) {
        echo 'AUSNAHME ' . get_class($e) . ': ' . $e->getMessage();
    }
    $aus = (string) ob_get_clean();
    if (strpos($aus, '<meta') !== false || strpos($aus, '<script') !== false) {
        $aus = '[HTML-Seite]'; // Seiten werden ueber die Texttabellen und die Pruefskripte verglichen
    }
    echo "=== $name\n";
    foreach ($GLOBALS['LOG'] as $l) { echo $l . "\n"; }
    if ($aus !== '') { echo "OUT $aus\n"; }
}
function formular(NRGDashboardTile $m): void
{
    echo 'FORM ' . json_encode(konsole(json_decode($m->GetConfigurationForm(), true)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}
/** private Methode aufrufen */
function privat(object $o, string $methode, ...$args)
{
    $r = new ReflectionMethod($o, $methode);
    $r->setAccessible(true);
    return $r->invokeArgs($o, $args);
}
function hook(NRGDashboardTile $m, array $get): void
{
    $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $m->ProcessHookData();
    $_GET = [];
}

$vars = [11 => 1500.0, 12 => -800.0, 13 => 300.0, 14 => 55.0, 15 => 400.0];
$welt = ['vars' => $vars, 'names' => [15 => 'Zaehler Haus']];

// --- Anlegen (Variablen- und Profilnamen)
szenario('Anlegen', $welt, [], [], function ($m) {
    $m->Create();
    $m->ApplyChanges();
    foreach ($GLOBALS['PROFILE'] as $p => $a) { echo "PROFIL $p " . json_encode($a, JSON_UNESCAPED_UNICODE) . "\n"; }
});
szenario('Anlegen mit altem deutschem Profil (Migration)', $welt + ['profile' => ['NRGDASH.OpenAnimStyle' => [0 => 'Funkenschauer', 1 => 'Portal-Iris', 2 => 'Kreiswellen', 3 => 'Zufällig']]], [], [], function ($m) {
    $m->Create();
    $m->ApplyChanges();
    foreach ($GLOBALS['PROFILE'] as $p => $a) { echo "PROFIL $p " . json_encode($a, JSON_UNESCAPED_UNICODE) . "\n"; }
});
szenario('Anlegen wiederholt (englisches/neues Profil bleibt)', $welt, [], [], function ($m) {
    $m->Create(); $m->ApplyChanges();
    $m->Create(); $m->ApplyChanges();
    foreach ($GLOBALS['PROFILE'] as $p => $a) { echo "PROFIL $p " . json_encode($a, JSON_UNESCAPED_UNICODE) . "\n"; }
});

// --- Formular
szenario('Formular Standard', $welt, [], [], function ($m) { formular($m); });
szenario('Formular manuelle Datenpunkte', $welt, ['ManualPvID' => 11, 'ManualGridID' => 12, 'ManualBatID' => 13, 'ManualHouseID' => 0], [], function ($m) { formular($m); });
szenario('Formular manuell invertiert, Hauslast-Zaehler', $welt, ['ManualPvID' => 11, 'ManualGridID' => 12, 'ManualGridInvert' => true, 'ManualBatID' => 13, 'ManualBatInvert' => true, 'ManualHouseID' => 15], [], function ($m) { formular($m); });
szenario('Formular PV negativ, unplausible Hauslast', ['vars' => [21 => -50.0, 22 => 2000.0, 23 => 100.0]], ['ManualPvID' => 21, 'ManualGridID' => 22, 'ManualBatID' => 23], [], function ($m) { formular($m); });
szenario('Formular ungueltiger Hauslastzaehler, kein Wert', ['vars' => [31 => 500.0]], ['ManualPvID' => 99, 'ManualGridID' => 31, 'ManualHouseID' => 98], [], function ($m) { formular($m); });
szenario('Formular Tessie vorhanden ohne Funktion', $welt + ['tessie' => [60], 'names' => [60 => 'Tessie 1']], [], [], function ($m) { formular($m); });
szenario('Formular Tibber und PVF', $welt + ['tibber' => [70], 'names' => [70 => 'Tibber 1']], [], [], function ($m) { formular($m); });
szenario('Formular alles weggeklickt', $welt, [], ['SeenNews' => '0.10.12', 'PurposeIntroGone' => true, 'ReviewHintDismissed' => true], function ($m) { formular($m); });
szenario('Formular nur neuere News', $welt, [], ['SeenNews' => '0.10.4'], function ($m) { formular($m); });
szenario('Formular Aufbau', $welt, ['FormLayout' => json_encode([['Key' => 'style', 'Hidden' => true, 'Pos' => 1], ['Key' => 'devices', 'Closed' => true, 'Pos' => 2]])], [], function ($m) { formular($m); });
szenario('Aufbau zuruecksetzen / Tour / Quittieren', $welt + ['siblings' => [1000, 1001]], [], [], function ($m) {
    echo $m->ResetFormLayout() . "\n";
    echo $m->ResetTour() . "\n";
    $m->AckNews(); $m->AckPurposeIntro(); $m->DismissReviewHint();
});
szenario('Suchlauf-Zeile', $welt, [], ['LastDiscoveryTs' => 1700000000, 'DeviceCache' => json_encode([['function' => 'pv', 'label' => 'x']])], function ($m) { formular($m); });
szenario('Suchlauf-Zeile Einzahl/Mehrzahl', $welt, [], ['LastDiscoveryTs' => 1700000000, 'DeviceCache' => json_encode([['function' => 'pv'], ['function' => 'grid']])], function ($m) { formular($m); });

// --- Hook / Aktionen (Fehlertexte)
foreach ([
    ['wallboxAction ohne Demo', ['wallboxAction' => 'start', 'key' => 'x', 'instanceId' => '9'], []],
    ['wallboxAction Demo', ['wallboxAction' => 'start', 'key' => 'x', 'instanceId' => '9'], ['DemoMode' => true]],
    ['emsWbAction Demo', ['emsWbAction' => 'mode', 'key' => 'x', 'powerId' => '1', 'value' => '1'], ['DemoMode' => true]],
    ['emsWbAction', ['emsWbAction' => 'mode', 'key' => 'x', 'powerId' => '1', 'value' => '1'], []],
    ['emsWeekSave Demo', ['emsWeekSave' => '1', 'key' => 'x', 'powerId' => '1', 'days' => '[]'], ['DemoMode' => true]],
    ['emsWeekSave', ['emsWeekSave' => '1', 'key' => 'x', 'powerId' => '1', 'days' => '[]'], []],
    ['wbManagedBy Demo', ['wbManagedBy' => 'set', 'key' => 'x', 'powerId' => '1', 'value' => 'ems'], ['DemoMode' => true]],
    ['wbManagedBy', ['wbManagedBy' => 'set', 'key' => 'x', 'powerId' => '1', 'value' => 'ems'], []],
    ['switchAction Demo', ['switchAction' => '1', 'key' => 'x', 'value' => '1'], ['DemoMode' => true]],
    ['switchAction', ['switchAction' => '1', 'key' => 'x', 'value' => '1'], []],
    ['Detail unbekanntes Geraet', ['detail' => 'gibtsnicht', 'json' => '1'], []],
    ['Mitglieder unbekannt', ['members' => 'gibtsnicht'], []],
] as [$name, $get, $props]) {
    szenario('Hook ' . $name, $welt, $props, [], function ($m) use ($get) { hook($m, $get); });
}

// --- Kachel/Detailseite: Texttabellen und eingesetzte Zahlenformate
szenario('Detailseite Hook (HTML, Textanfang)', $welt, [], [], function ($m) {
    $_GET = ['detail' => 'gibtsnicht'];
    ob_start();
    $m->ProcessHookData();
    $h = ob_get_clean();
    $_GET = [];
    echo 'DETAIL-PAYLOAD ' . (preg_match('/handleDetail\((\{"ok":false[^\n]*\})\);/', $h, $mm) ? $mm[1] : 'FEHLT') . "\n";
});

// --- Detail-Kennzahlen (BuildHighlights) und Preisquelle
$series = [[1700000000000, -1500], [1700000900000, -1800], [1700001800000, 2000], [1700002700000, 2500]];
$energy = ['bars' => [[date('Y-m-d'), 12.34]], 'unit' => 'kWh', 'approx' => false, 'name' => ''];
foreach (['grid', 'battery', 'pv', 'wallbox', 'heatpump'] as $fn) {
    szenario('Kennzahlen ' . $fn, $welt + ['tibber' => [], 'vars' => $vars], ['FeedInTariffCt' => 8.2, 'FeedInAbschlagEUR' => 25.0, 'PurchasePriceCt' => 31.5], [], function ($m) use ($fn, $series, $energy) {
        $d = ['function' => $fn, 'label' => 'Test', 'energyImportID' => 0, 'energyExportID' => 0, 'batteryCapacityID' => 14, 'socID' => 14, 'vehicleRangeKm' => 321,
              'secondaryGrid' => [['authority' => 'billing', 'latency' => 'delayed', 'label' => 'Inexogy']]];
        foreach ([true, false] as $heute) {
            $r = privat($m, 'BuildHighlights', $d, $series, $energy, $heute, strtotime('today'), strtotime('tomorrow'));
            echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        }
    });
}
szenario('Preisquelle eigener Preis / fester Preis / nichts', $welt, ['PurchasePriceCt' => 31.5], [], function ($m) {
    $r = privat($m, 'SessionPriceResolver', 1700000000, 1700003600);
    echo $r[1] . "\n";
    $m->props['PurchasePriceCt'] = 0.0;
    $m->props['PurchasePriceList'] = json_encode([['ValidFrom' => '2024-01-01', 'PriceCt' => 30.0], ['ValidFrom' => '2025-06-01', 'PriceCt' => 33.5]]);
    echo privat($m, 'SessionPriceResolver', 1700000000, 1700003600)[1] . "\n";
    $m->props['PurchasePriceList'] = json_encode([['ValidFrom' => '2024-01-01', 'PriceCt' => 30.0]]);
    echo privat($m, 'SessionPriceResolver', 1700000000, 1700003600)[1] . "\n";
    $m->props['PurchasePriceList'] = '[]';
    echo privat($m, 'SessionPriceResolver', 1700000000, 1700003600)[1] . "\n";
});
szenario('Zahlen- und Zeitformate', $welt, [], [], function ($m) {
    echo privat($m, 'FmtWWithUnit', 1234.5) . ' | ' . privat($m, 'FmtWWithUnit', 12345.6) . ' | ' . privat($m, 'FmtHours', 30.0) . ' | ' . privat($m, 'FmtHours', 5.5) . ' | ' . privat($m, 'FmtHours', 0.4) . "\n";
});

// Kachel: Texttabelle (nur neuer Stand)
if (is_file($root . '/libs/NRGDASH_Text.php')) {
    $GLOBALS['W'] = $welt;
    $m = new NRGDashboardTile();
    $m->Create();
    $m->ApplyChanges();
    $html = $m->GetVisualizationTile();
    preg_match('/window\.NRGDASH_TEXTS = (\{.*?\});<\/script>/s', $html, $mm);
    echo "=== Kachel-Texttabelle\n" . ($mm[1] ?? 'FEHLT') . "\n";
    $_GET = [];
    ob_start();
    @$m->ProcessHookData();
    $seite = (string) ob_get_clean();
    echo 'Standalone hat dieselbe Tabelle: ' . (isset($mm[1]) && strpos($seite, 'window.NRGDASH_TEXTS = ' . $mm[1] . ';') !== false ? 'ja' : 'NEIN') . "\n";
    $_GET = ['detail' => 'x'];
    ob_start();
    @$m->ProcessHookData();
    $detail = (string) ob_get_clean();
    $_GET = [];
    preg_match('/window\.NRGDASH_TEXTS = (\{.*?\});<\/script>/s', $detail, $md);
    echo "=== Detail-Texttabelle\n" . ($md[1] ?? 'FEHLT') . "\n";
}
