<?php

declare(strict_types=1);

/**
 * NRGDashboardTopology - Verbund-Uebersicht als Netzwerk-Karte (drittes
 * Modul neben NRGDashboardTile/Monitor). Auf Vorschlag von EMS (28.07.2026):
 * Stern-Topologie um die EMS-Instanz herum, Knoten = von EMS gefundene
 * Partnermodule, Farbe = Verbindungsstatus aus EMS_GetFederationHealth().
 *
 * Bewusst begrenzter Scope (v1, mit EMS abgestimmt): EMS kennt nur SEINE
 * eigenen gefundenen Partner, nicht das vollstaendige Beziehungsgeflecht
 * des gesamten Verbunds (z.B. dass NRGDashboard selbst direkt mit
 * InverterHub spricht). Ein vollstaendiger Multi-Modul-Graph braeuchte
 * einen neuen, noch nicht existierenden Vertrag (z.B. <Modul>_GetConnections()),
 * den der Verbund erst gemeinsam abstimmen muesste - das wird hier bewusst
 * NICHT vorweggenommen.
 */
class NRGDashboardTopology extends IPSModule
{
    private const EMS_GUID = '{31C61A7B-28C4-4F97-9651-1A64B3469E3C}';

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('EmsInstance', 0);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);

        $this->RegisterTimer('Refresh', 0, 'NRGDASHTOPO_Render($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);
        $this->SetStatus(102);
        // Verbindungsstatus kann sich haeufiger aendern als Energiewerte -
        // 60s-Takt statt der 5-Minuten der anderen beiden Module, plus ein
        // sofortiger Lauf bei ApplyChanges() (gleiche Lehre wie bei
        // NRGDashboardTile: sonst bleibt eine offene Kachel bis zum ersten
        // Timer-Tick auf einem veralteten Stand).
        $this->SetTimerInterval('Refresh', 60 * 1000);
        $this->Render();
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

    /**
     * EMS-Instanz - explizite Wahl gewinnt, sonst Automatik NUR bei genau
     * einer installierten Instanz (kein Raten bei mehreren, Muster:
     * PvfInstanceID() bei NRGDashboardMonitor).
     */
    private function EmsInstanceID(): int
    {
        $cfg = $this->readIntProperty('EmsInstance', 0);
        if ($cfg > 0 && IPS_InstanceExists($cfg)
            && IPS_GetInstance($cfg)['ModuleInfo']['ModuleID'] === self::EMS_GUID) {
            return $cfg;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::EMS_GUID);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    private function ColorOrEmpty(int $v): string
    {
        if ($v < 0) {
            return '';
        }
        return sprintf('#%06X', $v);
    }

    private function FontStack(string $v): string
    {
        if ($v === '' || $v === self::DEF_FONT) {
            return '';
        }
        return $v;
    }

    /**
     * Anzeigename je Partner-Modulschluessel (aus EMS_GetFederationHealth()
     * 'module'-Feld) - nur fuer die Beschriftung, kein neuer Vertrag.
     */
    private const MODULE_LABELS = [
        'goodweet'    => 'GoodWe ET',
        'inverterhub' => 'InverterHub',
        'meterhub'    => 'MeterHub',
        'chargerhub'  => 'ChargerHub',
        'heishamon'   => 'HeishaMon',
        'tessie'      => 'Tessie',
        'tibber'      => 'Tibber Grid Rewards',
    ];

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($this->buildPayload()) . ');</script>';
        return $html;
    }

    public function Render(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->buildPayload()));
    }

    /**
     * Stern-Topologie: EMS-Instanz in der Mitte, ein Knoten je Eintrag aus
     * EMS_GetFederationHealth()['entries']. Status je Knoten:
     *   healthy   - status===102 (laeuft)
     *   unhealthy - Instanz existiert, aber status!==102 (Fehler/Inaktiv)
     *   missing   - Instanz wurde geloescht (status===0 laut EMS-Vertrag)
     */
    private function buildPayload(): array
    {
        $emsID = $this->EmsInstanceID();
        if ($emsID <= 0 || !function_exists('EMS_GetFederationHealth')) {
            return [
                'ok'    => false,
                'error' => 'Keine EMS-Instanz gefunden - bitte im Formular auswählen oder EMS installieren.',
            ];
        }

        $health = @EMS_GetFederationHealth($emsID);
        if (!is_array($health) || !isset($health['entries']) || !is_array($health['entries'])) {
            return [
                'ok'    => false,
                'error' => 'EMS_GetFederationHealth() lieferte keine auswertbaren Daten.',
            ];
        }

        $nodes = [];
        foreach ($health['entries'] as $entry) {
            $module = (string) ($entry['module'] ?? '');
            $status = (int) ($entry['status'] ?? 0);
            $healthy = (bool) ($entry['healthy'] ?? false);
            $nodes[] = [
                'key'    => $module . '_' . (int) ($entry['instanceID'] ?? 0),
                'label'  => (string) ($entry['label'] ?? (self::MODULE_LABELS[$module] ?? $module)),
                'module' => $module,
                'status' => $status === 0 ? 'missing' : ($healthy ? 'healthy' : 'unhealthy'),
            ];
        }

        return [
            'ok'        => true,
            'emsLabel'  => IPS_GetName($emsID),
            'summary'   => (string) ($health['summary'] ?? ''),
            'total'     => (int) ($health['total'] ?? count($nodes)),
            'healthy'   => (int) ($health['healthyCount'] ?? 0),
            'nodes'     => $nodes,
            'bg'        => $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND)),
            'font'      => $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT)),
        ];
    }
}
