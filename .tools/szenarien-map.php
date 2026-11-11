<?php

declare(strict_types=1);

// ===========================================================================
// Szenario-Lauf fuer NRGDashboardMap mit nachgebautem IPS (Stubs). Schreibt jeden sichtbaren Text als Zeilen.
//   php .tools/szenarien-map.php <Wurzelordner der Bibliothek> <de|en>
// Wird von .tools/deutsch-vergleich-map.php zweimal gestartet (alter Stand aus git, aktueller Stand) und die Ausgaben werden
// verglichen; mit "en" laesst es sich nach dem Formular in englischer Sprache lesen.
// Der Uebersetzer ist die gebaute locale.json des Moduls (so wie Symcons Translate()); was die Symcon-Konsole selbst
// uebersetzt (caption/label/... im Formular), wird hier nachgebaut (konsole()). Fehlt locale.json (alter Stand): nichts davon.
// Das HTML der Karte (GenerateHTML) wird am Ende als Liste seiner Anzeige-Literale ausgegeben (Skript-Zeichenketten, Textknoten,
// title-Attribute; ohne three.js), im neuen Stand mit eingesetzter Texttabelle.
// ===========================================================================

$root = $argv[1] ?? dirname(__DIR__);
$lang = $argv[2] ?? 'de';
$GLOBALS['LANG'] = $lang;
$locFile = $root . '/NRGDashboardMap/locale.json';
$GLOBALS['DE'] = is_file($locFile) ? (json_decode((string) file_get_contents($locFile), true)['translations']['de'] ?? []) : [];
$GLOBALS['THREE'] = (string) file_get_contents($root . '/NRGDashboardMap/three.min.js');

const KR_READY = 10103;
const IHUB_G = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';
const MHUB_G = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
const CHUB_G = '{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}';
const HEISHA_G = '{1919151A-3C0F-4C09-B906-291638EC1469}';
const TESSIE_G = '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}';
const SELF_G = '{B5345E99-061C-47C4-B870-0489B61BC57E}';

/** Zustand der nachgebauten Anlage, je Szenario neu gesetzt */
$GLOBALS['W'] = [];
$GLOBALS['LOG'] = [];
$GLOBALS['HTML'] = '';

