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
}
