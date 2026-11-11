<?php

declare(strict_types=1);

// ===========================================================================
// Szenario-Lauf fuer NRGDashboardHeatSchema mit nachgebautem IPS (Stubs). Schreibt jeden sichtbaren Text als JSON-Zeilen.
//   php .tools/szenarien-heatschema.php <Wurzelordner der Bibliothek> <de|en>
// Wird von .tools/deutsch-vergleich-heatschema.php zweimal gestartet (alter Stand aus git, aktueller Stand) und die Ausgaben
// werden verglichen; mit "en" laesst es sich nach dem Formular, den Statuszeilen, Profilen und der Kachel-Texttabelle in
// englischer Sprache lesen. Der Uebersetzer ist die gebaute locale.json des Moduls (wie Symcons Translate()); was die
// Konsole selbst uebersetzt (caption/label/... im Formular), baut konsole() nach.
// ===========================================================================

$root = $argv[1] ?? dirname(__DIR__);
$lang = $argv[2] ?? 'de';
$GLOBALS['LANG'] = $lang;
$locFile = $root . '/NRGDashboardHeatSchema/locale.json';
$GLOBALS['DE'] = is_file($locFile) ? (json_decode((string) file_get_contents($locFile), true)['translations']['de'] ?? []) : [];
$GLOBALS['TRANSLATE_CALLS'] = 0;
date_default_timezone_set('Europe/Berlin');

const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const HEISHA_G = '{1919151A-3C0F-4C09-B906-291638EC1469}';
const WPHUB_G = '{5BE429EA-3AAD-4A8B-85DE-5778CCA2E6BC}';
const METERHUB_G = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
const SELF_G = '{424584D6-4402-4A56-BDF3-924DB66DE01D}';

$GLOBALS['W'] = [];
$GLOBALS['LOG'] = [];
$GLOBALS['IDENTS'] = [];
$GLOBALS['PROFILES'] = [];

function ips_log(string $what, $data): void
{
    $j = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $j = preg_replace('/"renderedAt":\d+/', '"renderedAt":T', (string) $j);
    $GLOBALS['LOG'][] = $what . ' ' . $j;
}
function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_GetInstanceListByModuleID($guid)
{
    $W = $GLOBALS['W'];
    return match ($guid) {
        HEISHA_G => array_keys($W['heisha'] ?? []),
        WPHUB_G => array_keys($W['wphub'] ?? []),
        METERHUB_G => $W['meterhub'] ?? [],
        SELF_G => $W['siblings'] ?? [],
        default => [],
    };
}
function IPS_InstanceExists($id) { return isset($GLOBALS['W']['heisha'][$id]) || isset($GLOBALS['W']['wphub'][$id]) || in_array($id, $GLOBALS['W']['siblings'] ?? [], true) || $id === 1000; }
function IPS_GetInstance($id)
{
    $e = $GLOBALS['W']['heisha'][$id] ?? $GLOBALS['W']['wphub'][$id] ?? ['status' => 102];
    return ['InstanceStatus' => $e['status'] ?? 102];
}
function IPS_GetName($id) { return $GLOBALS['W']['heisha'][$id]['name'] ?? $GLOBALS['W']['wphub'][$id]['name'] ?? 'Inst' . $id; }
function IPS_ObjectExists($id) { return IPS_InstanceExists($id); }
function IPS_GetLibrary($guid) { return ['Version' => '0.10.12', 'Build' => 135]; }
function IPS_GetProperty($id, $n) { return '[]'; }
function IPS_SetProperty($id, $n, $v) {}
function IPS_ApplyChanges($id) {}
function IPS_VariableExists($id) { return isset($GLOBALS['W']['vars'][$id]); }
function GetValue($id) { return $GLOBALS['W']['vars'][$id]; }
function IPS_VariableProfileExists($n) { return isset($GLOBALS['PROFILES'][$n]); }
function IPS_CreateVariableProfile($n, $t) { $GLOBALS['PROFILES'][$n] = []; }
function IPS_DeleteVariableProfile($n) { unset($GLOBALS['PROFILES'][$n]); }
function IPS_SetVariableProfileAssociation($n, $v, $name, $i, $c) { $GLOBALS['PROFILES'][$n][$v] = $name; ips_log('Profil ' . $n . '[' . $v . ']', $name); }
function IPS_GetObjectIDByIdent($ident, $parent) { return $GLOBALS['IDENTS'][$ident] ?? false; }
function IPS_SetHidden($id, $h) { ips_log('Hidden ' . $id, $h); }
function HEISHA_GetFunctions($id) { return $GLOBALS['W']['heisha'][$id]['fn'] ?? null; }
function WPHUB_GetFunctions($id) { return $GLOBALS['W']['wphub'][$id]['fn'] ?? null; }
function MHUB_GetFunctions($id) { return ['assignments' => [['function' => 'heatpump', 'powerID' => 9001, 'authority' => 'billing']]]; }
function NRGDASHHEAT_AckNewsApply($id) { throw new RuntimeException('Geschwister defekt'); }

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
    public function RegisterVariableBoolean($i, $n, $p, $pos) { $this->regVar($i, $n, $p, $pos); }
    public function RegisterVariableInteger($i, $n, $p, $pos) { $this->regVar($i, $n, $p, $pos); }
    private function regVar($i, $n, $p, $pos)
    {
        $GLOBALS['IDENTS'][$i] = $GLOBALS['IDENTS'][$i] ?? (5000 + count($GLOBALS['IDENTS']));
        $this->vars[$i] = $this->vars[$i] ?? null;
        ips_log('Variable ' . $i, [$n, $p, $pos]);
    }
    public function EnableAction($i) {}
    public function GetValue($i) { return $this->vars[$i]; }
    public function SetValue($i, $v) { $this->vars[$i] = $v; }
    public function RegisterTimer($n, $i, $s) {}
    public function SetTimerInterval($n, $i) {}
    public function SetVisualizationType($t) {}
    public function RegisterMessage($a, $b) {}
    public function SetStatus($s) { ips_log('SetStatus', $s); }
    public function UpdateVisualizationValue($v) { ips_log('UpdateVisualizationValue', json_decode($v, true)); }
    public function UpdateFormField($f, $k, $v) { ips_log('UpdateFormField ' . $f . '.' . $k, $v); }
    public function SendDebug($m, $t, $f) { ips_log('SendDebug ' . $m, $t); }
    public function Translate($t)
    {
        $GLOBALS['TRANSLATE_CALLS']++;
        return $GLOBALS['LANG'] === 'de' ? ($GLOBALS['DE'][$t] ?? $t) : $t;
    }
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

