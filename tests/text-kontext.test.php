<?php

declare(strict_types=1);

// Prueft NRGDASH_Text: Uebersetzung kommt in jedem Aufruf direkt von der aufrufenden Modul-Instanz (Translate() der Instanz),
// ohne gemeinsamen statischen Zustand; Merkliste je Modul und Einstiegspunkt.

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

// Zwei "Module" mit eigener Tabelle (wie je eine locale.json); Translate() ist protected wie in IPSModule.
class NRGDashboardTestA
{
    protected function Translate(string $t): string { return ['Balance' => 'Saldo', 'OnlyA' => 'NurA'][$t] ?? $t; }
    public function t(string $s): string { return NRGDASH_Text::t($s); }
    public function tabelle(): array { return NRGDASH_Text::tabelle(['Balance', 'OnlyA']); }
}
class NRGDashboardTestB
{
    protected function Translate(string $t): string { return ['Balance' => 'Bilanz', 'OnlyB' => 'NurB'][$t] ?? $t; }
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
    protected function Translate(string $t): string { return 'C:' . $t; }
}

$a = new NRGDashboardTestA();
$b = new NRGDashboardTestB();
$c = new NRGDashboardTestC();

// kein setzen(): nur die Instanz im Aufrufstapel zaehlt
pruefe('A Balance', $a->t('Balance'), 'Saldo');
pruefe('A OnlyA', $a->t('OnlyA'), 'NurA');
pruefe('B Balance', $b->t('Balance'), 'Bilanz');
pruefe('B OnlyA bleibt englisch (kennt B nicht)', $b->t('OnlyA'), 'OnlyA');
pruefe('B OnlyB', $b->t('OnlyB'), 'NurB');
pruefe('B aus Closure', $b->viaClosure('Balance'), 'Bilanz');
pruefe('C aus Trait', $c->ausTrait('x'), 'C:x');
pruefe('A Tabelle', $a->tabelle(), ['Balance' => 'Saldo', 'OnlyA' => 'NurA']);

// Einstiegspunkt: Translate() kann je Aufrufer eine andere Sprache liefern; die Merkliste darf nicht durchschlagen.
$GLOBALS['SPRACHE'] = 'de';
class NRGDashboardTestD
{
    protected function Translate(string $t): string { return $GLOBALS['SPRACHE'] === 'de' ? 'DE:' . $t : $t; }
    public function GetConfigurationForm(string $s): string { $GLOBALS['SPRACHE'] = 'de'; return $this->helfer($s); }
    public function ProcessHookData(string $s): string { $GLOBALS['SPRACHE'] = 'en'; return $this->helfer($s); }
    private function helfer(string $s): string { return NRGDASH_Text::t($s); }
}
$d = new NRGDashboardTestD();
pruefe('Formular zuerst', $d->GetConfigurationForm('Battery'), 'DE:Battery');
pruefe('WebHook danach', $d->ProcessHookData('Battery'), 'Battery');
pruefe('Formular erneut', $d->GetConfigurationForm('Battery'), 'DE:Battery');

// Rueckfall ohne Modul im Stapel: eingetragene Kette, nichts gemerkt
NRGDASH_Text::setzen(fn (string $t): string => 'K:' . $t);
pruefe('Rueckfall Kette', NRGDASH_Text::t('x'), 'K:x');

echo $fehler === 0 ? "Alles bestanden.\n" : "$fehler Fehler.\n";
exit($fehler === 0 ? 0 : 1);
