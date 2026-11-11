<?php

declare(strict_types=1);

// ===========================================================================
// Szenario-Lauf fuer NRGDashboardWPMonitor mit nachgebautem IPS (Stubs). Schreibt jeden sichtbaren Text als JSON-Zeilen.
//   php .tools/szenarien-wpmonitor.php <Wurzelordner der Bibliothek> <de|en>
// Wird von .tools/deutsch-vergleich-wpmonitor.php zweimal gestartet (alter Stand aus git, aktueller Stand) und die Ausgaben
// werden verglichen; mit "en" laesst es sich nach dem Formular in englischer Sprache lesen.
// Der Uebersetzer ist die gebaute locale.json des Moduls (so wie Symcons Translate()); was die Symcon-Konsole selbst
// uebersetzt (caption/label/... im Formular), wird hier nachgebaut (konsole()). Fehlt locale.json (alter Stand): nichts davon.
// ===========================================================================

$root = $argv[1] ?? dirname(__DIR__);
$lang = $argv[2] ?? 'de';
$GLOBALS['LANG'] = $lang;
$locFile = $root . '/NRGDashboardWPMonitor/locale.json';
$GLOBALS['DE'] = is_file($locFile) ? (json_decode((string) file_get_contents($locFile), true)['translations']['de'] ?? []) : [];

const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const VARIABLETYPE_INTEGER = 1;
const HEISHA_G = '{1919151A-3C0F-4C09-B906-291638EC1469}';
const WPHUB_G = '{5BE429EA-3AAD-4A8B-85DE-5778CCA2E6BC}';
const SELF_G = '{86574F22-636A-4208-A252-FEECB20789E9}';

/** Zustand der nachgebauten Anlage, je Szenario neu gesetzt */
$GLOBALS['W'] = [];
$GLOBALS['LOG'] = [];
$GLOBALS['PROFILE'] = [];

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
    if ($guid === '{43192F0B-135B-4CE7-A0A7-1475603F3060}') {
        return [4000]; // Archiv-Control
    }
    $out = [];
    foreach ($W['hp'] ?? [] as $id => $h) {
        if ($h['guid'] === $guid) {
            $out[] = $id;
        }
    }
    return $out;
}
function IPS_InstanceExists($id) { return isset($GLOBALS['W']['hp'][$id]) || in_array($id, $GLOBALS['W']['siblings'] ?? [], true) || $id === 1000; }
function IPS_GetInstance($id)
{
    $h = $GLOBALS['W']['hp'][$id] ?? ['guid' => '', 'status' => 102];
    return ['InstanceStatus' => $h['status'] ?? 102, 'ModuleInfo' => ['ModuleID' => $h['guid']]];
}
function IPS_GetName($id) { return $GLOBALS['W']['hp'][$id]['name'] ?? 'Inst' . $id; }
function IPS_ObjectExists($id) { return IPS_InstanceExists($id); }
function IPS_VariableExists($id) { return $id > 0; }
function GetValue($id) { return $GLOBALS['W']['vals'][$id] ?? 21.5; }
function IPS_GetLibrary($guid) { return ['Version' => '0.10.12', 'Build' => 135]; }
function IPS_GetProperty($id, $n) { return '[]'; }
function IPS_SetProperty($id, $n, $v) {}
function IPS_ApplyChanges($id) {}
function IPS_VariableProfileExists($n) { return isset($GLOBALS['PROFILE'][$n]); }
function IPS_CreateVariableProfile($n, $t) { $GLOBALS['PROFILE'][$n] = []; }
function IPS_SetVariableProfileAssociation($n, $v, $name, $icon, $color) { ips_log('Profile ' . $n . ' ' . $v, $name); }
function IPS_GetObjectIDByIdent($i, $p) { return false; }
function AC_GetLoggingStatus($a, $v) { return true; }
function AC_GetAggregatedValues($a, $v, $level, $start, $end, $limit)
{
    // wenige feste Zeilen je Zeitraum; Variable 7 liefert einen unplausiblen Wert (loest die Debug-Meldung aus)
    $rows = [];
    for ($i = 0; $i < 3; $i++) {
        $ts = $start + $i * 3600;
        $rows[] = ['TimeStamp' => $ts, 'Avg' => 100.0 + $i, 'Min' => 90.0, 'Max' => ($v === 7 && $i === 1) ? 9.9e9 : 120.0];
    }
    return $rows;
}
function HEISHA_GetFunctions($id) { return $GLOBALS['W']['hp'][$id]['fn'] ?? []; }
function WPHUB_GetFunctions($id) { return $GLOBALS['W']['hp'][$id]['fn'] ?? []; }
function HEISHA_GetHeatingCurve($id) { return ['curveModel' => 'twoPoint', 'zones' => []]; }
function HEISHA_GetOperations($id) { return ['quiet' => ['value' => 0]]; }
function HEISHA_GetHeatingAutomation($id) { return ['state' => 'off']; }
function HEISHA_SetHeatingAutomation($id, $json)
{
    if (($GLOBALS['W']['throw'] ?? false)) {
        throw new RuntimeException('Boom');
    }
    return json_encode(['ok' => true]);
}
function HEISHA_GetSchedule($id) { return ['enabled' => false]; }
function HEISHA_SetSchedule($id, $json)
{
    if (($GLOBALS['W']['throw'] ?? false)) {
        throw new RuntimeException('Boom');
    }
    return json_encode(['ok' => true]);
}
function NRGDASHWPMON_AckNewsApply($id) { throw new RuntimeException('Geschwister defekt'); }

