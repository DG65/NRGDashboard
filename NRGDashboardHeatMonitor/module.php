<?php

declare(strict_types=1);

/**
 * NRGDashboardHeatMonitor - Verlaufs-/Diagnose-Kachel fuer die Waermepumpe,
 * Geschwistermodul zu NRGDashboardMonitor (PV/Batterie/Netz) - gleiche
 * Optik/UX-Konvention (Datumsleiste, ECharts, Kennzahlen-Kopfzeile), siehe
 * NRGDashboardMonitor::module.html fuer die uebernommenen CSS-Klassen
 * (Dietmar, 17.08.2026: "eine weitere Monitoring Kachel in der Form und im
 * Aussehen von der vorhandenen PV-Monitoring Kachel").
 *
 * Datenquelle wie NRGDashboardHeatSchema: der gemeinsame 'heatpump'-
 * Vertragstyp (HEISHA_GetFunctions()/WPHUB_GetFunctions()), generisch -
 * kein Modul wird vorausgesetzt, fehlende optionale Felder (contractVersion
 * 1.10: heatOutputPowerID/outsideTempID/compressorStartsID/
 * operationsHoursID; 1.9: copEstimateID/copMeasuredID/
 * dailyPerformanceFactorID) blenden die jeweilige Kennzahl/Kurve einfach
 * aus, statt eine Nullanzeige zu zeigen.
 *
 * V1-Umfang (Dietmar hat eine erste funktionierende Version gesehen wollen,
 * bevor Woche/Monat/Jahr + Detail-Umschalter draufkommen): Kennzahlen-
 * Kopfzeile + Tagesansicht (elektrische/thermische Leistung, Vorlauf/
 * Ruecklauf, Aussentemp, Abtau-Schattierung). Wochen-/Monats-/Jahresansicht
 * und Detail-Umschalter (Verdichterfrequenz, Durchfluss, WW-Temp,
 * Betriebsart-Farbcodierung) sind bewusst noch nicht gebaut - naechster
 * Schritt, sobald die Tagesansicht bei Dietmar passt.
 */
