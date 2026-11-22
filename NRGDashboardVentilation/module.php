<?php

declare(strict_types=1);

/**
 * NRGDashboardVentilation - Lueftungskachel (achtes Modul der Bibliothek, 0.15.0, Dietmar/EMS 22.11.2026). Zeigt jede Lueftungs-/RLT-Anlage,
 * deren Modul den NRG-Stack-Vertrag 'ventilation' liefert (RLTHub, WPISM8 u. a.), als Karte: Temperaturen (Aussen-/Zu-/Abluft), CO2,
 * WRG-Wirkungsgrad, Ventilator-Volumenstrom, Filterwarnung, Sammelstoerung. Feldregister: SUITE.md "Kanonisches Feldregister:
 * Type=>'ventilation'-Vertrag" (1.0, Ergaenzung 1.1). Felder mit 0/leer = "nicht geliefert" und werden ausgeblendet.
 * Gefunden ueber die allgemeine Fremdsuche (libs/ForeignDiscovery.php, je EINTRAG nach Vertragstyp gefiltert, nie die ganze Liste).
 * Nur Anzeige: die Kachel steuert nichts. Eine Lueftung hat keine Leistungsvariable - deshalb eine eigene Kachel statt eines Knotens im
 * Energiefluss.
 */
require_once __DIR__ . '/../libs/ModuleBase.php';
require_once __DIR__ . '/../libs/ForeignDiscovery.php';
require_once __DIR__ . '/../libs/FormStatus.php';
require_once __DIR__ . '/../libs/FormLayout.php';
require_once __DIR__ . '/../libs/NRGDASH_Text.php';

class NRGDashboardVentilation extends IPSModule
{
    use NRGDashModuleBase;
    use NRGDashForeignDiscovery;
    use NRGDashFormStatus;
    use NRGDashFormLayout;

    private const HOOK_PATH = '/hook/nrgdashventilation';
    private const FN_PREFIX = 'NRGDASHVENT_';

    /** Waehlbare Fachpanels fuer "Formular: Aufbau" (SUITE.md 3a): Panel-name aus form.json => stabiler Schluessel. */
    private const FORM_LAYOUT_PANELS = ['PanelSource' => 'source', 'PanelStyle' => 'style'];

    /** Texte der Kachel (module.html): englische Quelltexte, die dort mit tr('...') benutzt werden (window.NRGDASH_TEXTS). */
    private const TILE_TEXTS = [
        'Fault',
        'No fault',
        'Filter change due',
        'Filter OK',
        'Data may be outdated',
        'As of %s',
        'No data available.',
    ];

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';

    /** Gemeinsame Rahmenkonstanten (libs/ModuleBase.php) */
    private const NEWS_VERSIONS = [
        '0.15.0' => [
            '🌬️ New module “Ventilation”: shows ventilation and air-handling units found through the NRG-Stack contract “ventilation” (for example RLTHub) as a card - temperatures, CO₂, heat-recovery efficiency, fan flow, filter warning and fault. Display only.'
        ],
    ];
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';
    private const FORUM_URL = 'https://community.symcon.de/t/modul-nrg-stack-dashboard-energiefluss-kachel-3d-karte-verlaufs-charts-fuer-den-ganzen-verbund/144394';
    private const LICENSE_URL = 'https://github.com/DG65/NRGDashboard/blob/main/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';
    private const SELF_MODULE_GUID = '{B2D6F316-7D73-439F-BF31-98468254B043}';

    /** Feldname im Vertrag => [Schluessel, Art] fuer die Wertzeilen (Beschriftung: siehe fieldCaption()) */
    private const VALUE_FIELDS = [
        ['outsideTempID', 'outside', 'temp'],
        ['supplyTempID', 'supply', 'temp'],
        ['extractTempID', 'extract', 'temp'],
        ['co2ID', 'co2', 'co2'],
        ['heatRecoveryEfficiencyID', 'wrg', 'pct'],
        ['fan1FlowID', 'fan1', 'flow'],
        ['fan2FlowID', 'fan2', 'flow'],
        ['filterRuntimeHoursID', 'filterHours', 'hours'],
    ];

