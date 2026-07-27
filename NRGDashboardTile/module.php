<?php

declare(strict_types=1);

// GUID    : {6B2F8C41-9E3A-4D6B-8F1C-5A7D9E2B4C61}
// Verbund : NRG-Stack (DG65) - siehe https://github.com/DG65/EMS/blob/main/SUITE.md
//
// Phase 1: automatische Geräte-Discovery über die *_GetFunctions-Verträge
// des Verbunds. Kein Hardcoding von Variablen-IDs - genau das Problem, das
// dieses Modul lösen soll (feste IPS View-Verknüpfungen brechen beim
// Löschen der referenzierten Instanz). Keine Darstellung in dieser Phase,
// nur die Erfassung und Normalisierung der Geräteliste.

// Partnermodul-GUIDs (für automatische Discovery). Kein Modul setzt ein
// anderes voraus - jeder Aufruf steht hinter function_exists().
define('NRGDASH_GUID_INVERTERHUB',    '{BBE2C593-1A91-426D-A714-29A9C7E87589}');
define('NRGDASH_GUID_INVERTERHUBMON', '{7B1F9A34-6C52-4E8D-9A1B-4F3E2D7C6A19}');
define('NRGDASH_GUID_METERHUB',       '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}');
define('NRGDASH_GUID_METERHUBV',      '{ADF18291-2E60-4354-92F5-B96863C127C8}');
define('NRGDASH_GUID_CHARGERHUB',     '{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}');
define('NRGDASH_GUID_HEISHAMON',      '{1919151A-3C0F-4C09-B906-291638EC1469}');
define('NRGDASH_GUID_TESSIE',         '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}');
define('NRGDASH_GUID_TIBBERGRIDREWARD', '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}');
define('NRGDASH_GUID_STROMGEDACHT',   '{D5A8C3A1-2222-4A55-8888-123456789003}');
define('NRGDASH_GUID_PVPROGNOSE',     '{257DD4E8-9705-462E-89FC-56D0A1038353}');
define('NRGDASH_GUID_LASTPROGNOSE',   '{DC5AD508-507F-40EA-8630-0959AED83050}');

class NRGDashboardTile extends IPSModule
{
    // Kategorien für die spätere Anordnung (Erzeugung -> Speicher ->
    // Verteilung -> Verbraucher), siehe Phase 2. functionCategory() ordnet
    // jeden gefundenen function-Wert einer dieser vier Kategorien zu.
    private const CATEGORY_ORDER = ['erzeugung', 'speicher', 'verteilung', 'verbraucher'];

