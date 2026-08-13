<?php

declare(strict_types=1);

/**
 * NRGDashboardHeatSchema - Anlagenschema fuer den Heizkreislauf (Pumpen,
 * Ventile, Durchfluss, Temperaturen), Vorbild Kieback&Peter-Gebaeudeleit-
 * technik-Visualisierungen (Dietmar, 13.08.2026). Konsumiert den
 * gemeinsamen 'heatpump'-Vertragstyp generisch (HEISHA_GetFunctions() /
 * WPHUB_GetFunctions()) - JEDES Waermepumpen-Modul liefert nur, was seine
 * Datenquelle hergibt, die restlichen optionalen Felder bleiben 0/leer.
 * Kein Modul (auch nicht HeishaMon) wird vorausgesetzt - reine
 * WPHub-Installationen ohne Zusatzplatine zeigen nur die Basisfelder
 * (elektrische Leistung, sofern gemessen), kein Rohrschema.
 *
 * Optionale Felder (contractVersion 1.3, mit HeishaMon abgestimmt, additiv
 * versioniert): pumpFlowID (l/min), pumpSpeedID (U/min), pumpDutyID
 * (Rohwert), threeWayValveStateID (0=Room/1=DHW), twoWayValveStateID
 * (bool), mainInletTempID/mainOutletTempID/z1WaterTempID/z2WaterTempID/
 * dhwTempID/bufferTempID/dischargeTempID (°C), compressorFreqID (Hz),
 * defrostingStateID (bool). Fehlt ein Feld (=0), wird es im Schema
 * ausgeblendet statt einer Nullanzeige.
 */
