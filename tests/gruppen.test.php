<?php

declare(strict_types=1);

// Prüfstand für die Gruppen über den Gruppennamen (0.16.0): NRGDashboardTile::applyDeviceGroups() und die berechnete Summe in
// resolvePowerValue(). Aufruf: php tests/gruppen.test.php
$src = file_get_contents(__DIR__ . '/../NRGDashboardTile/module.php');
$a = strpos($src, '    /** Geraete, die als Gruppenmitglied in der Geraeteliste auftauchen muessen');
$b = strpos($src, "    /**\n     * Ebene-1-Bereinigung") ?: strpos($src, '    private function hideRootsContainedInGroups(array $devices): array');
// bis zum Docblock der naechsten Methode schneiden
$b = strrpos(substr($src, 0, strpos($src, '    private function hideRootsContainedInGroups(array $devices): array')), '    /**');
if ($a === false || $b === false || $b < $a) { fwrite(STDERR, "Methode nicht gefunden\n"); exit(2); }
$grp = substr($src, $a, $b - $a);
$ra = strpos($src, '    private function resolvePowerValue(array &$device): ?float');
$rb = strpos($src, '    private function activePowerSign(array $device): int');
$rb = strrpos(substr($src, 0, $rb), '    /**');
$res = substr($src, $ra, $rb - $ra);
$ga = strpos($src, '    private function GroupSumFromMembers(array $ms): array');
$gb = strpos($src, '    private const DETAIL_MEMBER_MAX = 16;');
$sumM = substr($src, $ga, $gb - $ga);

$GLOBALS['vars'] = [];
eval('final class GrpStub {
    public array $ov = [];
    public array $vars = [];
' . $grp . $res . $sumM . '
    private function deviceOverrideMap(): array { return $this->ov; }
    private function deviceKey(array $d): string { $pid = (int) ($d["powerID"] ?? 0); return ($d["source"] ?? "") . "|" . ($d["instanceID"] ?? 0) . "|" . ($d["function"] ?? "") . "|" . ($pid > 0 ? "p" . $pid : "l" . ($d["label"] ?? "")); }
    private function overrideFor(array $d, array $o): ?array { return $o[$this->deviceKey($d)] ?? null; }
    private function normalizeDeviceCategory(string $f): string { return in_array($f, ["charger", "wallbox1", "wallbox2"], true) ? "wallbox" : $f; }
    private function attachMembers(array $d): array { $k = $this->deviceKey($d); foreach ($d["members"] as $i => &$m) { $m["key"] = $k . ">" . $i; } unset($m); $d["hasMembers"] = true; $d["memberCount"] = count($d["members"]); return $d; }
    private function resolveVariableValue(int $id): ?float { return $this->vars[$id] ?? null; }
    private function isStaleOrMissing($id, $v): bool { return $v === null; }
    private function activePowerSign(array $d): int { return (int) ($d["powerSign"] ?? 1); }
    public function groups(array $d): array { return $this->applyDeviceGroups($d); }
    public function cands(): array { return $this->groupCandidates; }
    public function value(array $d): ?float { return $this->resolvePowerValue($d); }
    public function sum(array $ms): array { return $this->GroupSumFromMembers($ms); }
}');

$fail = 0;
function check(string $n, bool $ok): void { global $fail; echo ($ok ? '  ok    ' : '  FEHLER ') . $n . "\n"; if (!$ok) { $fail++; } }
$wb = fn (string $l, int $pid, string $g = '') => ['function' => 'charger', 'label' => $l, 'powerID' => $pid, 'source' => 'chargerhub', 'instanceID' => 100 + $pid, 'groupName' => $g, 'plugStateID' => 9];
$lamp = fn (string $l, int $pid) => ['function' => 'light', 'label' => $l, 'powerID' => $pid, 'source' => 'meterhub', 'instanceID' => 50];
$find = fn (array $r, string $name) => array_values(array_filter($r, fn ($d) => ($d['source'] ?? '') === 'group' && $d['label'] === $name))[0] ?? null;

echo "Fall 1: Wallboxen mit gleichem groupName\n";
$t = new GrpStub();
$r = $t->groups([$wb('WB 1', 11, 'Ladepark'), $wb('WB 2', 12, 'ladepark'), $wb('WB 3', 13), ['function' => 'pv', 'label' => 'PV', 'powerID' => 1, 'groupName' => 'Ladepark']]);
$g = $find($r, 'Ladepark');
check('ein Gruppenknoten, Name wie beim ersten Mitglied, Groß-/Kleinschreibung egal', $g !== null && count(array_filter($r, fn ($d) => ($d['source'] ?? '') === 'group')) === 1);
check('zwei Mitglieder (WB 1, WB 2), WB 3 und PV nicht', $g['memberCount'] === 2 && array_column($g['members'], 'powerID') === [11, 12]);
check('nur Wallboxen: function wallboxgroup, kein eigenes powerID', $g['function'] === 'wallboxgroup' && $g['powerID'] === 0);
check('Summenquellen je [powerID, Skalierung, Vorzeichen]', $g['sumPowerIDs'] === [[11, 1.0, 1], [12, 1.0, 1]]);
check('Mitglieder tragen Schlüssel (Aufklappen)', $g['members'][0]['key'] === $g['members'][0]['key'] && strpos($g['members'][1]['key'], '>1') !== false);
check('Geräte-Wurzeln bleiben im Bestand (ausblenden macht hideRootsContainedInGroups)', count($r) === 4 + 1);

echo "Fall 2: Wallbox mit Licht und Werkstatt (Gruppe in der Geräteliste)\n";
$t2 = new GrpStub();
$t2->ov = ['chargerhub|111|charger|p11' => ['enabled' => true, 'name' => '', 'group' => 'Garage'],
           'meterhub|50|light|p21' => ['enabled' => true, 'name' => '', 'group' => 'Garage'],
           'meterhub|50|light|p22' => ['enabled' => true, 'name' => '', 'group' => 'garage']];
