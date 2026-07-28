<?php

declare(strict_types=1);

/**
 * NRGDashboardMonitor - Zeitreihen-Kachel (Phase 3 des NRGDashboard-Verbunds).
 * Übergabe von InverterHubMonitor (25.-27.07.2026): Diagnose-Logik bleibt bei
 * InverterHub (siehe NRGDashboardTile::discoverDiagnostics()), die
 * Zeitreihen-DARSTELLUNG wandert hierher. InverterHubMonitor bleibt parallel
 * voll nutzbar - kein Sofort-Abriss.
 *
 * Erste Ausbaustufe (27.07.2026): nur Reiter "PV & Einstrahlung", nur
 * Ansicht "Tag (Verlauf)" - exakt der Funktionsumfang, den InverterHub in
 * seiner Übergabe-Nachricht als Referenz beschrieben hat. Weitere Reiter
 * (MPP-Tracker/Batterie/Strompreis) und Ansichten (Woche/Monat/Jahr/Gesamt)
 * folgen in weiteren Runden - bewusst nicht in einem Schritt, um jede Stufe
 * live verifizieren zu können (Verbund-Arbeitsweise dieser Sitzung).
 */
class NRGDashboardMonitor extends IPSModule
{
    private const ARCHIVE_GUID     = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const PVF_GUID         = '{257DD4E8-9705-462E-89FC-56D0A1038353}';
    private const INVERTERHUB_GUID = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';
    private const IHUBMON_GUID     = '{7B1F9A34-6C52-4E8D-9A1B-4F3E2D7C6A19}';
    private const TIBBER_GUID      = '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}';
    private const LOCATION_GUID    = '{45E97A63-F870-408A-B259-2933F7EABF74}';
    private const AGG_5MIN         = 5;
    private const AGG_DAY          = 1;
    private const WINDOW_DAYS      = 8;
    private const SPAN_YEARS       = 5;
    private const SUN_MARGIN_SEC   = 3600;

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';
    private const DEF_ENGINE     = 'echarts';

