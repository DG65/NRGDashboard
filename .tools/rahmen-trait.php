<?php

declare(strict_types=1);

// ===========================================================================
// Werkzeug fuer den Umbau "gemeinsamer Modulrahmen" (0.13.0, 19.11.2026): verschiebt Rahmenfunktionen WOERTLICH aus einem Modul
// in libs/ModuleBase.php und prueft die Gleichheit.
//   php .tools/rahmen-trait.php erzeuge Topology   Trait aus der Vorlage-Klasse erzeugen (nur einmal)
//   php .tools/rahmen-trait.php verschiebe <Modul> Funktionen entfernen, die dem Trait zeichengleich entsprechen
//   php .tools/rahmen-trait.php pruefe <Modul>     nur pruefen, nichts schreiben (beide Richtungen)
// Gleichheit: Token-Vergleich ohne Kommentare und Leerraum; modulspezifisch sind nur der Hook-Pfad (self::HOOK_PATH) und das
// Funktionspraefix (self::FN_PREFIX). Abweichende Funktionen bleiben im Modul (Klassenmethoden schlagen Trait-Methoden).
// ===========================================================================

const NAMEN = ['RegisterHook', 'UnregisterHook', 'SyncHook', 'Destroy', 'LicenseHint', 'BaseVersion', 'AckNews', 'AckNewsApply',
    'AckPurposeIntro', 'AckPurposeIntroApply', 'DismissReviewHint', 'DismissReviewHintApply', 'propagateDismiss'];

$root = dirname(__DIR__);
$mode = $argv[1] ?? '';
$mod = $argv[2] ?? '';
$modFile = "$root/NRGDashboard$mod/module.php";
$traitFile = "$root/libs/ModuleBase.php";

