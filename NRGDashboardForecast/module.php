<?php

declare(strict_types=1);

/**
 * NRGDashboardForecast - eigenstaendige PV-/Lastprognose-Kachel, Uebernahme
 * der bisherigen Energiebilanz-Kachel (Prognose-Repo, Modul "Energiebilanz",
 * Praefix EFTILE) in NRGDashboard (Architekturentscheidung Dietmar,
 * 25.07.2026: NRGDashboard wird langfristig die einzige Darstellungsflaeche
 * im Verbund).
 *
 * Dietmar, 26.08.2026, nach einem eigenen (schwaecheren) ersten Nachbau-
 * Versuch: "Du loeschst Deine Bemuehungen die Prognose Kachel betreffend
 * und nimmst den HTML Code von Prognose. Du uebernimmst auch die komplette
 * Steuerung hinter dem Doppelpfeil ... Ich moechte die Kachel genau so wie
 * sie derzeit von Prognose bereit steht, kein aehnlich oder ungefaehr - ich
 * moechte das absolut 1:1 Abbild." module.html ist deshalb eine wortgetreue
 * Kopie von Prognose/Energiebilanz/module.html (reines HTML/CSS/JS, kein
 * PHP-Bezug). Dieses module.php baut denselben Payload wie Prognoses
 * GetFullUpdateMessage() (Stil-Felder + hasData/message/days/actualPV/
 * actualLoad), nur dass der Datenanteil ueber den bereits bestehenden
 * EFTILE_GetDaysData()-Vertrag kommt (liefert IMMER den vollen Umfang,
 * inkl. Gestern + Ist-Ueberlagerung) und hier lokal nach unseren EIGENEN
 * Doppelpfeil-Einstellungen (Days/ShowYesterday/ShowActualPV/...)
 * zurechtgeschnitten wird - die eigentliche Berechnung (k-NN-Prognose,
 * Perzentile, Ist-Integration aus dem Archiv) bleibt bei Prognose.
 */
class NRGDashboardForecast extends IPSModule
{
    private const FORECAST_GUID = '{481CBE19-C8D9-4B72-B13F-0D249006B709}';

    private const MAX_OFFSET = 4; // heute + 4 weitere Tage, deckungsgleich mit Prognose

    private const DEF_PV    = 0xE0A020; // Bernstein
    private const DEF_LOAD  = 0x2BB3C0; // Tuerkis
    private const DEF_SCALE  = 1.0;
    private const DEF_DAYS   = 3;
    private const DEF_LW     = 2.0;
    private const DEF_SMOOTH = true;
    private const DEF_BAND   = true;
    private const DEF_BANDOP = 0.16;
    private const DEF_GRID   = true;
    private const DEF_YMAX   = 0.0; // 0 = automatisch

    private const GITHUB_URL = 'https://github.com/DG65/NRGDashboard/issues';

