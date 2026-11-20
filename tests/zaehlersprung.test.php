<?php

declare(strict_types=1);

// Prüfstand für NRGDashboardTile::CorrectImplausibleCounterDays() (Zählersprung im Archiv, 0.14.1). Aufruf: php tests/zaehlersprung.test.php
$src = file_get_contents(__DIR__ . '/../NRGDashboardTile/module.php');
$a = strpos($src, '    private function CorrectImplausibleCounterDays(array $bars, int $powerID): array');
$b = strpos($src, "    /**\n     * Tages-Energiebalken der letzten 14 Tage");
if ($a === false || $b === false || $b < $a) { fwrite(STDERR, "Methode nicht gefunden\n"); exit(2); }
$method = substr($src, $a, $b - $a);

eval('final class ZsStub {
    public array $series = [];
    public int $calls = 0;
' . $method . '
    private function DaySeries(int $vid, int $from, int $to): array { $this->calls++; return $this->series[date("Y-m-d", $from)] ?? []; }
    public function run(array $bars, int $pid): array { return $this->CorrectImplausibleCounterDays($bars, $pid); }
}');

$fail = 0;
function check(string $n, bool $ok): void { global $fail; echo ($ok ? '  ok    ' : '  FEHLER ') . $n . "\n"; if (!$ok) { $fail++; } }
// 288 Punkte à 5 min mit konstant w Watt -> w * 24 / 1000 kWh
$tag = function (float $w): array { $o = []; for ($i = 0; $i < 288; $i++) { $o[] = [1700000000000 + $i * 300000, $w]; } return $o; };

$t = new ZsStub();
$t->series = ['2026-11-09' => $tag(10.0), '2026-11-10' => $tag(2000.0), '2026-11-11' => $tag(2000.0)];
[$bars, $fixed] = $t->run([['2026-11-08', 0.2], ['2026-11-09', 54.8], ['2026-11-10', 48.0], ['2026-11-11', 200.0]], 5);
check('Sprung (54,8 kWh bei 0,24 kWh Leistung) wird korrigiert', $bars[1][1] === 0.24 && $fixed === ['2026-11-09', '2026-11-11']);
check('plausibler grosser Tag (48 kWh bei 48 kWh) bleibt', $bars[2][1] === 48.0);
check('2000 W durchgehend = 48 kWh, 200 kWh ist unplausibel und wird 48,0', $bars[3][1] === 48.0);
check('kleine Tage (≤ 5 kWh) werden nie angefasst und kosten keine Abfrage', $bars[0][1] === 0.2 && $t->calls === 3);

$t2 = new ZsStub();
[$b2, $f2] = $t2->run([['2026-11-09', 54.8]], 5);
check('ohne Leistungsreihe (< 12 Punkte): unverändert', $b2[0][1] === 54.8 && $f2 === []);
[$b3, $f3] = (new ZsStub())->run([['2026-11-09', 54.8]], 0);
check('ohne Leistungsvariable: unverändert', $b3[0][1] === 54.8 && $f3 === []);
$t4 = new ZsStub(); $t4->series = ['2026-11-09' => $tag(1000.0)];
[$b4, $f4] = $t4->run([['2026-11-09', 30.0]], 5);
check('Zähler 30 kWh bei 24 kWh Leistung bleibt (unter 3-fach + 2)', $b4[0][1] === 30.0 && $f4 === []);
echo $fail === 0 ? "Alles bestanden.\n" : "$fail Fehler.\n";
exit($fail === 0 ? 0 : 1);
