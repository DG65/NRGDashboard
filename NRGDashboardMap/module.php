<?php

declare(strict_types=1);

// Vierte Kachel im NRG-Dashboard-Repo, "NRG-Stack Dashboard Map" (Dietmar,
// 29.07.2026): eine raeumliche 3D-Karte des gesamten Verbunds - physische
// Anordnung (PV-Strang -> Wechselrichter -> Batterie -> Netz/Zaehler ->
// Wallbox -> Fahrzeug) als Basis, Vertrags-/Datenfluss-Beziehungen als
// zusaetzliche Verbindungslinien obendrueber. Phase 1 (dieser Stand):
// Discovery + statisches Layout, noch OHNE Live-Werte/Animation - die
// kommen in Phase 2.
//
// Discovery-Muster 1:1 aus NRGDashboardTile uebernommen (dieselben
// Partnermodul-GUIDs/Vertraege) - bewusst dupliziert statt eine
// Abhaengigkeit auf die Tile-Instanz zu bauen, damit die Karte auch ohne
// installierte Tile funktioniert (Verbund-Grundregel: kein Modul setzt
// ein anderes voraus).

define('NRGDASHMAP_GUID_INVERTERHUB', '{BBE2C593-1A91-426D-A714-29A9C7E87589}');
define('NRGDASHMAP_GUID_METERHUB',    '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}');
define('NRGDASHMAP_GUID_CHARGERHUB',  '{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}');
define('NRGDASHMAP_GUID_HEISHAMON',   '{1919151A-3C0F-4C09-B906-291638EC1469}');
define('NRGDASHMAP_GUID_TESSIE',      '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}');
define('NRGDASHMAP_GUID_TIBBERGRIDREWARD', '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}');

class NRGDashboardMap extends IPSModule
{
    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';

    public function Create()
    {
        parent::Create();

        $this->RegisterAttributeString('MapCache', '[]');
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);

        // Phase 2: zwei Timer - langsame Topologie-Entdeckung + schnelle Wert-Updates
        $this->RegisterTimer('NRGDASHMAP_Discover', 0, 'NRGDASHMAP_Discover($_IPS[\'TARGET\']);');
        $this->RegisterTimer('NRGDASHMAP_UpdateValues', 0, 'NRGDASHMAP_UpdateValues($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Phase 2: Topologie selten neu entdecken (10min), Werte häufig updaten (10s)
        $this->SetTimerInterval('NRGDASHMAP_Discover', 10 * 60 * 1000);
        $this->SetTimerInterval('NRGDASHMAP_UpdateValues', 10 * 1000);
        $this->SetVisualizationType(1);
        $this->SetStatus(102);
        $this->Discover();
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
        return ($v < 0) ? '' : sprintf('#%06X', $v);
    }

    private function FontStack(string $v): string
    {
        return ($v === '' || $v === self::DEF_FONT) ? '' : $v;
    }

    /**
     * Rekursive Ident-Suche (1:1 aus NRGDashboardMonitor/-Tile uebernommen)
     * - IPS_GetObjectIDByIdent findet nur direkte Kinder, Treiber
     * verschieben ihre Variablen aber in fachliche Unterkategorien.
     */
    private function FindVarByIdent(int $parentID, string $ident): int
    {
        $children = @IPS_GetChildrenIDs($parentID);
        if (!is_array($children)) {
            return 0;
        }
        foreach ($children as $cid) {
            $obj = @IPS_GetObject($cid);
            if (!is_array($obj)) {
                continue;
            }
            if (($obj['ObjectIdent'] ?? '') === $ident) {
                return $cid;
            }
            if ($obj['HasChildren'] ?? false) {
                $found = $this->FindVarByIdent($cid, $ident);
                if ($found > 0) {
                    return $found;
                }
            }
        }
        return 0;
    }

    /**
     * Baut den kompletten Knoten-/Kantengraph frisch auf und cacht ihn
     * (Muster: NRGDashboardTile::Discover()). Layer = raeumliche Ebene
     * (0=PV-Straenge ... 5=Fahrzeuge), rein physische Anordnung; Kanten
     * tragen zusaetzlich 'kind' ('physical'|'contract') fuer die
     * unterschiedliche Linienart in der 3D-Darstellung.
     */
    public function UpdateValues(): void
    {
        // Phase 2: nur Wert-Update, keine Topologie-Neuberechnung
        $payload = [
            'values' => $this->GetLiveValues(),
        ];
        $this->UpdateVisualizationValue(json_encode($payload));
    }