class IPSModule
{
    public $InstanceID = 1000;
    public $props = [];
    public $attrs = [];
    public $vars = [];
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
    public function RegisterVariableInteger($ident, $name, $profile, $pos) { ips_log('RegisterVariableInteger ' . $ident, $name); $this->vars[$ident] = $this->vars[$ident] ?? 0; }
    public function EnableAction($i) {}
    public function SetValue($i, $v) { $this->vars[$i] = $v; }
    public function GetValue($i) { return $this->vars[$i] ?? 0; }
    public function RegisterTimer($n, $i, $s) {}
    public function SetTimerInterval($n, $i) {}
    public function SetVisualizationType($t) {}
    public function RegisterMessage($a, $b) {}
    public function SetStatus($s) { ips_log('SetStatus', $s); }
    public function UpdateVisualizationValue($v) { ips_log('UpdateVisualizationValue', json_decode($v, true)); }
    public function UpdateFormField($f, $k, $v) { ips_log('UpdateFormField ' . $f . '.' . $k, $v); }
    public function SendDebug($m, $t, $f) { ips_log('SendDebug ' . $m, $t); }
    public function RequestAction($ident, $value) {}
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

require_once $root . '/NRGDashboardWPMonitor/module.php';

function szenario(string $name, array $welt, array $props, array $attrs, callable $aktion): void
{
    $GLOBALS['W'] = $welt;
    $GLOBALS['LOG'] = [];
    $GLOBALS['PROFILE'] = [];
    $m = new NRGDashboardWPMonitor();
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
function formular(NRGDashboardWPMonitor $m): void
{
    echo 'FORM ' . json_encode(konsole(json_decode($m->GetConfigurationForm(), true)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}

$entry = fn (array $x) => ['Type' => 'heatpump', 'Caption' => 'WP'] + $x;
$heisha = [3000 => ['guid' => HEISHA_G, 'name' => 'Heisha', 'fn' => [$entry(['PowerID' => 7, 'energyID' => 8, 'heatOutputPowerID' => 9, 'outsideTempID' => 10, 'lastSeenAt' => 1000])]]];
$heishaNurTemp = [3000 => ['guid' => HEISHA_G, 'name' => 'Heisha', 'fn' => [$entry(['mainOutletTempID' => 11])]]];
$zwei = $heisha + [3001 => ['guid' => WPHUB_G, 'name' => 'WPHub', 'status' => 104, 'fn' => [$entry(['dailyEnergyTotalID' => 12, 'lastSeenAt' => time() - 60])]]];
$wphub = [3001 => ['guid' => WPHUB_G, 'name' => 'WPHub', 'fn' => [$entry(['dailyEnergyTotalID' => 12, 'lastSeenAt' => time() - 5000, 'pollInterval' => 60])]]];

szenario('keine Waermepumpe, Standard', [], [], [], function ($m) { formular($m); $m->Render(); echo $m->ResetTour() . "\n"; });
szenario('Heisha, Daten (unplausibler Archivwert, veraltet)', ['hp' => $heisha], [], [], function ($m) { $m->Render(); formular($m); });
szenario('Heisha nur Temperaturfelder', ['hp' => $heishaNurTemp], [], [], function ($m) { $m->Render(); formular($m); });
szenario('WPHub, Tageszaehler, veraltet', ['hp' => $wphub], [], [], function ($m) { $m->Render(); formular($m); });
szenario('zwei Waermepumpen, keine gewaehlt', ['hp' => $zwei], [], [], function ($m) { formular($m); });
szenario('zwei Waermepumpen, eine gewaehlt', ['hp' => $zwei], ['HeatpumpInstance' => 3001], [], function ($m) { formular($m); });
szenario('alles weggeklickt', ['hp' => $heisha], [], ['SeenNews' => '0.10.12', 'PurposeIntroGone' => true, 'ReviewHintDismissed' => true], function ($m) { formular($m); });
szenario('nur 0.9.170 neu', ['hp' => $heisha], [], ['SeenNews' => '0.2.8'], function ($m) { formular($m); });
szenario('Hinweise bestaetigen, Geschwister defekt', ['hp' => $heisha, 'siblings' => [1000, 1001]], [], [], function ($m) { $m->AckNews(); $m->AckPurposeIntro(); $m->DismissReviewHint(); });
szenario('Aktion: Automatik/Zeitplan speichern, Funktion fehlt (WPHub)', ['hp' => $wphub], [], [], function ($m) {
    $m->RequestAction('automationSave', '{"enabled":true}');
    $m->RequestAction('scheduleSave', '{"enabled":true}');
});
szenario('Aktion: Automatik/Zeitplan speichern, Heisha ok', ['hp' => $heisha], [], [], function ($m) {
    $m->RequestAction('automationSave', '{"enabled":true,"onBelowC":12}');
    $m->RequestAction('scheduleSave', '{"enabled":true,"channels":{"quiet":{"enabled":true,"default":0,"segments":[{"day":1,"from":0,"to":60,"value":1}]}}}');
});
szenario('Aktion: Automatik/Zeitplan speichern, Heisha wirft', ['hp' => $heisha, 'throw' => true], [], [], function ($m) {
    $m->RequestAction('automationSave', '{"enabled":true}');
    $m->RequestAction('scheduleSave', '{"enabled":true}');
});
szenario('Aktion: Laden und Bedienen', ['hp' => $heisha], [], [], function ($m) {
    foreach (['heatingCurveLoad', 'automationLoad', 'scheduleLoad', 'operationsLoad'] as $a) { $m->RequestAction($a, '{}'); }
    $m->RequestAction('TabAnimation', '2');
});

// Kachel: Texttabelle (nur neuer Stand) -- Payload steht oben in UpdateVisualizationValue
if (is_file($root . '/libs/NRGDASH_Text.php')) {
    $GLOBALS['W'] = ['hp' => $heisha];
    $m = new NRGDashboardWPMonitor();
    $m->Create();
    $m->ApplyChanges();
    $html = $m->GetVisualizationTile();
    preg_match('/window\.NRGDASH_TEXTS = (\{.*?\});<\/script>/s', $html, $mm);
    echo "=== Kachel-Texttabelle\n" . ($mm[1] ?? 'FEHLT') . "\n";
    ob_start();
    @$m->ProcessHookData();
    $seite = (string) ob_get_clean();
    echo 'Standalone hat dieselbe Tabelle: ' . (isset($mm[1]) && strpos($seite, 'window.NRGDASH_TEXTS = ' . $mm[1] . ';') !== false ? 'ja' : 'NEIN') . "\n";

    // Stolperstein 24: viele t()-Aufrufe, wenige Translate()-Anfragen (Merkliste)
    NRGDASH_Text::$anfragen = 0;
    for ($i = 0; $i < 200; $i++) {
        $m->GetConfigurationForm();
        $m->GetVisualizationTile();
    }
    echo "=== Stolperstein 24\nTranslate()-Anfragen nach 200 x Formular+Kachel: " . NRGDASH_Text::$anfragen . "\n";
}
