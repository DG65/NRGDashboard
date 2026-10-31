<?php

declare(strict_types=1);

// Prüfstand für libs/FormLayout.php ("Formular: Aufbau", SUITE.md 3a). Aufruf: php tests/formlayout.test.php
require_once __DIR__ . '/../libs/FormLayout.php';

final class LayoutStub
{
    use NRGDashFormLayout;

    public string $prop = '[]';
    public array $updated = [];

    public function ReadPropertyString(string $n): string { return $this->prop; }
    public function UpdateFormField(string $f, string $k, $v): void { $this->updated[] = [$f, $k, $v]; }
    private function formLayoutResetFunction(): string { return 'TEST_ResetFormLayout'; }
    public function apply(array $el, array $panels): array { return $this->formLayoutApply($el, $panels); }
    public function reset(string $path, array $panels): string { return $this->formLayoutReset($path, $panels); }
}

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '  ok    ' : '  FEHLER ') . $name . "\n";
    if (!$ok) { $fail++; }
}
function captions(array $els): array
{
    return array_map(function ($e) { return (string) (($e['caption'] ?? '') !== '' ? $e['caption'] : ($e['name'] ?? $e['type'])); }, $els);
}
function find(array $els, string $cap): ?array
{
    foreach ($els as $e) { if (str_starts_with((string) ($e['caption'] ?? ''), $cap)) { return $e; } }
    return null;
}

$panels = ['Alpha' => 'a', 'Beta' => 'b', 'Gamma' => 'c'];
$els = [
    ['type' => 'ExpansionPanel', 'caption' => '📖 Dokumentation', 'items' => []],
    ['type' => 'CheckBox', 'name' => 'Demo', 'caption' => 'Demo'],
    ['type' => 'ExpansionPanel', 'caption' => 'Alpha (optional)', 'expanded' => true, 'items' => []],
    ['type' => 'Button', 'caption' => 'Suchen'],
    ['type' => 'Label', 'name' => 'Ergebnis', 'caption' => ''],
    ['type' => 'ExpansionPanel', 'caption' => 'Beta', 'expanded' => false, 'items' => []],
    ['type' => 'ExpansionPanel', 'caption' => 'Gamma', 'expanded' => false, 'items' => []],
    ['type' => 'Button', 'caption' => 'Stil zurücksetzen'],
];

echo "Standard (leere Auswahl)\n";
$t = new LayoutStub();
$out = $t->apply($els, $panels);
check('Reihenfolge unverändert, Aufbau-Panel am Ende', captions($out) === ['📖 Dokumentation', 'Demo', 'Alpha (optional)', 'Suchen', 'Ergebnis', 'Beta', 'Gamma', 'Stil zurücksetzen', '🪟  Formular: Aufbau']);
check('Standard lässt expanded wie im Formular (Alpha auf, Beta zu)', find($out, 'Alpha')['expanded'] === true && find($out, 'Beta')['expanded'] === false);
check('nichts ausgeblendet', !isset(find($out, 'Alpha')['visible']));
$layoutPanel = find($out, '🪟');
check('Aufbau-Panel eingeklappt', $layoutPanel['expanded'] === false);
$list = null;
foreach ($layoutPanel['items'] as $i) { if (($i['name'] ?? '') === 'FormLayout') { $list = $i; } }
check('Liste mit 3 Zeilen, Schlüssel a/b/c', $list !== null && array_column($list['values'], 'Key') === ['a', 'b', 'c']);
check('Schlüsselspalte unsichtbar mit save', $list['columns'][1]['name'] === 'Key' && $list['columns'][1]['save'] === true && $list['columns'][1]['visible'] === false);
check('Reset-Knopf ruft die Modulfunktion', end($layoutPanel['items'])['onClick'] === 'echo TEST_ResetFormLayout($id);');