class NRGDashboardHeatSchema extends IPSModule
{
    private const HEISHA_GUID = '{1919151A-3C0F-4C09-B906-291638EC1469}';
    // WPHub-Vertrag (contractVersion 1.3, mit WPHub am 13.08.2026 bestaetigt):
    // liefert NIE die 14 Pumpen-/Ventilfelder (Cloud-API kennt sie nicht,
    // Schluessel fehlen komplett statt 0) - deshalb ueberall defensiv mit
    // ?? 0 statt fester Schluesselmenge gelesen (siehe DiscoverHeatpumps()).
    private const WPHUB_GUID = '{5BE429EA-3AAD-4A8B-85DE-5778CCA2E6BC}';
    private const METERHUB_GUID = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);

        $this->RegisterTimer('Refresh', 0, 'NRGDASHHEAT_Render($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);
        $this->SetTimerInterval('Refresh', 5 * 60 * 1000);
        $this->Render();
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    private function readIntProperty(string $name, int $default): int
    {
        $v = @$this->ReadPropertyInteger($name);
        return is_int($v) ? $v : $default;
    }

    private function readStringProperty(string $name, string $default): string
    {
        $v = @$this->ReadPropertyString($name);
        return is_string($v) && $v !== '' ? $v : $default;
    }

    private function ColorOrEmpty(int $v): string
    {
        return $v < 0 ? '' : sprintf('#%06X', $v);
    }

    private function FontStack(string $v): string
    {
        return ($v === '' || $v === self::DEF_FONT) ? '' : $v;
    }

    public function GetVisualizationTile()
    {
        $payload = $this->buildPayload();
        $this->updateInstanceStatus($payload);
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($payload) . ');</script>';
        return $html;
    }

    public function Render(): void
    {
        $payload = $this->buildPayload();
        $this->updateInstanceStatus($payload);
        $this->UpdateVisualizationValue(json_encode($payload));
    }

    private function updateInstanceStatus(array $payload): void
    {
        $this->SetStatus(($payload['ok'] ?? false) ? 102 : 104);
    }

    /**
     * Alle Waermepumpen-Instanzen ueber den gemeinsamen 'heatpump'-Vertrag
     * (additiv um die Anlagenschema-Felder erweitert) einsammeln - JEDES
     * liefernde Modul wird generisch behandelt, kein Modulname fest
     * verdrahtet ausser fuer die Discovery selbst (IPS_GetInstanceListBy-
     * ModuleID). Reale elektrische Leistung kommt bevorzugt aus dem Vertrag
     * selbst (Measured=true); ist dort keine echte Messung hinterlegt, wird
     * zusaetzlich ueber MeterHub (function==='heatpump', bevorzugt
     * authority='billing') gemerged - Muster: NRGDashboardMonitor::
     * GridPowerID(), MeterHubs eigener Vorschlag vom 13.08.2026 (Merge beim
     * Konsumenten statt Kopplung zwischen den Erzeuger-Modulen).
     */
    private function DiscoverHeatpumps(): array
    {
        $entries = [];
        foreach ([self::HEISHA_GUID => 'HEISHA_GetFunctions', self::WPHUB_GUID => 'WPHUB_GetFunctions'] as $guid => $fn) {
            if (!function_exists($fn)) {
                continue;
            }
            foreach (@IPS_GetInstanceListByModuleID($guid) as $id) {
                $data = @$fn((int) $id);
                if (is_string($data)) {
                    $data = json_decode($data, true);
                }
                if (!is_array($data)) {
                    continue;
                }
                // Manche Vertraege liefern EIN Objekt, manche eine Liste
                // (HeishaMon: Liste mit einem Eintrag je Instanz) - beide
                // Formen einheitlich als Liste behandeln.
                $list = (isset($data['Type']) || isset($data['type'])) ? [$data] : $data;
                foreach ($list as $e) {
                    if (!is_array($e) || (($e['Type'] ?? $e['type'] ?? '') !== 'heatpump')) {
                        continue;
                    }
                    $e['_instanceID'] = (int) $id;
                    $entries[] = $e;
                }
            }
        }
        return $entries;
    }

    private function ResolveMeterHubPower(): array
    {
        // [instanceID => ['powerID'=>int,'authority'=>string,'latency'=>string]]
        $out = [];
        if (!function_exists('MHUB_GetFunctions')) {
            return $out;
        }
        foreach (@IPS_GetInstanceListByModuleID(self::METERHUB_GUID) as $id) {
            $f = @MHUB_GetFunctions((int) $id);
            if (is_string($f)) {
                $f = json_decode($f, true);
            }
            if (!is_array($f) || !isset($f['assignments']) || !is_array($f['assignments'])) {
                continue;
            }
            foreach ($f['assignments'] as $a) {
                if (($a['function'] ?? '') !== 'heatpump') {
                    continue;
                }
                $out[] = [
                    'powerID'   => (int) ($a['powerID'] ?? 0),
                    'authority' => (string) ($a['authority'] ?? 'auxiliary'),
                ];
            }
        }
        return $out;
    }

    private function num(int $vid): ?float
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return null;
        }
        $v = GetValue($vid);
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * Wie num(), aber fuer Temperaturfelder: HeishaMon meldet einen fehlenden
     * Sensor (z.B. Zone 2 nicht verbaut) nicht als 0/null, sondern als
     * physikalisch unmoegliche Sentinel-Temperatur wie -78 °C - real bei
     * Dietmar gefunden, 13.08.2026 (Zone 2 hat eine gueltige Variablen-ID,
     * der Wert war aber -78). Alles unter -50°C/ueber 120°C gilt als
     * "kein echter Messwert" statt es als Zahl anzuzeigen.
     */
    private function numTemp(int $vid): ?float
    {
        $v = $this->num($vid);
        if ($v === null || $v < -50.0 || $v > 120.0) {
            return null;
        }
        return $v;
    }

    private function boolVal(int $vid): ?bool
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return null;
        }
        $v = GetValue($vid);
        return is_bool($v) ? $v : (is_numeric($v) ? ((float) $v != 0.0) : null);
    }

    private function buildPayload(): array
    {
        $heatpumps = $this->DiscoverHeatpumps();
        if (count($heatpumps) === 0) {
            return [
                'ok'    => false,
                'error' => 'Keine Wärmepumpe gefunden - HeishaMon oder WPHub installieren und konfigurieren.',
            ];
        }
        $meterhubPower = $this->ResolveMeterHubPower();

        $units = [];
        foreach ($heatpumps as $e) {
            $powerID = (int) ($e['PowerID'] ?? $e['powerID'] ?? 0);
            $measured = (bool) ($e['Measured'] ?? $e['measured'] ?? false);
            if (!$measured || $powerID <= 0) {
                // Eigene Messung fehlt/unecht - MeterHub-Fallback (bevorzugt
                // authority=billing, sonst der erste gefundene Eintrag).
                $billing = null;
                $any = null;
                foreach ($meterhubPower as $mp) {
                    if ($mp['powerID'] <= 0) {
                        continue;
                    }
                    $any = $any ?? $mp;
                    if ($mp['authority'] === 'billing') {
                        $billing = $mp;
                        break;
                    }
                }
                $pick = $billing ?? $any;
                if ($pick !== null) {
                    $powerID = $pick['powerID'];
                    $measured = true;
                }
            }

            $hasPipeSchema = (int) ($e['pumpFlowID'] ?? 0) > 0
                || (int) ($e['pumpSpeedID'] ?? 0) > 0
                || (int) ($e['mainInletTempID'] ?? 0) > 0
                || (int) ($e['mainOutletTempID'] ?? 0) > 0;

            $units[] = [
                'id'              => (int) $e['_instanceID'],
                'label'           => (string) ($e['Caption'] ?? $e['caption'] ?? IPS_GetName((int) $e['_instanceID'])),
                'hasPipeSchema'   => $hasPipeSchema,
                'power'           => $this->num($powerID),
                'pumpFlow'        => $this->num((int) ($e['pumpFlowID'] ?? 0)),
                'pumpSpeed'       => $this->num((int) ($e['pumpSpeedID'] ?? 0)),
                'pumpDuty'        => $this->num((int) ($e['pumpDutyID'] ?? 0)),
                'threeWayValve'   => $this->num((int) ($e['threeWayValveStateID'] ?? 0)),
                'twoWayValve'     => $this->boolVal((int) ($e['twoWayValveStateID'] ?? 0)),
                'mainInletTemp'   => $this->numTemp((int) ($e['mainInletTempID'] ?? 0)),
                'mainOutletTemp'  => $this->numTemp((int) ($e['mainOutletTempID'] ?? 0)),
                'z1WaterTemp'     => $this->numTemp((int) ($e['z1WaterTempID'] ?? 0)),
                'z2WaterTemp'     => $this->numTemp((int) ($e['z2WaterTempID'] ?? 0)),
                'dhwTemp'         => $this->numTemp((int) ($e['dhwTempID'] ?? 0)),
                'bufferTemp'      => $this->numTemp((int) ($e['bufferTempID'] ?? 0)),
                'compressorFreq'  => $this->num((int) ($e['compressorFreqID'] ?? 0)),
                'dischargeTemp'   => $this->numTemp((int) ($e['dischargeTempID'] ?? 0)),
                'defrosting'      => $this->boolVal((int) ($e['defrostingStateID'] ?? 0)),
            ];
        }

        return [
            'ok'          => true,
            'uid'         => (string) $this->InstanceID,
            'bg'          => $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND)),
            'font'        => $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT)),
            'renderedAt'  => time(),
            'units'       => $units,
        ];
    }
}