/** @return array<string,array{from:int,to:int,text:string,norm:string}> Zeilen von (Docblock davor) bis Funktionsende */
function funktionen(string $src): array
{
    $lines = explode("\n", $src);
    $t = token_get_all($src);
    $n = count($t);
    $zeile = [];
    $z = 1;
    foreach ($t as $idx => $x) {
        $zeile[$idx] = $z;
        $z += substr_count(is_array($x) ? $x[1] : $x, "\n");
    }
    $res = [];
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (!is_array($t[$j]) || $t[$j][0] !== T_STRING) {
            continue; // Closure
        }
        $name = $t[$j][1];
        // Start: erster Modifizierer vor "function"
        $s = $i;
        for ($k = $i - 1; $k >= 0; $k--) {
            if (is_array($t[$k]) && in_array($t[$k][0], [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT], true)) {
                $s = $k;
            } elseif (is_array($t[$k]) && $t[$k][0] === T_WHITESPACE) {
                continue;
            } else {
                break;
            }
        }
        $fromLine = $zeile[$s];
        $depth = 0;
        $started = false;
        $toLine = $fromLine;
        $norm = '';
        for ($k = $s; $k < $n; $k++) {
            $x = $t[$k];
            $str = is_array($x) ? $x[1] : $x;
            if (is_array($x) && in_array($x[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }
            $norm .= $str . ' ';
            if ($str === '{') {
                $depth++;
                $started = true;
            } elseif ($str === '}') {
                $depth--;
                if ($started && $depth === 0) {
                    $toLine = $zeile[$k];
                    break;
                }
            }
        }
        // Docblock/Kommentarlauf direkt davor (ohne Leerzeile) mitnehmen
        $from = $fromLine;
        while ($from - 2 >= 0) {
            $prev = trim($lines[$from - 2]);
            if ($prev !== '' && ($prev[0] === '*' || strncmp($prev, '/**', 3) === 0 || strncmp($prev, '//', 2) === 0 || strncmp($prev, '/*', 2) === 0)) {
                $from--;
            } else {
                break;
            }
        }
        $res[$name] = ['from' => $from, 'to' => $toLine, 'text' => implode("\n", array_slice($lines, $from - 1, $toLine - $from + 1)), 'norm' => $norm];
    }
    return $res;
}

/** Modulspezifisches durch die Trait-Platzhalter ersetzen. @return array{0:string,1:string,2:string} text, hookPfad, praefix */
function normalisiere(string $text, string &$hook, string &$prefix): string
{
    if (preg_match("#'(/hook/nrgdash[a-z]*)'#", $text, $m)) {
        $hook = $m[1];
        $text = str_replace("'" . $m[1] . "'", 'self::HOOK_PATH', $text);
    }
    if (preg_match("#'(NRGDASH[A-Z]*_)' \\. \\\$applyMethod#", $text, $m)) {
        $prefix = $m[1];
        $text = str_replace("'" . $m[1] . "' . \$applyMethod", 'self::FN_PREFIX . $applyMethod', $text);
    }
    return $text;
}

function tokNorm(string $code): string
{
    $out = '';
    foreach (token_get_all('<?php class X { ' . $code . ' }') as $x) {
        if (is_array($x) && in_array($x[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
            continue;
        }
        $out .= (is_array($x) ? $x[1] : $x) . ' ';
    }
    return $out;
}

if ($mode === 'erzeuge') {
    $src = (string) file_get_contents($modFile);
    $f = funktionen($src);
    $hook = '';
    $prefix = '';
    $teile = [];
    foreach (NAMEN as $name) {
        if (!isset($f[$name])) {
            fwrite(STDERR, "Vorlage hat $name nicht\n");
            exit(1);
        }
        $teile[] = normalisiere($f[$name]['text'], $hook, $prefix);
    }
    $kopf = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Gemeinsamer Modulrahmen der sieben Dashboard-Module (0.13.0, Umbau 1 der Code-Bestandsaufnahme vom 18.11.2026): WebHook-Eintrag
 * im WebHook-Control, Lizenzhinweis, "Was ist Neu"-/Wozu-Bestaetigung samt geteiltem Ausblenden ueber Geschwister-Instanzen.
 * Die Funktionen sind WOERTLICH aus den Modulen hierher verschoben (Pruefung: .tools/rahmen-trait.php). Modulspezifisch ist nur,
 * was die Klasse als Konstante liefert:
 *   HOOK_PATH           Pfad ohne Instanznummer, z. B. '/hook/nrgdashtopology'
 *   FN_PREFIX           Praefix der von Symcon erzeugten Modulfunktionen, z. B. 'NRGDASHTOPO_'
 *   SELF_MODULE_GUID    GUID des eigenen Moduls (Geschwister-Suche)
 *   NEWS_VERSIONS       "Was ist Neu"-Zeilen je Version
 *   ATTR_REVIEW_HINT_GONE, LICENSE_URL, PAYPAL_URL
 * Oeffentliche Funktionen dieses Traits exportiert Symcon je Modul mit dem Modulpraefix (Timer und Formularknoepfe rufen sie so auf).
 * Eine Klassenmethode gleichen Namens im Modul ersetzt die Trait-Methode - so bleiben abweichende Varianten (siehe CHANGELOG 0.13.0)
 * bis zu einer bewussten Entscheidung unveraendert.
 */
trait NRGDashModuleBase
{

PHP;
    file_put_contents($traitFile, $kopf . implode("\n\n", $teile) . "\n}\n");
    echo "libs/ModuleBase.php erzeugt (" . count($teile) . " Funktionen, Hook $hook, Praefix $prefix)\n";
    exit(0);
}

if (!in_array($mode, ['verschiebe', 'pruefe'], true) || $mod === '') {
    fwrite(STDERR, "Aufruf: php .tools/rahmen-trait.php erzeuge|verschiebe|pruefe <Modul>\n");
    exit(2);
}

$trait = (string) file_get_contents($traitFile);
$tf = funktionen('<?php trait T { ' . "\n" . substr($trait, strpos($trait, 'trait NRGDashModuleBase') + strlen("trait NRGDashModuleBase\n{")) );
$src = (string) file_get_contents($modFile);
$mf = funktionen($src);
$hook = '';
$prefix = '';
$entfernen = [];
foreach (NAMEN as $name) {
    if (!isset($mf[$name])) {
        echo str_pad($name, 26) . "nicht im Modul\n";
        continue;
    }
    $gleich = tokNorm(normalisiere($mf[$name]['text'], $hook, $prefix)) === tokNorm($tf[$name]['text'] ?? '');
    echo str_pad($name, 26) . ($gleich ? "gleich -> Trait\n" : "ABWEICHEND -> bleibt im Modul\n");
    if ($gleich) {
        $entfernen[$name] = $mf[$name];
    }
}
echo "Hook $hook, Praefix $prefix\n";

// Rueckrichtung: jede Trait-Funktion, die im Modul entfernt wird, muss genau EINMAL im Trait stehen
foreach ($entfernen as $name => $_) {
    if (!isset($tf[$name])) {
        echo "FEHLER: $name steht nicht im Trait\n";
        exit(1);
    }
}
if ($mode === 'pruefe') {
    exit(0);
}
$lines = explode("\n", $src);
uasort($entfernen, static fn ($a, $b) => $b['from'] <=> $a['from']);
foreach ($entfernen as $e) {
    array_splice($lines, $e['from'] - 1, $e['to'] - $e['from'] + 1);
}
$neu = implode("\n", $lines);
$neu = (string) preg_replace("/\n{3,}/", "\n\n", $neu);
// require_once, use und Konstanten
if (strpos($neu, "libs/ModuleBase.php") === false) {
    $neu = preg_replace("#(require_once __DIR__ \\. '/\\.\\./libs/FormStatus\\.php';)#", "require_once __DIR__ . '/../libs/ModuleBase.php';\n$1", $neu, 1);
    $neu = preg_replace('#(    use NRGDashFormStatus;)#', "    use NRGDashModuleBase;\n$1", $neu, 1);
}
if (strpos($neu, 'const HOOK_PATH') === false) {
    $neu = preg_replace('#(    use NRGDashFormLayout;\n)#', "$1\n    private const HOOK_PATH = '$hook';\n    private const FN_PREFIX = '$prefix';\n", $neu, 1);
}
file_put_contents($modFile, $neu);
echo count($entfernen) . " Funktionen aus dem Modul entfernt\n";
