<?php

declare(strict_types=1);

// ===========================================================================
// Statische Pruefung der Zweisprachigkeit EINES Moduls (SUITE.md Konventionen Punkt 9, Store-Punkt 25).
//   php .tools/locale-pruefen.php <Modulname> [Vergleichsstand, Standard: merge-base mit origin/beta]
// Beispiel: php .tools/locale-pruefen.php NRGDashboardTopology
// Fasst andere Module nicht an. Geprueft wird:
//  1. <Modul>/locale.json == libs/locale/gemeinsam.json + libs/locale/<Modul>.json (Bau-Ergebnis aktuell), gueltig, ohne
//     doppelte Schluessel.
//  2. Jeder Formulartext aus form.json (caption/label/suffix/prefix, auch Optionen, Spalten, status) steht in locale.json;
//     kein Deutsch mehr in form.json (Heuristik).
//  3. Jedes Literal in NRGDASH_Text::t('...') und 'caption' => '...' aus module.php, libs/FormLayout.php, libs/FormStatus.php,
//     jede Zeile von NEWS_VERSIONS und jeder Eintrag von TILE_TEXTS steht in locale.json; kein Deutsch in diesen Literalen und
//     in keinem anderen Zeichenketten-Literal des Moduls (Heuristik).
//  4. Panels: jedes Panel aus FORM_LAYOUT_PANELS hat in form.json ein 'name' (Logik nie am Anzeigetext).
//  5. Kachel: tr('...') in module.html == TILE_TEXTS == locale.json; kein Deutsch/Umlaut mehr ausserhalb von Kommentaren.
//  6. Gegenprobe: form.json mit locale.json "auf Deutsch" gebracht == form.json des Vergleichsstands (bis auf neue name-Felder;
//     %%HOOK%% heisst jetzt %s), NEWS-Zeilen auf Deutsch == alte Zeilen.
//  7. Jeder modul-eigene Eintrag in libs/locale/<Modul>.json wird auch benutzt.
// Rueckgabe 1 bei einem Fehler. Die Gegenprobe der PHP-Meldungen macht .tools/deutsch-vergleich.php.
// ===========================================================================

$root = dirname(__DIR__);
$modul = $argv[1] ?? '';
if ($modul === '' || !is_dir($root . '/' . $modul)) {
    fwrite(STDERR, "Aufruf: php .tools/locale-pruefen.php <Modulname> [Vergleichsstand]\n");
    exit(2);
}
$ref = $argv[2] ?? trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' merge-base HEAD origin/beta 2>/dev/null'));
if ($ref === '') {
    $ref = 'HEAD';
}
$fehler = 0;
$fail = function (string $m) use (&$fehler) { echo "  FEHLER  $m\n"; $fehler++; };
$git = function (string $pfad) use ($root, $ref): string {
    return (string) shell_exec('git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg($ref . ':' . $pfad) . ' 2>/dev/null');
};
$deutsch = '/[äöüÄÖÜß]|\b(und|oder|nicht|nur|wenn|für|der|die|das|bei|mit|ohne|aus|Akku|Batterie|Netz|Verbund|Kachel|Formular)\b/u';

// --- 1. locale.json ---
$laden = function (string $datei) use ($fail): array {
    $raw = is_file($datei) ? (string) file_get_contents($datei) : '';
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['translations']['de']) || !is_array($j['translations']['de'])) {
        $fail("$datei fehlt oder ist ungueltig");
        return [];
    }
    preg_match_all('/^\s*("(?:[^"\\\\]|\\\\.)*")\s*:/m', $raw, $m);
    if (count($m[1]) - 2 !== count($j['translations']['de'])) {
        $fail("$datei: doppelte Schluessel");
    }
    return $j['translations']['de'];
};
$gem = $laden($root . '/libs/locale/gemeinsam.json');
$eigen = is_file($root . "/libs/locale/$modul.json") ? $laden($root . "/libs/locale/$modul.json") : [];
$de = $laden($root . "/$modul/locale.json");
$erwartet = $gem + $eigen;
ksort($erwartet, SORT_STRING);
$ist = $de;
ksort($ist, SORT_STRING);
if ($erwartet !== $ist) {
    $fail("$modul/locale.json ist nicht aktuell: php .tools/locale-bauen.php ausfuehren");
}
echo "$modul/locale.json: " . count($de) . ' Eintraege (' . count($eigen) . " modul-eigene)\n";
$benutzt = [];
$braucht = function (string $s, string $wo) use (&$benutzt, $de, $fail) {
    if (!preg_match('/\p{L}/u', $s)) {
        return;
    }
    $benutzt[$s] = true;
    if (!array_key_exists($s, $de)) {
        $fail("$wo fehlt in locale.json: " . mb_substr($s, 0, 90));
    }
};