class NRGDashboardHeatMonitor extends IPSModule
{
    private const HEISHA_GUID  = '{1919151A-3C0F-4C09-B906-291638EC1469}';
    private const WPHUB_GUID   = '{5BE429EA-3AAD-4A8B-85DE-5778CCA2E6BC}';
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const AGG_5MIN     = 5;
    private const AGG_DAY      = 1;
    private const AGG_MONTH    = 3;
    private const SPAN_YEARS   = 5;

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);
        $this->RegisterPropertyBoolean('LightTheme', false);
        // Manuelle Waermepumpen-Auswahl fuer den Fall mehrerer gefundener
        // Instanzen (Dietmar hat bei sich nur eine - Default 0 = automatisch
        // die erste gefundene nehmen, wie schon in DiscoverHeatpumps()
        // dokumentiert).
        $this->RegisterPropertyInteger('HeatpumpInstance', 0);

        $this->RegisterTimer('Refresh', 0, 'NRGDASHHEATMON_Render($_IPS[\'TARGET\']);');
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
        try {
            $v = $this->ReadPropertyInteger($name);
            return $v !== 0 ? $v : $default;
        } catch (Exception $e) {
            return $default;
        }
    }

    private function readStringProperty(string $name, string $default): string
    {
        try {
            $v = $this->ReadPropertyString($name);
            return $v !== '' ? $v : $default;
        } catch (Exception $e) {
            return $default;
        }
    }

    /**
     * Alle 'heatpump'-Vertragseintraege einsammeln - identisch zu
     * NRGDashboardHeatSchema::DiscoverHeatpumps() (ohne die dortige
     * manuelle Datenanbindung, die ist fuer eine Verlaufs-Kachel ohne
     * Archiv-Historie der manuellen Variable wenig sinnvoll - wer keine
     * HeishaMon/WPHub-Instanz hat, bekommt hier v1 noch keinen Verlauf).
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

    /**
     * Die eine Waermepumpe, die diese Kachel darstellt - konfigurierte
     * Instanz bevorzugt, sonst die erste gefundene (Dietmar hat nur eine).
     */
    private function SelectedHeatpump(): ?array
    {
        $entries = $this->DiscoverHeatpumps();
        if (count($entries) === 0) {
            return null;
        }
        $want = $this->readIntProperty('HeatpumpInstance', 0);
        if ($want > 0) {
            foreach ($entries as $e) {
                if ((int) $e['_instanceID'] === $want) {
                    return $e;
                }
            }
        }
        return $entries[0];
    }

    // 1:1 NRGDashboardMonitor::ColorOrEmpty()/FontStack() - dieselbe
    // Darstellungs-Konvention (Hintergrundfarbe/Schriftart aus den
    // Formular-Properties in den Payload, module.html wendet sie an).
    private function ColorOrEmpty(int $v): string
    {
        return ($v < 0) ? '' : sprintf('#%06X', $v);
    }

    private function FontStack(string $v): string
    {
        return ($v === '' || $v === self::DEF_FONT) ? '' : $v;
    }

    private function num(int $vid): ?float
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return null;
        }
        $v = GetValue($vid);
        return is_numeric($v) ? (float) $v : null;
    }

    // Wie num(), aber fuer Temperaturfelder - HeishaMon meldet einen
    // fehlenden Sensor als Sentinel-Temperatur (z.B. -78°C) statt null
    // (siehe NRGDashboardHeatSchema::numTemp(), identische Logik).
    private function numTemp(int $vid): ?float
    {
        $v = $this->num($vid);
        if ($v === null || $v < -50.0 || $v > 120.0) {
            return null;
        }
        return $v;
    }

    private function ArchiveID(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return $ids[0] ?? 0;
    }

    /**
     * 5-Minuten-Zeitreihe (Mittelwert je Bucket), [[tsMs, value],...] -
     * 1:1 Muster NRGDashboardMonitor::DaySeries().
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
     * Zusammenhaengende "aktiv"-Intervalle einer boolschen/0-1-Groesse
     * (hier: Abtaubetrieb) als [[startMs,endMs],...] - fuer die
     * Schattierung im Chart (ECharts markArea). Bucket gilt als aktiv,
     * wenn der 5-Minuten-Mittelwert > 0.5 ist.
     */
    private function ActiveEpisodes(int $aid, int $vid, int $start, int $end): array
    {
        $series = $this->DaySeries($aid, $vid, $start, $end);
        $episodes = [];
        $openStart = null;
        $lastTs = null;
        foreach ($series as $pt) {
            $ts = $pt[0];
            $active = $pt[1] > 0.5;
            if ($active && $openStart === null) {
                $openStart = $ts;
            } elseif (!$active && $openStart !== null) {
                $episodes[] = [$openStart, $lastTs ?? $ts];
                $openStart = null;
            }
            $lastTs = $ts;
        }
        if ($openStart !== null) {
            $episodes[] = [$openStart, $lastTs ?? $openStart];
        }
        return $episodes;
    }

    /**
     * Leistungs-Zeitreihe zu kWh aufintegriert (nur positive Anteile,
     * Leistung ist hier immer eine Bezugsgroesse) - Muster
     * NRGDashboardMonitor::PowerToEnergy().
     */
    private function PowerToEnergy(int $aid, int $vid, int $start, int $end): float
    {
        $data = ($vid > 0 && IPS_VariableExists($vid) && @AC_GetLoggingStatus($aid, $vid))
            ? @AC_GetAggregatedValues($aid, $vid, self::AGG_5MIN, $start, $end, 0)
            : null;
        if (!is_array($data)) {
            return 0.0;
        }
        $kwh = 0.0;
        foreach ($data as $row) {
            $kwh += max(0.0, (float) $row['Avg']) * (5.0 / 60.0) / 1000.0;
        }
        return $kwh;
    }

    /**
     * Tages-kWh-Karte (Datum => kWh), 1:1 Muster
     * NRGDashboardMonitor::DailyEnergyMap() - ein einziger AC-Aufruf ueber
     * SPAN_YEARS Jahre, danach in BuildPeriod() lokal auf den gewuenschten
     * Zeitraum eingeschraenkt (Woche/Monat als Tagesbalken).
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
     * DailyEnergyMap) - fuer nicht-energetische Groessen wie Aussentemp,
     * 1:1 Muster NRGDashboardMonitor::DailyAverageMap().
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

    /**
     * Monats-kWh-Karte ('Y-m' => kWh) fuer die Jahresansicht -
     * coverage-bewusste Hochrechnung mit den tatsaechlich abgedeckten
     * Stunden statt der vollen Kalendertage, sonst wird ein noch laufender
     * Monat massiv ueberschaetzt (realer Fund in NRGDashboardMonitor,
     * 05.08.2026: August zeigte 1325 statt ~299 kWh) - 1:1 Muster
     * NRGDashboardMonitor::MonthlyEnergyMap().
     */
    private function MonthlyEnergyMap(int $aid, int $vid, int $start, int $end): array
    {
        if ($vid <= 0 || !IPS_VariableExists($vid) || !@AC_GetLoggingStatus($aid, $vid)) {
            return [];
        }
        $data = @AC_GetAggregatedValues($aid, $vid, self::AGG_MONTH, $start, $end, 0);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $row) {
            if (!isset($row['Avg'])) {
                continue;
            }
            $ts = (int) $row['TimeStamp'];
            $monthStart = strtotime(date('Y-m-01 00:00:00', $ts));
            $monthEnd = strtotime('+1 month', $monthStart);
            $coverageEnd = min($end, $monthEnd);
            $hours = max(0.0, ($coverageEnd - $monthStart) / 3600.0);
            $kwh = round(((float) $row['Avg']) * $hours / 1000.0, 2);
            if (is_finite($kwh) && $kwh >= 0) {
                $out[date('Y-m', $monthStart)] = $kwh;
            }
        }
        return $out;
    }

    /**
     * Woche/Monat/Jahr als Balken-Zeitreihe - Woche/Monat: Tagesbalken aus
     * DailyEnergyMap()/DailyAverageMap() auf den Zeitraum eingeschraenkt;
     * Jahr: Monatsbalken aus MonthlyEnergyMap(). Kein Tagesansicht-
     * Feinschliff (5-Min-Kurve/Abtau-Schattierung) - das bleibt der
     * Tagesansicht (BuildDay()) vorbehalten.
     */
    private function BuildPeriod(string $type, int $start, int $end): array
    {
        $unit = $this->SelectedHeatpump();
        if ($unit === null) {
            return ['type' => $type, 'start' => $start * 1000, 'end' => $end * 1000, 'buckets' => []];
        }
        $powerID = (int) ($unit['PowerID'] ?? $unit['powerID'] ?? 0);
        $heatOutID = (int) ($unit['heatOutputPowerID'] ?? 0);
        $outsideTempID = (int) ($unit['outsideTempID'] ?? 0);
        $aid = $this->ArchiveID();

        $buckets = [];
        if ($type === 'year') {
            $electric = $this->MonthlyEnergyMap($aid, $powerID, $start, $end);
            $thermal = $this->MonthlyEnergyMap($aid, $heatOutID, $start, $end);
            $cursor = strtotime(date('Y-m-01 00:00:00', $start));
            while ($cursor < $end) {
                $key = date('Y-m', $cursor);
                $buckets[] = [
                    'label'    => $key,
                    'ts'       => $cursor * 1000,
                    'electric' => $electric[$key] ?? 0.0,
                    'thermal'  => $thermal[$key] ?? 0.0,
                ];
                $cursor = strtotime('+1 month', $cursor);
            }
        } else {
            $electric = $this->DailyEnergyMap($aid, $powerID);
            $thermal = $this->DailyEnergyMap($aid, $heatOutID);
            $outside = $this->DailyAverageMap($aid, $outsideTempID);
            $cursor = $start;
            while ($cursor < $end) {
                $key = date('Y-m-d', $cursor);
                $buckets[] = [
                    'label'       => $key,
                    'ts'          => $cursor * 1000,
                    'electric'    => $electric[$key] ?? 0.0,
                    'thermal'     => $thermal[$key] ?? 0.0,
                    'outsideTemp' => $outside[$key] ?? null,
                ];
                $cursor = strtotime('+1 day', $cursor);
            }
        }

        return [
            'type'    => $type,
            'start'   => $start * 1000,
            'end'     => $end * 1000,
            'buckets' => $buckets,
            'electricEnergyKwh' => round(array_sum(array_column($buckets, 'electric')), 2),
            'thermalEnergyKwh'  => round(array_sum(array_column($buckets, 'thermal')), 2),
        ];
    }

    public function GetVisualizationTile()
    {
        $payload = $this->buildPayload();
        $this->SetStatus(($payload['ok'] ?? false) ? 102 : 104);
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($payload) . ');</script>';
        return $html;
    }

    public function Render(): void
    {
        $payload = $this->buildPayload();
        $this->SetStatus(($payload['ok'] ?? false) ? 102 : 104);
        $this->UpdateVisualizationValue(json_encode($payload));
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'dayWindow') {
            $req = json_decode((string) $Value, true);
            $start = (int) ($req['start'] ?? 0);
            $end = (int) ($req['end'] ?? 0);
            $this->UpdateVisualizationValue(json_encode([
                'ok'   => true,
                'type' => 'dayUpdate',
                'day'  => ($end > $start) ? $this->BuildDay($start, $end) : null,
            ]));
            return;
        }
        if ($Ident === 'periodWindow') {
            $req = json_decode((string) $Value, true);
            $type = (string) ($req['type'] ?? 'week');
            $start = (int) ($req['start'] ?? 0);
            $end = (int) ($req['end'] ?? 0);
            $this->UpdateVisualizationValue(json_encode([
                'ok'     => true,
                'type'   => 'periodUpdate',
                'period' => ($end > $start) ? $this->BuildPeriod($type, $start, $end) : null,
            ]));
            return;
        }
        throw new Exception('Invalid Ident: ' . $Ident);
    }

    /**
     * Kennzahlen-Kopfzeile + heutige Tagesansicht - Grundgeruest fuer
     * GetVisualizationTile()/Render(). Weitere Zeitfenster (Woche/Monat/
     * Jahr) sind der naechste Ausbauschritt (siehe Klassenkommentar).
     */
    private function buildPayload(): array
    {
        $unit = $this->SelectedHeatpump();
        if ($unit === null) {
            return [
                'ok'    => false,
                'error' => 'Keine Wärmepumpe gefunden - HeishaMon oder WPHub installieren und konfigurieren.',
            ];
        }

        $powerID = (int) ($unit['PowerID'] ?? $unit['powerID'] ?? 0);
        $heatOutID = (int) ($unit['heatOutputPowerID'] ?? 0);
        $mainOutletID = (int) ($unit['mainOutletTempID'] ?? 0);
        $mainInletID = (int) ($unit['mainInletTempID'] ?? 0);
        $outsideTempID = (int) ($unit['outsideTempID'] ?? 0);
        $defrostID = (int) ($unit['defrostingStateID'] ?? 0);
        $copMeasuredID = (int) ($unit['copMeasuredID'] ?? 0);
        $copEstimateID = (int) ($unit['copEstimateID'] ?? 0);
        $dailyAzID = (int) ($unit['dailyPerformanceFactorID'] ?? 0);
        $startsID = (int) ($unit['compressorStartsID'] ?? 0);
        $hoursID = (int) ($unit['operationsHoursID'] ?? 0);

        $now = (int) $this->getNow();
        // Lokale Tagesgrenze statt UTC - mktime() nutzt die vom
        // Symcon-System konfigurierte Zeitzone.
        $dayStart = mktime(0, 0, 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));
        $dayEnd = $dayStart + 86400;

        $copMeasured = $this->num($copMeasuredID);
        $copEstimate = $this->num($copEstimateID);
        $starts = $this->num($startsID);
        $hours = $this->num($hoursID);

        return [
            'ok'         => true,
            'label'      => (string) ($unit['Caption'] ?? $unit['caption'] ?? IPS_GetName((int) $unit['_instanceID'])),
            // Wie NRGDashboardMonitor: Symcon bietet einer Kachel keinen
            // Weg, das aktuelle Hell/Dunkel-Theme zu erkennen - der Nutzer
            // setzt es einmalig selbst (Formular "Darstellung").
            'lightTheme' => $this->ReadPropertyBoolean('LightTheme'),
            'bg'         => $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND)),
            'font'       => $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT)),
            'kpi'        => [
                'copCurrent'   => ($copMeasured !== null && $copMeasured > 0) ? round($copMeasured, 1)
                    : (($copEstimate !== null && $copEstimate > 0) ? round($copEstimate) : null),
                'dailyAz'      => (function () use ($dailyAzID) {
                    $v = $this->num($dailyAzID);
                    return ($v !== null && $v > 0) ? round($v, 1) : null;
                })(),
                'compressorStarts' => ($starts !== null) ? (int) $starts : null,
                'operationsHours'  => ($hours !== null) ? round($hours, 1) : null,
                'avgRuntimeMin' => ($starts !== null && $starts > 0 && $hours !== null)
                    ? round($hours * 60 / $starts, 1) : null,
            ],
            'day'   => $this->BuildDay($dayStart, $dayEnd, $powerID, $heatOutID, $mainOutletID, $mainInletID, $outsideTempID, $defrostID),
        ];
    }

    private function getNow(): int
    {
        // Date()/time() sind in Symcon-Skripten normal nutzbar (anders als
        // in Workflow-Skripten) - eigener Wrapper nur, damit ein spaeterer
        // Test-Mock (Simulation) hier ansetzen kann, ohne buildPayload()
        // selbst anfassen zu muessen.
        return time();
    }

    /**
     * Tagesdaten fuer den Hauptchart. Wird sowohl initial (buildPayload())
     * als auch bei Navigation (RequestAction 'dayWindow') mit denselben
     * ID-Feldern der aktuell ausgewaehlten Waermepumpe aufgerufen - bei der
     * Navigation muessen die IDs daher erneut aufgeloest werden (die
     * Instanz kann sich zwischen zwei Kachel-Interaktionen theoretisch
     * geaendert haben, gleiches Muster wie im uebrigen NRG-Stack: nie
     * IDs cachen, immer frisch aufloesen).
     */
    private function BuildDay(int $start, int $end, ?int $powerID = null, ?int $heatOutID = null, ?int $mainOutletID = null, ?int $mainInletID = null, ?int $outsideTempID = null, ?int $defrostID = null): array
    {
        if ($powerID === null) {
            $unit = $this->SelectedHeatpump();
            if ($unit === null) {
                return ['start' => $start, 'end' => $end];
            }
            $powerID = (int) ($unit['PowerID'] ?? $unit['powerID'] ?? 0);
            $heatOutID = (int) ($unit['heatOutputPowerID'] ?? 0);
            $mainOutletID = (int) ($unit['mainOutletTempID'] ?? 0);
            $mainInletID = (int) ($unit['mainInletTempID'] ?? 0);
            $outsideTempID = (int) ($unit['outsideTempID'] ?? 0);
            $defrostID = (int) ($unit['defrostingStateID'] ?? 0);
        }

        $aid = $this->ArchiveID();

        return [
            'start'          => $start * 1000,
            'end'            => $end * 1000,
            'electricPower'  => $this->DaySeries($aid, $powerID, $start, $end),
            'thermalPower'   => $this->DaySeries($aid, $heatOutID, $start, $end),
            'mainOutletTemp' => $this->DaySeries($aid, $mainOutletID, $start, $end),
            'mainInletTemp'  => $this->DaySeries($aid, $mainInletID, $start, $end),
            'outsideTemp'    => $this->DaySeries($aid, $outsideTempID, $start, $end),
            'defrostEpisodes' => $this->ActiveEpisodes($aid, $defrostID, $start, $end),
            'electricEnergyKwh' => round($this->PowerToEnergy($aid, $powerID, $start, $end), 2),
            'thermalEnergyKwh'  => round($this->PowerToEnergy($aid, $heatOutID, $start, $end), 2),
        ];
    }
}
