<?php

declare(strict_types=1);

// Prueft die Delta-Aktualisierung des PV-Monitorings (0.12.0): libs/Delta.php berechnet die Unterschiede, applyTail()/applyDelta()
// aus module.html (echter Kachel-Code, mit node ausgefuehrt) arbeitet sie ein - das Ergebnis muss dem neuen Paket entsprechen.
// Ohne node werden nur die PHP-Teile geprueft.

require_once __DIR__ . '/../libs/Delta.php';

$fehler = 0;
function pruefe(string $name, $ist, $soll): void
{
    global $fehler;
    if ($ist !== $soll) {
        echo "FEHLER $name: erwartet " . json_encode($soll) . ', war ' . json_encode($ist) . "\n";
        $fehler++;
    }
}
function norm($a): array
{
    return json_decode((string) json_encode($a), true);
}

$FELDER = ['pv', 'irr', 'expected', 'bat', 'soc', 'gridDraw'];

function tag(string $id, int $punkte, float $faktor = 1.0): array
{
    $reihe = static function (int $n, float $f): array {
        $r = [];
        for ($i = 0; $i < $n; $i++) {
            $r[] = [1000000 + $i * 300000, round($i * $f, 1)];
        }
        return $r;
    };
    return [
        'k' => 0, 'id' => $id, 'label' => $id, 'hasData' => true, 'dayStart' => 1, 'dayEnd' => 2,
        'pv' => $reihe($punkte, $faktor), 'irr' => $reihe($punkte, 2.0), 'expected' => $reihe($punkte, 1.5),
        'bat' => $reihe($punkte, 0.5), 'soc' => $reihe($punkte, 0.1),
        'mppt' => [1 => $reihe($punkte, 0.7), 2 => $reihe($punkte, 0.3)],
        'price' => [['start' => 1, 'price' => 30.1]], 'spot' => [], 'flow' => ['hasData' => true, 'sum' => $punkte],
        'gridDraw' => $reihe($punkte, 0.2), 'gridEnergy' => ['day' => 1.5], 'gridMonthPeak' => null,
    ];
}
function paket(int $heute, string $stand): array
{
    return norm([
        'ok' => true, 'uid' => '1', 'hasPv' => true, 'gridWatermarkTs' => $stand, 'mpptKeys' => [1, 2],
        'days' => [tag('2026-11-18', 0), tag('2026-11-17', $heute), tag('2026-11-16', 288)],
        'energy' => [
            'pv' => ['2026-11-16' => 12.3, '2026-11-17' => $heute * 0.1],
            'bat' => ['2026-11-16' => 1.2, '2026-11-17' => 0.4],
            'irr' => [], 'expected' => [],
            'mppt' => [1 => ['2026-11-16' => 5.0, '2026-11-17' => $heute * 0.01], 2 => ['2026-11-16' => 7.0, '2026-11-17' => $heute * 0.02]],
        ],
    ]);
}

// ---- PHP-Teil
$alt = paket(100, 'a');
pruefe('gleich = null', NRGDASH_Delta::diff($alt, paket(100, 'a'), $FELDER), null);

$neu = paket(103, 'b');
$d = NRGDASH_Delta::diff($alt, $neu, $FELDER);
pruefe('Delta ist ein Array', is_array($d), true);
pruefe('oberste Ebene', $d['top'] ?? null, ['gridWatermarkTs' => 'b']);
pruefe('nur der heutige Tag', array_keys($d['days'] ?? []), ['2026-11-17']);
pruefe('Schwanz ab Stelle 100 (pv)', $d['days']['2026-11-17']['pv']['__tail'][0] ?? null, 100);
pruefe('3 neue Punkte (pv)', count($d['days']['2026-11-17']['pv']['__tail'][1] ?? []), 3);
pruefe('mppt je Strang als Schwanz', isset($d['days']['2026-11-17']['mppt'][1]['__tail']), true);
pruefe('Energie nur der heutige Eintrag', array_keys($d['energy']['pv'] ?? []), ['2026-11-17']);
pruefe('Energie-mppt je Strang', array_keys($d['energy']['mppt'] ?? []), [1, 2]);

// Tageswechsel -> voller Satz
$wechsel = $neu;
$wechsel['days'][0]['id'] = '2026-11-19';
pruefe('Tageswechsel = false', NRGDASH_Delta::diff($alt, $wechsel, $FELDER), false);
// entfernter Energieeintrag -> voller Satz
$weg = $neu;
unset($weg['energy']['pv']['2026-11-16']);
pruefe('entfernter Eintrag = false', NRGDASH_Delta::diff($alt, $weg, $FELDER), false);
// Punktliste wird kuerzer (SOC-Glaettung o. ae.): Schwanz mit weniger Punkten
$kurz = $neu;
$kurz['days'][1]['soc'] = array_slice($kurz['days'][1]['soc'], 0, 90);
$dk = NRGDASH_Delta::diff($alt, $kurz, $FELDER);
pruefe('kuerzere Liste', $dk['days']['2026-11-17']['soc']['__tail'][0] ?? null, 90);