    // Verbund-Formularkonvention (EMS/SUITE.md "Einheitliche Formular-Optik",
    // Muster NRGDashboardPVMonitor/HeatSchema) - "Was ist Neu" (versionsscharf
    // dismissible, Version IN der Caption) + Doku-Panel mit dauerhafter
    // Versionszeile + GitHub-Hinweis. NEWS_VERSION bei jeder nutzersichtbaren
    // Aenderung erhoehen.
    private const NEWS_VERSION = '0.2.0';
    private const NEWS_ITEMS = [
        '1:1-Uebernahme der Darstellung von Prognoses Energiebilanz-Kachel: Scroll ab mehr als 3 Tagen mit feststehender Y-Achse, Legende zum Ausblenden einzelner Kurven, automatische Diagrammhoehe.',
        'Alle Darstellungseinstellungen (Farben, Schriftart, Engine, Tage, Ist-Anzeige, Gitter, Legende, Y-Achse fest ...) direkt im WebFront - Kachel ueber den Doppelpfeil aufziehen, statt in der Konsole zu suchen.',
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('ForecastInstance', 0);

        $this->RegisterAttributeString('ReviewHintDismissed', '0');
        $this->RegisterAttributeString('SeenNews', '');

        $this->RegisterTimer('Refresh', 0, 'NRGDASHFC_Render($_IPS[\'TARGET\']);');

        // Alle Darstellungseinstellungen als echte Instanz-Variablen statt
        // Formular-Properties (1:1 aus Prognoses Energiebilanz uebernommen,
        // siehe Klassendoku oben) - Symcon zeigt sie automatisch als
        // Schalter/Dropdown/Zahlenfeld in der aufgezogenen Kachelansicht.
        $this->ensureProfiles();

        $bool = [
            'ShowPV'         => ['PV-Erzeugung anzeigen', 10, true],
            'ShowLoad'       => ['Verbrauch anzeigen', 11, true],
            'ShowActualPV'   => ['Gemessenen PV-Tagesverlauf (heute) als Linie zeigen', 31, false],
            'ShowActualLoad' => ['Gemessenen Verbrauchs-Tagesverlauf (heute) als Linie zeigen', 32, false],
            'ShowYesterday'  => ['Gestern mit anzeigen', 33, false],
            'Smooth'         => ['Kurven glaetten (gegen kantige Linien)', 49, self::DEF_SMOOTH],
            'ShowBand'       => ['Unsicherheitsband (P10-P90) anzeigen', 50, self::DEF_BAND],
            'ShowGrid'       => ['Gitter & Achsenbeschriftung anzeigen', 52, self::DEF_GRID],
            'ShowLegend'     => ['Legende anzeigen', 55, true],
            'ShowIstRow'     => ['"Ist"-Zeile im Tagesstreifen anzeigen', 56, true],
            'ColorBackgroundAuto' => ['Hintergrund automatisch (IPS-Theme/transparent)', 43, true],
        ];
        foreach ($bool as $ident => $spec) {
            [$caption, $pos, $default] = $spec;
            $isNew = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->RegisterVariableBoolean($ident, $caption, '', $pos);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, $default);
            }
        }

        $int = [
            'Days'            => ['Anzuzeigende Tage', 'NRGDASHFC.Days', 20, self::DEF_DAYS],
            'ChartEngine'     => ['Diagramm-Engine', 'NRGDASHFC.Engine', 40, 0],
            'ColorPV'         => ['Farbe PV-Erzeugung', '~HexColor', 41, self::DEF_PV],
            'ColorLoad'       => ['Farbe Verbrauch', '~HexColor', 42, self::DEF_LOAD],
            'ColorBackground' => ['Hintergrundfarbe (falls nicht automatisch)', '~HexColor', 44, 0xFFFFFF],
            'FontFamily'      => ['Schriftart', 'NRGDASHFC.Font', 45, 0],
        ];
        foreach ($int as $ident => $spec) {
            [$caption, $profile, $pos, $default] = $spec;
            $isNew = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->RegisterVariableInteger($ident, $caption, $profile, $pos);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, $default);
            }
        }

        $float = [
            'FontScale'   => ['Schriftgroesse (Faktor, wirkt auf alle Beschriftungen)', 'NRGDASHFC.Scale', 46, self::DEF_SCALE],
            'LineWidth'   => ['Linienstaerke', 'NRGDASHFC.LineWidth', 48, self::DEF_LW],
            'BandOpacity' => ['Band-Transparenz (0 = unsichtbar ... 0.6)', 'NRGDASHFC.BandOpacity', 51, self::DEF_BANDOP],
            'YMaxManual'  => ['Y-Achse max. fest (kW, 0 = automatisch)', 'NRGDASHFC.YMax', 53, self::DEF_YMAX],
        ];
        foreach ($float as $ident => $spec) {
            [$caption, $profile, $pos, $default] = $spec;
            $isNew = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->RegisterVariableFloat($ident, $caption, $profile, $pos);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, (float) $default);
            }
        }

        $this->SetVisualizationType(1);
    }

    /** Legt/aktualisiert alle NRGDASHFC.*-Variablenprofile (idempotent), 1:1 Werte wie Prognoses EFTILE.*-Profile. */
    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('NRGDASHFC.Days')) { IPS_CreateVariableProfile('NRGDASHFC.Days', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileAssociation('NRGDASHFC.Days', 1, 'Heute', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Days', 2, 'Heute + morgen', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Days', 3, 'Heute + 2 Tage', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Days', 4, 'Heute + 3 Tage', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Days', 5, 'Heute + 4 Tage (voller Horizont)', '', -1);

        if (!IPS_VariableProfileExists('NRGDASHFC.Engine')) { IPS_CreateVariableProfile('NRGDASHFC.Engine', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileAssociation('NRGDASHFC.Engine', 0, 'ECharts (quelloffen, auch kommerziell)', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Engine', 1, 'Highcharts (nur privat/nicht-kommerziell)', '', -1);

        if (!IPS_VariableProfileExists('NRGDASHFC.Font')) { IPS_CreateVariableProfile('NRGDASHFC.Font', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileAssociation('NRGDASHFC.Font', 0, 'System', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Font', 1, 'Arial', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Font', 2, 'Verdana', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Font', 3, 'Tahoma', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Font', 4, 'Trebuchet MS', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Font', 5, 'Georgia', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Font', 6, 'Courier New', '', -1);

        if (!IPS_VariableProfileExists('NRGDASHFC.Scale')) { IPS_CreateVariableProfile('NRGDASHFC.Scale', VARIABLETYPE_FLOAT); }
        IPS_SetVariableProfileValues('NRGDASHFC.Scale', 0.5, 2.5, 0.1);
        IPS_SetVariableProfileDigits('NRGDASHFC.Scale', 2);
        IPS_SetVariableProfileText('NRGDASHFC.Scale', '', ' x');

        if (!IPS_VariableProfileExists('NRGDASHFC.LineWidth')) { IPS_CreateVariableProfile('NRGDASHFC.LineWidth', VARIABLETYPE_FLOAT); }
        IPS_SetVariableProfileValues('NRGDASHFC.LineWidth', 0.5, 6, 0.5);
        IPS_SetVariableProfileDigits('NRGDASHFC.LineWidth', 1);
        IPS_SetVariableProfileText('NRGDASHFC.LineWidth', '', ' px');

        if (!IPS_VariableProfileExists('NRGDASHFC.BandOpacity')) { IPS_CreateVariableProfile('NRGDASHFC.BandOpacity', VARIABLETYPE_FLOAT); }
        IPS_SetVariableProfileValues('NRGDASHFC.BandOpacity', 0, 0.6, 0.02);
        IPS_SetVariableProfileDigits('NRGDASHFC.BandOpacity', 2);

        if (!IPS_VariableProfileExists('NRGDASHFC.YMax')) { IPS_CreateVariableProfile('NRGDASHFC.YMax', VARIABLETYPE_FLOAT); }
        IPS_SetVariableProfileValues('NRGDASHFC.YMax', 0, 100, 0.5);
        IPS_SetVariableProfileDigits('NRGDASHFC.YMax', 1);
        IPS_SetVariableProfileText('NRGDASHFC.YMax', '', ' kW');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);
        $this->SetStatus(102);
        $this->SetTimerInterval('Refresh', 5 * 60 * 1000);
        $this->Render();
    }

    /**
     * WebFront-Bedienung der Doppelpfeil-Variablen (siehe Create()) - Muster
     * Prognoses Energiebilanz::RequestAction()/NRGDashboardHeatSchema: Wert
     * setzen, Kachel neu rendern.
     */
    public function RequestAction($Ident, $Value)
    {
        $boolIdents = ['ShowPV', 'ShowLoad', 'ShowActualPV', 'ShowActualLoad', 'ShowYesterday',
                       'Smooth', 'ShowBand', 'ShowGrid', 'ShowLegend', 'ShowIstRow', 'ColorBackgroundAuto'];
        $intIdents  = ['Days', 'ChartEngine', 'ColorPV', 'ColorLoad', 'ColorBackground', 'FontFamily'];
        $floatIdents = ['FontScale', 'LineWidth', 'BandOpacity', 'YMaxManual'];

        if (in_array($Ident, $boolIdents, true)) {
            $this->SetValue($Ident, (bool) $Value);
            $this->Render();
            return;
        }
        if (in_array($Ident, $intIdents, true)) {
            $this->SetValue($Ident, (int) $Value);
            $this->Render();
            return;
        }
        if (in_array($Ident, $floatIdents, true)) {
            $this->SetValue($Ident, (float) $Value);
            $this->Render();
        }
    }

    /**
     * Property zuerst (mehrere Energiebilanz-Instanzen moeglich), sonst
     * Auto-Discovery bei genau einer gefundenen Instanz.
     */
    private function ForecastInstanceID(): int
    {
        $explicit = $this->ReadPropertyInteger('ForecastInstance');
        if ($explicit > 0 && @IPS_InstanceExists($explicit)) {
            return $explicit;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::FORECAST_GUID);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    private function FontScaleValue(): float
    {
        $s = (float) $this->GetValue('FontScale');
        return max(0.5, min(2.5, $s));
    }

    /** 1:1 Prognoses FontStack() - Schluessel wie im NRGDASHFC.Font-Profil oben. */
    private function FontStack(int $key): string
    {
        switch ($key) {
            case 1: return 'Arial, Helvetica, sans-serif';
            case 2: return 'Verdana, Geneva, sans-serif';
            case 3: return 'Tahoma, Geneva, sans-serif';
            case 4: return '"Trebuchet MS", Helvetica, sans-serif';
            case 5: return 'Georgia, "Times New Roman", serif';
            case 6: return '"Courier New", Courier, monospace';
            case 0:
            default: return "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
        }
    }

    private function Render(): void
    {
        $this->UpdateVisualizationValue($this->buildPayload());
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . $this->buildPayload() . ');</script>';
        return $html;
    }

    // -------------------------------------------------------------------
    // Datenanbindung: EFTILE_GetDaysData() liefert IMMER den vollen Umfang
    // (5-Tage-Horizont inkl. Gestern + Ist-Ueberlagerung, unabhaengig von
    // Prognoses eigenen Anzeige-Properties) - wir schneiden hier lokal nach
    // UNSEREN Doppelpfeil-Einstellungen zurecht (Days/ShowYesterday/
    // ShowActualPV/ShowActualLoad/ShowPV/ShowLoad), analog zu Prognoses
    // eigenem buildDaysData($full=false)-Zweig fuer die eigene Kachel.
    // -------------------------------------------------------------------

    private function buildPayload(): string
    {
        $style = [
            'pvColor'    => sprintf('#%06x', (int) $this->GetValue('ColorPV')),
            'loadColor'  => sprintf('#%06x', (int) $this->GetValue('ColorLoad')),
            'bg'         => $this->GetValue('ColorBackgroundAuto') ? '' : sprintf('#%06x', (int) $this->GetValue('ColorBackground')),
            'scale'      => $this->FontScaleValue(),
            'lineWidth'  => max(0.5, min(6.0, (float) $this->GetValue('LineWidth'))),
            'smooth'     => (bool) $this->GetValue('Smooth'),
            'showBand'   => (bool) $this->GetValue('ShowBand'),
            'bandOp'     => max(0.0, min(0.6, (float) $this->GetValue('BandOpacity'))),
            'showGrid'   => (bool) $this->GetValue('ShowGrid'),
            'showLegend' => (bool) $this->GetValue('ShowLegend'),
            'showIstRow' => (bool) $this->GetValue('ShowIstRow'),
            'yMaxManual' => max(0.0, (float) $this->GetValue('YMaxManual')),
            'font'       => $this->FontStack((int) $this->GetValue('FontFamily')),
            'engine'     => ((int) $this->GetValue('ChartEngine') === 1) ? 'highcharts' : 'echarts',
        ];

        $id = $this->ForecastInstanceID();
        $data = null;
        if ($id > 0 && function_exists('EFTILE_GetDaysData')) {
            $raw = @EFTILE_GetDaysData($id);
            $data = is_string($raw) ? json_decode($raw, true) : $raw;
        }

        if (!is_array($data)) {
            return json_encode(array_merge($style, [
                'hasData' => false,
                'message' => ($id <= 0) ? 'Keine Energiebilanz-Instanz gefunden' : 'Keine Prognosedaten',
                'days'    => [],
                'actualPV'   => null,
                'actualLoad' => null,
            ]));
        }

        return json_encode(array_merge($style, $this->trimToOwnSettings($data)));
    }

    /**
     * Schneidet den vollen EFTILE_GetDaysData()-Datenanteil auf UNSERE
     * eigenen Doppelpfeil-Einstellungen zu - Gegenstueck zu Prognoses
     * buildDaysData($full=false), nur hier statt dort, weil die Berechnung
     * bei Prognose bleibt und wir nur die fertigen Tage nachtraeglich
     * filtern.
     */
    private function trimToOwnSettings(array $data): array
    {
        $showPV   = (bool) $this->GetValue('ShowPV');
        $showLoad = (bool) $this->GetValue('ShowLoad');
        $showYesterday  = (bool) $this->GetValue('ShowYesterday');
        $showActualPV   = (bool) $this->GetValue('ShowActualPV');
        $showActualLoad = (bool) $this->GetValue('ShowActualLoad');
        $limit = max(1, min(self::MAX_OFFSET + 1, (int) $this->GetValue('Days')));

        $days = is_array($data['days'] ?? null) ? $data['days'] : [];

        // Gestern (falls vorhanden, immer der erste Eintrag) nur behalten,
        // wenn ShowYesterday an ist; die restlichen Tage werden danach auf
        // $limit Prognosetage gekappt.
        $out = [];
        $forecastCount = 0;
        foreach ($days as $d) {
            $isYesterday = ($d['label'] ?? '') === 'gestern';
            if ($isYesterday) {
                if ($showYesterday) { $out[] = $this->filterDay($d, $showPV, $showLoad); }
                continue;
            }
            if ($forecastCount >= $limit) { continue; }
            $out[] = $this->filterDay($d, $showPV, $showLoad);
            $forecastCount++;
        }
        // "Heute" ist immer der erste Prognosetag - Ist-Ueberlagerung dort
        // zusaetzlich nach ShowActualPV/ShowActualLoad filtern (Gestern
        // behaelt seine Ist-Kurve immer, siehe Prognoses eigene Logik: dort
        // gibt es keinen separaten Schalter fuer Gestern-Ist).
        foreach ($out as &$d) {
            if (($d['label'] ?? '') === 'heute') {
                if (!$showActualPV) { $d['pvMeas'] = null; }
                if (!$showActualLoad) { $d['loMeas'] = null; }
            }
        }
        unset($d);

        $hasData = false;
        foreach ($out as $d) {
            if ($d['pv'] !== null || $d['load'] !== null) { $hasData = true; break; }
        }

        return [
            'hasData'    => $hasData,
            'message'    => $hasData ? '' : (string) ($data['message'] ?? 'Keine Prognosedaten'),
            'days'       => $out,
            'actualPV'   => $showPV   ? ($data['actualPV']   ?? null) : null,
            'actualLoad' => $showLoad ? ($data['actualLoad'] ?? null) : null,
        ];
    }

    private function filterDay(array $d, bool $showPV, bool $showLoad): array
    {
        if (!$showPV) {
            $d['pv'] = null;
            $d['pvMeas'] = null;
            $d['pvKwhIst'] = null;
        }
        if (!$showLoad) {
            $d['load'] = null;
            $d['loMeas'] = null;
            $d['loKwhIst'] = null;
        }
        return $d;
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

    /** Alle Doppelpfeil-Einstellungen auf den Modul-Default zuruecksetzen (Konsolen-Button). */
    public function ResetStyle(): void
    {
        $this->SetValue('ShowPV', true);
        $this->SetValue('ShowLoad', true);
        $this->SetValue('ShowActualPV', false);
        $this->SetValue('ShowActualLoad', false);
        $this->SetValue('ShowYesterday', false);
        $this->SetValue('Days', self::DEF_DAYS);
        $this->SetValue('ChartEngine', 0);
        $this->SetValue('ColorPV', self::DEF_PV);
        $this->SetValue('ColorLoad', self::DEF_LOAD);
        $this->SetValue('ColorBackgroundAuto', true);
        $this->SetValue('ColorBackground', 0xFFFFFF);
        $this->SetValue('FontFamily', 0);
        $this->SetValue('FontScale', self::DEF_SCALE);
        $this->SetValue('LineWidth', self::DEF_LW);
        $this->SetValue('Smooth', self::DEF_SMOOTH);
        $this->SetValue('ShowBand', self::DEF_BAND);
        $this->SetValue('BandOpacity', self::DEF_BANDOP);
        $this->SetValue('ShowGrid', self::DEF_GRID);
        $this->SetValue('ShowLegend', true);
        $this->SetValue('ShowIstRow', true);
        $this->SetValue('YMaxManual', self::DEF_YMAX);
        $this->Render();
    }
}
