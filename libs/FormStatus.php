<?php

declare(strict_types=1);

require_once __DIR__ . '/NRGDASH_Text.php';

/**
 * Live berechnete Verbindungs-Statuszeilen fuers Konfigurationsformular
 * (SUITE.md, "Verbund-Verbindungen im Formular sichtbar machen", 21.09.2026).
 * Vier Zustaende: ✅ verbunden (mit Instanz und uebernommenen Werten), ⚠️ verbunden/
 * mehrdeutig/nichts Brauchbares, ℹ️ nicht gefunden (und was dann gilt).
 * Texte englisch im Code, deutsche Fassung in libs/locale.json (NRGDASH_Text). Logik nie am angezeigten Text:
 * ob eine Instanz aktiv ist, sagt formInstanceActive(), nicht der Vergleich mit formInstanceState().
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

    /**
     * Setzt nur die Beschriftung des Elements mit dem Namen $name (rekursiv), ohne die Statusfarbe von setFormStatusLine().
     * Gedacht fuer feste Formulartexte mit eingesetztem Wert, die die Konsole so nicht mehr selbst uebersetzen kann.
     */
    private function setFormCaption(array &$items, string $name, string $caption): bool
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
                if (isset($el[$key]) && is_array($el[$key]) && $this->setFormCaption($el[$key], $name, $caption)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** "#1234 „Name“" - fuer die Statuszeilen (die Anfuehrungszeichen der Sprache stehen in der Vorlage). */
    private function formInstanceLabel(int $id): string
    {
        if ($id <= 0 || !@IPS_InstanceExists($id)) {
            return '#' . $id;
        }
        return NRGDASH_Text::t('#%d “%s”', $id, IPS_GetName($id));
    }

    /** true, wenn die Instanz existiert und aktiv ist (Status 102). Fuer Vergleiche; nie den Anzeigetext vergleichen. */
    private function formInstanceActive(int $id): bool
    {
        return $id > 0 && @IPS_InstanceExists($id) && ((int) (IPS_GetInstance($id)['InstanceStatus'] ?? 0)) === 102;
    }

    /** Instanzstatus als kurzer Text (102 = aktiv), nur zur Anzeige. */
    private function formInstanceState(int $id): string
    {
        if ($id <= 0 || !@IPS_InstanceExists($id)) {
            return NRGDASH_Text::t('unknown');
        }
        return $this->formInstanceActive($id) ? NRGDASH_Text::t('active') : NRGDASH_Text::t('not active');
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
        $what = $isInstance ? $this->formInstanceLabel($resolved) : NRGDASH_Text::t('#%d “%s”', $resolved, @IPS_ObjectExists($resolved) ? IPS_GetName($resolved) : '?');
        if ($explicit > 0) {
            return NRGDASH_Text::t('✏️ %s: %s (own entry)', $label, $what);
        }
        if ($resolved > 0) {
            $this->hideFormField($elements, $field);
            return NRGDASH_Text::t('🔗 %s: %s (detected automatically from %s)', $label, $what, $source);
        }
        return NRGDASH_Text::t('ℹ️ %s: %s', $label, $noneText);
    }
}
