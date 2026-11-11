<?php

declare(strict_types=1);

// ===========================================================================
// Szenario-Lauf fuer NRGDashboardForecast mit nachgebautem IPS (Stubs). Schreibt jeden sichtbaren Text als Zeilen.
//   php .tools/szenarien-forecast.php <Wurzelordner der Bibliothek> <de|en>
// Wird von .tools/deutsch-vergleich-forecast.php zweimal gestartet (alter Stand aus git, aktueller Stand) und die Ausgaben werden
// verglichen; mit "en" laesst es sich nach dem Formular in englischer Sprache lesen.
// Der Uebersetzer ist die gebaute locale.json des Moduls (so wie Symcons Translate()); was die Symcon-Konsole selbst
// uebersetzt (caption/label/... im Formular), wird hier nachgebaut (konsole()). Fehlt locale.json-Inhalt (alter Stand): nichts davon.
// Aufgezeichnet werden: Formular, Statuszeilen, Rueckgaben, UpdateFormField, SendDebug, Payload der Kachel, Namen und
// Profil-Zuordnungen der Instanzvariablen.
// ===========================================================================

date_default_timezone_set('Europe/Berlin');
$root = $argv[1] ?? dirname(__DIR__);
$lang = $argv[2] ?? 'de';
$GLOBALS['LANG'] = $lang;
$locFile = $root . '/NRGDashboardForecast/locale.json';
$GLOBALS['DE'] = is_file($locFile) ? (json_decode((string) file_get_contents($locFile), true)['translations']['de'] ?? []) : [];

const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const VM_UPDATE = 10603;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const GUID_PV = '{257DD4E8-9705-462E-89FC-56D0A1038353}';
const GUID_LOAD = '{DC5AD508-507F-40EA-8630-0959AED83050}';
const GUID_ARCHIVE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
const GUID_SELF = '{C9C30229-C36F-4B1C-A474-C0F5C7C4CF30}';

/** Zustand der nachgebauten Anlage, je Szenario neu gesetzt */
$GLOBALS['W'] = [];
$GLOBALS['LOG'] = [];
$GLOBALS['VARS'] = [];     // Ident => ['id' => int, 'name' => string, 'value' => mixed]
$GLOBALS['PROF'] = [];     // Profilname => true

