<?php

declare(strict_types=1);

/**
 * NRGDashboardForecast - eigenstaendige PV-/Lastprognose-Kachel, Uebernahme
 * der bisherigen Energiebilanz-Kachel (Prognose-Repo, Modul "Energiebilanz",
 * Praefix EFTILE) in NRGDashboard (Architekturentscheidung Dietmar,
 * 25.07.2026: NRGDashboard wird langfristig die einzige Darstellungsflaeche
 * im Verbund). Dietmar, 25.08.2026: "Im Tagesplan moechte ich nicht die
 * komplette Darstellung der Prognosekachel. Die Prognosekachel muss eine
 * separate Kachel bleiben, allerdings muss die von der Prognose zum
 * Dashboard wandern." - bewusst EIGENSTAENDIGE Kachel, NICHT Teil von
 * NRGDashboardPVMonitor::Tagesplan.
 *
 * Reine Darstellungsschicht (CLAUDE.md Kernprinzip): die Berechnung
 * (k-NN-Prognose, Perzentile, Ist-Integration aus dem Archiv) bleibt bei
 * Prognose. Hier wird nur EFTILE_GetDaysData() konsumiert und gezeichnet -
 * kein eigener Datenvertrag, keine eigene Prognoselogik.
 */
class NRGDashboardForecast extends IPSModule
{
    private const FORECAST_GUID = '{481CBE19-C8D9-4B72-B13F-0D249006B709}';

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';
    private const DEF_ENGINE     = 'echarts';

    private const GITHUB_URL = 'https://github.com/DG65/NRGDashboard/issues';

    // Verbund-Formularkonvention (EMS/SUITE.md "Einheitliche Formular-Optik",
    // Muster NRGDashboardPVMonitor/HeatSchema) - "Was ist Neu" (versionsscharf
    // dismissible, Version IN der Caption) + Doku-Panel mit dauerhafter
    // Versionszeile + GitHub-Hinweis. NEWS_VERSION bei jeder nutzersichtbaren
    // Aenderung erhoehen.
    private const NEWS_VERSION = '0.1.0';
    private const NEWS_ITEMS = [
        'Erste Fassung: PV-/Lastprognose als scrollbares Mehrtage-Diagramm, mit Unsicherheitsband und Ist-Überlagerung (Übernahme der bisherigen Prognose-eigenen Energiebilanz-Kachel).',
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('ForecastInstance', 0);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);
        $this->RegisterPropertyString('Engine', self::DEF_ENGINE);
        // Manuelle Angabe statt automatischer Erkennung (Muster PVMonitor,
        // 31.07.2026: Symcon bietet einer Kachel keinen Weg an, das
        // Hell/Dunkel-Theme der Oberflaeche zu erkennen).
        $this->RegisterPropertyBoolean('LightTheme', false);

        $this->RegisterAttributeString('ReviewHintDismissed', '0');
        $this->RegisterAttributeString('SeenNews', '');

        $this->RegisterTimer('Refresh', 0, 'NRGDASHFC_Render($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);
        $this->SetStatus(102);
        // Archivbasiert (Prognose macht die eigentliche Rechnung) - kein
        // VM_UPDATE-Ereignis, periodischer Timer statt Ereignissteuerung,
        // plus ein sofortiger Lauf bei ApplyChanges() (Muster PVMonitor),
        // damit eine offene Kachel nicht bis zum ersten Timer-Tick auf
        // einem veralteten Stand bleibt.
        $this->SetTimerInterval('Refresh', 5 * 60 * 1000);
        $this->Render();
    }

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

        if (!@$this->ReadAttributeBoolean('ReviewHintDismissed')) {
            $form['elements'][] = [
                'type' => 'RowLayout',
                'name' => 'ReviewHint',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 NRG-Stack Energieprognose ist Beta — Rückmeldungen sind willkommen:'],
                    ['type' => 'Label', 'link' => true, 'caption' => self::GITHUB_URL],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'NRGDASHFC_DismissReviewHint($id);'],
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

    private function newsBanner(): ?array
    {
        if (@$this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 Neu in diesem Modul — bitte kurz ansehen und ggf. die Einstellungen prüfen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'NRGDASHFC_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function DismissReviewHint(): void
    {
        $this->WriteAttributeString('ReviewHintDismissed', '1');
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

    /**
     * Property zuerst (mehrere Energiebilanz-Instanzen moeglich), sonst
     * Auto-Discovery bei genau einer gefundenen Instanz (Muster PVMonitors
     * TibberInstanceID()/PvfInstanceID()) - kein manueller Variablen-Link,
     * nur die Instanzwahl selbst.
     */
    private function ForecastInstanceID(): int
    {
        $explicit = $this->readIntProperty('ForecastInstance', 0);
        if ($explicit > 0 && @IPS_InstanceExists($explicit)) {
            return $explicit;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::FORECAST_GUID);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    private function buildPayload(): array
    {
        $id = $this->ForecastInstanceID();
        $data = null;
        if ($id > 0 && function_exists('EFTILE_GetDaysData')) {
            $raw = @EFTILE_GetDaysData($id);
            $data = is_string($raw) ? json_decode($raw, true) : $raw;
        }

        return [
            'ok'         => true,
            'hasSource'  => $id > 0,
            'hasData'    => is_array($data) && (bool) ($data['hasData'] ?? false),
            'message'    => is_array($data) ? (string) ($data['message'] ?? '') : '',
            'days'       => (is_array($data) && is_array($data['days'] ?? null)) ? $data['days'] : [],
            'actualPV'   => is_array($data) ? $data['actualPV'] ?? null : null,
            'actualLoad' => is_array($data) ? $data['actualLoad'] ?? null : null,
            'bg'         => $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND)),
            'font'       => $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT)),
            'engine'     => $this->readStringProperty('Engine', self::DEF_ENGINE),
            'lightTheme' => $this->ReadPropertyBoolean('LightTheme'),
        ];
    }

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
}