function ips_log(string $what, $data): void
{
    $GLOBALS['LOG'][] = $what . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_GetInstanceListByModuleID($guid)
{
    $W = $GLOBALS['W'];
    switch ($guid) {
        case IHUB_G:
            return array_keys($W['ihub'] ?? []);
        case MHUB_G:
            return array_keys($W['mhub'] ?? []);
        case CHUB_G:
            return array_keys($W['chub'] ?? []);
        case HEISHA_G:
            return array_keys($W['heisha'] ?? []);
        case TESSIE_G:
            return array_keys($W['tessie'] ?? []);
        case SELF_G:
            return $W['siblings'] ?? [];
    }
    return [];
}
function IPS_InstanceExists($id)
{
    $W = $GLOBALS['W'];
    foreach (['ihub', 'mhub', 'chub', 'heisha', 'tessie'] as $k) {
        if (isset($W[$k][$id])) {
            return true;
        }
    }
    return in_array($id, $W['siblings'] ?? [], true) || $id === 1000;
}
function IPS_GetInstance($id)
{
    $e = $GLOBALS['W']['ihub'][$id] ?? ['status' => 102];
    return ['InstanceStatus' => $e['status'], 'ModuleInfo' => ['ModuleID' => IHUB_G]];
}
function IPS_GetName($id)
{
    $W = $GLOBALS['W'];
    return $W['ihub'][$id]['name'] ?? $W['heisha'][$id] ?? 'Inst' . $id;
}
function IPS_GetLibrary($guid) { return ['Version' => '0.10.12', 'Build' => 135]; }
function IPS_GetProperty($id, $n) { return '[]'; }
function IPS_SetProperty($id, $n, $v) {}
function IPS_ApplyChanges($id) {}
function IPS_GetChildrenIDs($id)
{
    $out = [];
    foreach ($GLOBALS['W']['ihub'][$id]['idents'] ?? [] as $i => $ident) {
        $out[] = $id * 10 + $i + 1;
    }
    return $out;
}
function IPS_GetObject($id)
{
    $parent = intdiv($id, 10);
    $idents = $GLOBALS['W']['ihub'][$parent]['idents'] ?? [];
    return ['ObjectIdent' => $idents[$id % 10 - 1] ?? '', 'HasChildren' => false];
}
function SetValue($id, $v)
{
    $GLOBALS['HTML'] = (string) $v;
    ips_log('SetValue', '[HTML der Karte]');
}
function IHUB_GetFunctions($id) { return $GLOBALS['W']['ihub'][$id]['functions'] ?? null; }
function MHUB_GetFunctions($id) { return $GLOBALS['W']['mhub'][$id] ?? null; }
function CHUB_GetFunctions($id) { return $GLOBALS['W']['chub'][$id] ?? null; }
function TESSIE_GetVehicleState($id) { return $GLOBALS['W']['tessie'][$id] ?? null; }
function NRGDASHMAP_AckNewsApply($id) { throw new RuntimeException('Geschwister defekt'); }

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
    public function RegisterVariableString($i, $n, $p) {}
    public function GetIDForIdent($i) { return 9999; }
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
    public function SetStatus($s) { ips_log('SetStatus', $s); }
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

require_once $root . '/NRGDashboardMap/module.php';

function szenario(string $name, array $welt, array $props, array $attrs, callable $aktion): void
{
    $GLOBALS['W'] = $welt;
    $GLOBALS['LOG'] = [];
    $m = new NRGDashboardMap();
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
function formular(NRGDashboardMap $m): void
{
    echo 'FORM ' . json_encode(konsole(json_decode($m->GetConfigurationForm(), true)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}
function entdeckung(NRGDashboardMap $m): void
{
    echo 'DISCOVER ' . json_encode($m->Discover(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

$mppt = ['mppt1_power', 'mppt2_power', 'other', 'mppt4_power'];
$ihubVoll = [3000 => ['status' => 102, 'name' => 'WR Dach', 'idents' => $mppt, 'functions' => ['contractVersion' => '2.1', 'pvPowerID' => 11, 'batPowerID' => 12, 'gridPowerID' => 13]]];
$ihubOhneVertrag = [3000 => ['status' => 102, 'name' => 'WR Dach', 'idents' => $mppt, 'functions' => ['pvPowerID' => 11, 'batPowerID' => 0]]];
$ihubLeer = [3000 => ['status' => 102, 'name' => 'WR Dach', 'idents' => [], 'functions' => ['contractVersion' => '2.1']]];
$ihubLeerOhne = [3000 => ['status' => 104, 'name' => 'WR Dach', 'idents' => [], 'functions' => []]];
$mhub = [4000 => ['assignments' => [
    ['function' => 'grid', 'label' => 'Netz Inexogy', 'powerID' => 41],
    ['function' => 'grid', 'label' => 'Netz PAC2200', 'powerID' => 42],
    ['function' => 'house', 'label' => 'Haus', 'powerID' => 43],
    ['function' => 'heatpump', 'label' => 'Waermepumpe', 'powerID' => 44],
    ['function' => 'stove', 'powerID' => 45],
    ['function' => 'pv', 'label' => 'PV Zaehler', 'powerID' => 46],
]]];
$welt = ['ihub' => $ihubVoll, 'mhub' => $mhub, 'heisha' => [5000 => 'HeishaMon Keller'],
    'chub' => [6000 => [['label' => 'WB Garage'], ['x' => 1]], 6001 => json_encode([['label' => 'WB Carport']])],
    'tessie' => [7000 => ['name' => 'Model Y'], 7001 => json_encode(['x' => 1]), 7002 => ['nix' => 1]]];

szenario('keine Geraete, Standard (Formular, Tour, Suche)', [], [], [], function ($m) { formular($m); echo $m->ResetTour() . "\n"; entdeckung($m); formular($m); });
szenario('voller Verbund', $welt, [], [], function ($m) { entdeckung($m); formular($m); });
szenario('InverterHub ohne Vertrag, nur PV', ['ihub' => $ihubOhneVertrag], [], [], function ($m) { formular($m); });
szenario('InverterHub ohne Leistungen, mit Vertrag', ['ihub' => $ihubLeer], [], [], function ($m) { formular($m); });
szenario('InverterHub ohne Leistungen, ohne Vertrag, inaktiv', ['ihub' => $ihubLeerOhne], [], [], function ($m) { formular($m); });
szenario('InverterHub gewaehlt, ohne Leistungen, mit Vertrag', ['ihub' => $ihubLeer], ['InverterInstance' => 3000], [], function ($m) { formular($m); });
szenario('InverterHub gewaehlt, ohne Leistungen, ohne Vertrag', ['ihub' => $ihubLeerOhne], ['InverterInstance' => 3000], [], function ($m) { formular($m); });
szenario('InverterHub gewaehlt, mit Vertrag', ['ihub' => $ihubVoll], ['InverterInstance' => 3000], [], function ($m) { formular($m); });
szenario('InverterHub gewaehlt, ohne Vertrag', ['ihub' => $ihubOhneVertrag], ['InverterInstance' => 3000], [], function ($m) { formular($m); });
szenario('zwei InverterHub, keiner gewaehlt', ['ihub' => $ihubVoll + [3001 => ['status' => 104, 'name' => 'WR Garage', 'idents' => [], 'functions' => []]]], [], [], function ($m) { formular($m); });
szenario('InverterHub liefert JSON-Text', ['ihub' => [3000 => ['status' => 102, 'name' => 'WR', 'idents' => $mppt, 'functions' => json_encode(['batPowerID' => 5])]]], [], [], function ($m) { entdeckung($m); });
szenario('ein Geraet gefunden (Einzahl)', ['heisha' => [5000 => 'HeishaMon Keller']], [], [], function ($m) { entdeckung($m); formular($m); });
szenario('alles weggeklickt', $welt, [], ['SeenNews' => '0.10.12', 'PurposeIntroGone' => true, 'ReviewHintDismissed' => true], function ($m) { entdeckung($m); formular($m); });
szenario('nur 0.7.6 neu', $welt, [], ['SeenNews' => '0.7.5'], function ($m) { formular($m); });
szenario('Aufbau: zwei ausgeblendet, Position', $welt, ['FormLayout' => json_encode([['Key' => 'style', 'Hidden' => true, 'Pos' => 1], ['Key' => 'details', 'Hidden' => true, 'Pos' => 2], ['Key' => 'source', 'Closed' => true, 'Pos' => 3]])], [], function ($m) { entdeckung($m); formular($m); });
szenario('Aufbau zuruecksetzen', [], [], [], function ($m) { echo $m->ResetFormLayout() . "\n"; });
szenario('Hinweise bestaetigen, Geschwister defekt', ['siblings' => [1000, 1001]], [], [], function ($m) { $m->AckNews(); $m->AckPurposeIntro(); $m->DismissReviewHint(); });
szenario('Tour-Bestaetigung (Hook)', [], [], [], function ($m) { $_GET['dismissTour'] = '1'; @$m->ProcessHookData(); unset($_GET['dismissTour']); echo json_encode($m->attrs['TourSeen']); });

// Kachel: Anzeige-Literale des Karten-HTML (ohne three.js), im neuen Stand mit eingesetzter Texttabelle
$GLOBALS['W'] = $welt;
$m = new NRGDashboardMap();
$m->Create();
$m->ApplyChanges();
if (getenv('MAP_HTML_OUT')) {
    file_put_contents((string) getenv('MAP_HTML_OUT'), $GLOBALS['HTML']); // fuer die Sichtprobe / node --check
}
$html = str_replace($GLOBALS['THREE'], '[three.js]', $GLOBALS['HTML']);
$tabelle = [];
if (preg_match('/<script>window\.NRGDASH_TEXTS = (\{.*?\});<\/script>/s', $html, $mm)) {
    $tabelle = json_decode($mm[1], true) ?: [];
    $html = str_replace($mm[0], '', $html);
    $html = str_replace('<!--NRGDASH_TEXTS-->', '', $html);
}
$unesc = fn (string $s) => str_replace("\\'", "'", $s);
$wert = function (string $en) use ($tabelle, $unesc) {
    $en = $unesc($en);
    return $tabelle[$en] ?? $en;
};
if ($tabelle !== []) {
    // tr('English') -> 'Deutsch' (bzw. Englisch), wie es die Karte im Browser auswertet
    $html = preg_replace_callback("/\\btr\\('((?:[^'\\\\]|\\\\.)*)'\\)/", fn ($x) => "'" . $wert($x[1]) . "'", $html);
}
$skriptOhne = preg_replace(['~/\*.*?\*/~s', '~(?<![:"\'])//[^\n]*~'], '', $html);
$lit = [];
preg_match_all("/'((?:[^'\\\\\n]|\\\\.)*)'/", (string) $skriptOhne, $lm);
foreach ($lm[1] as $s) {
    $lit[] = $unesc($s);
}
// Textknoten und title-Attribute (feste Texte im HTML; im neuen Stand setzt das Skript sie per tr() beim Laden)
preg_match_all('/>([^<>{}]*[^<>{}\s][^<>{}]*)</', $html, $tm);
foreach ($tm[1] as $s) {
    $lit[] = $tabelle !== [] ? $wert(trim($s)) : trim($s);
}
preg_match_all('/\btitle="([^"]*)"/', $html, $am);
foreach ($am[1] as $s) {
    $lit[] = $tabelle !== [] ? $wert($s) : $s;
}
$lit = array_map(fn ($s) => str_replace(['%d', '%s'], '', $s), $lit);
$lit = array_values(array_unique(array_filter($lit, fn ($s) => $s !== '')));
sort($lit, SORT_STRING);
echo "=== Kachel-Literale\n";
foreach ($lit as $s) {
    echo json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
if ($tabelle !== []) {
    echo "=== Kachel-Texttabelle\n" . json_encode($tabelle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

// Stolperstein 24: Wie oft fragt das Modul Symcons Translate() wirklich? (nur neuer Stand; steht hinter der Texttabelle, der Vergleich schneidet es ab)
if (class_exists('NRGDASH_Text')) {
    $GLOBALS['W'] = $welt;
    $m = new NRGDashboardMap();
    $m->Create();
    $m->ApplyChanges();
    NRGDASH_Text::$anfragen = 0;
    for ($i = 0; $i < 200; $i++) {
        $m->Discover();
    }
    for ($i = 0; $i < 100; $i++) {
        $m->GetConfigurationForm();
    }
    $anzahl = NRGDASH_Text::$anfragen;
    $m->ApplyChanges(); // Merkliste leeren (wie ein "Uebernehmen") und noch einmal
    NRGDASH_Text::$anfragen = 0;
    $m->Discover();
    $m->GetConfigurationForm();
    echo "=== Translate-Anfragen\n200 x Discover + 100 x GetConfigurationForm nach Warmlauf: $anzahl\nnach Uebernehmen, 1 x Discover + 1 x Formular: " . NRGDASH_Text::$anfragen . "\n";
}