    public function Discover(): array
    {
        $nodes = [];
        $edges = [];

        $ihub = $this->singleInverterHubCoreID();
        $ihubData = ($ihub > 0 && function_exists('IHUB_GetFunctions')) ? @IHUB_GetFunctions($ihub) : null;

        if ($ihub > 0 && is_array($ihubData)) {
            // Layer 0: PV-Straenge (MPPT)
            $stringKeys = [];
            for ($i = 1; $i <= 4; $i++) {
                $vid = $this->FindVarByIdent($ihub, 'mppt' . $i . '_power');
                if ($vid > 0) {
                    $key = 'pv_string_' . $i;
                    // PHASE 2: powerID wird gespeichert
                    $nodes[] = ['key' => $key, 'label' => 'PV-Strang ' . $i, 'layer' => 0, 'category' => 'pv', 'powerID' => $vid, 'debug' => 'vid=' . $vid];
                    $stringKeys[] = $key;
                }
            }
            // Layer 1: Wechselrichter
            $acPowerID = $this->FindVarByIdent($ihub, 'ac_power');
            $nodes[] = ['key' => 'inverter', 'label' => IPS_GetName($ihub), 'layer' => 1, 'category' => 'inverter', 'powerID' => ($acPowerID > 0 ? $acPowerID : 0)];
            foreach ($stringKeys as $k) {
                $edges[] = ['from' => $k, 'to' => 'inverter', 'kind' => 'physical'];
            }
            // Layer 2: Batterie
            if ((int) ($ihubData['batPowerID'] ?? 0) > 0) {
                $batPowerID = (int) ($ihubData['batPowerID'] ?? 0);
                $socID = $this->FindVarByIdent($ihub, 'soc');
                $nodes[] = ['key' => 'battery', 'label' => 'Batterie', 'layer' => 2, 'category' => 'battery', 'powerID' => $batPowerID, 'valueID' => ($socID > 0 ? $socID : 0)];
                $edges[] = ['from' => 'inverter', 'to' => 'battery', 'kind' => 'physical'];
            }
        }

        // Layer 3: Netz/Zaehler + Verbraucher (MeterHub)
        $gridKey = null;
        $houseKey = null;
        foreach ($this->MeterHubAssignments() as $a) {
            $fn = $a['function'] ?? '';
            $label = (string) ($a['label'] ?? $fn);
            $powerID = (int) ($a['powerID'] ?? 0);
            $key = 'meter_' . $fn . '_' . ($powerID ?: uniqid());
            if ($fn === 'grid') {
                $gridKey = $key;
                $nodes[] = ['key' => $key, 'label' => $label, 'layer' => 3, 'category' => 'grid', 'powerID' => $powerID];
                if ($ihub > 0) {
                    $edges[] = ['from' => 'inverter', 'to' => $key, 'kind' => 'physical'];
                }
            } elseif ($fn === 'house') {
                $houseKey = $key;
                $nodes[] = ['key' => $key, 'label' => $label, 'layer' => 3, 'category' => 'house', 'powerID' => $powerID];
            } elseif ($fn !== '' && $fn !== 'pv' && $fn !== 'battery') {
                $nodes[] = ['key' => $key, 'label' => $label, 'layer' => 3, 'category' => 'consumer', 'powerID' => $powerID];
                $edges[] = ['from' => $houseKey ?? ($gridKey ?? 'inverter'), 'to' => $key, 'kind' => 'physical'];
            }
        }
        if ($gridKey !== null && $houseKey !== null) {
            $edges[] = ['from' => $gridKey, 'to' => $houseKey, 'kind' => 'physical'];
        }

        // HeishaMon (Waermepumpe) - Verbraucher, gleiche Ebene wie MeterHub-Verbraucher.
        if (function_exists('HEISHA_GetFunctions')) {
            foreach (@IPS_GetInstanceListByModuleID(NRGDASHMAP_GUID_HEISHAMON) as $id) {
                $id = (int) $id;
                $powerID = $this->FindVarByIdent($id, 'power');
                $key = 'heishamon_' . $id;
                $nodes[] = ['key' => $key, 'label' => IPS_GetName($id), 'layer' => 3, 'category' => 'consumer', 'powerID' => ($powerID > 0 ? $powerID : 0)];
                $edges[] = ['from' => $houseKey ?? ($gridKey ?? 'inverter'), 'to' => $key, 'kind' => 'physical'];
            }
        }

        // Layer 4: Wallboxen (ChargerHub)
        $wbKeys = [];
        if (function_exists('CHUB_GetFunctions')) {
            foreach (@IPS_GetInstanceListByModuleID(NRGDASHMAP_GUID_CHARGERHUB) as $id) {
                $id = (int) $id;
                $entries = @CHUB_GetFunctions($id);
                if (is_string($entries)) {
                    $entries = json_decode($entries, true);
                }
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $e) {
                    $powerID = (int) ($e['powerID'] ?? 0);
                    $plugStateID = (int) ($e['plugStateID'] ?? 0);
                    $key = 'wallbox_' . $id;
                    $nodes[] = ['key' => $key, 'label' => (string) ($e['label'] ?? 'Wallbox'), 'layer' => 4, 'category' => 'wallbox', 'plugStateID' => $plugStateID, 'powerID' => $powerID];
                    $edges[] = ['from' => $houseKey ?? ($gridKey ?? 'inverter'), 'to' => $key, 'kind' => 'physical'];
                    $wbKeys[$key] = $plugStateID;
                }
            }
        }

