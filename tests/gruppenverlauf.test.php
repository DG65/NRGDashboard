<?php

declare(strict_types=1);

// Prüfstand für NRGDashboardTile::DetailMemberSeries() (Detailseite: Summe / Einzeln / Gestapelt, 0.14.0). Aufruf: php tests/gruppenverlauf.test.php
require_once __DIR__ . '/../libs/NRGDASH_Text.php';
$src = file_get_contents(__DIR__ . '/../NRGDashboardTile/module.php');
$a = strpos($src, '    private const DETAIL_MEMBER_MAX = 16;');
$b = strpos($src, '    private function ResolveSubMeters(array $d): array');
if ($a === false || $b === false) { fwrite(STDERR, "Methode nicht gefunden\n"); exit(2); }
$method = substr($src, $a, $b - $a);

eval('final class GvStub {
    public array $members = [];
    public array $series = [];
    public array $bars = [];
' . $method . '
    private function MembersForKey(string $key): array { return $this->members; }
    private function DaySeries(int $vid, int $from, int $to): array { return $this->series[$vid] ?? []; }
    private function synthesizeMemberDevice(array $m, ?array $n): array { return ["powerID" => $m["powerID"]]; }
    private function DailyEnergyBars(array $d, int $dayStart, bool $integrate = true): array { return $this->bars[$d["powerID"]] ?? ["bars" => [], "unit" => "", "approx" => false]; }
    public function run(string $key, array $d): ?array { return $this->DetailMemberSeries($key, $d, 0, 100); }
}');

$fail = 0;
function check(string $n, bool $ok): void { global $fail; echo ($ok ? '  ok    ' : '  FEHLER ') . $n . "\n"; if (!$ok) { $fail++; } }
$m = fn (string $l, int $pid, float $f = 100, string $fn = 'other', bool $deeper = false) => ['key' => "K>$pid", 'label' => $l, 'function' => $fn, 'factor' => $f, 'powerID' => $pid, 'hasMembers' => $deeper];

$t = new GvStub();
$d = ['hasMembers' => true, 'function' => 'house'];
check('ohne Mitglieder: null', $t->run('K', ['hasMembers' => false, 'function' => 'house']) === null);
$t->members = [$m('A', 1)];
check('ein Mitglied: null', $t->run('K', $d) === null);

$t->members = [$m('Haus', 1), $m('Wallbox', 2, -100, 'other', true), $m('Netz', 3, 100, 'grid'), $m('ohne ID', 0)];
$t->series = [
    1 => [[1000, 500.0], [2000, 600.0]],
    2 => [[2000, 100.0], [3000, 150.0]],
    3 => [[1000, 10.0]],
];
$t->bars = [1 => ['bars' => [['2026-11-19', 4.0], ['2026-11-20', 5.0]], 'unit' => 'kWh', 'approx' => false], 2 => ['bars' => [['2026-11-20', 2.0]], 'unit' => 'kWh', 'approx' => false]];
$r = $t->run('K', $d);
check('Mitglied ohne Leistungsvariable fällt weg (3)', $r !== null && count($r['members']) === 3);
check('gemeinsame Zeitachse (Vereinigung)', $r['t'] === [1000, 2000, 3000]);
check('Wert mit Lücke ist null', $r['members'][0]['v'] === [500.0, 600.0, null]);
check('abgezogenes Mitglied negativ', $r['members'][1]['v'] === [null, -100.0, -150.0] && $r['members'][1]['sign'] === -1);
check('Netz-Mitglied gedreht', $r['members'][2]['v'][0] === -10.0);
check('Tage vereinigt', $r['days'] === ['2026-11-19', '2026-11-20']);
check('Energie je Mitglied, Vorzeichen des Faktors', $r['members'][0]['kwh'] === [4.0, 5.0] && $r['members'][1]['kwh'] === [null, -2.0]);
check('Mitglied ohne Zähler: nur null', $r['members'][2]['kwh'] === [null, null]);
check('Einheit', $r['unit'] === 'kWh');
check('tiefere Ebene gemeldet', $r['members'][1]['deeper'] === true && $r['members'][0]['deeper'] === false);
check('nichts weggelassen', $r['omitted'] === 0);

$t->members = []; $t->series = [];
for ($i = 1; $i <= 20; $i++) { $t->members[] = $m("M$i", $i); $t->series[$i] = [[1000, 1.0]]; }
$r = $t->run('K', $d);
check('höchstens 16 Mitglieder, Rest gezählt', count($r['members']) === 16 && $r['omitted'] === 4);
$f = new GvStub(); $f->members = [$m('X', 1, 50), $m('Y', 2)]; $f->series = [1 => [[1000, 100.0]], 2 => [[1000, 7.0]]];
$r = $f->run('K', $d);
check('Faktor 50 % skaliert', $r['members'][0]['v'] === [50.0]);
echo $fail === 0 ? "Alles bestanden.\n" : "$fail Fehler.\n";
exit($fail === 0 ? 0 : 1);