    private const GITHUB_URL = 'https://github.com/DG65/NRGDashboard/issues';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('PvPowerID', 0);
        $this->RegisterPropertyInteger('IrradianceID', 0);
        $this->RegisterPropertyInteger('TemperatureID', 0);
        $this->RegisterPropertyFloat('TempCoeff', -0.40);
        $this->RegisterPropertyInteger('PvfInstance', 0);
        $this->RegisterPropertyInteger('BatPowerID', 0);
        $this->RegisterPropertyInteger('SocID', 0);
        $this->RegisterPropertyInteger('Mppt1ID', 0);
        $this->RegisterPropertyInteger('Mppt2ID', 0);
        $this->RegisterPropertyInteger('Mppt3ID', 0);
        $this->RegisterPropertyInteger('Mppt4ID', 0);
        $this->RegisterPropertyInteger('TibberInstance', 0);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);
        $this->RegisterPropertyString('Engine', self::DEF_ENGINE);

        $this->RegisterAttributeString('ReviewHintDismissed', '0');

        $this->RegisterTimer('Refresh', 0, 'NRGDASHMON_Render($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);
        $this->SetStatus(102);
        // Archivbasierte Kachel (kein VM_UPDATE-Ereignis wie beim Energiefluss-
        // Diagramm) - periodischer Timer statt Ereignissteuerung, plus ein
        // sofortiger Lauf bei ApplyChanges() (Kernel-Start/Formular-Übernehmen),
        // damit eine offene Kachel nicht bis zum ersten Timer-Tick auf einem
        // veralteten Stand bleibt (siehe NRGDashboardTile, gleiche Lehre).
        $this->SetTimerInterval('Refresh', 5 * 60 * 1000);
        $this->Render();
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }

        if (!@$this->ReadAttributeBoolean('ReviewHintDismissed')) {
            $form['elements'][] = [
                'type' => 'RowLayout',
                'name' => 'ReviewHint',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 NRG Dashboard Monitoring ist Beta — Rückmeldungen sind willkommen:'],
                    ['type' => 'Label', 'link' => true, 'caption' => self::GITHUB_URL],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'NRGDASHMON_DismissReviewHint($id);'],
                ],
            ];
        }

        return json_encode($form);
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

    private function readFloatProperty(string $name, float $default): float
    {
        $v = @$this->ReadPropertyFloat($name);
        return is_float($v) ? $v : $default;
    }

    /**
     * Temperaturkorrektur fuer "PV erwartet" - fehlte bisher komplett
     * (Fund der Prognose-Sitzung, 28.07.2026, gegen Dietmars Live-Archiv
     * verifiziert: ohne dieses Glied weicht die Linie ab Mittag zunehmend
     * nach oben ab). Exakt dieselbe NOCT-Naeherung wie in
     * PVPrognose/module.php::fetchOpenMeteo() (Zeilen ~742-748) - selbe
     * Konstanten (800 W/m^2 NOCT-Referenz, 20 K NOCT-Delta), damit unsere
     * Diagnose und die Prognose physikalisch konsistent bleiben, obwohl
     * wir bewusst NICHT PVF_GetForecast() konsumieren (siehe Kommentar bei
     * PvfModel() - ein Wetterprognosefehler soll nicht wie ein
     * Anlagenfehler aussehen; die Temperaturkorrektur hier nutzt dagegen
     * ausschliesslich echte Messwerte, kein API-Aufruf).
     */
    private function DerateFactor(float $ta, float $irrWm2, float $tc): float
    {
        if ($tc == 0.0 || $irrWm2 <= 0.0) {
            return 1.0;
        }
        $tcell = $ta + $irrWm2 / 800.0 * 20.0;
        return max(0.0, 1.0 + ($tc / 100.0) * ($tcell - 25.0));
    }

    /**
     * Findet die PV-Leistungsvariable automatisch über InverterHub
     * (IHUB_GetFunctions - Objekt-Vertrag, siehe NRGDashboardTile::
     * discoverInverterHub()), falls keine explizit gewählt wurde. Nimmt die
     * erste gefundene InverterHub-Instanz - bei mehreren Instanzen (mehrere
     * Wechselrichter) muss die Variable explizit gewählt werden, es wird
     * NICHT geraten (Muster: PvfInstanceID() bei InverterHub selbst).
     */
    private function PvPowerID(): int
    {
        $explicit = $this->readIntProperty('PvPowerID', 0);
        if ($explicit > 0 && IPS_VariableExists($explicit)) {
            return $explicit;
        }
        if (!function_exists('IHUB_GetFunctions')) {
            return 0;
        }
        $ids = @IPS_GetInstanceListByModuleID('{BBE2C593-1A91-426D-A714-29A9C7E87589}');
        if (!is_array($ids) || count($ids) !== 1) {
            return 0;
        }
        $data = @IHUB_GetFunctions((int) $ids[0]);
        $pv = (int) ($data['pvPowerID'] ?? 0);
        return ($pv > 0 && IPS_VariableExists($pv)) ? $pv : 0;
    }

    /**
     * Liefert die InverterHub-Instanz, sofern genau eine installiert ist -
     * gemeinsame Grundlage fuer alle IHUB_GetFunctions()-basierten Zugriffe
     * (PV/Batterie/SOC), damit die "kein Raten bei mehreren WR"-Regel an
     * einer Stelle gilt.
     */
    private function singleInverterHubID(): int
    {
        if (!function_exists('IHUB_GetFunctions')) {
            return 0;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::INVERTERHUB_GUID);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    private function BatPowerID(): int
    {
        $explicit = $this->readIntProperty('BatPowerID', 0);
        if ($explicit > 0 && IPS_VariableExists($explicit)) {
            return $explicit;
        }
        $ihub = $this->singleInverterHubID();
        if ($ihub <= 0) {
            return 0;
        }
        $data = @IHUB_GetFunctions($ihub);
        $bat = (int) ($data['batPowerID'] ?? 0);
        return ($bat > 0 && IPS_VariableExists($bat)) ? $bat : 0;
    }

    private function SocID(): int
    {
        $explicit = $this->readIntProperty('SocID', 0);
        if ($explicit > 0 && IPS_VariableExists($explicit)) {
            return $explicit;
        }
        $ihub = $this->singleInverterHubID();
        if ($ihub <= 0) {
            return 0;
        }
        $data = @IHUB_GetFunctions($ihub);
        $soc = (int) ($data['socID'] ?? 0);
        return ($soc > 0 && IPS_VariableExists($soc)) ? $soc : 0;
    }

    /**
     * MPPT-Strang-Referenzen ueber den bereits bestehenden Diagnostik-
     * Vertrag IHUBMON_GetDiagnostics() (Eintrag mppt_string_compare,
     * stringPowerIDs) - kein eigener, neuer Vertrag noetig, InverterHub
     * pflegt diese Zuordnung bereits selbst. Nur bei genau einer
     * InverterHubMonitor-Instanz automatisch, sonst leer (kein Raten).
     */
    private function MpptPowerIDs(): array
    {
        $out = [];
        if (function_exists('IHUBMON_GetDiagnostics')) {
            $ids = @IPS_GetInstanceListByModuleID(self::IHUBMON_GUID);
            if (is_array($ids) && count($ids) === 1) {
                $data = @IHUBMON_GetDiagnostics((int) $ids[0]);
                if (is_array($data) && isset($data['entries']) && is_array($data['entries'])) {
                    foreach ($data['entries'] as $entry) {
                        if (($entry['type'] ?? '') === 'mppt_string_compare' && isset($entry['stringPowerIDs'])) {
                            foreach ($entry['stringPowerIDs'] as $n => $vid) {
                                if ((int) $vid > 0 && IPS_VariableExists((int) $vid)) {
                                    $out[(string) $n] = (int) $vid;
                                }
                            }
                            break;
                        }
                    }
                }
            }
        }
        if (count($out) > 0) {
            return $out;
        }
        // Manueller Rueckfall (Store-Review-Checkliste Punkt 12,
        // "Neuinstallations-Simulation", 28.07.2026): ohne installierte
        // InverterHubMonitor-Instanz gab es bislang KEINEN Weg an MPPT-Daten
        // zu kommen, anders als bei PV/Batterie/SOC, die alle ein manuelles
        // Feld haben - echte Luecke, kein reines Anzeigeproblem.
        for ($i = 1; $i <= 4; $i++) {
            $vid = $this->readIntProperty('Mppt' . $i . 'ID', 0);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $out[(string) $i] = $vid;
            }
        }
        return $out;
    }

    private function TibberInstanceID(): int
    {
        $cfg = $this->readIntProperty('TibberInstance', 0);
        if ($cfg > 0 && IPS_InstanceExists($cfg)
            && IPS_GetInstance($cfg)['ModuleInfo']['ModuleID'] === self::TIBBER_GUID) {
            return $cfg;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::TIBBER_GUID);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    /**
     * Aktuelle Strompreiskurve (TIBBERGR_GetPriceCurve, Verbund-Vertrag) -
     * bewusst NICHT Teil des navigierbaren Tage-Fensters: der Vertrag ist
     * ein VORWAERTS gerichteter Verlauf (jetzt + kommende Slots), keine
     * archivierte Zeitreihe vergangener Tage. Der Strompreis-Reiter zeigt
     * deshalb immer die aktuelle Kurve, unabhaengig vom gewaehlten Tag im
     * Navigations-Fenster (Verbund-Konvention: "Strompreis nur in der
     * Tagesansicht sinnvoll").
     */
    private function PriceCurve(): array
    {
        $id = $this->TibberInstanceID();
        if ($id <= 0 || !function_exists('TIBBERGR_GetPriceCurve')) {
            return [];
        }
        $curve = @TIBBERGR_GetPriceCurve($id);
        if (!is_array($curve)) {
            return [];
        }
        $out = [];
        foreach ($curve as $slot) {
            if (!isset($slot['start'], $slot['end'], $slot['price'])) {
                continue;
            }
            $out[] = [(int) $slot['start'] * 1000, (int) $slot['end'] * 1000, round((float) $slot['price'], 2)];
        }
        return $out;
    }

    private function PvfInstanceID(): int
    {
        $cfg = $this->readIntProperty('PvfInstance', 0);
        if ($cfg > 0 && IPS_InstanceExists($cfg)
            && IPS_GetInstance($cfg)['ModuleInfo']['ModuleID'] === self::PVF_GUID) {
            return $cfg;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::PVF_GUID);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    /**
     * Erwartungsmodell (kWp * PR je Generator) - 1:1 Muster aus
     * InverterHubMonitor::PvfModel(). Bewusst NIE PVF_GetForecast() (kann
     * einen ratenbegrenzten Wetter-API-Abruf auslösen) - die Diagnose
     * vergleicht gemessene Einstrahlung x Generatorparameter, nicht die
     * Wetterprognose (sonst würde ein Wetterfehler wie ein Anlagenfehler
     * aussehen, InverterHub/Prognose-Absprache 23.07.2026).
     */
    private function PvfModel(): ?array
    {
        $id = $this->PvfInstanceID();
        if ($id <= 0) {
            return null;
        }
        $rows = [];
        $pr = 0.0;
        if (function_exists('PVF_GetGenerators')) {
            $r = @PVF_GetGenerators($id);
            if (is_array($r) && isset($r['generators']) && is_array($r['generators'])) {
                $pr = (float) ($r['pr'] ?? 0);
                foreach ($r['generators'] as $g) {
                    $rows[] = ['kwp' => (float) ($g['kwp'] ?? 0), 'factor' => (float) ($g['factor'] ?? 1.0)];
                }
            }
        }
        if (count($rows) === 0) {
            $cfg = @IPS_GetConfiguration($id);
            $cfg = is_string($cfg) ? json_decode($cfg, true) : null;
            if (is_array($cfg)) {
                $pr = (float) ($cfg['PVF_PR'] ?? 0);
                $list = json_decode($cfg['PVGenerators'] ?? '[]', true);
                if (is_array($list)) {
                    foreach ($list as $row) {
                        $rows[] = ['kwp' => (float) ($row['kWp'] ?? 0), 'factor' => (float) ($row['Factor'] ?? 1.0)];
                    }
                }
            }
        }
        if ($pr <= 0.0) {
            $pr = 0.85;
        }
        $totalKwp = 0.0;
        foreach ($rows as $row) {
            if ($row['kwp'] > 0.0) {
                $totalKwp += $row['kwp'] * (($row['factor'] > 0.0) ? $row['factor'] : 1.0);
            }
        }
        return ($totalKwp > 0.0) ? ['pr' => $pr, 'totalKwp' => $totalKwp] : null;
    }

    /**
     * Koordinaten aus IP-Symcons eigener "Location Control"-Instanz (Kernel-
     * Systemstandort, JSON-Property "Location") - dieselbe Quelle, aus der
     * IPS selbst IsDayStart/IsDayEnd ableitet, keine eigene Konfiguration
     * noetig.
     */
    private function Coordinates(): ?array
    {
        $ids = @IPS_GetInstanceListByModuleID(self::LOCATION_GUID);
        if (!is_array($ids) || count($ids) === 0) {
            return null;
        }
        $raw = @IPS_GetProperty((int) $ids[0], 'Location');
        $loc = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($loc) || !isset($loc['latitude'], $loc['longitude'])) {
            return null;
        }
        return ['lat' => (float) $loc['latitude'], 'lon' => (float) $loc['longitude']];
    }

    /**
     * Sonnenaufgang−1h bis Sonnenuntergang+1h fuer den Tag von $dayStart, als
     * [startMs, endMs] fuers Diagramm "PV & Einstrahlung" (Dietmars Wunsch,
     * 27.07.2026 - die Nachtstunden ohne jede Erzeugung sollen nicht die
     * halbe Diagrammbreite einnehmen). PHP-Kernfunktion date_sun_info() statt
     * eigener Astronomie - liefert bereits Unix-Zeitstempel. Fallback: der
     * volle Kalendertag, falls kein Systemstandort konfiguriert ist oder der
     * Ort einen Polartag/-nacht hat (sunrise/sunset dann bool statt Zeit).
     */
    private function SunRange(int $dayStart): array
    {
        $dayEnd = $dayStart + 86400;
        $coords = $this->Coordinates();
        if ($coords === null) {
            return [$dayStart, $dayEnd];
        }
        $info = @date_sun_info($dayStart + 43200, $coords['lat'], $coords['lon']);
        if (!is_array($info) || !is_int($info['sunrise']) || !is_int($info['sunset'])) {
            return [$dayStart, $dayEnd];
        }
        return [$info['sunrise'] - self::SUN_MARGIN_SEC, $info['sunset'] + self::SUN_MARGIN_SEC];
    }

    private function ArchiveID(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return $ids[0] ?? 0;
    }

    /**
     * 5-Minuten-Zeitreihe (Mittelwert je Bucket) eines Tages, [[tsMs, W],...]
     * - Muster: InverterHubMonitor::DaySeries(). IMMER die 6-Argument-Form
     * von AC_GetAggregatedValues (Limit=0), sonst bricht der Aufruf.
     */
    private function DaySeries(int $aid, int $vid, int $start, int $end): array
    {
        if ($vid <= 0 || !IPS_VariableExists($vid) || !@AC_GetLoggingStatus($aid, $vid)) {
            return [];
        }
        $data = @AC_GetAggregatedValues($aid, $vid, self::AGG_5MIN, $start, $end, 0);
        if (!is_array($data)) {
            return [];
        }
        usort($data, function ($a, $b) { return (int) $a['TimeStamp'] <=> (int) $b['TimeStamp']; });
        $pts = [];
        foreach ($data as $row) {
            $pts[] = [(int) $row['TimeStamp'] * 1000, round((float) $row['Avg'], 1)];
        }
        return $pts;
    }

    /**
     * Tages-kWh je Kalendertag ueber die letzten SPAN_YEARS Jahre, als
     * ['Y-m-d' => kWh] - EIN Archivdurchlauf pro Serie (Muster:
     * InverterHubMonitor, "eine Tageswerte-Zeitreihe pro Serie über 5 Jahre
     * zurückgerechnet, ein Archivdurchlauf pro Serie, nicht pro Ansicht").
     * Woche/Monat/Jahr/Gesamt/Benutzerdefiniert werden daraus im Frontend
     * gruppiert, statt je Ansicht erneut das Archiv abzufragen.
     *
     * Alle unsere Quellen (PV-/Batterie-/MPPT-Leistung) sind reine
     * Leistungswerte, keine kumulativen Zaehler - anders als
     * InverterHubMonitors CATALOG-Werte gibt es bei uns aktuell keinen
     * energyImportID-Vertrag dafuer. Tages-kWh wird deshalb immer aus der
     * Leistung hochgerechnet (Avg * 24h / 1000), nicht aus einem
     * Zaehler-Zuwachs - das ist eine Naeherung (nimmt an, der Tagesmittel-
     * wert haette 24h angehalten), aber die einzige uns verfuegbare Methode.
     */
    private function DailyEnergyMap(int $aid, int $vid): array
    {
        if ($vid <= 0 || !IPS_VariableExists($vid) || !@AC_GetLoggingStatus($aid, $vid)) {
            return [];
        }
        $end = time();
        $start = strtotime('-' . self::SPAN_YEARS . ' years', $end);
        $data = @AC_GetAggregatedValues($aid, $vid, self::AGG_DAY, $start, $end, 0);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $row) {
            $kwh = round(((float) $row['Avg']) * 24.0 / 1000.0, 2);
            if (is_finite($kwh) && $kwh >= 0) {
                $out[date('Y-m-d', (int) $row['TimeStamp'])] = $kwh;
            }
        }
        return $out;
    }

    /**
     * Reiner Tages-Mittelwert (kein Energie-Hochrechnungs-Kunstgriff wie
     * DailyEnergyMap) - fuer nicht-energetische Groessen wie Temperatur,
     * die als Tagesdurchschnitt in die Energie-Ansichten einfliessen.
     */
    private function DailyAverageMap(int $aid, int $vid): array
    {
        if ($vid <= 0 || !IPS_VariableExists($vid) || !@AC_GetLoggingStatus($aid, $vid)) {
            return [];
        }
        $end = time();
        $start = strtotime('-' . self::SPAN_YEARS . ' years', $end);
        $data = @AC_GetAggregatedValues($aid, $vid, self::AGG_DAY, $start, $end, 0);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $row) {
            $out[date('Y-m-d', (int) $row['TimeStamp'])] = round((float) $row['Avg'], 2);
        }
        return $out;
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        // ECharts (~1 MB) nur einbetten, wenn diese Engine auch gewaehlt ist -
        // sonst reisst allein die eingebettete Bibliothek IP-Symcons
        // Ausgabepuffer fuer Kacheln (1 MB), unabhaengig von der tatsaechlich
        // genutzten Engine (live aufgetreten: "Output-Buffer exceeds Limit",
        // auch mit gewaehltem Highcharts, weil der Platzhalter bisher IMMER
        // ersetzt wurde).
        $engine = ($this->readStringProperty('Engine', self::DEF_ENGINE) === 'highcharts') ? 'highcharts' : 'echarts';
        $echarts = ($engine === 'echarts') ? file_get_contents(__DIR__ . '/echarts.min.js') : '';
        $html = str_replace('/*__ECHARTS_JS__*/', $echarts, $html);
        $html .= '<script>handleMessage(' . json_encode($this->buildPayload()) . ');</script>';
        return $html;
    }

    // Temporaeres Diagnose-Hilfsmittel (28.07.2026) - wird nach der ECharts-
    // Blank-Chart-Fehlersuche wieder entfernt, siehe README.
    public function DebugPayload(): string
    {
        return json_encode($this->buildPayload());
    }

    public function Render(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->buildPayload()));
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
     * Baut die Nutzlast fuer ein navigierbares Tage-Fenster (Ansicht
     * "Tag (Verlauf)") - Muster: InverterHubMonitor::BuildPayload(),
     * WINDOW_DAYS=8. Alle Tage des Fensters werden in EINEM Archivdurchlauf
     * pro Serie mitgeschickt; das Frontend navigiert rein clientseitig
     * zwischen den mitgelieferten Tagen, ohne bei jedem Klick auf
     * Vor/Zurück erneut das Modul aufzurufen.
     */
    private function buildPayload(): array
    {
        $aid = $this->ArchiveID();
        $pvID = $this->PvPowerID();
        $irrID = $this->readIntProperty('IrradianceID', 0);
        $tempID = $this->readIntProperty('TemperatureID', 0);
        $tc = $this->readFloatProperty('TempCoeff', -0.40);
        $batID = $this->BatPowerID();
        $socID = $this->SocID();
        $mppt = $this->MpptPowerIDs();
        $model = $this->PvfModel();

        $days = [];
        for ($k = 0; $k < self::WINDOW_DAYS; $k++) {
            $start = strtotime("today -{$k} days 00:00:00");
            $end   = min(time(), $start + 86400);

            $pv  = $aid > 0 ? $this->DaySeries($aid, $pvID, $start, $end) : [];
            $irr = $aid > 0 ? $this->DaySeries($aid, $irrID, $start, $end) : [];
            $temp = $aid > 0 ? $this->DaySeries($aid, $tempID, $start, $end) : [];
            $bat = $aid > 0 ? $this->DaySeries($aid, $batID, $start, $end) : [];
            // SOC-Rauschen glaetten (Muster: InverterHubMonitor::SmoothPoints,
            // Fenster 15) - nur fuer die eigene Anzeige, kein Diagnostik-Wert.
            $soc = $aid > 0 ? $this->SmoothPoints($this->DaySeries($aid, $socID, $start, $end), 15) : [];

            $mpptSeries = [];
            foreach ($mppt as $n => $vid) {
                $mpptSeries[$n] = $aid > 0 ? $this->DaySeries($aid, $vid, $start, $end) : [];
            }

            $expected = [];
            if ($model !== null && count($irr) > 0) {
                // Muster: InverterHubMonitor - expectedW = Einstrahlung(W/m^2)
                // * totalKwp * PR. Der scheinbar fehlende Faktor 1000
                // (W/m^2 <-> kWp) kuerzt sich numerisch weg: kWp ist "kW bei
                // 1000 W/m^2 STC", 1 kWp entspricht also zahlenmaessig
                // 1000 W - beide Male /1000 bzw. *1000 heben sich auf.
                // Temperaturkorrektur (Fund der Prognose-Sitzung, 28.07.2026):
                // ohne Temperaturglied fehlt der Grossteil der ab Mittag
                // zunehmenden Abweichung nach oben - Zellen werden bei hoher
                // Einstrahlung deutlich waermer als die 25 C STC-Referenz und
                // liefern dadurch real weniger, als die reine Einstrahlungs-
                // Rechnung vorhersagt. Temperaturwerte je Zeitstempel
                // zuordnen (gleiches 5-Minuten-Raster wie Einstrahlung, daher
                // per Zeitstempel-Map statt Index koppelbar).
                $tempByTs = [];
                foreach ($temp as $tp) { $tempByTs[$tp[0]] = $tp[1]; }
                foreach ($irr as $p) {
                    $ta = $tempByTs[$p[0]] ?? null;
                    $derate = ($ta !== null) ? $this->DerateFactor((float) $ta, (float) $p[1], $tc) : 1.0;
                    $expected[] = [$p[0], round($p[1] * $model['totalKwp'] * $model['pr'] * $derate, 0)];
                }
            }

            $hasData = count($pv) > 0 || count($irr) > 0 || count($bat) > 0 || count($soc) > 0;
            foreach ($mpptSeries as $s) {
                $hasData = $hasData || count($s) > 0;
            }

            $sun = $this->SunRange($start);

            $days[] = [
                'id'       => date('Y-m-d', $start),
                'label'    => date('d.m.Y', $start),
                'hasData'  => $hasData,
                // Sonnenaufgang-1h/Sonnenuntergang+1h in ms - nur fuer den
                // Reiter "PV & Einstrahlung" als x-Achsen-Bereich genutzt
                // (Dietmars Wunsch: Nachtstunden ohne Erzeugung nicht anzeigen).
                'sunStart' => $sun[0] * 1000,
                'sunEnd'   => $sun[1] * 1000,
                'pv'       => $pv,
                'irr'      => $irr,
                'expected' => $expected,
                'bat'      => $bat,
                'soc'      => $soc,
                'mppt'     => $mpptSeries,
            ];
        }

        // Woche/Monat/Jahr/Gesamt/Benutzerdefiniert: EIN Archivdurchlauf pro
        // Serie ueber SPAN_YEARS Jahre (Tages-kWh), das Frontend gruppiert
        // daraus die jeweilige Ansicht selbst - kein erneuter Archivzugriff
        // bei jedem Ansichts-/Zeitraumwechsel.
        $energyMppt = [];
        foreach ($mppt as $n => $vid) {
            $energyMppt[$n] = $aid > 0 ? $this->DailyEnergyMap($aid, $vid) : [];
        }
        // Einstrahlung/PV erwartet fehlten in der ersten Fassung der
        // Energie-Ansichten (Dietmar, 28.07.2026: "Woche zeigt nur PV-
        // Erzeugung") - bewusst nachgezogen, damit "PV & Einstrahlung"
        // auch hier alle drei Linien der Tagesansicht spiegelt.
        $energyIrr = $aid > 0 ? $this->DailyEnergyMap($aid, $irrID) : [];
        // Temperaturkorrektur auch hier (Fund der Prognose-Sitzung,
        // 28.07.2026) - bewusst ein GROBER Tagesdurchschnitt statt der
        // exakten 5-Minuten-Kopplung aus dem Tagesverlauf: eine echte
        // Integration ueber 5 Jahre x 5-Minuten-Werte je Serie waere fuer
        // einen einzelnen Kachel-Aufbau zu teuer. $avgIrrWm2 wird aus dem
        // bereits vorhandenen "Tages-kWh"-Kunstgriff zurueckgerechnet
        // (kwhEquivalent = Avg*24/1000 → Avg = kwhEquivalent*1000/24).
        $energyTemp = ($aid > 0 && $tempID > 0) ? $this->DailyAverageMap($aid, $tempID) : [];
        $energyExpected = [];
        if ($model !== null) {
            // Gleicher Kunstgriff wie im Tagesverlauf: Einstrahlung(W/m^2)
            // * totalKwp * PR - hier auf der bereits zu "Tages-kWh"
            // hochgerechneten Einstrahlungs-Reihe angewendet (derselbe
            // Avg*24/1000-Kunstgriff, der Faktor kuerzt sich identisch weg).
            foreach ($energyIrr as $day => $kwhEquivalent) {
                $derate = 1.0;
                if (isset($energyTemp[$day])) {
                    $avgIrrWm2 = $kwhEquivalent * 1000.0 / 24.0;
                    $derate = $this->DerateFactor((float) $energyTemp[$day], $avgIrrWm2, $tc);
                }
                $energyExpected[$day] = round($kwhEquivalent * $model['totalKwp'] * $model['pr'] * $derate, 2);
            }
        }
        $energy = [
            'pv'       => $aid > 0 ? $this->DailyEnergyMap($aid, $pvID) : [],
            'bat'      => $aid > 0 ? $this->DailyEnergyMap($aid, $batID) : [],
            'irr'      => $energyIrr,
            'expected' => $energyExpected,
            'mppt'     => $energyMppt,
        ];

        return [
            'ok'       => true,
            // Instanz-ID als Namensraum fuer die Legenden-Sichtbarkeit
            // (localStorage im Frontend) - Muster: InverterHubMonitor.
            'uid'      => (string) $this->InstanceID,
            'hasPv'    => $pvID > 0,
            'hasIrr'   => $irrID > 0,
            'hasModel' => $model !== null,
            'hasBat'   => $batID > 0,
            'hasSoc'   => $socID > 0,
            'mpptKeys' => array_keys($mppt),
            'price'    => $this->PriceCurve(),
            'days'     => $days,
            'energy'   => $energy,
            'engine'   => ($this->readStringProperty('Engine', self::DEF_ENGINE) === 'highcharts') ? 'highcharts' : 'echarts',
            'bg'       => $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND)),
            'font'     => $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT)),
        ];
    }

    /**
     * Zentrierter gleitender Mittelwert ueber $win Punkte - Muster:
     * InverterHubMonitor::SmoothPoints() (glaettet BMS-Rauschen, z.B. SOC).
     * Zeitstempel bleiben erhalten.
     */
    private function SmoothPoints(array $pts, int $win): array
    {
        $n = count($pts);
        if ($n < 3 || $win < 2) {
            return $pts;
        }
        $half = intdiv($win, 2);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $lo = max(0, $i - $half);
            $hi = min($n - 1, $i + $half);
            $sum = 0.0;
            $c = 0;
            for ($j = $lo; $j <= $hi; $j++) {
                $sum += $pts[$j][1];
                $c++;
            }
            $out[] = [$pts[$i][0], round($sum / $c, 1)];
        }
        return $out;
    }
}