    public function Create()
    {
        parent::Create();

        $this->RegisterAttributeString('DeviceCache', '[]');
        $this->RegisterAttributeString('DiagnosticsCache', '[]');
        $this->RegisterAttributeInteger('LastDiscoveryTs', 0);
        $this->RegisterTimer('NRGDASH_Refresh', 0, 'NRGDASH_Discover($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('NRGDASH_Refresh', 5 * 60 * 1000);
        // Baseline-Status auch VOR dem ersten Discover()-Lauf sichtbar setzen -
        // sonst zeigt die Instanz bis zum ersten Timer-Tick keinen definierten
        // Zustand (Verbund-Konvention: Zustand sichtbar melden, nicht nur im Log).
        $this->SetStatus(102);
    }

    /**
     * Durchsucht alle installierten Partnerinstanzen und normalisiert deren
     * *_GetFunctions-Verträge auf ein gemeinsames Geräte-Schema. Ergebnis
     * wird gecacht (Attribut DeviceCache), damit die Kachel nicht bei jedem
     * Rendern erneut alle Instanzen abfragen muss.
     */
    public function Discover(): array
    {
        $devices = [];

        $devices = array_merge($devices, $this->discoverListContract(
            NRGDASH_GUID_INVERTERHUB, 'IHUB_GetFunctions', 'inverterhub'
        ));
        $devices = array_merge($devices, $this->discoverListContract(
            NRGDASH_GUID_METERHUB, 'MHUB_GetFunctions', 'meterhub'
        ));
        $devices = array_merge($devices, $this->discoverListContract(
            NRGDASH_GUID_METERHUBV, 'MHUBV_GetFunctions', 'meterhub'
        ));
        $devices = array_merge($devices, $this->discoverListContract(
            NRGDASH_GUID_CHARGERHUB, 'CHUB_GetFunctions', 'chargerhub'
        ));
        $devices = array_merge($devices, $this->discoverHeishaMon());
        $devices = array_merge($devices, $this->discoverTessie());

        $diagnostics = $this->discoverDiagnostics();

        $this->WriteAttributeString('DeviceCache', json_encode($devices));
        $this->WriteAttributeString('DiagnosticsCache', json_encode($diagnostics));
        $this->WriteAttributeInteger('LastDiscoveryTs', time());
        $this->SetStatus(102);
        $this->LogMessage(
            sprintf('NRG Dashboard: %d Geräte, %d Diagnose-Einträge gefunden', count($devices), count($diagnostics)),
            KL_MESSAGE
        );
        $this->UpdateVisualizationValue(json_encode($this->buildPayload()));

        return $devices;
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($this->buildPayload()) . ');</script>';
        return $html;
    }

    /**
     * Baut die Nutzlast fuer die Kachel (Phase 2: Energiefluss-Diagramm).
     * Ergaenzt jedes Geraet um seinen aktuellen Leistungswert (aufgeloest
     * aus powerID) - der Discovery-Cache selbst speichert nur die Referenz,
     * damit ein Cache-Alter die Werte nie veralten laesst (Discover() laeuft
     * nur alle 5 Minuten, Werte werden bei jedem Rendern/UpdateVisualizationValue
     * frisch gelesen).
     */
    private function buildPayload(): array
    {
        $devices = array_map(function (array $d) {
            $d['value'] = $this->resolvePowerValue($d);
            return $d;
        }, $this->GetDevices());

        return [
            'ok'          => true,
            'devices'     => $devices,
            'diagnostics' => $this->GetDiagnostics(),
            // Sichtbarer Aktualisierungsstand fuer den Nutzer, ohne Log-Zugriff
            // (Verbund-Konvention: Zustand sichtbar melden). Zeitpunkt des
            // letzten erfolgreichen Discover()-Laufs, nicht des Renderns.
            'updatedAt'   => $this->ReadAttributeInteger('LastDiscoveryTs'),
        ];
    }

    /**
     * Liest den aktuellen Leistungswert eines Geraete-Eintrags aus seiner
     * powerID (Referenz, kein gecachter Wert - siehe *_GetFunctions-
     * Konvention). null, wenn keine powerID vorhanden oder die Variable
     * zwischenzeitlich geloescht wurde (genau der Fall, den dieses Modul
     * gegenueber IPS View robust machen soll).
     */
    private function resolvePowerValue(array $device): ?float
    {
        $id = $device['powerID'] ?? 0;
        if ($id > 0 && IPS_VariableExists($id)) {
            return (float) GetValue($id);
        }
        return null;
    }

    /**
     * Zuletzt bekannter Discovery-Stand, ohne erneut abzufragen - für den
     * Konsum durch die HTML-Kachel (Phase 2).
     */
    public function GetDevices(): array
    {
        $json = $this->ReadAttributeString('DeviceCache');
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    public function GetDiagnostics(): array
    {
        $json = $this->ReadAttributeString('DiagnosticsCache');
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Diagnose-Verträge sind bewusst NICHT ins gemeinsame Geräte-Schema
     * gemischt (normalizeEntry()/functionCategory()) - sie tragen keinen
     * function-Wert und gehören fachlich zu einer eigenen Instanz statt
     * einem Fluss-Icon. Erster Anbieter: IHUBMON_GetDiagnostics
     * (InverterHubMonitor, ab 0.74.0-beta.1). Jeder Eintrag ist bereits
     * generisch (type/label/level/threshold/reason + Referenzen/Werte je
     * Typ) - das Rendering in module.html iteriert type-neutral, damit ein
     * künftiger zweiter Anbieter (MeterHub, HeishaMon, ...) ohne
     * Aenderungen an dieser Stelle reinpasst, solange er demselben
     * Grundschema folgt (siehe README, Abschnitt "Diagnostik-Vertrag").
     */
    private function discoverDiagnostics(): array
    {
        $results = [];
        if (!function_exists('IHUBMON_GetDiagnostics')) {
            return $results;
        }
        foreach (IPS_GetInstanceListByModuleID(NRGDASH_GUID_INVERTERHUBMON) as $id) {
            $data = IHUBMON_GetDiagnostics($id);
            if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
                continue;
            }
            foreach ($data['entries'] as $entry) {
                if (!is_array($entry) || !isset($entry['type'])) {
                    continue;
                }
                $entry['source']     = 'inverterhubmonitor';
                $entry['instanceID'] = $data['instanceID'] ?? $id;
                $results[] = $entry;
            }
        }
        return $results;
    }

    /**
     * Gemeinsamer Pfad für alle Partner, die dem MHUB_GetFunctions-Muster
     * folgen (Liste von Einträgen mit function/label/powerID/...): Instanzen
     * suchen, Vertrag abrufen, jeden Eintrag um Quelle und Kategorie
     * ergänzen. Ohne installiertes Partnermodul bleibt die Liste leer -
     * Verbund-Grundregel, kein Modul setzt ein anderes voraus.
     */
    private function discoverListContract(string $moduleGUID, string $function, string $source): array
    {
        $results = [];
        if (!function_exists($function)) {
            return $results;
        }
        foreach (IPS_GetInstanceListByModuleID($moduleGUID) as $id) {
            $entries = call_user_func($function, $id);
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry) || !isset($entry['function'])) {
                    continue;
                }
                $results[] = $this->normalizeEntry($entry, $source, $id);
            }
        }
        return $results;
    }

    /**
     * HeishaMon weicht bewusst vom function/label-Vokabular ab
     * (Type/Caption/PowerID/EnergyID/Measured, vor der Verbund-Konvention
     * veröffentlicht - ein publizierter Vertrag wird nicht umbenannt). Die
     * Uebersetzung liegt auf der Konsumentenseite, hier.
     */
    private function discoverHeishaMon(): array
    {
        $results = [];
        if (!function_exists('HEISHA_GetFunctions')) {
            return $results;
        }
        foreach (IPS_GetInstanceListByModuleID(NRGDASH_GUID_HEISHAMON) as $id) {
            $entries = HEISHA_GetFunctions($id);
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry) || !isset($entry['Type'])) {
                    continue;
                }
                $results[] = $this->normalizeEntry([
                    'function'       => $entry['Type'],
                    'label'          => $entry['Caption'] ?? IPS_GetName($id),
                    'powerID'        => $entry['PowerID'] ?? 0,
                    'energyImportID' => $entry['EnergyID'] ?? 0,
                    'measured'       => $entry['Measured'] ?? true,
                ], 'heishamon', $id);
            }
        }
        return $results;
    }

    /**
     * Tessie liefert ein Objekt-Vertrag (kein GetFunctions), daher eigene
     * Uebersetzung: ein Fahrzeug wird als Gerät vom Typ 'vehicle' geführt.
     */
    private function discoverTessie(): array
    {
        $results = [];
        if (!function_exists('TESSIE_GetVehicleState')) {
            return $results;
        }
        foreach (IPS_GetInstanceListByModuleID(NRGDASH_GUID_TESSIE) as $id) {
            $state = TESSIE_GetVehicleState($id);
            if (!is_array($state)) {
                continue;
            }
            $results[] = $this->normalizeEntry([
                'function' => 'vehicle',
                'label'    => IPS_GetName($id),
                'socID'    => $state['socID'] ?? 0,
            ], 'tessie', $id);
        }
        return $results;
    }

    /**
     * Ein Eintrag im gemeinsamen Geräte-Schema der Kachel: function/label
     * wie im Verbund üblich, dazu Herkunft (Quellmodul) und instanceID
     * (welche Partnerinstanz das geliefert hat - für Nachverfolgung bei
     * mehreren gleichartigen Instanzen) sowie die vorläufige Kategorie
     * für die Anordnung in Phase 2.
     */
    private function normalizeEntry(array $entry, string $source, int $instanceID): array
    {
        $entry['source']     = $source;
        $entry['instanceID'] = $instanceID;
        $entry['category']   = $this->functionCategory((string) $entry['function']);
        return $entry;
    }

    /**
     * Ordnet einen function-Wert des Verbund-Vokabulars einer der vier
     * Anzeigekategorien zu (Erzeugung -> Speicher -> Verteilung ->
     * Verbraucher, siehe SUITE.md-Formular-Konvention). Unbekannte Werte
     * fallen auf 'verbraucher' zurück, statt zu verschwinden - lieber
     * falsch einsortiert als unsichtbar.
     */
    private function functionCategory(string $function): string
    {
        $map = [
            'pv'      => 'erzeugung',
            'battery' => 'speicher',
            'grid'    => 'verteilung',
            'house'   => 'verbraucher',
            'charger' => 'verbraucher',
            'heatpump' => 'verbraucher',
            'vehicle'  => 'verbraucher',
        ];
        if (isset($map[$function])) {
            return $map[$function];
        }
        // Wallbox-Kanäle aus MeterHub (wallbox1..5) und ähnlich
        // präfixierte Verbraucherkanäle fallen ebenfalls auf Verbraucher.
        return 'verbraucher';
    }
}
