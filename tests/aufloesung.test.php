<?php

declare(strict_types=1);

// Prueft unifyResolution() aus NRGDashboardPVMonitor/module.html (0.12.0): alle Linien eines Diagramms auf das feinste Raster.
// Der Kachel-Code wird mit node ausgefuehrt. Ohne node wird der Test uebersprungen.

$node = trim((string) @shell_exec('command -v node'));
if ($node === '') {
    echo "Hinweis: node nicht gefunden, Test uebersprungen.\n";
    exit(0);
}
$html = (string) file_get_contents(__DIR__ . '/../NRGDashboardPVMonitor/module.html');
$a = strpos($html, 'function unifyResolution(');
$b = strpos($html, 'function planSeries(');
if ($a === false || $b === false || $b < $a) {
    echo "FEHLER: unifyResolution nicht in module.html gefunden\n";
    exit(1);
}
$js = substr($html, $a, $b - $a) . <<<'JS'

var M = 60000, H = 3600000, T0 = 1700000000000 - (1700000000000 % H); // volle Stunde
function reihe(schritt, n, f) { var r = []; for (var i = 0; i < n; i++) { r.push([T0 + i * schritt, f(i)]); } return r; }
var ergebnis = {};

// 1) 5-Minuten-Leistung, stuendliche Prognose (linear), 15-Minuten-Stufe (Preis, Slotangaben)
var leistung = { name: 'P', data: reihe(5 * M, 24, function (i) { return i; }) };
var prognose = { name: 'F', data: reihe(H, 3, function (i) { return i * 10; }) };
var slots = [];
for (var k = 0; k < 8; k++) { slots.push({ value: [T0 + k * 15 * M + 7.5 * M, k], slotStart: T0 + k * 15 * M, slotEnd: T0 + (k + 1) * 15 * M }); }
var preis = { name: 'C', data: slots, step: 'middle' };
var balken = { name: 'B', bar: true, data: reihe(15 * M, 8, function (i) { return i; }) };
var liste = [leistung, prognose, preis, balken];
unifyResolution(liste);
ergebnis.leistungUnveraendert = leistung.data.length === 24 && leistung.data[3][0] === T0 + 15 * M;
ergebnis.balkenUnveraendert = balken.data.length === 8;
ergebnis.prognoseAnzahl = prognose.data.length;                    // 0 .. 2 h in 5 Minuten = 25 Punkte
ergebnis.prognoseAnfang = prognose.data[0][0] === T0;                // nicht ueber die Daten hinaus verlaengert
ergebnis.prognoseEnde = prognose.data[prognose.data.length - 1][0] === T0 + 2 * H;
ergebnis.prognoseMitte = prognose.data[6][1];                      // bei +30 min: 5
ergebnis.prognoseRaster = prognose.data[1][0] - prognose.data[0][0];
ergebnis.prognoseAufRaster = (prognose.data[0][0] - leistung.data[0][0]) % (5 * M);
ergebnis.preisRaster = preis.data[1].value[0] - preis.data[0].value[0];
ergebnis.preisStufe = preis.step;
// Wert im Zeitfenster [15, 30) min ist 1, an der Grenze 15 min schon der neue Wert
var an15 = preis.data.filter(function (p) { return p.value[0] === T0 + 15 * M; })[0];
var an10 = preis.data.filter(function (p) { return p.value[0] === T0 + 10 * M; })[0];
ergebnis.preisAn10 = an10 ? an10.value[1] : null;
ergebnis.preisAn15 = an15 ? an15.value[1] : null;
ergebnis.slotErhalten = an15 ? [an15.slotStart === T0 + 15 * M, an15.slotEnd === T0 + 30 * M] : null;

// 1b) feinste Kurve ist selbst eine Stufenkurve mit Punkten in der Fenstermitte (Boerse 15 min), Preis stuendlich:
//     die Stufe muss ZWISCHEN den Punkten wechseln ('middle'), nicht am Punkt
var boerse = []; for (var b = 0; b < 12; b++) { boerse.push({ value: [T0 + b * 15 * M + 7.5 * M, b], slotStart: T0 + b * 15 * M, slotEnd: T0 + (b + 1) * 15 * M, isSpot: true }); }
var stundenpreis = []; for (var h = 0; h < 3; h++) { stundenpreis.push({ value: [T0 + h * H + H / 2, 20 + h], slotStart: T0 + h * H, slotEnd: T0 + (h + 1) * H }); }
var sb = { name: 'S', data: boerse, step: 'middle' }, sp = { name: 'P', data: stundenpreis, step: 'middle' };
unifyResolution([sb, sp]);
ergebnis.mitteModus = sp.step;
ergebnis.mitteAnzahl = sp.data.length;
ergebnis.mitteWerte = [sp.data[0].value[1], sp.data[3].value[1], sp.data[4].value[1]];   // 1. Stunde: 4 Punkte, danach neue Stufe
ergebnis.mitteGleichesRaster = sp.data[0].value[0] === sb.data[0].value[0] && sp.data[1].value[0] === sb.data[1].value[0];