    /** Beschriftung je Wertzeile (woertliche t()-Literale, damit die Uebersetzungspruefung sie findet). */
    private function fieldCaption(string $key): string
    {
        switch ($key) {
            case 'outside':
                return NRGDASH_Text::t('Outside air');
            case 'supply':
                return NRGDASH_Text::t('Supply air');
            case 'extract':
                return NRGDASH_Text::t('Extract air');
            case 'co2':
                return NRGDASH_Text::t('CO₂');
            case 'wrg':
                return NRGDASH_Text::t('Heat recovery');
            case 'fan1':
                return NRGDASH_Text::t('Fan 1');
            case 'fan2':
                return NRGDASH_Text::t('Fan 2');
            default:
                return NRGDASH_Text::t('Filter runtime');
        }
    }

    public function Create()
    {
        parent::Create();
        NRGDASH_Text::setzen(fn (string $text): string => $this->Translate($text));

        $this->RegisterPropertyInteger('VentInstance', 0);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyBoolean('StandaloneEnabled', true);
        $this->RegisterPropertyString('FormLayout', '[]');
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);

        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeInteger('LastDiscoveryTs', 0);
        $this->RegisterAttributeString('UnitNamesCache', '[]');

        $this->RegisterTimer('Refresh', 0, 'NRGDASHVENT_Render($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        NRGDASH_Text::setzen(fn (string $text): string => $this->Translate($text));
        $this->SetVisualizationType(1);
        $this->SetTimerInterval('Refresh', 60 * 1000);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->SyncHook('/hook/nrgdashventilation' . $this->InstanceID);
        } else {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        }
        $this->Render();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && isset($Data[0]) && $Data[0] === KR_READY) {
            $this->ApplyChanges();
        }
    }

    /** Standalone-Seite: /hook/nrgdashventilation<InstanzID>, mit ?json=1 nur die Daten. */
    public function ProcessHookData()
    {
        $payload = $this->buildPayload();
        $this->updateInstanceStatus($payload);
        if (isset($_GET['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        $html = NRGDASH_Text::kachelHtml((string) file_get_contents(__DIR__ . '/module.html'), self::TILE_TEXTS);
        $html .= '<script>handleMessage(' . json_encode($payload) . ');'
               . 'setInterval(function(){fetch(window.location.pathname+"?json=1")'
               . '.then(function(r){return r.text();}).then(function(t){handleMessage(t);})'
               . '.catch(function(){});},30000);</script>';
        echo $html;
    }

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
        $html = NRGDASH_Text::kachelHtml((string) file_get_contents(__DIR__ . '/module.html'), self::TILE_TEXTS);
        $html .= '<script>handleMessage(' . json_encode($payload) . ');</script>';
        return $html;
    }

    public function Render(): void
    {
        $payload = $this->buildPayload();
        $this->updateInstanceStatus($payload);
        $this->UpdateVisualizationValue(json_encode($payload));
        $names = array_map(static fn (array $u) => $u['label'], $payload['units'] ?? []);
        $this->WriteAttributeInteger('LastDiscoveryTs', time());
        $this->WriteAttributeString('UnitNamesCache', json_encode($names));
        $this->UpdateFormField('DiscoveryResult', 'caption', $this->getDiscoverySummaryLine());
        $this->UpdateFormField('VentStatus', 'caption', $this->ventStatusLine());
    }

    private function getDiscoverySummaryLine(): string
    {
        $ts = (int) $this->ReadAttributeInteger('LastDiscoveryTs');
        if ($ts === 0) {
            return NRGDASH_Text::t('ℹ️ Not searched yet — open the tile or apply the form.');
        }
        $names = json_decode((string) $this->ReadAttributeString('UnitNamesCache'), true) ?: [];
        $count = count($names);
        $icon = $count > 0 ? '✅' : '⚠️';
        if ($count === 1) {
            return NRGDASH_Text::t('%s %d ventilation unit found (last checked at %s).', $icon, $count, date('H:i:s', $ts));
        }
        return NRGDASH_Text::t('%s %d ventilation units found (last checked at %s).', $icon, $count, date('H:i:s', $ts));
    }

    /** Verbindungsstatus im Formular (SUITE.md "Verbund-Verbindungen sichtbar machen"). */
    private function ventStatusLine(): string
    {
        $names = json_decode((string) $this->ReadAttributeString('UnitNamesCache'), true) ?: [];
        if ($this->ReadAttributeInteger('LastDiscoveryTs') === 0) {
            return NRGDASH_Text::t('ℹ️ Not searched yet — open the tile or apply the form.');
        }
        if (count($names) === 0) {
            return NRGDASH_Text::t('ℹ️ No ventilation unit found - install a module that provides the contract “ventilation” (for example RLTHub). The tile then fills by itself.');
        }
        return NRGDASH_Text::t('🔗 Found automatically: %s', implode(', ', $names));
    }

    public function ResetStyle(): void
    {
        $this->UpdateFormField('ColorBackground', 'value', self::DEF_BACKGROUND);
        $this->UpdateFormField('FontFamily', 'value', self::DEF_FONT);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }
        $this->setFormCaption($form['elements'], 'HookInfo', NRGDASH_Text::t('IPSView/popup: the tile also runs as a standalone web page at http://<Symcon-IP>:3777%s (auto-refresh every 30 s). In IPSView, place a WebView element on a popup page and enter this URL. Note: WebHooks can be reached on the local network without logging in.', '/hook/nrgdashventilation' . $this->InstanceID));

        $this->injectVersionIntoDocPanel($form);
        $this->setFormStatusLine($form['elements'], 'VentStatus', $this->ventStatusLine());
        foreach ($form['elements'] as &$el) {
            if (($el['name'] ?? '') === 'DiscoveryResult') {
                $el['caption'] = $this->getDiscoverySummaryLine();
            }
        }
        unset($el);

        $form['elements'] = $this->formLayoutApply($form['elements'], self::FORM_LAYOUT_PANELS);

        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }
        $purposeIntro = $this->PurposeIntro();
        if ($purposeIntro !== null) {
            array_unshift($form['elements'], $purposeIntro);
        }

        if (!@$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'ExpansionPanel', 'name' => 'ReviewHint', 'expanded' => true,
                'caption' => '💬  Feedback in the Symcon forum',
                'items' => [
                    ['type' => 'Label', 'caption' => 'NRGDashboard is in beta — feedback, bug reports and wishes are explicitly welcome in the forum thread.'],
                    ['type' => 'Button', 'caption' => 'To the forum thread', 'onClick' => "echo '" . self::FORUM_URL . "';", 'link' => true],
                    ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHVENT_DismissReviewHint($id);'],
                ],
            ];
        }
        $form['elements'][] = $this->LicenseHint();

        return json_encode($form);
    }

    /** Knopf "Auf Standard zuruecksetzen" im Panel "Formular: Aufbau". */
    public function ResetFormLayout(): string
    {
        return $this->formLayoutReset(__DIR__ . '/form.json', self::FORM_LAYOUT_PANELS);
    }

    private function formLayoutResetFunction(): string
    {
        return 'NRGDASHVENT_ResetFormLayout';
    }

    private function injectVersionIntoDocPanel(array &$form): void
    {
        $lib = @IPS_GetLibrary('{8D4E7A2C-1F6B-4C93-A5D8-3E9F1B6C7D02}');
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? NRGDASH_Text::t('ℹ️ NRG-Stack Dashboard version %s (build %s)', $lib['Version'], $lib['Build'] ?? '?')
            : 'ℹ️ NRG-Stack Dashboard';
        foreach ($form['elements'] as &$el) {
            if (($el['type'] ?? '') === 'ExpansionPanel' && ($el['name'] ?? '') === 'PanelDoc') {
                array_unshift($el['items'], ['type' => 'Label', 'caption' => $verTxt]);
                return;
            }
        }
        unset($el);
    }

    /** "Wozu dieses Modul?" (SUITE.md "Einheitliche Formular-Optik" Punkt 0) - ganz oben, einmalig dismissible. */
    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Shows your ventilation or air-handling unit at a glance: temperatures of outside, supply and extract air, CO₂, how much heat is recovered, fan flow, filter warning and faults.'],
                ['type' => 'Label', 'caption' => 'The benefit: you see whether the unit runs well and whether the filter needs a change, without opening the app of the unit - and the tile finds the unit by itself.'],
                ['type' => 'Label', 'caption' => 'Display only: the tile controls nothing. Whatever your unit does not report stays hidden.'],
                ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHVENT_AckPurposeIntro($id);'],
            ],
        ];
    }

    /** "Was ist Neu"-Banner, versionsscharf dismissible (Muster der anderen Module). */
    private function newsBanner(): ?array
    {
        $seen = (string) @$this->ReadAttributeString('SeenNews');
        $pending = [];
        foreach (self::NEWS_VERSIONS as $ver => $lines) {
            if ($seen === '' || version_compare($ver, $seen, '>')) {
                $pending[$ver] = $lines;
            }
        }
        if (count($pending) === 0) {
            return null;
        }
        uksort($pending, 'version_compare');
        $items = [['type' => 'Label', 'caption' => NRGDASH_Text::t('🆕 New since the version you last saw — please take a quick look and check your settings if needed:')]];
        $multi = count($pending) > 1;
        foreach ($pending as $ver => $lines) {
            if ($multi) {
                $items[] = ['type' => 'Label', 'caption' => NRGDASH_Text::t('Version %s:', $ver)];
            }
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => '• ' . NRGDASH_Text::t($line)];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHVENT_AckNews($id);'];
        $latest = array_key_last($pending);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => NRGDASH_Text::t('🆕 New up to version %s', $latest), 'expanded' => true, 'items' => $items];
    }

    /**
     * Nutzlast der Kachel: je gefundener Lueftungsanlage eine Einheit mit den gelieferten Werten. Felder mit 0/leer oder nicht
     * existierender Variable fehlen ("nicht geliefert"). fan*Flow bekommt die Einheit aus fanFlowUnit ('m3/h' oder '%'), leer = ohne
     * Umrechnung wie vom Profil. Frische: lastSeenAt aelter als 10 Minuten oder reachable=false = moeglicherweise veraltet.
     */
    private function buildPayload(): array
    {
        $filter = $this->readIntProperty('VentInstance', 0);
        $units = [];
        foreach ($this->discoverForeignByType('ventilation') as $i => $e) {
            $iid = (int) ($e['_instanceID'] ?? 0);
            if ($filter > 0 && $iid !== $filter) {
                continue;
            }
            $units[] = $this->unitFromEntry($e, $i);
        }
        $out = [
            'ok'         => count($units) > 0,
            'units'      => $units,
            'renderedAt' => time(),
            'bg'         => $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND)),
            'font'       => $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT)),
        ];
        if (!$out['ok']) {
            $out['error'] = NRGDASH_Text::t('No ventilation unit found - install a module that provides the contract “ventilation” (for example RLTHub).');
        }
        return $out;
    }

    private function unitFromEntry(array $e, int $index): array
    {
        $iid = (int) ($e['_instanceID'] ?? 0);
        $label = trim((string) ($e['label'] ?? ($e['Caption'] ?? '')));
        if ($label === '' && $iid > 0 && IPS_InstanceExists($iid)) {
            $label = IPS_GetName($iid);
        }
        $flowUnit = trim((string) ($e['fanFlowUnit'] ?? ''));
        $flowUnitText = $flowUnit === 'm3/h' ? 'm³/h' : $flowUnit;
        $rows = [];
        foreach (self::VALUE_FIELDS as [$field, $key, $kind]) {
            $vid = (int) ($e[$field] ?? 0);
            if ($vid <= 0 || !IPS_VariableExists($vid)) {
                continue;
            }
            $raw = GetValue($vid);
            $text = trim((string) GetValueFormatted($vid));
            if ($kind === 'flow' && $flowUnitText !== '' && stripos($text, $flowUnitText) === false && is_numeric($raw)) {
                $text = rtrim(rtrim(number_format((float) $raw, 1, '.', ''), '0'), '.') . ' ' . $flowUnitText;
            }
            $rows[] = ['key' => $key, 'label' => $this->fieldCaption($key), 'text' => $text, 'value' => is_numeric($raw) ? (float) $raw : null];
        }
        $fault = $this->boolField($e, 'faultSummaryID');
        $filterWarn = $this->boolField($e, 'filterWarningID');
        $seen = isset($e['lastSeenAt']) ? (int) $e['lastSeenAt'] : null;
        $stale = ($seen !== null && $seen > 0 && time() - $seen > 600) || (array_key_exists('reachable', $e) && $e['reachable'] === false);
        return [
            'key'        => $iid . '#' . $index,
            'label'      => $label !== '' ? $label : NRGDASH_Text::t('Ventilation'),
            'source'     => (string) ($e['source'] ?? ''),
            'rows'       => $rows,
            'fault'      => $fault,
            'filterWarn' => $filterWarn,
            'stale'      => $stale,
            'seenAt'     => $seen,
        ];
    }

    /** Bool-Feld eines Eintrags: null = nicht geliefert (ID 0/leer/Variable fehlt), sonst der Wert. */
    private function boolField(array $e, string $field): ?bool
    {
        $vid = (int) ($e[$field] ?? 0);
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return null;
        }
        return (bool) GetValue($vid);
    }
}
