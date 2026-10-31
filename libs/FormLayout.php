<?php

declare(strict_types=1);

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
 */
trait NRGDashFormLayout
{
    /**
     * @param array $elements Elemente des Formulars (oberste Ebene)
     * @param array $panels   Beginn der Panel-Caption => stabiler Schluessel
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
                $cap = (string) ($el['caption'] ?? '');
                foreach ($panels as $prefix => $k) {
                    if (str_starts_with($cap, (string) $prefix)) {
                        $key = $k;
                        break;
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
        return trim((string) preg_replace('/\s*\(.*$/u', '', (string) ($panel['caption'] ?? '')));
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
            ? [['type' => 'Label', 'caption' => '🙈 Ausgeblendet: ' . implode(', ', $hidden) . ' — zum Wiederzeigen den Haken bei „Ausgeblendet“ entfernen. Ausgeblendete Bereiche arbeiten weiter, nur die Anzeige fehlt.']]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🪟  Formular: Aufbau' . ($hidden ? ' (' . count($hidden) . ' ausgeblendet)' : ''),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => 'Hier legst du fest, welche Bereiche beim Öffnen des Formulars aufgeklappt sind und in welcher Reihenfolge sie stehen. Symcon merkt sich das Auf- und Zuklappen nicht selbst, deshalb gilt die Einstellung hier jedes Mal beim Öffnen. Sie wirkt nach „Übernehmen“ beim nächsten Öffnen des Formulars.'],
                ['type' => 'Label', 'caption' => 'Kein Haken heißt „Standard“: wie das Modul es vorsieht. Wer „Zugeklappt“ wählt, sieht dann auch Neues dort nicht sofort. Die Position ist eine Zahl; bei gleicher Zahl bleibt die Standardreihenfolge. „Ausgeblendet“ nimmt einen Bereich aus der Anzeige, ohne dass er etwas verliert: seine Einstellungen bleiben erhalten und wirken weiter (gedacht für Bereiche, die man selten braucht). Zum Wiederzeigen den Haken bei „Ausgeblendet“ entfernen oder „Auf Standard zurücksetzen“ klicken. Sind mehrere Haken gesetzt, gilt „Ausgeblendet“ vor „Zugeklappt“ vor „Aufgeklappt“. Die Bereiche oben (Zweck, Neu, Dokumentation) und unten (Rückmeldungen, Über dieses Modul) bleiben, wo sie sind, und lassen sich nicht ausblenden.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Bereiche', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'Bereich', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Schlüssel', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Aufgeklappt', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Zugeklappt', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Ausgeblendet', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Auf Standard zurücksetzen', 'onClick' => 'echo ' . $this->formLayoutResetFunction() . '($id);'],
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
        return 'ℹ️ Auf Standard gesetzt. Zum Speichern unten „Übernehmen“ klicken.';
    }
}