// 2) alle gleich fein: nichts aendern
var x1 = { data: reihe(5 * M, 10, function (i) { return i; }) }, x2 = { data: reihe(5 * M, 10, function (i) { return -i; }) };
var vor1 = JSON.stringify(x1.data), vor2 = JSON.stringify(x2.data);
unifyResolution([x1, x2]);
ergebnis.gleichFein = JSON.stringify(x1.data) === vor1 && JSON.stringify(x2.data) === vor2;

// 3) nur eine Linie: nichts aendern; zu kurze Serie wird ignoriert
var einzel = { data: reihe(H, 5, function (i) { return i; }) };
unifyResolution([einzel, { data: reihe(5 * M, 2, function () { return 1; }) }]);
ergebnis.einzel = einzel.data.length === 5;

// 4) HC-Punktform {x, y, ...} bleibt erhalten, Luecke groesser als 2,5 Schritte -> null
var hc = { data: [{ x: T0, y: 1, slotStart: 1 }, { x: T0 + H, y: 2, slotStart: 2 }, { x: T0 + 2 * H, y: 3, slotStart: 3 }, { x: T0 + 6 * H, y: 4, slotStart: 4 }] };
var fein = { data: reihe(15 * M, 24, function () { return 0; }) };
unifyResolution([fein, hc]);
ergebnis.hcForm = hc.data[0].x === T0 && hc.data[1].y === 1.25;    // 15 min nach dem ersten Punkt: 1 + 1 * 0,25
ergebnis.hcLuecke = hc.data.some(function (p) { return p.y === null; });
ergebnis.hcFelder = hc.data[1].slotStart === 1;
console.log(JSON.stringify(ergebnis));
JS;
$dir = sys_get_temp_dir() . '/nrgdash-aufl-' . getmypid();
mkdir($dir);
file_put_contents($dir . '/t.js', $js);
$roh = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($dir . '/t.js') . ' 2>&1');
$aus = json_decode($roh, true);
array_map('unlink', glob($dir . '/*'));
rmdir($dir);

$fehler = 0;
function pruefe(string $name, $ist, $soll): void
{
    global $fehler;
    if ($ist !== $soll) {
        echo "FEHLER $name: erwartet " . json_encode($soll) . ', war ' . json_encode($ist) . "\n";
        $fehler++;
    }
}
if (!is_array($aus)) {
    echo "FEHLER: node-Ausgabe nicht lesbar: " . substr($roh, 0, 400) . "\n";
    exit(1);
}
pruefe('feine Leistung bleibt', $aus['leistungUnveraendert'], true);
pruefe('Balken bleiben', $aus['balkenUnveraendert'], true);
pruefe('Prognose auf 5-Minuten-Raster', $aus['prognoseRaster'], 300000);
pruefe('Prognose: 25 Punkte fuer 2 Stunden', $aus['prognoseAnzahl'], 25);
pruefe('Prognose beginnt beim ersten Punkt', $aus['prognoseAnfang'], true);
pruefe('Prognose endet beim letzten Punkt', $aus['prognoseEnde'], true);
pruefe('Prognose linear bei +30 min', $aus['prognoseMitte'], 5);
pruefe('Prognose liegt auf dem Raster der Leistung', $aus['prognoseAufRaster'], 0);
pruefe('Preis auf 5-Minuten-Raster', $aus['preisRaster'], 300000);
pruefe('Preis haelt den Wert (Stufe)', $aus['preisStufe'], 'hold');
pruefe('Preis an 10 min = erstes Fenster', $aus['preisAn10'], 0);
pruefe('Preis an 15 min = neues Fenster', $aus['preisAn15'], 1);
pruefe('slotStart/slotEnd bleiben', $aus['slotErhalten'], [true, true]);
pruefe('Fenstermitte: Stufe wechselt zwischen den Punkten', $aus['mitteModus'], 'middle');
pruefe('Fenstermitte: 12 Punkte fuer 3 Stunden', $aus['mitteAnzahl'], 12);
pruefe('Fenstermitte: Werte je Fenster', $aus['mitteWerte'], [20, 20, 21]);
pruefe('Fenstermitte: Punkte liegen auf dem Raster der feinen Kurve', $aus['mitteGleichesRaster'], true);
pruefe('gleich feine Serien unveraendert', $aus['gleichFein'], true);
pruefe('einzelne Linie unveraendert', $aus['einzel'], true);
pruefe('Highcharts-Punktform und Interpolation', $aus['hcForm'], true);
pruefe('Luecke bleibt Luecke (null)', $aus['hcLuecke'], true);
pruefe('Zusatzfelder bleiben', $aus['hcFelder'], true);
echo $fehler === 0 ? "Alles bestanden.\n" : "$fehler Fehler.\n";
exit($fehler === 0 ? 0 : 1);
