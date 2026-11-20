<?php

declare(strict_types=1);

// Prüfstand für NRGDashboardTile::discoverEstimateMeters() (Schätzzähler MeterHubEstimate, 0.13.1). Aufruf: php tests/schaetzzaehler.test.php
// Schätzwerte werden nur angezeigt: measured/energyMeasured false, estimated true, nie als Solar/Batterie/Netz/Haus.
$src = file_get_contents(__DIR__ . '/../NRGDashboardTile/module.php');
$a = strpos($src, '    private function discoverEstimateMeters(): array');
$b = strpos($src, '    private function isMhubvInstance(int $inst): bool');
if ($a === false || $b === false) { fwrite(STDERR, "Methode nicht gefunden\n"); exit(2); }
$method = substr($src, $a, $b - $a);
define('NRGDASH_GUID_METERHUBE', '{D9F12FFD-BA63-4A91-B1DE-B1CFCB118B6D}');
if (strpos($src, "define('NRGDASH_GUID_METERHUBE',      '{D9F12FFD-BA63-4A91-B1DE-B1CFCB118B6D}');") === false) { fwrite(STDERR, "GUID-Konstante fehlt oder falsch\n"); exit(2); }

eval('final class EstStub {
    public array $liste = [];
    public array $aufrufe = [];
    private function discoverListContract(string $guid, string $fn, string $source): array { $this->aufrufe[] = [$guid, $fn, $source]; return $this->liste; }
' . $method . '
    public function run(): array { return $this->discoverEstimateMeters(); }
}');

$fail = 0;
function check(string $n, bool $ok): void { global $fail; echo ($ok ? '  ok    ' : '  FEHLER ') . $n . "\n"; if (!$ok) { $fail++; } }
$t = new EstStub();
$t->liste = [
    ['function' => 'other', 'label' => 'Trockner (geschätzt)', 'powerID' => 11, 'measured' => true, 'energyMeasured' => true, 'source' => 'meterhub'],
    ['function' => 'heatpump', 'label' => 'WP', 'powerID' => 12, 'source' => 'meterhub'],
    ['function' => 'grid', 'label' => 'Netz?', 'powerID' => 13, 'source' => 'meterhub'],
    ['function' => 'house', 'label' => 'Haus?', 'powerID' => 14, 'source' => 'meterhub'],
    ['function' => 'pv', 'label' => 'PV?', 'powerID' => 15, 'source' => 'meterhub'],
    ['function' => 'battery', 'label' => 'Akku?', 'powerID' => 16, 'source' => 'meterhub'],
];
$r = $t->run();
check('GUID und Vertrag richtig abgefragt', $t->aufrufe === [['{D9F12FFD-BA63-4A91-B1DE-B1CFCB118B6D}', 'MHUBE_GetFunctions', 'meterhub']]);
check('Verbraucher und Wärmepumpe bleiben (2)', count($r) === 2);
check('Kernrollen Solar/Batterie/Netz/Haus werden nicht übernommen', !array_filter($r, fn ($e) => in_array($e['function'], ['pv', 'battery', 'grid', 'house'], true)));
check('measured false auch wenn der Vertrag true meldet', $r[0]['measured'] === false);
check('energyMeasured false', $r[0]['energyMeasured'] === false && $r[1]['energyMeasured'] === false);
check('estimated true', $r[0]['estimated'] === true && $r[1]['estimated'] === true);
check('Quelle meterhub bleibt', $r[0]['source'] === 'meterhub');
$t->liste = [];
check('ohne Schätzzähler leer', $t->run() === []);
echo $fail === 0 ? "Alles bestanden.\n" : "$fail Fehler.\n";
exit($fail === 0 ? 0 : 1);
