<?php

declare(strict_types=1);

require_once __DIR__ . '/NRGDASH_Text.php';

/**
 * "🪟 Formular: Aufbau" (SUITE.md "Einheitliche Formular-Optik" Punkt 3a, Dietmar 29.10.2026, Vorbild Batteriewächter): je Fachpanel
 * waehlt der Nutzer Aufgeklappt / Zugeklappt / Ausgeblendet und die Position. Gespeichert in der Property "FormLayout"
 * (JSON-Liste {Key, Panel, Opened, Closed, Hidden, Pos}); kein Haken = Standard; bei Widerspruch gilt ausgeblendet vor zugeklappt vor aufgeklappt.
 * Ausgeblendet heisst visible:false, nie weglassen: die Werte bleiben erhalten. Unbekannte Schluessel fallen auf Standard zurueck.
 *
 * Ein "Bundle" ist ein waehlbares Fachpanel samt den frei stehenden Elementen, die direkt dahinter stehen (z. B. ein Such-Knopf mit Ergebniszeile
 * oder "Stil zuruecksetzen"); sie wandern und verschwinden mit dem Panel. Alles VOR dem ersten Fachpanel bleibt fest oben.
 * Die Klasse braucht die Property "FormLayout" (RegisterPropertyString('FormLayout', '[]')) und einen Wrapper
 * public function ResetFormLayout(): string { return $this->formLayoutReset(__DIR__ . '/form.json', self::FORM_LAYOUT_PANELS); }
 *
 * Panels werden ueber ihr stabiles 'name'-Feld in form.json gefunden (SUITE.md Konventionen Punkt 9: Logik nie an angezeigtem
 * Text), NICHT ueber die (uebersetzte) Caption. FORM_LAYOUT_PANELS ist dann 'PanelName' => 'schluessel'. Solange ein Modul noch
 * keine Panel-Namen hat, gilt der alte Rueckfall: Beginn der (deutschen) Caption => 'schluessel'. Gemischte Tabellen sind erlaubt.
 * Die Texte dieses Traits stehen englisch im Code, die deutsche Fassung in libs/locale.json (NRGDASH_Text); feste
 * Beschriftungen uebersetzt die Konsole selbst, zusammengesetzte Texte und Listenzellen uebersetzt t().
 */
trait NRGDashFormLayout
{
    /**
     * @param array $elements Elemente des Formulars (oberste Ebene)
     * @param array $panels   Panel-name => stabiler Schluessel (Rueckfall: Beginn der Panel-Caption => stabiler Schluessel)
     * @return array [head, bundles] bundles: key => ['panel' => ..., 'rest' => [...]], in Standardreihenfolge
     */
    private function formLayoutSplit(array $elements, array $panels): array
    {
        $head = [];
        $bundles = [];
        $current = null;
        foreach ($elements as $el) {
            $key = null;
            if (is_array($el) && ($el['type'] ?? '') === 'ExpansionPanel') {
                $name = (string) ($el['name'] ?? '');
                if ($name !== '' && isset($panels[$name])) {
                    $key = $panels[$name];
                } else {
                    // Rueckfall fuer Module ohne Panel-Namen: Beginn der Caption
                    $cap = (string) ($el['caption'] ?? '');
                    foreach ($panels as $prefix => $k) {
                        if (str_starts_with($cap, (string) $prefix)) {
                            $key = $k;
                            break;
                        }
                    }
                }
            }
            if ($key !== null) {
                $bundles[$key] = ['panel' => $el, 'rest' => []];
                $current = $key;
            } elseif ($current === null) {
                $head[] = $el;
            } else {
                $bundles[$current]['rest'][] = $el;
            }
        }
        return [$head, $bundles];
    }