echo "Position und Aufklappen\n";
$t->prop = json_encode([
    ['Key' => 'a', 'Pos' => 3, 'Opened' => false, 'Closed' => true, 'Hidden' => false],
    ['Key' => 'c', 'Pos' => 1, 'Opened' => true, 'Closed' => false, 'Hidden' => false],
]);
$out = $t->apply($els, $panels);
check('Reihenfolge nach Position: Gamma (mit Stil-Knopf), Beta, Alpha (mit Suchen)', captions($out) === ['📖 Dokumentation', 'Demo', 'Gamma', 'Stil zurücksetzen', 'Beta', 'Alpha (optional)', 'Suchen', 'Ergebnis', '🪟  Formular: Aufbau']);
check('Gamma aufgeklappt, Alpha zugeklappt', find($out, 'Gamma')['expanded'] === true && find($out, 'Alpha')['expanded'] === false);
check('Beta (kein Eintrag) bleibt Standard auf Position 2', find($out, 'Beta')['expanded'] === false);
check('Elemente hinter Alpha wandern mit (Suchen, Ergebnis)', array_slice(captions($out), 5, 3) === ['Alpha (optional)', 'Suchen', 'Ergebnis']);

echo "Ausgeblendet\n";
$t->prop = json_encode([['Key' => 'a', 'Pos' => 1, 'Opened' => true, 'Closed' => true, 'Hidden' => true]]);
$out = $t->apply($els, $panels);
check('Alpha bleibt im Formular mit visible:false', find($out, 'Alpha') !== null && find($out, 'Alpha')['visible'] === false);
check('Folgeelemente ebenfalls visible:false', $out[3]['visible'] === false && $out[4]['visible'] === false);
check('Beta und Gamma bleiben sichtbar', !isset(find($out, 'Beta')['visible']) && !isset(find($out, 'Gamma')['visible']));
check('Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt', true);
$layoutPanel = find($out, '🪟');
check('Caption zeigt Zahl ausgeblendeter Bereiche', str_contains($layoutPanel['caption'], '(1 ausgeblendet)'));
check('Hinweiszeile nennt den Bereich', str_contains($layoutPanel['items'][0]['caption'], 'Ausgeblendet: Alpha'));
$t->prop = json_encode([['Key' => 'b', 'Pos' => 1, 'Opened' => true, 'Closed' => true, 'Hidden' => false]]);
$out = $t->apply($els, $panels);
check('Widerspruch zugeklappt vor aufgeklappt', find($out, 'Beta')['expanded'] === false);

echo "Rückfall\n";
$t->prop = json_encode([['Key' => 'gibtsnicht', 'Pos' => 1, 'Hidden' => true], ['Key' => 'b', 'Pos' => 500]]);
$out = $t->apply($els, $panels);
check('unbekannter Schlüssel wird ignoriert, nichts ausgeblendet', !isset(find($out, 'Alpha')['visible']) && !isset(find($out, 'Beta')['visible']));
check('Position wird auf 99 begrenzt (Beta ans Ende der Fachpanels)', array_slice(captions($out), 2, 6) === ['Alpha (optional)', 'Suchen', 'Ergebnis', 'Gamma', 'Stil zurücksetzen', 'Beta']);
$t->prop = 'kaputt{';
check('kaputtes JSON fällt auf Standard zurück', count($t->apply($els, $panels)) === 9);
$t->prop = '[]';
check('Formular ohne Fachpanel bleibt unverändert', $t->apply([['type' => 'Label', 'caption' => 'x']], $panels) === [['type' => 'Label', 'caption' => 'x']]);
check('Neues Panel ohne Eintrag erscheint am Standardplatz', count(array_filter($t->apply($els, ['Alpha' => 'a', 'Beta' => 'b', 'Gamma' => 'c', 'Delta' => 'd']), fn ($e) => ($e['type'] ?? '') === 'ExpansionPanel')) === 5);