require_once $root . '/NRGDashboardHeatSchema/module.php';

function szenario(string $name, array $welt, array $props, array $attrs, callable $aktion, bool $frisch = true): void
{
    $GLOBALS['W'] = $welt;
    $GLOBALS['LOG'] = [];
    if ($frisch) {
        $GLOBALS['IDENTS'] = [];
        $GLOBALS['PROFILES'] = [];
    }
    $m = new NRGDashboardHeatSchema();
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
function formular(NRGDashboardHeatSchema $m): void
{
    echo 'FORM ' . json_encode(konsole(json_decode($m->GetConfigurationForm(), true)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}

// Waermepumpen-Welt: Variablen 100.. mit Werten
$vars = [100 => 1500.0, 101 => 15.0, 102 => 1450.0, 103 => 0, 104 => 36.0, 105 => 42.0, 106 => 38.5, 107 => 44.0, 108 => 40.0, 109 => 34.0,
    110 => false, 111 => 400.0, 112 => 4.2, 9001 => 1200.0];
$fnHeisha = [['Type' => 'heatpump', 'Caption' => 'Panasonic Aquarea', 'Measured' => true, 'PowerID' => 100, 'pumpFlowID' => 101, 'pumpSpeedID' => 102,
    'mainInletTempID' => 104, 'mainOutletTempID' => 105, 'z1WaterTempID' => 106, 'dhwTempID' => 107, 'bufferTempID' => 108,
    'compressorFreqID' => 109, 'defrostingStateID' => 110, 'fan1SpeedID' => 111, 'copEstimateID' => 112, 'lastSeenAt' => time() - 30]];
$fnWphub = ['Type' => 'heatpump', 'Caption' => 'WPHub Wärmepumpe', 'Measured' => false, 'lastSeenAt' => 1700000000, 'pollInterval' => 60];
$w1 = ['heisha' => [2000 => ['status' => 102, 'name' => 'HeishaMon', 'fn' => $fnHeisha]], 'vars' => $vars];
$w2 = $w1 + [];
$w2['wphub'] = [2001 => ['status' => 104, 'name' => 'WPHub', 'fn' => $fnWphub]];
$w2['meterhub'] = [3000];

szenario('keine Waermepumpe, Standard', ['vars' => $vars], [], [], function ($m) { formular($m); $m->Render(); echo $m->ResetTour() . "\n"; });
szenario('eine Waermepumpe', $w1, [], [], function ($m) { $m->Render(); formular($m); });
szenario('zwei Waermepumpen, veraltet, manuell', $w2, ['ManualEnabled' => true, 'ManualCaption' => '', 'ManualPowerID' => 100, 'ManualPumpFlowID' => 101], [], function ($m) { $m->Render(); formular($m); });
szenario('nur manuell, keine Instanz', ['vars' => $vars], ['ManualEnabled' => true, 'ManualPowerID' => 100, 'ManualMainOutletTempID' => 105, 'Zone1Caption' => 'Radiatoren'], [], function ($m) { $m->Render(); formular($m); });
for ($bauart = 0; $bauart <= 4; $bauart++) {
    for ($mode = 1; $mode <= 5; $mode++) {
        szenario("Simulation Bauart $bauart Modus $mode", $w1, [], [], function ($m) use ($bauart, $mode) {
            $m->RequestAction('Bauart', $bauart);
            $m->RequestAction('SimulationMode', $mode);
        });
    }
}
szenario('Speicherart Kombi und Frischwasser', $w1, [], [], function ($m) { $m->RequestAction('StorageType', 1); $m->RequestAction('StorageType', 2); $m->RequestAction('FlowStyle', 3); });
szenario('alles weggeklickt', $w1, [], ['SeenNews' => '0.10.12', 'PurposeIntroGone' => true, 'ReviewHintDismissed' => true], function ($m) { formular($m); });
szenario('nur 0.9.147 neu', $w1, [], ['SeenNews' => '0.9.0'], function ($m) { formular($m); });
szenario('Hinweise bestaetigen, Geschwister defekt', $w1 + ['siblings' => [1000, 1001]], [], [], function ($m) { $m->AckNews(); $m->AckPurposeIntro(); $m->DismissReviewHint(); });
szenario('zweites Create ohne frisch (bestehende Instanz)', $w1, [], [], function ($m) { $m->Create(); }, false);

// Kachel: Texttabelle (nur neuer Stand)
if (is_file($root . '/libs/NRGDASH_Text.php') && strpos((string) file_get_contents($root . '/NRGDashboardHeatSchema/module.php'), 'TILE_TEXTS') !== false) {
    $GLOBALS['W'] = $w1;
    $m = new NRGDashboardHeatSchema();
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

// Stolperstein 24: wie oft wurde Symcons Translate() insgesamt gefragt (nur mit STAT=1, sonst keine Ausgabe)
if (getenv('STAT') === '1' && class_exists('NRGDASH_Text')) {
    // 300 Durchlaeufe (Timer-Render, Kachel, Formular, Statuszeile) nach dem Anlauf: wie viele neue Anfragen?
    $GLOBALS['W'] = $w2;
    $GLOBALS['LOG'] = [];
    $m = new NRGDashboardHeatSchema();
    $m->Create();
    $m->ApplyChanges();
    $vorher = $GLOBALS['TRANSLATE_CALLS'];
    for ($i = 0; $i < 100; $i++) {
        $m->Render();
        $m->GetVisualizationTile();
        $m->GetConfigurationForm();
    }
    $erste = $GLOBALS['TRANSLATE_CALLS'] - $vorher;
    for ($i = 0; $i < 200; $i++) {
        $m->Render();
        $m->GetVisualizationTile();
        $m->GetConfigurationForm();
    }
    fwrite(STDERR, "Translate()-Anfragen: Anlauf nach Create/Apply: $vorher; erste 100 Durchlaeufe: $erste; weitere 200 Durchlaeufe: " . ($GLOBALS['TRANSLATE_CALLS'] - $vorher - $erste) . "\n");
    fwrite(STDERR, 'Translate()-Anfragen im ganzen Lauf: ' . $GLOBALS['TRANSLATE_CALLS'] . ' (NRGDASH_Text::$anfragen = ' . NRGDASH_Text::$anfragen . ")\n");
}