function ips_log(string $what, $data): void
{
    $GLOBALS['LOG'][] = $what . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_GetInstanceListByModuleID($guid)
{
    $W = $GLOBALS['W'];
    if ($guid === GUID_PV) { return array_keys($W['pv'] ?? []); }
    if ($guid === GUID_LOAD) { return array_keys($W['load'] ?? []); }
    if ($guid === GUID_ARCHIVE) { return isset($W['archive']) ? [9000] : []; }
    if ($guid === GUID_SELF) { return $W['siblings'] ?? [1000]; }
    return [];
}
function IPS_InstanceExists($id)
{
    $W = $GLOBALS['W'];
    return isset($W['pv'][$id]) || isset($W['load'][$id]) || in_array($id, $W['siblings'] ?? [1000], true) || $id === 1000;
}
function IPS_GetInstance($id)
{
    $e = $GLOBALS['W']['pv'][$id] ?? $GLOBALS['W']['load'][$id] ?? ['status' => 102];
    return ['InstanceStatus' => $e['status'], 'ModuleInfo' => ['ModuleID' => '']];
}
function IPS_GetName($id)
{
    foreach ($GLOBALS['VARS'] as $v) {
        if ($v['id'] === $id) { return $v['name']; }
    }
    return $GLOBALS['W']['names'][$id] ?? 'Inst' . $id;
}
function IPS_SetName($id, $name)
{
    foreach ($GLOBALS['VARS'] as $k => $v) {
        if ($v['id'] === $id) { $GLOBALS['VARS'][$k]['name'] = $name; ips_log('IPS_SetName ' . $k, $name); }
    }
}
function IPS_ObjectExists($id) { return IPS_InstanceExists($id) || IPS_VariableExists($id); }
function IPS_VariableExists($id) { return in_array($id, $GLOBALS['W']['variables'] ?? [], true); }
function IPS_GetObjectIDByIdent($ident, $parent)
{
    $W = $GLOBALS['W'];
    if ($parent === 1000) { return isset($GLOBALS['VARS'][$ident]) ? $GLOBALS['VARS'][$ident]['id'] : false; }
    $src = $W['pv'][$parent] ?? $W['load'][$parent] ?? null;
    if ($src !== null && isset($src['series'][$ident])) { return $parent * 10 + array_search($ident, array_keys($src['series'])) + 1; }
    return false;
}
function GetValue($vid)
{
    $W = $GLOBALS['W'];
    foreach (['pv', 'load'] as $k) {
        foreach ($W[$k] ?? [] as $iid => $src) {
            foreach (array_keys($src['series'] ?? []) as $n => $ident) {
                if ($iid * 10 + $n + 1 === $vid) { return $src['series'][$ident]; }
            }
        }
    }
    return $W['values'][$vid] ?? 0;
}
function IPS_GetVariable($vid) { return ['VariableCustomProfile' => '', 'VariableProfile' => '~Watt']; }
function IPS_VariableProfileExists($n) { return $n === '~Watt' || isset($GLOBALS['PROF'][$n]); }
function IPS_GetVariableProfile($n) { return ['Suffix' => ' W']; }
function IPS_CreateVariableProfile($n, $t) { $GLOBALS['PROF'][$n] = true; }
function IPS_SetVariableProfileAssociation($n, $v, $name, $icon, $color) { ips_log('Profil ' . $n . ' ' . $v, $name); }
function IPS_SetVariableProfileValues($n, $a, $b, $c) {}
function IPS_SetVariableProfileText($n, $a, $b) {}
function IPS_SetVariableProfileDigits($n, $d) {}
function IPS_GetLibrary($guid) { return ['Version' => '0.10.12', 'Build' => 135]; }
function IPS_GetProperty($id, $n) { return '[]'; }
function IPS_SetProperty($id, $n, $v) {}
function IPS_ApplyChanges($id) {}
function PVF_GetSnapshot($src, $date) { return ['p50' => array_fill(0, 24, 800.0), 'kwh' => 9.5]; }
function LFC_GetSnapshot($src, $date) { return ['p50' => array_fill(0, 24, 500.0), 'kwh' => 12.0]; }
function PVF_GetGenerators($src) { return ['totalKwp' => 9.18]; }
function AC_GetLoggingStatus($aid, $vid) { return $GLOBALS['W']['logged'][$vid] ?? true; }
function AC_GetAggregatedValues($aid, $vid, $level, $start, $end, $limit)
{
    if ($level === 1) { return [['Max' => 6200.0, 'Min' => 0.0, 'Avg' => 1500.0, 'TimeStamp' => $start]]; }
    if (!empty($GLOBALS['W']['aggfail'])) { return false; }
    $rows = [];
    foreach ($GLOBALS['W']['hours'] ?? [8, 9, 10, 11] as $h) {
        $rows[] = ['TimeStamp' => $start + 3600 * $h, 'Avg' => 1000.0, 'Max' => 1200.0, 'Min' => 800.0];
    }
    if (!empty($GLOBALS['W']['implausible'])) {
        $rows[] = ['TimeStamp' => $start + 3600 * 14, 'Avg' => 1000.0, 'Max' => 99000000.0, 'Min' => 0.0];
    }
    return $rows;
}
function AC_GetLoggedValues($aid, $vid, $start, $end, $limit)
{
    if ($limit === 1) { return [['TimeStamp' => $start, 'Value' => 1000.0]]; }
    $rows = [['TimeStamp' => $start + 7200, 'Value' => 1000.0]];
    if (!empty($GLOBALS['W']['implausible'])) {
        $rows[] = ['TimeStamp' => $start + 9000, 'Value' => 99000000.0];
    }
    return $rows;
}
function NRGDASHFC_AckNewsApply($id) { throw new RuntimeException('Geschwister defekt'); }

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
    private function registerVar(string $ident, string $name, $profile, $pos, $default)
    {
        $id = 5000 + count($GLOBALS['VARS']);
        if (!isset($GLOBALS['VARS'][$ident])) {
            $GLOBALS['VARS'][$ident] = ['id' => $id, 'name' => $name, 'value' => $default];
            $GLOBALS['W']['variables'][] = $id;
            ips_log('Variable ' . $ident, [$name, $profile, $pos]);
        }
    }
    public function RegisterVariableBoolean($i, $n, $p = '', $pos = 0) { $this->registerVar($i, $n, $p, $pos, false); }
    public function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { $this->registerVar($i, $n, $p, $pos, 0); }
    public function RegisterVariableFloat($i, $n, $p = '', $pos = 0) { $this->registerVar($i, $n, $p, $pos, 0.0); }
    public function EnableAction($i) {}
    public function SetValue($i, $v) { $GLOBALS['VARS'][$i]['value'] = $v; }
    public function GetValue($i) { return $GLOBALS['VARS'][$i]['value']; }
    public function GetMessageList() { return []; }
    public function RegisterMessage($a, $b) {}
    public function UnregisterMessage($a, $b) {}
    public function RegisterReference($a) {}
    public function RegisterTimer($n, $i, $s) {}
    public function SetTimerInterval($n, $i) {}
    public function SetVisualizationType($t) {}
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

require_once $root . '/NRGDashboardForecast/module.php';

function szenario(string $name, array $welt, array $props, array $attrs, callable $aktion): void
{
    $GLOBALS['W'] = $welt;
    $GLOBALS['LOG'] = [];
    $GLOBALS['VARS'] = [];
    $GLOBALS['PROF'] = [];
    $m = new NRGDashboardForecast();
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
function formular(NRGDashboardForecast $m): void
{
    echo 'FORM ' . json_encode(konsole(json_decode($m->GetConfigurationForm(), true)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}
function kurve(int $n, float $f): string
{
    $p50 = [];
    for ($i = 0; $i < $n; $i++) { $p50[] = round(($i % 12) * 100 * $f, 1); }
    return json_encode(['p10' => array_map(fn ($v) => $v * 0.8, $p50), 'p50' => $p50, 'p90' => array_map(fn ($v) => $v * 1.2, $p50), 'kwh' => round(array_sum($p50) / 1000, 2)]);
}
function quelle(int $n, float $f, string $praefix, int $status = 102): array
{
    $s = [];
    foreach (['Today', 'Tomorrow', 'DayAfter', 'Day3', 'Day4'] as $d) { $s[$praefix . '_' . $d] = kurve($n, $f); }
    return ['status' => $status, 'series' => $s];
}
function welt(array $extra = []): array
{
    return array_replace([
        'pv' => [2000 => quelle(24, 1.0, 'PVF')],
        'load' => [2100 => quelle(24, 0.6, 'LFC')],
        'archive' => true,
        'variables' => [7001, 7002],
        'names' => [7001 => 'PV-Leistung', 7002 => 'Hausverbrauch'],
        'values' => [7001 => 3400.0, 7002 => 1250.0],
    ], $extra);
}
$istAn = ['ActualPV' => 7001, 'ActualLoad' => 7002];

szenario('keine Quellen, Neuinstallation', ['archive' => true], [], [], function ($m) { formular($m); echo $m->ResetTour() . "\n"; });
szenario('Daten, Ist-Variablen, Kachel', welt(), $istAn, [], function ($m) { formular($m); });
szenario('Daten, Ist-Linien an, gestern an', welt(), $istAn, [], function ($m) {
    foreach (['ShowActualPV', 'ShowActualLoad', 'ShowYesterday'] as $i) { $m->SetValue($i, true); }
    $m->SetValue('Days', 5);
    $m->ApplyChanges();
    formular($m);
});
szenario('96 Zeitscheiben, Balken', welt(['pv' => [2000 => quelle(96, 1.0, 'PVF')], 'load' => [2100 => quelle(96, 0.6, 'LFC')]]), $istAn, [], function ($m) {
    $m->SetValue('ChartView', 1); $m->SetValue('ShowActualPV', true); $m->ApplyChanges(); formular($m);
});
szenario('ohne Last, Anlagenleistung', welt(['load' => []]), ['MaxLineKw' => 12.5, 'ActualPV' => 7001], [], function ($m) {
    $m->SetValue('ShowMaxLine', true); $m->ApplyChanges(); formular($m);
});
szenario('Archiv defekt, eine Stunde', welt(['aggfail' => true, 'hours' => [9]]), $istAn, [], function ($m) { formular($m); });
szenario('Archiv defekt, keine Werte heute', welt(['aggfail' => true, 'hours' => []]), $istAn, [], function ($m) { formular($m); });
szenario('Archiv defekt, viele Stunden, Linie aus', welt(['aggfail' => true]), $istAn, [], function ($m) { $m->SetValue('ShowActualLoad', false); $m->ApplyChanges(); formular($m); });
szenario('Archiv ok, eine Stunde', welt(['hours' => [9]]), $istAn, [], function ($m) { formular($m); });
szenario('Archiv ok, ohne Messwerte', welt(['hours' => []]), $istAn, [], function ($m) { formular($m); });
szenario('unplausibler Archivwert', welt(['implausible' => true]), $istAn, [], function ($m) { $m->SetValue('ShowActualPV', true); $m->ApplyChanges(); formular($m); });
szenario('nicht archiviert, Variable fehlt', welt(['logged' => [7001 => false], 'variables' => [7001]]), $istAn, [], function ($m) { formular($m); });
szenario('zwei aktive Quellen, keine gewaehlt', welt(['pv' => [2000 => quelle(24, 1.0, 'PVF'), 2001 => quelle(24, 1.0, 'PVF')], 'load' => [2100 => quelle(24, 0.6, 'LFC', 104), 2101 => quelle(24, 0.6, 'LFC', 104)]]), [], [], function ($m) { formular($m); });
szenario('veraltete Instanz eingetragen, eine Quelle nicht aktiv', welt(['pv' => [2000 => quelle(24, 1.0, 'PVF', 104)]]), ['PVSource' => 1999, 'LoadSource' => 2100], [], function ($m) { formular($m); });
szenario('alles weggeklickt', welt(), $istAn, ['SeenNews' => '0.10.12', 'PurposeIntroGone' => true, 'ReviewHintDismissed' => '1'], function ($m) { formular($m); });
szenario('nur 0.2.10 neu', welt(), [], ['SeenNews' => '0.2.9'], function ($m) { formular($m); });
szenario('Hinweise bestaetigen, Geschwister defekt', welt(['siblings' => [1000, 1001]]), [], [], function ($m) { $m->AckNews(); $m->AckPurposeIntro(); $m->DismissReviewHint(); });
szenario('Einstellungen zuruecksetzen', welt(), $istAn, [], function ($m) { $m->ResetStyle(); });
szenario('Variable umbenannt, Sprachwechsel-Nachzug', welt(), [], [], function ($m) {
    $GLOBALS['VARS']['ShowPV']['name'] = 'Mein PV-Schalter';
    $GLOBALS['VARS']['Smooth']['name'] = 'Altname';
    $m->ApplyChanges();
});

// Sprachwechsel (nur neuer Stand, daher nicht Teil des Vergleichs alt/neu): nachgezogen wird nur, was das Modul zuletzt selbst gesetzt hat
if ($lang === 'en') {
    szenario('Sprachwechsel: Namen nachziehen (nur en)', welt(), [], [], function ($m) {
        $GLOBALS['VARS']['Smooth']['name'] = 'Kurven glaetten (gegen kantige Linien)';
        $GLOBALS['VARS']['ShowPV']['name'] = 'Mein PV-Schalter';
        $m->attrs['VarNames'] = json_encode(['Smooth' => 'Kurven glaetten (gegen kantige Linien)', 'ShowPV' => 'PV-Erzeugung anzeigen'], JSON_UNESCAPED_UNICODE);
        $m->ApplyChanges();
        echo 'Smooth=' . $GLOBALS['VARS']['Smooth']['name'] . ' | ShowPV=' . $GLOBALS['VARS']['ShowPV']['name'] . "\n";
    });
}

// Kachel: Texttabelle (nur neuer Stand)
if (is_file($root . '/libs/NRGDASH_Text.php') && strpos((string) file_get_contents($root . '/NRGDashboardForecast/module.php'), 'TILE_TEXTS') !== false) {
    $GLOBALS['W'] = welt();
    $GLOBALS['LOG'] = [];
    $GLOBALS['VARS'] = [];
    $m = new NRGDashboardForecast();
    $m->Create();
    $m->props['ActualPV'] = 7001;
    $m->ApplyChanges();
    $html = $m->GetVisualizationTile();
    preg_match('/window\.NRGDASH_TEXTS = (\{.*?\});<\/script>/s', $html, $mm);
    echo "=== Kachel-Texttabelle\n" . ($mm[1] ?? 'FEHLT') . "\n";
    ob_start();
    @$m->ProcessHookData();
    $seite = (string) ob_get_clean();
    echo 'Standalone hat dieselbe Tabelle: ' . (isset($mm[1]) && strpos($seite, 'window.NRGDASH_TEXTS = ' . $mm[1] . ';') !== false ? 'ja' : 'NEIN') . "\n";
    echo 'Zahl der Translate()-Anfragen bisher: ' . NRGDASH_Text::$anfragen . "\n";
}

// Stolperstein 24: wie oft fragt das Modul Symcon wirklich, wenn es viele Texte uebersetzt? (nur neuer Stand)
if (is_file($root . '/libs/NRGDASH_Text.php') && strpos((string) file_get_contents($root . '/NRGDashboardForecast/module.php'), 'TILE_TEXTS') !== false) {
    $GLOBALS['W'] = welt(['aggfail' => true]);
    $GLOBALS['VARS'] = [];
    $m = new NRGDashboardForecast();
    $m->Create();
    $m->props['ActualPV'] = 7001;
    $m->props['ActualLoad'] = 7002;
    $m->ApplyChanges();
    NRGDASH_Text::$anfragen = 0;
    $GLOBALS['LOG'] = [];
    for ($i = 0; $i < 200; $i++) { $m->ApplyChanges(); }
    $nachApply = NRGDASH_Text::$anfragen;
    for ($i = 0; $i < 300; $i++) { $m->GetVisualizationTile(); json_decode($m->GetConfigurationForm()); }
    echo '=== Stolperstein 24' . "\n";
    echo '200 x ApplyChanges (Setzen leert die Merkliste je Aufruf): ' . $nachApply . " Translate()-Anfragen, " . round($nachApply / 200, 1) . " je Aufruf\n";
    NRGDASH_Text::$anfragen = 0;
    for ($i = 0; $i < 300; $i++) { $m->GetVisualizationTile(); json_decode($m->GetConfigurationForm()); }
    echo '300 x (Kachel + Formular) ohne ApplyChanges: ' . NRGDASH_Text::$anfragen . " Translate()-Anfragen\n";
}