    /** Gespeicherte Auswahl: key => ['open' => std|open|closed|hidden, 'pos' => 1..99, 'idx' => Ursprungsindex], in der anzuwendenden Reihenfolge. */
    private function formLayoutRead(array $keys, string $json): array
    {
        $saved = json_decode($json, true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string) $row['Key']] = $row;
            }
        }
        $out = [];
        foreach (array_values($keys) as $i => $k) {
            $r = $by[$k] ?? [];
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = ['open' => $open, 'pos' => isset($r['Pos']) ? max(1, min(99, (int) $r['Pos'])) : $i + 1, 'idx' => $i];
        }
        uasort($out, function ($a, $b) { return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']]; });
        return $out;
    }

    /** Setzt Reihenfolge, Aufklapp-Zustand und Sichtbarkeit der Fachpanels; haengt das Panel "Formular: Aufbau" hinten an. Rueckgabe: neue Elementliste. */
    private function formLayoutApply(array $elements, array $panels): array
    {
        [$head, $bundles] = $this->formLayoutSplit($elements, $panels);
        if (count($bundles) === 0) {
            return $elements;
        }
        $layout = $this->formLayoutRead(array_keys($bundles), (string) $this->ReadPropertyString('FormLayout'));
        $middle = [];
        foreach ($layout as $key => $l) {
            $panel = $bundles[$key]['panel'];
            $rest = $bundles[$key]['rest'];
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                // nicht weglassen: die Felder bleiben im Formular, gespeicherte Werte bleiben beim "Uebernehmen" erhalten
                $panel['visible'] = false;
                foreach ($rest as &$r) {
                    if (is_array($r)) {
                        $r['visible'] = false;
                    }
                }
                unset($r);
            }
            $middle[] = $panel;
            foreach ($rest as $r) {
                $middle[] = $r;
            }
        }
        return array_merge($head, $middle, [$this->formLayoutPanel($layout, $bundles)]);
    }

    private function formLayoutPanelName(array $panel): string
    {
        // Listenzellen uebersetzt die Konsole nicht: Anzeigename selbst uebersetzen (nur Anzeige, die Logik laeuft ueber 'name')
        return NRGDASH_Text::t(trim((string) preg_replace('/\s*\(.*$/u', '', (string) ($panel['caption'] ?? ''))));
    }

    private function formLayoutPanel(array $layout, array $bundles): array
    {
        $rows = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name = $this->formLayoutPanelName($bundles[$key]['panel']);
            $rows[] = ['Key' => $key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => NRGDASH_Text::t('🙈 Hidden: %s — to show them again, untick “Hidden”. Hidden sections keep working, only the display is missing.', implode(', ', $hidden))]]
            : [];
        // Mit Zahl der ausgeblendeten Bereiche steht ein Wert in der Caption: selbst uebersetzen (die Konsole fände den Text sonst nicht)
        if ($hidden) {
            $layoutCaption = NRGDASH_Text::t('🪟  Form: layout (%d hidden)', count($hidden));
        } else {
            $layoutCaption = NRGDASH_Text::t('🪟  Form: layout');
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => $layoutCaption,
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => 'Here you define which sections are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing itself, so the setting here applies every time the form opens. It takes effect after “Apply changes” the next time the form is opened.'],
                ['type' => 'Label', 'caption' => 'No tick means “Default”: as the module intends. If you choose “Collapsed”, you will not see new items there immediately. The position is a number; with equal numbers the default order is kept. “Hidden” removes a section from the display without it losing anything: its settings are kept and keep working (meant for sections you rarely need). To show it again, untick “Hidden” or click “Reset to default”. If several ticks are set, “Hidden” wins over “Collapsed”, which wins over “Expanded”. The sections at the top (purpose, news, documentation) and at the bottom (feedback, about this module) stay where they are and cannot be hidden.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Sections', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'Section', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Key', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Expanded', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Collapsed', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Hidden', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo ' . $this->formLayoutResetFunction() . '($id);'],
            ]),
        ];
    }

    /** Setzt Aufklapp-Zustand und Reihenfolge im offenen Formular auf den Standard (gilt nach "Uebernehmen"). */
    private function formLayoutReset(string $formPath, array $panels): string
    {
        $form = json_decode((string) file_get_contents($formPath), true);
        [, $bundles] = $this->formLayoutSplit(is_array($form['elements'] ?? null) ? $form['elements'] : [], $panels);
        $rows = [];
        $i = 0;
        foreach ($bundles as $key => $b) {
            $rows[] = ['Key' => $key, 'Panel' => $this->formLayoutPanelName($b['panel']), 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => ++$i];
        }
        $this->UpdateFormField('FormLayout', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return NRGDASH_Text::t('ℹ️ Reset to default. Click “Apply changes” below to save.');
    }
}
