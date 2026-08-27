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
 * PHP-Bezug).
 *
 * Instanzformular ebenfalls 1:1 (Dietmar, 26.08.2026, zum Vergleichs-
 * Screenshot: "Da fehlt aber noch etwas. Das hier ist die Referenz von
 * Prognose." -> explizit bestaetigt: zwei getrennte Instanzfelder statt
 * einer zusammengefassten Energiebilanz-Instanz): dieses module.php liest
 * PVForecast/LoadForecast deshalb DIREKT (PVSource/LoadSource-Properties,
 * ReadSeries()/snapshotToDay(), 1:1 aus Prognoses eigenem buildDaysData()
 * uebernommen) statt ueber EFTILE_GetDaysData() zu gehen - genau wie
 * Prognoses Energiebilanz es selbst tut. Baut denselben Payload wie
 * Prognoses GetFullUpdateMessage() (Stil-Felder + hasData/message/days/
 * actualPV/actualLoad), hier lokal nach unseren EIGENEN Doppelpfeil-
 * Einstellungen (Days/ShowYesterday/ShowActualPV/...) zurechtgeschnitten -
 * die Prognoseberechnung selbst (k-NN, Perzentile) bleibt bei PVForecast/
 * LoadForecast, die Ist-Integration aus dem Archiv uebernehmen wir jetzt
 * ueber eigene ActualPV/ActualLoad-Variablen komplett selbst.
 */
class NRGDashboardForecast extends IPSModule
{
    // Request-lokaler Cache der automatisch erkannten Einheiten je Variable
    // (autoPowerFactor()) - 1:1 Muster aus Prognoses Energiebilanz.
    private $unitCache = [];

