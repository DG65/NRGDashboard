<?php

declare(strict_types=1);

/**
 * Live berechnete Verbindungs-Statuszeilen fuers Konfigurationsformular
 * (SUITE.md, "Verbund-Verbindungen im Formular sichtbar machen", 21.09.2026).
 * Vier Zustaende: ✅ verbunden (mit Instanz und uebernommenen Werten), ⚠️ verbunden/
 * mehrdeutig/nichts Brauchbares, ℹ️ nicht gefunden (und was dann gilt).
 */
trait NRGDashFormStatus
{
    /**
     * Setzt die Beschriftung des Elements mit dem Namen $name - immer REKURSIV ueber
     * alle 'items' (ein Label kann in einem ExpansionPanel liegen; nur auf oberster
     * Ebene zu suchen war der Fehler im Szenariorechner). true, wenn gefunden.
     */
    private function setFormStatusLine(array &$items, string $name, string $caption): bool
    {
        foreach ($items as &$el) {
            if (!is_array($el)) {
                continue;
            }
            if (($el['name'] ?? null) === $name) {
                $el['caption'] = $caption;
                // Verbund-Konvention: rein automatisch uebernommene (🔗) Zeilen GRUEN,
                // gemischte/andere Zustaende Standardfarbe (Label-Farbe gilt fuer das ganze Label)
                $lines = array_filter(array_map('trim', explode("\n", $caption)), 'strlen');
                $allAuto = count($lines) > 0;
                foreach ($lines as $l) {
                    if (strpos($l, '🔗') !== 0) {
                        $allAuto = false;
                        break;
                    }
                }
                $el['color'] = $allAuto ? 0x2E8B3D : -1;
                return true;
            }
            foreach (['items', 'elements'] as $key) {
                if (isset($el[$key]) && is_array($el[$key]) && $this->setFormStatusLine($el[$key], $name, $caption)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** "#1234 „Name“" - fuer die Statuszeilen. */
    private function formInstanceLabel(int $id): string
    {
        if ($id <= 0 || !@IPS_InstanceExists($id)) {
            return '#' . $id;
        }
        return '#' . $id . ' „' . IPS_GetName($id) . '“';
    }

    /** Instanzstatus als kurzer Text (102 = aktiv). */
    private function formInstanceState(int $id): string
    {
        if ($id <= 0 || !@IPS_InstanceExists($id)) {
            return 'unbekannt';
        }
        return ((int) (IPS_GetInstance($id)['InstanceStatus'] ?? 0)) === 102 ? 'aktiv' : 'nicht aktiv';
    }

    /**
     * Blendet ein Eingabefeld aus (visible=false), ebenfalls rekursiv. Der Wert bleibt in
     * der Konfiguration erhalten - ausgeblendet ist nur die Anzeige. Der automatische Wert
     * wird NIE per UpdateFormField('value') ins Feld geschrieben, sonst speichert
     * "Uebernehmen" ihn als eigene Angabe (SUITE.md, "Wert kommt automatisch").
     */
    private function hideFormField(array &$items, string $name): bool
    {
        foreach ($items as &$el) {
            if (!is_array($el)) {
                continue;
            }
            if (($el['name'] ?? null) === $name) {
                $el['visible'] = false;
                return true;
            }
            foreach (['items', 'elements'] as $key) {
                if (isset($el[$key]) && is_array($el[$key]) && $this->hideFormField($el[$key], $name)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Automatik statt Eingabefeld: eigene Angabe (✏️, Feld bleibt sichtbar), automatisch
     * erkannt (🔗, Feld ausgeblendet), nichts gefunden (ℹ️, Feld sichtbar). Liefert die Zeile.
     * $explicit = Wert im Eingabefeld, $resolved = das, was tatsaechlich gilt.
     */
    private function autoFieldLine(array &$elements, string $field, string $label, int $explicit, int $resolved, string $source, string $noneText, bool $isInstance = true): string
    {
        $what = $isInstance ? $this->formInstanceLabel($resolved) : ('#' . $resolved . ' „' . (@IPS_ObjectExists($resolved) ? IPS_GetName($resolved) : '?') . '“');
        if ($explicit > 0) {
            return '✏️ ' . $label . ': ' . $what . ' (eigene Angabe)';
        }
        if ($resolved > 0) {
            $this->hideFormField($elements, $field);
            return '🔗 ' . $label . ': ' . $what . ' (automatisch von ' . $source . ')';
        }
        return 'ℹ️ ' . $label . ': ' . $noneText;
    }
}
