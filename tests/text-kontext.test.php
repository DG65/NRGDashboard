<?php

declare(strict_types=1);

// Prueft, dass NRGDASH_Text die Uebersetzung je Modul trennt: jedes Modul hat seine eigene locale.json, ein Text darf nie
// ueber den Uebersetzer eines ANDEREN Moduls beantwortet und fuer alle gemerkt werden (Deutsch/Englisch-Mischung).

require_once __DIR__ . '/../libs/NRGDASH_Text.php';

$fehler = 0;
function pruefe(string $name, $ist, $soll): void
{
    global $fehler;
    if ($ist !== $soll) {
        echo "FEHLER $name: erwartet " . var_export($soll, true) . ', war ' . var_export($ist, true) . "\n";
        $fehler++;
    }
}

// Zwei "Module" mit unterschiedlichen Tabellen; "Balance" heisst in beiden etwas anderes, "OnlyA" kennt nur A.
class NRGDashboardTestA
{
    public function bauen(): void
    {
        NRGDASH_Text::setzen(fn (string $t): string => ['Balance' => 'Saldo', 'OnlyA' => 'NurA'][$t] ?? $t);
    }
    public function t(string $s): string { return NRGDASH_Text::t($s); }
    public function tabelle(): array { return NRGDASH_Text::tabelle(['Balance', 'OnlyA']); }
}
class NRGDashboardTestB
{
    public function bauen(): void
    {
        NRGDASH_Text::setzen(fn (string $t): string => ['Balance' => 'Bilanz', 'OnlyB' => 'NurB'][$t] ?? $t);
    }
    public function t(string $s): string { return NRGDASH_Text::t($s); }
    public function viaClosure(string $s): string { return (function () use ($s) { return NRGDASH_Text::t($s); })(); }
}
trait NRGDashboardTestTrait
{
    public function ausTrait(string $s): string { return NRGDASH_Text::t($s); }
}
class NRGDashboardTestC
{
    use NRGDashboardTestTrait;
    public function bauen(): void { NRGDASH_Text::setzen(fn (string $t): string => 'C:' . $t); }
}

$a = new NRGDashboardTestA();
$b = new NRGDashboardTestB();
$c = new NRGDashboardTestC();
$a->bauen();
$b->bauen();
$c->bauen();

// A zuerst gefragt, B als neuester Uebersetzer: A darf nicht ueber B antworten
pruefe('A Balance', $a->t('Balance'), 'Saldo');
pruefe('A OnlyA (fehlt in B)', $a->t('OnlyA'), 'NurA');
pruefe('B Balance', $b->t('Balance'), 'Bilanz');
pruefe('B OnlyA bleibt englisch (kennt B nicht)', $b->t('OnlyA'), 'OnlyA');
pruefe('B OnlyB', $b->t('OnlyB'), 'NurB');
pruefe('B aus Closure', $b->viaClosure('Balance'), 'Bilanz');
pruefe('C aus Trait', $c->ausTrait('x'), 'C:x');
pruefe('A Tabelle', $a->tabelle(), ['Balance' => 'Saldo', 'OnlyA' => 'NurA']);

// Spaeter gebaute Instanz desselben Moduls leert nur dessen Merkliste, B bleibt unberuehrt
$a->bauen();
pruefe('A nach Neubau', $a->t('Balance'), 'Saldo');
pruefe('B nach A-Neubau', $b->t('Balance'), 'Bilanz');


// Einstiegspunkt: Translate() liefert je nach Aufrufer (Formular/Konsole, Kachel, WebHook) eine andere Sprache. Die Merkliste
// darf deshalb nicht von einem Einstiegspunkt auf den anderen durchschlagen.
$GLOBALS['SPRACHE'] = 'de';
class NRGDashboardTestD
{
    public function bauen(): void
    {
        NRGDASH_Text::setzen(fn (string $t): string => $GLOBALS['SPRACHE'] === 'de' ? 'DE:' . $t : $t);
    }
    public function GetConfigurationForm(string $s): string { $GLOBALS['SPRACHE'] = 'de'; return $this->helfer($s); }
    public function ProcessHookData(string $s): string { $GLOBALS['SPRACHE'] = 'en'; return $this->helfer($s); }
    private function helfer(string $s): string { return NRGDASH_Text::t($s); }
}
$d = new NRGDashboardTestD();
$d->bauen();
pruefe('Formular zuerst', $d->GetConfigurationForm('Battery'), 'DE:Battery');
pruefe('WebHook danach (nicht aus Formular-Merkliste)', $d->ProcessHookData('Battery'), 'Battery');
pruefe('Formular erneut', $d->GetConfigurationForm('Battery'), 'DE:Battery');

echo $fehler === 0 ? "Alles bestanden.\n" : "$fehler Fehler.\n";
exit($fehler === 0 ? 0 : 1);