    private const SOURCE_PV   = '{257DD4E8-9705-462E-89FC-56D0A1038353}'; // PVForecast
    private const SOURCE_LOAD = '{DC5AD508-507F-40EA-8630-0959AED83050}'; // LoadForecast

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
        'Eigene Ist-Leistungsvariablen (Konsole: "Ist-Werte") mit eigener Einheiten-Erkennung und Archiv-Cache-Intervall - unabhaengig von Prognoses eigener Konfiguration.',
        'Datenquelle jetzt zwei getrennte Felder (PV-Prognose-/Last-Prognose-Instanz) statt einer zusammengefassten Energiebilanz-Instanz, 1:1 wie in Prognoses eigenem Formular - bei genau je einer installierten Instanz weiterhin automatisch erkannt.',
    ];

    public function Create()
    {
        parent::Create();

        // Reine Verdrahtung (Quell-/Variablenauswahl) bleibt Property - nur
        // die Konsole bietet SelectInstance/SelectVariable, eine
        // Doppelpfeil-Variable kann das nicht abbilden (Dashboard-Muster,
        // 1:1 wie Prognoses eigenes Energiebilanz-Formular: PVSource +
        // LoadSource statt einer zusammengefassten Energiebilanz-Instanz).
        $this->RegisterPropertyInteger('PVSource', 0);
        $this->RegisterPropertyInteger('LoadSource', 0);
        // Eigene Ist-Leistungsvariablen (Dietmar, 26.08.2026: "hinter dem
        // Doppelpfeil fehlen noch Einheit der Ist-Leistungsvariablen und
        // Archiv-Cache" - das ergibt nur Sinn, wenn wir den gemessenen
        // Verlauf SELBST aus dem Archiv lesen, statt uns auf Prognoses
        // eigene ActualPV/ActualLoad-Konfiguration zu verlassen).
        $this->RegisterPropertyInteger('ActualPV', 0);
        $this->RegisterPropertyInteger('ActualLoad', 0);
        $this->RegisterAttributeString('MeasuredCache', '');

        $this->RegisterAttributeString('ReviewHintDismissed', '0');
        $this->RegisterAttributeString('SeenNews', '');

        // Kein eigener Timer mehr (1:1 wie Prognoses Energiebilanz): wir
        // lesen PVF_Today/LFC_Today & Co. jetzt direkt und reagieren rein
        // ereignisgesteuert auf deren VM_UPDATE (siehe ApplyChanges()),
        // statt archivbasiert alle 5 Minuten zu pollen.

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
            'Days'             => ['Anzuzeigende Tage', 'NRGDASHFC.Days', 20, self::DEF_DAYS],
            'PowerUnit'        => ['Einheit der Ist-Leistungsvariablen', 'NRGDASHFC.PowerUnit', 30, 2],
            'MeasuredCacheSec' => ['Ist-Verlauf neu berechnen alle ... s (Archiv-Cache)', 'NRGDASHFC.CacheSec', 34, 120],
            'ChartEngine'      => ['Diagramm-Engine', 'NRGDASHFC.Engine', 40, 0],
            'ColorPV'          => ['Farbe PV-Erzeugung', '~HexColor', 41, self::DEF_PV],
            'ColorLoad'        => ['Farbe Verbrauch', '~HexColor', 42, self::DEF_LOAD],
            'ColorBackground'  => ['Hintergrundfarbe (falls nicht automatisch)', '~HexColor', 44, 0xFFFFFF],
            'FontFamily'       => ['Schriftart', 'NRGDASHFC.Font', 45, 0],
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

        if (!IPS_VariableProfileExists('NRGDASHFC.PowerUnit')) { IPS_CreateVariableProfile('NRGDASHFC.PowerUnit', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileAssociation('NRGDASHFC.PowerUnit', 0, 'W (Watt)', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.PowerUnit', 1, 'kW (Kilowatt)', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.PowerUnit', 2, 'Automatisch erkennen (Profil/Groessenordnung)', '', -1);

        if (!IPS_VariableProfileExists('NRGDASHFC.CacheSec')) { IPS_CreateVariableProfile('NRGDASHFC.CacheSec', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileValues('NRGDASHFC.CacheSec', 15, 900, 5);
        IPS_SetVariableProfileText('NRGDASHFC.CacheSec', '', ' s');

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
        $this->WriteAttributeString('MeasuredCache', ''); // Cache bei Konfig-Aenderung verwerfen

        // Rein ereignisgesteuert (1:1 Muster Prognoses Energiebilanz::
        // ApplyChanges()): PVF_Today/LFC_Today & Co. der aufgeloesten
        // Quellen sowie die eigenen Ist-Leistungsvariablen live abonnieren.
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg === VM_UPDATE) { $this->UnregisterMessage($senderID, VM_UPDATE); }
            }
        }

        $found = false;
        $pv = $this->ResolveSource(self::SOURCE_PV, 'PVSource');
        if ($pv > 0) {
            foreach (['PVF_Today', 'PVF_Tomorrow', 'PVF_DayAfter', 'PVF_Day3', 'PVF_Day4'] as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $pv);
                if ($vid !== false && $vid > 0) { $this->RegisterReference($vid); $this->RegisterMessage($vid, VM_UPDATE); $found = true; }
            }
        }
        $load = $this->ResolveSource(self::SOURCE_LOAD, 'LoadSource');
        if ($load > 0) {
            foreach (['LFC_Today', 'LFC_Tomorrow', 'LFC_DayAfter', 'LFC_Day3', 'LFC_Day4'] as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $load);
                if ($vid !== false && $vid > 0) { $this->RegisterReference($vid); $this->RegisterMessage($vid, VM_UPDATE); $found = true; }
            }
        }
        foreach (['ActualPV', 'ActualLoad'] as $prop) {
            $vid = $this->ReadPropertyInteger($prop);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterReference($vid);
                $this->RegisterMessage($vid, VM_UPDATE);
            }
        }

        $this->SetStatus($found ? 102 : 104);
        $this->Render();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE) {
            $this->Render();
        }
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
        $intIdents  = ['Days', 'PowerUnit', 'MeasuredCacheSec', 'ChartEngine', 'ColorPV', 'ColorLoad', 'ColorBackground', 'FontFamily'];
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

    /** Property zuerst, sonst Auto-Discovery bei genau einer gefundenen Instanz. */
    private function ResolveSource(string $guid, string $prop): int
    {
        $configured = $this->ReadPropertyInteger($prop);
        if ($configured > 0 && IPS_InstanceExists($configured)) { return $configured; }
        $list = IPS_GetInstanceListByModuleID($guid);
        return (count($list) === 1) ? (int) $list[0] : 0;
    }

    /** Liest die JSON-Prognosevariablen einer Quelle in [Tag => {p10,p50,p90,kwh}|null]. */
    private function ReadSeries(int $src, array $idents, int $limit): array
    {
        $out = [];
        for ($i = 0; $i < $limit; $i++) {
            $out[$i] = null;
            if ($src <= 0) { continue; }
            $vid = @IPS_GetObjectIDByIdent($idents[$i], $src);
            $raw = ($vid !== false && $vid > 0) ? GetValue($vid) : null;
            $fc  = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($fc) || !isset($fc['p50']) || !is_array($fc['p50'])) { continue; }
            $out[$i] = [
                'p10' => array_map('floatval', $fc['p10'] ?? []),
                'p50' => array_map('floatval', $fc['p50']),
                'p90' => array_map('floatval', $fc['p90'] ?? []),
                'kwh' => round((float) ($fc['kwh'] ?? 0), 2),
            ];
        }
        return $out;
    }

    /**
     * Gespeicherten Prognose-Snapshot (Soll) eines Tages als Tag-Struktur.
     * p10=p50=p90 (Snapshot hat nur den Median -> Linie ohne Band).
     */
    private function snapshotToDay(int $src, string $fn, string $date)
    {
        if ($src <= 0 || !function_exists($fn)) { return null; }
        $snap = @$fn($src, $date);
        if (!is_array($snap) || empty($snap['p50']) || !is_array($snap['p50'])) { return null; }
        $p50 = array_map('floatval', $snap['p50']);
        return ['p10' => $p50, 'p50' => $p50, 'p90' => $p50, 'kwh' => round((float) ($snap['kwh'] ?? 0), 2)];
    }

    /** Wochentag (deutsches Kuerzel) + Datum fuer einen Tages-Offset ab heute (0=heute). */
    private function dayLabel(int $offsetDays): string
    {
        $ts = strtotime($offsetDays . ' days');
        $wd = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][((int) date('N', $ts)) - 1];
        return $wd . ' ' . date('d.m.', $ts);
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
    // Datenanbindung: buildFullDaysData() liest PVForecast/LoadForecast
    // direkt und liefert IMMER den vollen Umfang (5-Tage-Horizont inkl.
    // Gestern) - wir schneiden hier lokal nach UNSEREN Doppelpfeil-
    // Einstellungen zurecht (Days/ShowYesterday/ShowActualPV/
    // ShowActualLoad/ShowPV/ShowLoad), analog zu Prognoses eigenem
    // buildDaysData($full=false)-Zweig fuer die eigene Kachel.
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

        return json_encode(array_merge($style, $this->trimToOwnSettings($this->buildFullDaysData())));
    }

    /**
     * Liest PVForecast/LoadForecast direkt (1:1 aus Prognoses
     * buildDaysData($full=true) uebernommen, siehe deren Klassendoku:
     * immer voller 5-Tage-Horizont + Gestern, unabhaengig von unseren
     * eigenen Doppelpfeil-Einstellungen - trimToOwnSettings() schneidet
     * danach zurecht). Ist-Ueberlagerung kommt NICHT von hier, sondern aus
     * unseren eigenen ActualPV/ActualLoad-Variablen (trimToOwnSettings()).
     */
    private function buildFullDaysData(): array
    {
        $pvSrc   = $this->ResolveSource(self::SOURCE_PV, 'PVSource');
        $loadSrc = $this->ResolveSource(self::SOURCE_LOAD, 'LoadSource');

        $limit = self::MAX_OFFSET + 1;
        $pvIdents   = ['PVF_Today', 'PVF_Tomorrow', 'PVF_DayAfter', 'PVF_Day3', 'PVF_Day4'];
        $loadIdents = ['LFC_Today', 'LFC_Tomorrow', 'LFC_DayAfter', 'LFC_Day3', 'LFC_Day4'];
        $pvDays   = $this->ReadSeries($pvSrc,   $pvIdents,   $limit);
        $loadDays = $this->ReadSeries($loadSrc, $loadIdents, $limit);

        $labels = ['heute'];
        for ($i = 1; $i <= self::MAX_OFFSET; $i++) { $labels[] = $this->dayLabel($i) . ' (heute +' . $i . ')'; }

        $days = [];

        // Gestern: Soll aus gespeichertem Snapshot (Ist kommt spaeter aus
        // unseren eigenen ActualPV/ActualLoad-Variablen, trimToOwnSettings()).
        $yDate = date('Y-m-d', strtotime('yesterday'));
        $gpv = $this->snapshotToDay($pvSrc,   'PVF_GetSnapshot', $yDate);
        $glo = $this->snapshotToDay($loadSrc, 'LFC_GetSnapshot', $yDate);
        if ($gpv !== null || $glo !== null) {
            $days[] = ['label' => 'gestern', 'pv' => $gpv, 'load' => $glo,
                       'pvMeas' => null, 'loMeas' => null, 'pvKwhIst' => null, 'loKwhIst' => null];
        }

        $hasData = false;
        for ($i = 0; $i < $limit; $i++) {
            $pv   = $pvDays[$i]   ?? null;
            $load = $loadDays[$i] ?? null;
            if ($pv !== null || $load !== null) { $hasData = true; }
            $days[] = ['label' => $labels[$i], 'pv' => $pv, 'load' => $load,
                       'pvMeas' => null, 'loMeas' => null, 'pvKwhIst' => null, 'loKwhIst' => null];
        }

        return [
            'hasData' => $hasData,
            'message' => $hasData ? '' : 'Keine Prognosedaten',
            'days'    => $days,
        ];
    }

    /**
     * Schneidet den vollen buildFullDaysData()-Datenanteil auf UNSERE
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
        // gibt es keinen separaten Schalter fuer Gestern-Ist). Sind EIGENE
        // Ist-Leistungsvariablen konfiguriert (ActualPV/ActualLoad-
        // Property), werden pvMeas/loMeas/*KwhIst hier durch unsere EIGENE
        // archivbasierte Berechnung ersetzt statt Prognoses mitgelieferte
        // Werte zu uebernehmen - genau dafuer existieren PowerUnit/
        // MeasuredCacheSec bei uns. Ohne eigene Variable bleibt, was der
        // Vertrag mitliefert (graceful fallback, keine Pflichtkonfiguration).
        $pvVar = $this->ReadPropertyInteger('ActualPV');
        $loVar = $this->ReadPropertyInteger('ActualLoad');
        $today = strtotime('today');
        foreach ($out as &$d) {
            $isYesterday = ($d['label'] ?? '') === 'gestern';
            $isToday     = ($d['label'] ?? '') === 'heute';
            if (!$isYesterday && !$isToday) { continue; }
            $start = $isYesterday ? strtotime('yesterday') : $today;

            if ($showPV && $pvVar > 0 && $d['pv'] !== null) {
                $n = count($d['pv']['p50']);
                $m = $this->measuredCached('pv', $pvVar, $n, $start);
                if (is_array($m)) {
                    $d['pvKwhIst'] = $this->sumKwh($m, $n);
                    $d['pvMeas']   = ($isYesterday || $showActualPV) ? $m : null;
                }
            } elseif ($isToday && !$showActualPV) {
                $d['pvMeas'] = null;
            }
            if ($showLoad && $loVar > 0 && $d['load'] !== null) {
                $n = count($d['load']['p50']);
                $m = $this->measuredCached('load', $loVar, $n, $start);
                if (is_array($m)) {
                    $d['loKwhIst'] = $this->sumKwh($m, $n);
                    $d['loMeas']   = ($isYesterday || $showActualLoad) ? $m : null;
                }
            } elseif ($isToday && !$showActualLoad) {
                $d['loMeas'] = null;
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
            'actualPV'   => $showPV   ? $this->actualValue('ActualPV', $pvVar, $data)   : null,
            'actualLoad' => $showLoad ? $this->actualValue('ActualLoad', $loVar, $data) : null,
        ];
    }

    /** Eigene Ist-Leistungsvariable bevorzugt, sonst Prognoses mitgelieferter Momentanwert. */
    private function actualValue(string $prop, int $vid, array $data)
    {
        if ($vid > 0) { return $this->readActual($prop); }
        $key = ($prop === 'ActualPV') ? 'actualPV' : 'actualLoad';
        return $data[$key] ?? null;
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

    // -------------------------------------------------------------------
    // Eigene Ist-Leistungsvariablen: archivbasierte Berechnung (1:1 aus
    // Prognoses Energiebilanz uebernommen, siehe deren PowerUnit/
    // MeasuredCacheSec/measuredCached()/readMeasured()/measuredFine()) -
    // unabhaengig von Prognoses eigener ActualPV/ActualLoad-Konfiguration.
    // -------------------------------------------------------------------

    /** Momentane Leistung (W) einer Ist-Wert-Variablen; null wenn unkonfiguriert. */
    private function readActual(string $prop)
    {
        $vid = $this->ReadPropertyInteger($prop);
        if ($vid <= 0 || !IPS_VariableExists($vid)) { return null; }
        return (float) GetValue($vid) * $this->varPowerFactor($vid);
    }

    /** Faktor zur Umrechnung nach W: 0=W, 1=kW, 2=automatisch je Variable. */
    private function varPowerFactor(int $vid): float
    {
        $mode = (int) $this->GetValue('PowerUnit');
        if ($mode === 0) { return 1.0; }
        if ($mode === 1) { return 1000.0; }
        if (isset($this->unitCache[$vid])) { return $this->unitCache[$vid]; }
        $f = $this->autoPowerFactor($vid);
        $this->unitCache[$vid] = $f;
        return $f;
    }

    /**
     * Automatische Einheiten-Erkennung: 1) Profil-Suffix ("W"/"kW"),
     * 2) Groessenordnung der Tagesmaxima (letzte 7 Tage, < 100 -> kW), 3) W.
     */
    private function autoPowerFactor(int $vid): float
    {
        $v    = IPS_GetVariable($vid);
        $prof = ($v['VariableCustomProfile'] !== '') ? $v['VariableCustomProfile'] : $v['VariableProfile'];
        if ($prof !== '' && IPS_VariableProfileExists($prof)) {
            $suffix = strtolower(trim(IPS_GetVariableProfile($prof)['Suffix']));
            if ($suffix === 'kw') { return 1000.0; }
            if ($suffix === 'w')  { return 1.0; }
            if ($suffix === 'mw') { return 1000000.0; }
        }
        $aid = $this->getArchiveID();
        if ($aid > 0) {
            $rows = @AC_GetAggregatedValues($aid, $vid, 1, strtotime('-7 days'), time(), 0);
            if (is_array($rows) && count($rows) > 0) {
                $max = 0.0;
                foreach ($rows as $r) { $max = max($max, (float) $r['Max']); }
                if ($max > 0 && $max < 100) { return 1000.0; }
            }
        }
        return 1.0;
    }

    /** Ist-Tagessumme (kWh bis jetzt) aus einem Slot-Profil (Ø-W je Slot). */
    private function sumKwh($arr, int $n)
    {
        if (!is_array($arr) || $n <= 0) { return null; }
        $hoursPerSlot = 24.0 / $n;
        $sum = 0.0; $any = false;
        foreach ($arr as $v) { if ($v !== null) { $sum += (float) $v; $any = true; } }
        return $any ? $sum * $hoursPerSlot / 1000.0 : null;
    }

    /**
     * Wie readMeasured(), aber mit Cache: integriert den Ist-Verlauf nur
     * alle MeasuredCacheSec Sekunden neu (Archiv-Zugriff), dazwischen aus
     * dem Attribut. Der "jetzt"-Punkt/Legendenwert (readActual()) bleibt
     * davon unberuehrt (live).
     */
    private function measuredCached(string $key, int $vid, int $slots, int $start)
    {
        $today   = strtotime('today');
        $dateStr = date('Y-m-d', $start);
        $cKey    = $key . '_' . $dateStr;
        // Abgeschlossene Tage aendern sich nicht mehr -> laenger cachen.
        $ttl     = ($start < $today) ? 21600 : max(15, (int) $this->GetValue('MeasuredCacheSec'));

        $cache = json_decode($this->ReadAttributeString('MeasuredCache'), true);
        if (!is_array($cache)) { $cache = []; }

        $e = $cache[$cKey] ?? null;
        if (is_array($e)
            && (int) ($e['vid'] ?? 0) === $vid
            && (int) ($e['slots'] ?? 0) === $slots
            && (time() - (int) ($e['ts'] ?? 0)) < $ttl) {
            return $e['data'];
        }

        $data = $this->readMeasured($vid, $slots, $start);
        $cache[$cKey] = ['ts' => time(), 'vid' => $vid, 'slots' => $slots, 'data' => $data];
        $this->WriteAttributeString('MeasuredCache', json_encode($cache));
        return $data;
    }

    private function readMeasured(int $vid, int $slots, int $start)
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) { return null; }
        $aid = $this->getArchiveID();
        if ($aid === 0) { return null; }

        $f = $this->varPowerFactor($vid);

        // 60 min: stuendliches Aggregat (exakt, leichtgewichtig).
        if ($slots <= 24) {
            // strtotime('+1 day', ...) statt +86400: an DST-Tagen sonst zu
            // kurzes (Oktober) oder in den Folgetag ragendes (Maerz)
            // Abfragefenster (Verbund-DST-Audit, 27.08.2026).
            $rows = AC_GetAggregatedValues($aid, $vid, 0, $start, strtotime('+1 day', $start) - 1, 0);
            if (!is_array($rows) || count($rows) === 0) { return null; }
            $out = array_fill(0, $slots, null);
            foreach ($rows as $r) {
                $h = (int) date('G', $r['TimeStamp']);
                if ($h >= 0 && $h < $slots) { $out[$h] = (float) $r['Avg'] * $f; }
            }
            return $out;
        }

        // 30/15 min: zeitgewichtet aus den Rohwerten (keine Treppenstufen).
        $fine = $this->measuredFine($aid, $vid, $start, $slots);
        if (is_array($fine) && $f !== 1.0) {
            foreach ($fine as $i => $v) { if ($v !== null) { $fine[$i] = $v * $f; } }
        }
        return $fine;
    }

    /**
     * Gemessenes Slot-Profil (Tag bis "jetzt" bzw. voller Tag bei Gestern)
     * zeitgewichtet aus den Rohwerten: jeder geloggte Wert gilt bis zum
     * naechsten Wechsel, Oe-Leistung je Slot = Sum v*dt / Sum dt.
     * Zukuenftige Slots = null.
     */
    private function measuredFine(int $aid, int $vid, int $start, int $slots)
    {
        // strtotime('+1 day', ...) statt +86400: die $slots (z.B. 96 x
        // 15 Min.) sollen den ECHTEN Kalendertag abdecken, der an einem
        // DST-Tag nur 23 oder 25 Stunden hat, nicht pauschal 24
        // (Verbund-DST-Audit, 27.08.2026) - sonst fehlen am Umstellungstag
        // die letzten Slots (Oktober) oder es werden faelschlich schon
        // Slots des Folgetags mitgezaehlt (Maerz).
        $dayEnd  = strtotime('+1 day', $start);
        $until   = min($dayEnd, time());
        $slotSec = ($dayEnd - $start) / $slots;

        $carry = null;
        $pre = AC_GetLoggedValues($aid, $vid, 0, $start - 1, 1);
        if (is_array($pre) && count($pre) > 0) { $carry = (float) $pre[0]['Value']; }

        $rows = AC_GetLoggedValues($aid, $vid, $start, $until, 0);
        if (!is_array($rows)) { $rows = []; }
        usort($rows, function ($a, $b) { return $a['TimeStamp'] <=> $b['TimeStamp']; });

        $points = [];
        $first  = ($carry !== null) ? $carry : (count($rows) > 0 ? (float) $rows[0]['Value'] : null);
        $points[] = ['t' => $start, 'v' => $first];
        foreach ($rows as $r) {
            $t = (int) $r['TimeStamp'];
            if ($t > $start && $t <= $until) { $points[] = ['t' => $t, 'v' => (float) $r['Value']]; }
        }
        if ($first === null && count($points) <= 1) { return null; }

        $sumW = array_fill(0, $slots, 0.0);
        $sumS = array_fill(0, $slots, 0.0);
        $cnt  = count($points);
        for ($p = 0; $p < $cnt; $p++) {
            $v = $points[$p]['v'];
            if ($v === null) { continue; }
            $t0 = $points[$p]['t'];
            $t1 = ($p + 1 < $cnt) ? $points[$p + 1]['t'] : $until;
            while ($t0 < $t1) {
                $slot = (int) (($t0 - $start) / $slotSec);
                if ($slot < 0 || $slot >= $slots) { break; }
                $slotEnd = $start + ($slot + 1) * $slotSec;
                $segEnd  = min($t1, $slotEnd);
                $dur     = $segEnd - $t0;
                $sumW[$slot] += $v * $dur;
                $sumS[$slot] += $dur;
                $t0 = $segEnd;
            }
        }
        $out = array_fill(0, $slots, null);
        for ($s = 0; $s < $slots; $s++) {
            if ($sumS[$s] > 0) { $out[$s] = $sumW[$s] / $sumS[$s]; }
        }
        return $out;
    }

    private function getArchiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return (count($ids) > 0) ? (int) $ids[0] : 0;
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
        $this->SetValue('PowerUnit', 2);
        $this->SetValue('MeasuredCacheSec', 120);
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