$r2 = $t2->groups([$wb('WB 1', 11), $lamp('Licht', 21), $lamp('Werkstatt', 22), $lamp('Keller', 23)]);
$g2 = $find($r2, 'Garage');
check('gemischte Gruppe aus Wallbox, Licht und Werkstatt (3 Mitglieder)', $g2 !== null && $g2['memberCount'] === 3);
check('gemischt: function other', $g2['function'] === 'other');
check('Keller (ohne Gruppe) bleibt draußen', !in_array(23, array_column($g2['members'], 'powerID'), true));
$t3 = new GrpStub();
$t3->ov = ['meterhub|50|light|p21' => ['enabled' => true, 'name' => '', 'group' => 'Licht'], 'meterhub|50|light|p22' => ['enabled' => true, 'name' => '', 'group' => 'Licht']];
$g3 = $find($t3->groups([$lamp('A', 21), $lamp('B', 22)]), 'Licht');
check('lauter gleiche Funktion: Funktion bleibt (light)', $g3 !== null && $g3['function'] === 'light');

echo "Regeln\n";
$t4 = new GrpStub();
check('eine einzelne Gruppen-Wallbox bildet keine Gruppe', $find($t4->groups([$wb('WB 1', 11, 'Solo')]), 'Solo') === null);
$t5 = new GrpStub();
$t5->ov = ['chargerhub|112|charger|p12' => ['enabled' => true, 'name' => '', 'group' => '-']];
check('"-" in der Geräteliste nimmt die Wallbox aus der Gruppe des Moduls (dann nur 1 Mitglied, keine Gruppe)', $find($t5->groups([$wb('WB 1', 11, 'Park'), $wb('WB 2', 12, 'Park')]), 'Park') === null);
$t6 = new GrpStub();
$t6->ov = ['chargerhub|112|charger|p12' => ['enabled' => false, 'name' => '', 'group' => '']];
check('ausgeblendetes Gerät tritt keiner Gruppe bei', $find($t6->groups([$wb('WB 1', 11, 'Park'), $wb('WB 2', 12, 'Park')]), 'Park') === null);
$t7 = new GrpStub();
$ladestation = ['function' => 'wallbox1', 'label' => 'Ladestation', 'powerID' => 30, 'source' => 'meterhub', 'instanceID' => 5, 'hasMembers' => true,
    'members' => [['powerID' => 11, 'factor' => 100], ['powerID' => 12, 'factor' => 100]]];
check('Wallboxen, die schon Mitglied eines Sammelzählers sind, bleiben dort (keine doppelte Zählung)', $find($t7->groups([$ladestation, $wb('WB 1', 11, 'Park'), $wb('WB 2', 12, 'Park')]), 'Park') === null);
$t8 = new GrpStub();
$r8 = $t8->groups([['function' => 'house', 'label' => 'Haus', 'powerID' => 40, 'groupName' => 'Garage'], ['function' => 'grid', 'label' => 'Netz', 'powerID' => 41, 'groupName' => 'Garage']]);
check('Kernrollen (Haus, Netz) treten keiner Gruppe bei', $find($r8, 'Garage') === null);
$t9 = new GrpStub();
$t9->groups([$wb('WB 1', 11, 'Park'), $wb('WB 2', 12, 'Park'), $lamp('Licht', 21)]);
check('Gruppen-Kandidaten für die Geräteliste: alle aufnehmbaren Geräte (3)', count($t9->cands()) === 3);
$lang = new GrpStub();
$l60 = str_repeat('x', 70);
$rl = $lang->groups([$wb('A', 11, $l60), $wb('B', 12, $l60)]);
check('Gruppenname auf 60 Zeichen begrenzt', strlen(array_values(array_filter($rl, fn ($d) => ($d['source'] ?? '') === 'group'))[0]['label']) === 60);

echo "Summe\n";
$s = new GrpStub();
$s->vars = [11 => 3000.0, 12 => 1500.0, 21 => 40.0];
check('Wert = Summe der Mitglieder', $s->value($g) === 4500.0);
check('mit Skalierung (kW) und Vorzeichen', $s->value(['powerID' => 0, 'sumPowerIDs' => [[21, 1000.0, 1], [11, 1.0, -1]]]) === 40000.0 - 3000.0);
check('fehlende Mitglieder zählen nicht, alle fehlend = null', $s->value(['powerID' => 0, 'sumPowerIDs' => [[11, 1.0, 1], [99, 1.0, 1]]]) === 3000.0 && $s->value(['powerID' => 0, 'sumPowerIDs' => [[98, 1.0, 1]]]) === null);
check('normales Gerät mit powerID liest weiter seinen Wert', $s->value(['powerID' => 11]) === 3000.0);
[$pw, $en] = $s->sum(['t' => [1000, 2000, 3000], 'days' => ['2026-11-20', '2026-11-21'], 'unit' => 'kWh', 'members' => [
    ['v' => [100.0, null, 50.0], 'kwh' => [2.0, null]], ['v' => [10.0, 20.0, null], 'kwh' => [1.0, 3.0]]]]);
check('Leistungsverlauf: Summe je Zeitpunkt, Lücken zählen nicht', $pw === [[1000, 110.0], [2000, 20.0], [3000, 50.0]]);
check('Energie je Tag: Summe der Mitglieder mit Zähler', $en['bars'] === [['2026-11-20', 3.0], ['2026-11-21', 3.0]] && $en['unit'] === 'kWh');
echo $fail === 0 ? "Alles bestanden.\n" : "$fail Fehler.\n";
exit($fail === 0 ? 0 : 1);