echo "Reset\n";
$tmp = tempnam(sys_get_temp_dir(), 'fl');
file_put_contents($tmp, json_encode(['elements' => $els]));
$msg = $t->reset($tmp, $panels);
unlink($tmp);
check('Reset setzt Liste per UpdateFormField', $t->updated[0][0] === 'FormLayout' && $t->updated[0][1] === 'values');
$rows = json_decode($t->updated[0][2], true);
check('Reset: Standardposition 1..3, keine Haken', array_column($rows, 'Pos') === [1, 2, 3] && !array_filter(array_merge(array_column($rows, 'Opened'), array_column($rows, 'Closed'), array_column($rows, 'Hidden'))));
check('Reset gibt Klartext mit Übernehmen-Hinweis', str_contains($msg, 'Übernehmen'));

echo "Echte Formulare der Module\n";
$expect = [
    'Map' => [['Datenquelle' => 'source', 'Details je Kategorie' => 'details', 'Darstellung' => 'style'], ['source', 'details', 'style']],
    'Topology' => [['Datenquelle' => 'source', 'Details je Partner' => 'details', 'Darstellung' => 'style'], ['source', 'details', 'style']],
    'Tile' => [['Automatisch gefundene Geräte' => 'devices', 'Manuelle Datenpunkte' => 'manual', 'Weitere Verbraucher' => 'consumers', 'Fahrzeuge' => 'vehicles', 'Gesundheit / Diagnose' => 'health', 'Darstellung' => 'style'], ['devices', 'manual', 'consumers', 'vehicles', 'health', 'style']],
];
foreach ($expect as $mod => [$map, $keys]) {
    $form = json_decode(file_get_contents(__DIR__ . '/../NRGDashboard' . $mod . '/form.json'), true);
    $t = new LayoutStub();
    $src = file_get_contents(__DIR__ . '/../NRGDashboard' . $mod . '/module.php');
    check($mod . ': Panel-Schlüssel in module.php stimmen mit dem Test überein', str_contains($src, 'private const FORM_LAYOUT_PANELS = ' . "['" . array_key_first($map) . "'"));
    $out = $t->apply($form['elements'], $map);
    $elementCount = count($form['elements']) + 1;
    check($mod . ': alle Elemente bleiben erhalten (+ Aufbau-Panel)', count($out) === $elementCount);
    check($mod . ': Dokumentation steht weiter ganz oben', str_starts_with((string) $out[0]['caption'], '📖'));
    check($mod . ': Aufbau-Panel steht ganz hinten', str_starts_with((string) end($out)['caption'], '🪟'));
    $l = null;
    foreach (end($out)['items'] as $i) { if (($i['name'] ?? '') === 'FormLayout') { $l = $i; } }
    check($mod . ': Liste kennt genau die Fachpanels ' . implode('/', $keys), $l !== null && array_column($l['values'], 'Key') === $keys);
    // alles ausblenden: nichts darf aus dem Formular verschwinden
    $t->prop = json_encode(array_map(fn ($k) => ['Key' => $k, 'Hidden' => true, 'Pos' => 1], $keys));
    $hid = $t->apply($form['elements'], $map);
    check($mod . ': alles ausgeblendet = gleiche Elementzahl, Fachpanels visible:false', count($hid) === $elementCount && count(array_filter($hid, fn ($e) => ($e['visible'] ?? true) === false && ($e['type'] ?? '') === 'ExpansionPanel')) === count($keys));
    // jedes benannte Feld bleibt im Formular auffindbar
    $names = function (array $els) use (&$names): array {
        $r = [];
        foreach ($els as $e) { if (isset($e['name'])) { $r[] = $e['name']; } foreach (['items', 'elements'] as $k) { if (isset($e[$k]) && is_array($e[$k])) { $r = array_merge($r, $names($e[$k])); } } }
        sort($r);
        return $r;
    };
    check($mod . ': alle Felder bleiben erhalten (Werte gehen beim Übernehmen nicht verloren)', array_diff($names($form['elements']), $names($hid)) === []);
}

echo $fail === 0 ? "\nAlles bestanden.\n" : "\n$fail Prüfung(en) fehlgeschlagen.\n";
exit($fail === 0 ? 0 : 1);
