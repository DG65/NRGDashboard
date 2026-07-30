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

    // Verbund-Formularkonvention (Muster NRGDashboardTile): "Was ist Neu"
    // (versionsscharf dismissible) + Forum-Hinweis (einmalig dismissible) +
    // Versionszeile im Doku-Panel. NEWS_VERSION bei jeder nutzersichtbaren
    // Aenderung an diesem Modul erhoehen.
    private const NEWS_VERSION = '0.6.0';
    private const NEWS_ITEMS = [
        'Neu: Verbund-Gesundheit als Stern-Topologie um die EMS-Instanz - Partnermodule farbig nach Verbindungsstatus.',
    ];
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';
    private const GITHUB_URL = 'https://github.com/DG65/NRGDashboard';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('EmsInstance', 0);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);

        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);

        $this->RegisterTimer('Refresh', 0, 'NRGDASHTOPO_Render($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);
        // Verbindungsstatus kann sich haeufiger aendern als Energiewerte -
        // 60s-Takt statt der 5-Minuten der anderen beiden Module, plus ein
        // sofortiger Lauf bei ApplyChanges() (gleiche Lehre wie bei
        // NRGDashboardTile: sonst bleibt eine offene Kachel bis zum ersten
        // Timer-Tick auf einem veralteten Stand).
        $this->SetTimerInterval('Refresh', 60 * 1000);
        $this->Render();
    }

    /**
     * Instanzstatus spiegelt jetzt den tatsaechlichen Zustand wider (Muster:
     * Tile/Monitor) statt immer auf 102 zu stehen - buildPayload() liefert
     * das Fehlerbild ohnehin schon, hier nur zusaetzlich auf die
     * IPS-Statusanzeige (Konsole/Objektbaum) gespiegelt.
     */
    private function updateInstanceStatus(array $payload): void
    {
        $this->SetStatus(($payload['ok'] ?? false) ? 102 : 104);
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
     * EMS-Instanz - explizite Wahl gewinnt, sonst Automatik: bei mehreren
     * Instanzen die erste mit Status 102 (gesund), sonst die erste Instanz.
     * Muster: z.B. Mehrinstanz-Testszenarios mit alten/neuen EMS-Kopien.
     */
    private function EmsInstanceID(): int
    {
        $cfg = $this->readIntProperty('EmsInstance', 0);
        if ($cfg > 0 && IPS_InstanceExists($cfg)
            && IPS_GetInstance($cfg)['ModuleInfo']['ModuleID'] === self::EMS_GUID) {
            return $cfg;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::EMS_GUID);
        if (!is_array($ids) || count($ids) === 0) {
            return 0;
        }
        // Bei genau einer: nimm sie
        if (count($ids) === 1) {
            return (int) $ids[0];
        }
        // Bei mehreren: bevorzuge Status 102 (gesund)
        foreach ($ids as $id) {
            if (IPS_GetInstance((int) $id)['InstanceStatus'] === 102) {
                return (int) $id;
            }
        }
        // Fallback: erste Instanz
        return (int) $ids[0];
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
        $this->updateDiscoveryResultLabel($payload);
    }

    /**
     * Ergebnis-Label im Formular (Muster NRGDashboardTile::DiscoveryResult),
     * hier zusaetzlich mit den Partnernamen statt nur der Anzahl - genau die
     * Namen, die auch die Kachel je Knoten zeigt (node.label), damit der
     * Nutzer schon im Formular sieht, WER gefunden wurde, nicht nur wie viele.
     * No-Op, wenn gerade kein Formular offen ist (UpdateFormField).
     */
    private function updateDiscoveryResultLabel(array $payload): void
    {
        if (!($payload['ok'] ?? false)) {
            $this->UpdateFormField('DiscoveryResult', 'caption', '⚠️ ' . ($payload['error'] ?? 'Keine Verbindungsdaten verfügbar.'));
            return;
        }
        $names = array_map(static fn (array $n) => $n['label'], $payload['nodes'] ?? []);
        $this->UpdateFormField(
            'DiscoveryResult',
            'caption',
            count($names) > 0
                ? sprintf('✅ %d Partner gefunden: %s (zuletzt %s Uhr).', count($names), implode(', ', $names), date('H:i:s'))
                : sprintf('⚠️ EMS-Instanz „%s“ gefunden, aber keine Partnermodule gemeldet.', $payload['emsLabel'] ?? '?')
        );
    }

    public function ResetStyle(): void
    {
        $this->UpdateFormField('ColorBackground', 'value', self::DEF_BACKGROUND);
        $this->UpdateFormField('FontFamily', 'value', self::DEF_FONT);
    }

    /**
     * Fuegt "Was ist Neu" (versionsscharf dismissible) und den Forum-Hinweis
     * (einmalig dismissible) um die statische form.json herum ein, traegt die
     * Versionsnummer ins Doku-Panel ein - exakte Struktur wie
     * NRGDashboardTile (Muster fuer den ganzen Verbund).
     */
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }

        $this->injectVersionIntoDocPanel($form);

        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        if (!@$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'RowLayout',
                'name' => 'ReviewHint',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 NRG-Stack Dashboard ist Beta — Rückmeldungen sind willkommen:'],
                    ['type' => 'Label', 'link' => true, 'caption' => self::GITHUB_URL],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'NRGDASHTOPO_DismissReviewHint($id);'],
                ],
            ];
        }

        return json_encode($form);
    }

    private function injectVersionIntoDocPanel(array &$form): void
    {
        $lib = @IPS_GetLibrary('{8D4E7A2C-1F6B-4C93-A5D8-3E9F1B6C7D02}');
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ NRG-Stack Dashboard Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ NRG-Stack Dashboard';
        foreach ($form['elements'] as &$el) {
            if (($el['type'] ?? '') === 'ExpansionPanel' && str_contains($el['caption'] ?? '', 'Dokumentation')) {
                array_unshift($el['items'], ['type' => 'Label', 'caption' => $verTxt]);
                return;
            }
        }
        unset($el);
    }

    /**
     * "Was ist Neu"-Panel, versionsscharf dismissible: erscheint erneut,
     * sobald sich NEWS_VERSION erhoeht, auch wenn eine fruehere Version
     * schon bestaetigt wurde. Version STEHT in der Caption (Klaerung EMS/
     * InverterHub, 30.07.2026: SUITE.md-Text "keine Versionsnummer im
     * Panel" war veraltet/falsch, nicht die Praxis - Dismiss blendet das
     * GESAMTE Panel aus, kein Zwischenzustand moeglich. SUITE.md in
     * Commit da42f8c korrigiert.)
     */
    private function newsBanner(): ?array
    {
        if (@$this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 Neu in diesem Modul — bitte kurz ansehen und ggf. die Einstellungen prüfen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'NRGDASHTOPO_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function DismissReviewHint(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
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