        // Layer 5: Fahrzeuge (Tessie) - Vertragskante (kind='contract') zu
        // einer gerade "verbunden" gemeldeten Wallbox, best-effort ohne die
        // volle Zeitkorrelation aus NRGDashboardTile::AssignVehicles().
        // Phase 2: auch SoC-ID tracken fuer Live-Anzeige.
        if (function_exists('TESSIE_GetVehicleState')) {
            $anyWbConnected = null;
            foreach ($wbKeys as $k => $plugID) {
                if ($plugID > 0 && IPS_VariableExists($plugID) && (bool) GetValue($plugID)) {
                    $anyWbConnected = $k;
                    break;
                }
            }
            foreach (@IPS_GetInstanceListByModuleID(NRGDASHMAP_GUID_TESSIE) as $id) {
                $id = (int) $id;
                $raw = @TESSIE_GetVehicleState($id);
                $state = is_string($raw) ? json_decode($raw, true) : $raw;
                if (!is_array($state)) {
                    continue;
                }
                $socID = (int) ($state['socID'] ?? 0);
                $key = 'vehicle_' . $id;
                $nodes[] = ['key' => $key, 'label' => (string) ($state['name'] ?? 'Fahrzeug'), 'layer' => 5, 'category' => 'vehicle', 'valueID' => $socID];
                if (($state['connected'] ?? false) === true && $anyWbConnected !== null) {
                    $edges[] = ['from' => $anyWbConnected, 'to' => $key, 'kind' => 'contract'];
                }
            }
        }

        // Tibber Grid Rewards - kein physisches Geraet, reines Preissignal
        // an den Netzknoten (Vertragskante).
        if (function_exists('TIBBERGR_GetPriceCurve')) {
            foreach (@IPS_GetInstanceListByModuleID(NRGDASHMAP_GUID_TIBBERGRIDREWARD) as $id) {
                $key = 'tibber_' . $id;
                $nodes[] = ['key' => $key, 'label' => IPS_GetName((int) $id), 'layer' => -1, 'category' => 'signal'];
                if ($gridKey !== null) {
                    $edges[] = ['from' => $key, 'to' => $gridKey, 'kind' => 'contract'];
                }
            }
        }

        $result = ['nodes' => $nodes, 'edges' => $edges];
        $this->WriteAttributeString('MapCache', json_encode($result));
        $this->UpdateVisualizationValue(json_encode($this->buildPayload()));
        return $result;
    }

    private function singleInverterHubCoreID(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(NRGDASHMAP_GUID_INVERTERHUB);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    private function MeterHubAssignments(): array
    {
        $ids = @IPS_GetInstanceListByModuleID(NRGDASHMAP_GUID_METERHUB);
        if (!is_array($ids) || !function_exists('MHUB_GetFunctions')) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            $f = @MHUB_GetFunctions((int) $id);
            if (is_string($f)) {
                $f = json_decode($f, true);
            }
            if (!is_array($f) || !isset($f['assignments']) || !is_array($f['assignments'])) {
                continue;
            }
            foreach ($f['assignments'] as $a) {
                $out[] = $a;
            }
        }
        return $out;
    }

    public function GetMap(): array
    {
        $json = $this->ReadAttributeString('MapCache');
        $data = json_decode($json, true);
        return is_array($data) ? $data : ['nodes' => [], 'edges' => []];
    }

    private function GetLiveValues(): array
    {
        $map = $this->GetMap();
        $values = [];
        foreach ($map['nodes'] ?? [] as $node) {
            $key = $node['key'] ?? '';
            $nodeValues = [];

            // Leistung (powerID)
            if (isset($node['powerID']) && $node['powerID'] > 0 && IPS_VariableExists($node['powerID'])) {
                $power = (float) GetValue($node['powerID']);
                $nodeValues['power'] = round($power, 1);
            }

            // Weitere Werte (SoC, Prozent, etc.)
            if (isset($node['valueID']) && $node['valueID'] > 0 && IPS_VariableExists($node['valueID'])) {
                $value = (float) GetValue($node['valueID']);
                $nodeValues['value'] = round($value, 1);
            }

            if (!empty($nodeValues)) {
                $values[$key] = $nodeValues;
            }
        }
        return $values;
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($this->buildPayload()) . ');</script>';
        return $html;
    }

    private function buildPayload(): array
    {
        $map = $this->GetMap();
        return [
            'ok'     => true,
            'nodes'  => $map['nodes'],
            'edges'  => $map['edges'],
            'values' => $this->GetLiveValues(),
            'bg'     => $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND)),
            'font'   => $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT)),
        ];
    }
}