// Glaettung aendert die letzten Punkte der alten Liste
$gl = $neu;
$gl['days'][1]['soc'][98][1] = 99.9;
$dg = NRGDASH_Delta::diff($alt, $gl, $FELDER);
pruefe('geaenderter alter Punkt: Schwanz ab 98', $dg['days']['2026-11-17']['soc']['__tail'][0] ?? null, 98);

// ---- JavaScript-Teil (echter Kachel-Code)
$node = trim((string) @shell_exec('command -v node'));
if ($node === '') {
    echo "Hinweis: node nicht gefunden, JavaScript-Teil uebersprungen.\n";
} else {
    $html = (string) file_get_contents(__DIR__ . '/../NRGDashboardPVMonitor/module.html');
    $a = strpos($html, 'function applyTail(');
    $b = strpos($html, 'function handleMessage(');
    if ($a === false || $b === false || $b < $a) {
        echo "FEHLER: applyTail/applyDelta nicht in module.html gefunden\n";
        $fehler++;
    } else {
        $code = substr($html, $a, $b - $a);
        $skript = "var lastData=null,lastSeq=-1,angefordert=[],verarbeitet=0;\n"
            . "function requestAction(a){angefordert.push(a);}\n"
            . "function handleMessage(x){verarbeitet++;}\n" . $code . "\n"
            . "var fall=JSON.parse(require('fs').readFileSync(process.argv[2],'utf8'));\n"
            . "var res=[];fall.forEach(function(f){lastData=JSON.parse(JSON.stringify(f.alt));lastSeq=f.lastSeq;angefordert=[];verarbeitet=0;"
            . "applyDelta(f.delta);res.push({lastData:lastData,angefordert:angefordert,verarbeitet:verarbeitet,lastSeq:lastSeq});});\n"
            . "console.log(JSON.stringify(res));\n";
        $dir = sys_get_temp_dir() . '/nrgdash-delta-' . getmypid();
        mkdir($dir);
        file_put_contents($dir . '/t.js', $skript);

        $fa = $alt;
        $fa['seq'] = 7;
        $delta = $d;
        $delta['seq'] = 8;
        $delta['type'] = 'delta';
        $delta['ok'] = true;
        $erwartet = $neu;
        $faelle = [
            ['alt' => $fa, 'delta' => $delta, 'lastSeq' => 7],
            ['alt' => $fa, 'delta' => $delta, 'lastSeq' => 5],      // Luecke -> fullLoad
        ];
        // Glaettung + geaenderter alter Punkt
        $fa2 = $alt;
        $fa2['seq'] = 7;
        $d2 = $dg;
        $d2['seq'] = 8;
        $d2['type'] = 'delta';
        $d2['ok'] = true;
        $faelle[] = ['alt' => $fa2, 'delta' => $d2, 'lastSeq' => 7];
        file_put_contents($dir . '/f.json', json_encode($faelle));
        $aus = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($dir . '/t.js') . ' ' . escapeshellarg($dir . '/f.json') . ' 2>&1'), true);
        if (!is_array($aus)) {
            echo "FEHLER: node-Ausgabe nicht lesbar\n";
            $fehler++;
        } else {
            $ist = $aus[0]['lastData'];
            unset($ist['seq']);
            pruefe('JS: Delta ergibt das neue Paket', $ist, $erwartet);
            pruefe('JS: seq uebernommen', $aus[0]['lastSeq'], 8);
            pruefe('JS: neu gezeichnet', $aus[0]['verarbeitet'], 1);
            pruefe('JS: keine Nachforderung bei passender Nummer', $aus[0]['angefordert'], []);
            pruefe('JS: Luecke fordert den vollen Satz', $aus[1]['angefordert'], ['fullLoad']);
            pruefe('JS: Luecke laesst die Daten unveraendert', $aus[1]['lastData']['days'][1]['pv'] === $alt['days'][1]['pv'], true);
            $ist2 = $aus[2]['lastData'];
            pruefe('JS: geaenderter alter Punkt', $ist2['days'][1]['soc'][98][1], 99.9);
            pruefe('JS: Laenge nach Schwanz', count($ist2['days'][1]['soc']), 103);
        }
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
}

echo $fehler === 0 ? "Alles bestanden.\n" : "$fehler Fehler.\n";
exit($fehler === 0 ? 0 : 1);