// --- 2. form.json ---
$formTexte = [];
$walk = function ($n) use (&$walk, &$formTexte) {
    if (!is_array($n)) {
        return;
    }
    foreach ($n as $k => $v) {
        if ($k === 'values') {
            continue;
        }
        if (is_string($v) && in_array($k, ['caption', 'label', 'suffix', 'prefix'], true)) {
            $formTexte[] = $v;
        } elseif (is_array($v)) {
            $walk($v);
        }
    }
};
$form = json_decode((string) file_get_contents("$root/$modul/form.json"), true);
if (!is_array($form)) {
    $fail('form.json ungueltig');
    $form = [];
}
$walk($form);
$formTexte = array_unique($formTexte);
foreach ($formTexte as $s) {
    $braucht($s, 'form.json');
    if (preg_match($deutsch, $s)) {
        $fail('form.json enthaelt noch Deutsch: ' . mb_substr($s, 0, 90));
    }
}
echo 'form.json: ' . count($formTexte) . " Formulartexte\n";

// --- 3. PHP-Literale ---
$literale = [];
$alleLiterale = [];
foreach (["$modul/module.php", 'libs/FormLayout.php', 'libs/FormStatus.php'] as $datei) {
    $tok = token_get_all((string) file_get_contents("$root/$datei"));
    $n = count($tok);
    $naechster = function (int $i) use ($tok, $n): int {
        $i++;
        while ($i < $n && is_array($tok[$i]) && in_array($tok[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $i++;
        }
        return $i;
    };
    for ($i = 0; $i < $n; $i++) {
        $t = $tok[$i];
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $alleLiterale[] = [$datei, $t[2], eval('return ' . $t[1] . ';')];
        }
        // NRGDASH_Text::t('...')
        if ($t[0] === T_STRING && $t[1] === 'NRGDASH_Text') {
            $a = $naechster($i);
            $b = $naechster($a);
            $c = $naechster($b);
            $d = $naechster($c);
            if (($tok[$a][0] ?? null) === T_DOUBLE_COLON && ($tok[$b][1] ?? '') === 't' && ($tok[$c] ?? null) === '(' && is_array($tok[$d]) && $tok[$d][0] === T_CONSTANT_ENCAPSED_STRING) {
                $literale[] = [$datei, $t[2], eval('return ' . $tok[$d][1] . ';')];
            }
        }
        // 'caption' => '...'
        if ($t[0] === T_CONSTANT_ENCAPSED_STRING && $t[1] === "'caption'") {
            $a = $naechster($i);
            $b = $naechster($a);
            $c = $naechster($b);
            if (is_array($tok[$a] ?? null) && $tok[$a][0] === T_DOUBLE_ARROW && is_array($tok[$b] ?? null) && $tok[$b][0] === T_CONSTANT_ENCAPSED_STRING && in_array($tok[$c] ?? null, [',', ']', ')'], true)) {
                $literale[] = [$datei, $tok[$b][2], eval('return ' . $tok[$b][1] . ';')];
            }
        }
    }
}
foreach ($literale as [$datei, $zeile, $s]) {
    $braucht($s, "$datei:$zeile");
    if (preg_match($deutsch, $s)) {
        $fail("$datei:$zeile englischer Quelltext enthaelt Deutsch: " . mb_substr($s, 0, 90));
    }
}
// kein Deutsch in irgendeinem anderen Literal (Grossschreibung/Umlaute); Kennungen und Texte in locale.json ausgenommen
foreach ($alleLiterale as [$datei, $zeile, $s]) {
    if (preg_match('/[äöüÄÖÜß]/u', $s) && !array_key_exists($s, $de)) {
        $fail("$datei:$zeile Literal mit Umlaut ausserhalb der Uebersetzung: " . mb_substr($s, 0, 90));
    }
}
echo count($literale) . " PHP-Literale geprueft\n";

// --- NEWS_VERSIONS, TILE_TEXTS, MODULE_LABELS, FORM_LAYOUT_PANELS ---
$mod = (string) file_get_contents("$root/$modul/module.php");
$konstante = function (string $src, string $name) {
    if (!preg_match('/const ' . $name . ' = (\[[^\n]*\]);\n/', $src, $m) && !preg_match('/const ' . $name . ' = (\[\n.*?\n    \]);\n/s', $src, $m)) {
        return null;
    }
    return eval('return ' . $m[1] . ';');
};
$news = $konstante($mod, 'NEWS_VERSIONS');
$newsZeilen = [];
foreach (is_array($news) ? $news : [] as $ver => $zeilen) {
    foreach ($zeilen as $z) {
        $newsZeilen[$ver][] = $z;
        $braucht($z, "NEWS $ver");
        if (preg_match($deutsch, $z)) {
            $fail("NEWS $ver enthaelt Deutsch: " . mb_substr($z, 0, 80));
        }
    }
}
$tileTexte = $konstante($mod, 'TILE_TEXTS');
foreach (is_array($tileTexte) ? $tileTexte : [] as $s) {
    $braucht($s, 'TILE_TEXTS');
}
$eigennamen = ['GoodWe ET', 'InverterHub', 'MeterHub', 'ChargerHub', 'HeishaMon', 'Tessie', 'Tibber Grid Rewards'];
$labels = $konstante($mod, 'MODULE_LABELS');
foreach (is_array($labels) ? $labels : [] as $s) {
    if (!in_array($s, $eigennamen, true)) {
        $braucht($s, 'MODULE_LABELS');
    }
}
$panels = $konstante($mod, 'FORM_LAYOUT_PANELS');
if (is_array($panels)) {
    $namen = [];
    $suche = function ($n) use (&$suche, &$namen) {
        if (!is_array($n)) {
            return;
        }
        if (($n['type'] ?? '') === 'ExpansionPanel' && isset($n['name'])) {
            $namen[] = $n['name'];
        }
        foreach ($n as $v) {
            if (is_array($v)) {
                $suche($v);
            }
        }
    };
    $suche($form['elements'] ?? []);
    foreach (array_keys($panels) as $p) {
        if (!in_array($p, $namen, true)) {
            $fail("FORM_LAYOUT_PANELS-Schluessel '$p' ist kein Panel-name in form.json");
        }
    }
    echo 'Panel-Namen: ' . implode(', ', array_keys($panels)) . " in form.json vorhanden\n";
}

// --- 5. Kachel ---
$htmlDatei = "$root/$modul/module.html";
if (is_file($htmlDatei)) {
    $html = (string) file_get_contents($htmlDatei);
    preg_match_all("/\\btr\\('((?:[^'\\\\]|\\\\.)*)'\\)/", $html, $m);
    $trTexte = array_values(array_unique(array_map(function ($x) { return str_replace("\\'", "'", $x); }, $m[1])));
    foreach ($trTexte as $s) {
        $braucht($s, 'module.html tr()');
        if (is_array($tileTexte) && !in_array($s, $tileTexte, true)) {
            $fail('Kachel benutzt Text, der nicht in TILE_TEXTS steht: ' . mb_substr($s, 0, 70));
        }
    }
    foreach (is_array($tileTexte) ? $tileTexte : [] as $s) {
        if (!in_array($s, $trTexte, true)) {
            $fail('TILE_TEXTS enthaelt Text, den die Kachel nicht benutzt: ' . mb_substr($s, 0, 70));
        }
    }
    if (strpos($html, '<!--NRGDASH_TEXTS-->') === false && $trTexte !== []) {
        $fail('module.html hat die Marke <!--NRGDASH_TEXTS--> nicht');
    }
    // Deutsch ausserhalb von Kommentaren
    $ohne = preg_replace(['~<!--.*?-->~s', '~/\*.*?\*/~s', '~(?<![:"\'])//[^\n]*~'], '', $html);
    foreach (explode("\n", (string) $ohne) as $i => $zeile) {
        if (preg_match('/[äöüÄÖÜß]/u', $zeile)) {
            $fail('module.html enthaelt Umlaut ausserhalb von Kommentaren, Zeile ~' . ($i + 1) . ': ' . trim(mb_substr($zeile, 0, 80)));
        }
    }
    echo count($trTexte) . " Kachel-Texte (tr) geprueft\n";
    // Gegenprobe der festen Kachel-Texte: jeder Text ohne Platzhalter muss im alten HTML woertlich stehen
    $altHtml = $git("$modul/module.html");
    $altHtmlU = str_replace("\\'", "'", $altHtml);
    $komponiert = [
        '%d instances' => ' Instanzen', '%d instances · status %s' => ' Instanzen · Status ', 'Status: %s' => 'Status: ',
        '%d/%d partner instances healthy' => ' Partnerinstanzen gesund',
    ];
    foreach ($trTexte as $s) {
        $d = $de[$s] ?? '';
        if (isset($komponiert[$s])) {
            if (strpos($altHtmlU, $komponiert[$s]) === false) {
                $fail("alte Kachel enthaelt nicht '{$komponiert[$s]}' fuer: $s");
            }
        } elseif ($altHtml !== '' && strpos($altHtmlU, $d) === false) {
            $fail('deutsche Kachel-Fassung nicht woertlich im Vergleichsstand: ' . mb_substr($d, 0, 80));
        }
    }
}

// --- 6. Gegenprobe form.json / NEWS ---
$alt = json_decode(str_replace('%%HOOK%%', '%s', $git("$modul/form.json")), true);
if (!is_array($alt)) {
    $fail("form.json aus $ref nicht lesbar");
} else {
    $toDe = function ($n) use (&$toDe, $de) {
        if (!is_array($n)) {
            return $n;
        }
        $o = [];
        foreach ($n as $k => $v) {
            if ($k === 'name' && is_string($v) && preg_match('/^(Panel[A-Za-z0-9]+|HookInfo)$/', $v)) {
                continue; // neu vergebene Panel-/Label-Namen
            }
            if ($k !== 'values' && is_string($v) && in_array($k, ['caption', 'label', 'suffix', 'prefix'], true)) {
                $o[$k] = $de[$v] ?? $v;
            } else {
                $o[$k] = is_array($v) && $k !== 'values' ? $toDe($v) : $v;
            }
        }
        return $o;
    };
    if ($toDe($form) !== $alt) {
        $fail("form.json auf Deutsch weicht von $ref ab");
        $a = explode("\n", (string) json_encode($alt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $b = explode("\n", (string) json_encode($toDe($form), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        foreach ($a as $i => $l) {
            if (($b[$i] ?? '') !== $l) {
                echo "    alt: $l\n    neu: " . ($b[$i] ?? '') . "\n";
                break;
            }
        }
    } else {
        echo "form.json auf Deutsch == $ref (alle " . count($formTexte) . " Texte identisch)\n";
    }
}
$altMod = $git("$modul/module.php");
$altNews = $konstante($altMod, 'NEWS_VERSIONS');
if (is_array($altNews)) {
    $neuDe = array_map(function ($z) use ($de) { return array_map(function ($l) use ($de) { return $de[$l] ?? $l; }, $z); }, $newsZeilen);
    if ($neuDe !== $altNews) {
        $fail("NEWS auf Deutsch weicht von $ref ab");
    } else {
        echo 'NEWS auf Deutsch == ' . $ref . ' (' . array_sum(array_map('count', $newsZeilen)) . " Zeilen)\n";
    }
}

// --- 7. ungenutzte modul-eigene Eintraege ---
foreach (array_keys($eigen) as $s) {
    if (!isset($benutzt[$s])) {
        $fail("libs/locale/$modul.json: Eintrag wird nicht benutzt: " . mb_substr($s, 0, 90));
    }
}

echo $fehler === 0 ? "LOCALE-PRUEFUNG BESTANDEN\n" : "LOCALE-PRUEFUNG: $fehler Fehler\n";
exit($fehler === 0 ? 0 : 1);
