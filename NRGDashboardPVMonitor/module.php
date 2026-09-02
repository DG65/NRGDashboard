<?php

declare(strict_types=1);

/**
 * NRGDashboardPVMonitor - Zeitreihen-Kachel (Phase 3 des NRGDashboard-Verbunds).
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
class NRGDashboardPVMonitor extends IPSModule
{
    private const ARCHIVE_GUID     = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const PVF_GUID         = '{257DD4E8-9705-462E-89FC-56D0A1038353}';
    private const INVERTERHUB_GUID = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';
    private const IHUBMON_GUID     = '{7B1F9A34-6C52-4E8D-9A1B-4F3E2D7C6A19}';
    private const TIBBER_GUID      = '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}';
    private const METERHUB_GUID    = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
    private const LOCATION_GUID    = '{45E97A63-F870-408A-B259-2933F7EABF74}';
    private const EMS_GUID         = '{31C61A7B-28C4-4F97-9651-1A64B3469E3C}';
    private const LFC_GUID         = '{DC5AD508-507F-40EA-8630-0959AED83050}';
    private const STROMGEDACHT_GUID = '{D5A8C3A1-2222-4A55-8888-123456789003}';
    private const AGG_5MIN         = 5;
    private const AGG_DAY          = 1;
    private const WINDOW_DAYS      = 8;
    private const SPAN_YEARS       = 5;
    private const SUN_MARGIN_SEC   = 3600;
    // Sanity-Obergrenze fuer archivierte Leistungswerte (01.09.2026, Fund
    // bei NRGDashboardTile: 261.554.185 W nachts durch einen Modbus-TID-Bug
    // bei InverterHub, hat dort einen Tagesbalken auf 2204,91 kWh statt eines
    // plausiblen Werts gezogen - derselbe Fehlermechanismus betrifft hier
    // DailyEnergyMap()/MonthlyEnergyMap(), da beide unveraendert AC_
    // GetAggregatedValues()-Tages-/Monats-Mittelwerte in kWh hochrechnen).
    // Bewusst KEIN anlagenspezifischer Wert (CLAUDE.md Kernprinzip 2) -
    // 1 MW ist fuer jede denkbare Heim-/Kleingewerbe-PV-Anlage implausibel.
    private const IMPLAUSIBLE_POWER_W = 1_000_000.0;

    /**
     * NACHTRAG 01.09.2026 (Dietmar: "Passt aber immer noch nicht die
     * Anzeige!" - der erste Ausreisser-Fix pruefte nur 'Avg', das verduennt
     * einen einzelnen Ausreisser aber ueber Tag/Monat so stark, dass er
     * unauffaellig unter der Implausibilitaetsgrenze bleibt (261.554.185 W
     * wurden als Tages-Avg zu 91.871 W, als Monats-Avg noch viel kleiner -
     * beides < 1 MW, der Fix griff also gar nicht). 'Max'/'Min' derselben
     * Aggregationszeile enthalten dagegen den tatsaechlichen Rohwert
     * UNVERDUENNT, auch bei grober Aggregation (Monat/Jahr) - das ist die
     * richtige Pruefstelle, nicht 'Avg'.
     */
    private function RowHasImplausiblePower(array $row): bool
    {
        $max = isset($row['Max']) ? abs((float) $row['Max']) : 0.0;
        $min = isset($row['Min']) ? abs((float) $row['Min']) : 0.0;
        return max($max, $min) > self::IMPLAUSIBLE_POWER_W;
    }

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';
    private const DEF_ENGINE     = 'echarts';

    private const GITHUB_URL = 'https://github.com/DG65/NRGDashboard/issues';

    // Verbund-Formularkonvention (EMS/SUITE.md "Einheitliche Formular-Optik",
    // Muster NRGDashboardMap/Topology/Tile) - bislang fehlte hier die Haelfte
    // "Was ist Neu" (nur der GitHub-Hinweis existierte). NEWS_VERSION bei
    // jeder nutzersichtbaren Aenderung erhoehen.
    private const NEWS_VERSION = '0.10.3';
    private const NEWS_ITEMS = [
        'Fix: der Reiterleisten-Pfeil stand im Jahresvergleich zu weit oben (unter der Kachel-Titelzeile) - dort wird die Zeitsteuerungszeile ausgeblendet, auf deren Höhe der Pfeil sonst kalibriert ist. Rückt jetzt korrekt mit.',
        'Fix: der Jahresvergleich verwarf bisher den kompletten Monat, sobald irgendein einzelner Archivwert darin unplausibel war - jetzt fällt nur der einzelne betroffene Tag weg, der Rest des Monats bleibt korrekt erhalten.',
        'Fix: ein einzelner defekter Archivwert (z. B. ein Kommunikationsfehler bei einem Partnermodul in der Größenordnung von Megawatt bei einer Haushaltsanlage) verzerrte bisher Tagesansicht, Jahresvergleich und Energiebilanz - solche unplausiblen Werte werden jetzt verworfen statt in die Darstellung einzufließen.',
        'Neu: Reiter "Tagesplan" zeigt den EMS-Ladeplan (heute + morgen) als Zeitleiste - Betriebsart farbig als Hintergrundband, dazu Strompreis, geplanter Batterie-SOC sowie PV-/Lastprognose (direkt von PVPrognose/Lastprognose, sofern installiert).',
        'Neu: Strompreis-Reiter zeigt beim Netzbezug wahlweise kWh oder Ø Leistung (kW), inkl. gelber Monats-Spitzenwert-Linie.',
        'Neu: Jahresvergleich erlaubt manuelles Nachtragen von Vorjahreswerten ohne Archivhistorie; laufendes Jahr/laufender Monat werden nicht mehr fälschlich hochgerechnet.',
        'Fix: Theme-Beschriftung (Hell/Dunkel) an mehreren Charts korrigiert (Solar/Batterie/Strompreis/Bilanz/Jahresvergleich).',
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('PvPowerID', 0);
        $this->RegisterPropertyInteger('IrradianceID', 0);
        $this->RegisterPropertyInteger('TemperatureID', 0);
        $this->RegisterPropertyFloat('TempCoeff', -0.40);
        $this->RegisterPropertyInteger('PvfInstance', 0);
        $this->RegisterPropertyInteger('BatPowerID', 0);
        $this->RegisterPropertyInteger('GridPowerID', 0);
        $this->RegisterPropertyInteger('SocID', 0);
        $this->RegisterPropertyInteger('Mppt1ID', 0);
        $this->RegisterPropertyInteger('Mppt2ID', 0);
        $this->RegisterPropertyInteger('Mppt3ID', 0);
        $this->RegisterPropertyInteger('Mppt4ID', 0);
        $this->RegisterPropertyInteger('TibberInstance', 0);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);
        $this->RegisterPropertyString('Engine', self::DEF_ENGINE);
        // Manuelle Angabe statt automatischer Erkennung (Dietmar,
        // 31.07.2026: Recherche ergab, dass Symcon einer Kachel keinen
        // Weg anbietet, das aktuelle Hell/Dunkel-Theme der Oberflaeche zu
        // erkennen - weder CSS-Variable/Klasse noch postMessage/
        // Query-Parameter, prefers-color-scheme spiegelt nur die
        // Betriebssystem-Einstellung, nicht Symcons eigenen Umschalter).
        $this->RegisterPropertyBoolean('LightTheme', false);

        $this->RegisterAttributeString('ReviewHintDismissed', '0');
        $this->RegisterAttributeString('SeenNews', '');
        // Jahresvergleich-Konfiguration (Dietmar, 31.07.2026): spezifischer
        // erwarteter Jahresertrag + Anlagenleistung (auto aus Prognose ODER
        // manuell) + 12 Monatsanteile in % - siehe YearCompareConfig().
        $this->RegisterAttributeString('YearCompareConfig', '{}');
        // 15-Minuten-Cache der PV-Prognose fuer die Sonnen-Tattoos
        // (PvfSunForecast()).
        $this->RegisterAttributeString('PvfSunCache', '');
        // Manuell nachgetragene Vorjahreswerte (Dietmar, 05.08.2026): Monate
        // vor Inbetriebnahme des Archivs/der Anlage haben keine Zaehler-
        // historie - hier lassen sich pro Jahr/Monat feste kWh-Werte
        // eintragen, die BuildYearCompare() nur dort einsetzt, wo das
        // Archiv selbst keinen Wert liefert (echte Messwerte haben immer
        // Vorrang). Format: {"2025": {"1": 436.0, "2": 464.94, ...}}.
        $this->RegisterAttributeString('ManualHistory', '{}');
        // Einfuehrungs-Tour bei erster Benutzung (29.08.2026, Dietmar:
        // "eine Tour die bei der ersten Benutzung eingeblendet und nur per
        // Haken ausgeblendet werden kann") - je Instanz einmalig, WebFront-
        // seitig. Bestaetigung kommt ueber den WebHook zurueck
        // (ProcessHookData(), ?dismissTour=1) - die Kachel selbst hat als
        // sandboxed HTML-SDK-Tile keinen anderen Rueckkanal in die Instanz.
        $this->RegisterAttributeBoolean('TourSeen', false);

        // Animationsstil der einklappbaren Reiterleiste, waehlbar hinter
        // dem Doppelpfeil (Dietmar, 27.08.2026: "Mache doch alle 4 und
        // baue die Auswahl hinter den Doppelpfeil") - echte Instanz-
        // Variable mit EnableAction statt Formular-Property (SUITE.md
        // Punkt 10, Muster HeatSchema/Forecast). Default nur bei ECHTER
        // Neuanlage setzen: Create() laeuft bei jedem Symcon-Neustart
        // erneut, ein unbedingtes SetValue() wuerde die Wahl sonst bei
        // jedem Neustart zuruecksetzen (CometWiFi-Fund, 16.08.2026).
        if (!IPS_VariableProfileExists('NRGDASHMON.TabAnim')) {
            IPS_CreateVariableProfile('NRGDASHMON.TabAnim', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation('NRGDASHMON.TabAnim', 0, 'Federnder Einschub', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHMON.TabAnim', 1, '3D-Kaskaden-Flip', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHMON.TabAnim', 2, 'Ecke für Ecke in den Pfeil', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHMON.TabAnim', 3, 'Blur-Morph', '', -1);
        $animIsNew = @IPS_GetObjectIDByIdent('TabAnimation', $this->InstanceID) === false;
        $this->RegisterVariableInteger('TabAnimation', 'Reiterleisten-Animation', 'NRGDASHMON.TabAnim', 10);
        $this->EnableAction('TabAnimation');
        if ($animIsNew) {
            $this->SetValue('TabAnimation', 0);
        }

        $this->RegisterTimer('Refresh', 0, 'NRGDASHPVMON_Render($_IPS[\'TARGET\']);');
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
        // Standalone-Webseite fuer IPSView/Browser (WebView/Popup) - Muster
        // Prognoses Energiebilanz-Modul (Dietmar, 27.08.2026: "alle Kacheln
        // ... auch so vorbereiten, dass man sie in IPSView einbinden kann").
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->RegisterHook('/hook/nrgdashpvmonitor' . $this->InstanceID);
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

    /** WebHook beim WebHook-Control registrieren (Standard-Muster, 1:1 aus Prognoses Energiebilanz). */
    private function RegisterHook(string $WebHook): void
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        foreach ($hooks as $index => $hook) {
            if ($hook['Hook'] === $WebHook) {
                if ((int) $hook['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$index]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    /**
     * Liefert die Kachel als eigenstaendige Webseite (fuer IPSView-WebView/
     * Popup oder jeden Browser). Aufruf: /hook/nrgdashpvmonitor<InstanzID>.
     * Mit ?json=1 werden nur die Daten geliefert (fuer die Auto-Aktualisierung).
     */
    public function ProcessHookData()
    {
        // Einfuehrungs-Tour bestaetigt (29.08.2026) - vom Tour-Overlay in
        // module.html per fetch() aufgerufen, siehe Create()/dismissTour().
        if (isset($_GET['dismissTour'])) {
            $this->WriteAttributeBoolean('TourSeen', true);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            return;
        }
        if (isset($_GET['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->buildPayload());
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        $html = file_get_contents(__DIR__ . '/module.html');
        $html = str_replace('/*__ECHARTS_JS__*/', '', $html);
        $html .= '<script>handleMessage(' . json_encode($this->buildPayload()) . ');'
               . 'setInterval(function(){fetch(window.location.pathname+"?json=1")'
               . '.then(function(r){return r.text();}).then(function(t){handleMessage(t);})'
               . '.catch(function(){});},30000);</script>';
        echo $html;
    }

    public function GetConfigurationForm()
    {
        $raw = str_replace('%%HOOK%%', '/hook/nrgdashpvmonitor' . $this->InstanceID, file_get_contents(__DIR__ . '/form.json'));
        $form = json_decode($raw, true);
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
                    ['type' => 'Label', 'caption' => '🧪 NRG Dashboard Monitoring ist Beta — Rückmeldungen sind willkommen:'],
                    ['type' => 'Label', 'link' => true, 'caption' => self::GITHUB_URL],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'NRGDASHPVMON_DismissReviewHint($id);'],
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
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'NRGDASHPVMON_AckNews($id);'];
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

    /** Konsolen-Gegenstueck zur WebFront-Dismiss-Tour. */
    public function ResetTour(): void
    {
        $this->WriteAttributeBoolean('TourSeen', false);
    }

    private function readIntProperty(string $name, int $default): int
    {
        $v = @$this->ReadPropertyInteger($name);
        return is_int($v) ? $v : $default;
    }

    /**
     * Rekursive Ident-Suche unterhalb einer Instanz - IPS_GetObjectIDByIdent
     * findet nur DIREKTE Kinder. InverterHubs Treiber verschiebt seine
     * Variablen aber sofort nach der Anlage in fachliche Unterkategorien
     * (hier z.B. "PV / MPPT") - live bestaetigt (29.07.2026): mppt1_power
     * usw. liegen NICHT direkt an der Instanz, sondern in dieser
     * Unterkategorie. Ohne Rekursion faende IPS_GetObjectIDByIdent sie nie.
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

    private function GridPowerID(): int
    {
        $explicit = $this->readIntProperty('GridPowerID', 0);
        if ($explicit > 0 && IPS_VariableExists($explicit)) {
            return $explicit;
        }
        $ihub = $this->singleInverterHubID();
        if ($ihub <= 0) {
            return 0;
        }
        $data = @IHUB_GetFunctions($ihub);
        $grid = (int) ($data['gridPowerID'] ?? 0);
        return ($grid > 0 && IPS_VariableExists($grid)) ? $grid : 0;
    }

    /**
     * Vorzeichen-Multiplikator, um eine Netzleistungs-Variable in unsere
     * kanonische Konvention "+ = Einspeisung" zu lesen - Fund der MeterHub-
     * Sitzung, 23.08.2026: MeterHub zaehlt Netzleistung modulweit als
     * "+ = Bezug" (MeterHub/README.md, Zeile 166; MeterHub/CLAUDE.md,
     * Punkt 2 - GENAU umgekehrt zu unserer eigenen Konvention). Dietmars
     * manueller GridPowerID-Override zeigt auf MeterHubs "power_total"
     * (Inexogy), wurde bisher aber ungeprueft im "+Einspeisung"-Sinn
     * gelesen - Netzbezug und Einspeisung erschienen dadurch im
     * Strompreis-Reiter vertauscht. Statt eines manuellen Vorzeichen-Flags
     * wird die Herkunft automatisch erkannt: entspricht $vid der powerID
     * einer MeterHub-"grid"-Zuordnung (MHUB_GetFunctions()), ist das
     * Vorzeichen -1, sonst (IHUB-Pfad oder eine andere manuelle
     * Verknuepfung) unveraendert +1 - funktioniert unabhaengig davon, ob
     * $vid automatisch oder manuell (Property GridPowerID) verknuepft
     * wurde, ohne dass Dietmar am Formular etwas aendern muss.
     */
    private function GridPowerSign(int $vid): int
    {
        if ($vid <= 0) {
            return 1;
        }
        foreach ($this->MeterHubAssignments() as $a) {
            if (($a['function'] ?? '') === 'grid' && (int) ($a['powerID'] ?? 0) === $vid) {
                return -1;
            }
        }
        return 1;
    }

    /**
     * Unix-Zeitstempel des letzten Lastgang-Datensatzes, fuer den der
     * Netzzaehler ALLE drei Zielvariablen archiviert hat (MeterHub-Feld
     * "archiveWatermarkTs", MHUB_GetFunctions() contractVersion 1.2) - nur
     * bei verzoegert archivierenden Zaehlern gesetzt ("latency"==="delayed",
     * bei Dietmar Inexogy), sonst null (Echtzeit-Zaehler brauchen keine
     * Anzeige, siehe MeterHub-Sitzung 27.08.2026).
     */
    private function GridArchiveWatermarkTs(): ?int
    {
        foreach ($this->MeterHubAssignments() as $a) {
            if (($a['function'] ?? '') !== 'grid' || ($a['latency'] ?? '') !== 'delayed') {
                continue;
            }
            $ts = $a['archiveWatermarkTs'] ?? null;
            return is_numeric($ts) ? (int) $ts : null;
        }
        return null;
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
        // Direkter Ident-Zugriff auf die InverterHub-KERNINSTANZ (nicht
        // InverterHubMonitor) - deren Treiber legt die MPPT-Straenge als
        // eigene Variablen mppt1_power../mppt4_power an, allerdings NICHT
        // direkt an der Instanz, sondern in einer fachlichen Unterkategorie
        // ("PV / MPPT") - deshalb rekursive Suche (FindVarByIdent), ein
        // einfaches IPS_GetObjectIDByIdent faende nur direkte Kinder (live
        // bestaetigt, 29.07.2026, mit der InverterHub-Sitzung abgestimmt).
        // Dietmar wollte InverterHubMonitor/-Energy NICHT als zusaetzliche,
        // zu unserer Kachel redundante Anzeige-Instanzen behalten - dieser
        // Weg braucht nur die ohnehin vorhandene WR-Instanz, kein
        // Diagnose-Zwischenmodul mehr.
        $ihub = $this->singleInverterHubID();
        if ($ihub > 0) {
            for ($i = 1; $i <= 4; $i++) {
                $vid = $this->FindVarByIdent($ihub, 'mppt' . $i . '_power');
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $out[(string) $i] = $vid;
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
     * VORWAERTS gerichteter Verlauf (deckt laut Tibber Grid Rewards immer
     * den vollen heutigen Tag von 0 Uhr an ab, plus den Folgetag sobald
     * dessen Preise veroeffentlicht sind - kein reiner "ab jetzt"-Ausschnitt).
     * Fuer Tage VOR heute reicht dieser Vertrag nicht, siehe PriceDaySlots().
     */
    private function PriceCurve(): array
    {
        $id = $this->TibberInstanceID();
        if ($id <= 0 || !function_exists('TIBBERGR_GetPriceCurve')) {
            return [];
        }
        // try/catch (31.08.2026, Anlass: Tibber-eigener Fatal Error in
        // GetPriceApiToken()) - @ unterdrueckt nur Warnings/Notices, keinen
        // Fatal Error/uncaught Throwable AUS der aufgerufenen Funktion
        // selbst. Ohne diesen Schutz reisst ein Absturz bei Tibber unsere
        // gesamte Seite mit, statt dass der Strompreis-Reiter einfach leer
        // bleibt (Muster: runPartnerCall() in NRGDashboardTile).
        try {
            $curve = @TIBBERGR_GetPriceCurve($id);
        } catch (\Throwable $e) {
            return [];
        }
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

    /**
     * Strompreis-Stufenverlauf fuer EINEN Kalendertag, als [[startMs, endMs,
     * price],...] - Dietmars Wunsch (28.07.2026): der Strompreis-Reiter soll
     * wie die anderen Reiter Tage rueckwaerts navigieren koennen, nicht nur
     * die aktuelle Vorwaertskurve zeigen.
     *
     * Heute/morgen: aus PriceCurve() (TIBBERGR_GetPriceCurve, deckt den
     * vollen Tag ab) auf das angefragte Fenster zugeschnitten.
     *
     * Tage VOR heute: PriceCurve() kennt sie nicht mehr (reiner Vorwaerts-
     * Vertrag) - stattdessen aus der ARCHIVIERTEN Statusvariable "CurrentPrice"
     * der Tibber Grid Rewards-Instanz rekonstruiert (dort archiviert Tibber
     * Grid Rewards selbst jeden Slot-Wechsel, siehe deren
     * TibberGridReward::ApplyCurrentPriceSlot()). AC_GetLoggedValues liefert
     * die rohen Aenderungszeitpunkte (kein Aggregat) - daraus werden analog
     * priceStepPoints() im Frontend Stufen gebaut: jeder geloggte Wert gilt
     * bis zum naechsten geloggten Zeitpunkt bzw. bis Tagesende. Der Zustand
     * VOR Tagesbeginn (fuer die erste Stufe) kommt aus dem letzten Log-Wert
     * vor $dayStart (bis zu 7 Tage zurueckgesucht - Grid Rewards aktualisiert
     * mindestens stuendlich, ein leerer 7-Tage-Rueckblick bedeutet also
     * plausibel "keine Archivdaten", nicht nur "seltener Wechsel").
     *
     * ZWEI mit echten Daten gefundene Fallstricke (Dietmar, 29.07.2026:
     * "Gestern sieht anders aus als heute"):
     * 1. "CurrentPrice" speichert EUR/kWh (TibberGridReward::
     *    ApplyCurrentPriceSlot() rechnet den Slot-Preis /100), waehrend
     *    PriceCurve()/TIBBERGR_GetPriceCurve() bereits in ct/kWh liefert -
     *    ohne *100 waren rekonstruierte Tage um den Faktor 100 zu klein.
     * 2. Die Archiv-Log-Zeile aendert sich nicht nur bei echtem Slot-Wechsel,
     *    sondern auch durch wiederholtes Schreiben desselben Werts mit
     *    minimaler Gleitkomma-Abweichung (z.B. 0,1800/0,1801/0,1861 EUR
     *    innerhalb weniger Minuten) - jede Log-Zeile ungefiltert als neue
     *    Stufe zu behandeln erzeugte dadurch viele winzige, falsche
     *    Zwischenstufen statt sauberer Stunden-/Viertelstunden-Bloecke.
     *    Aufeinanderfolgende Werte, die sich um weniger als 0,1 ct/kWh
     *    unterscheiden, gelten deshalb als derselbe Preis (kein Stufenwechsel).
     */
    /**
     * Gleiche Aufloesung fuer JEDEN Tag erzwingen - GENAU 96 Viertelstunden-
     * Buckets, unabhaengig davon, ob die Quelle stuendliche Vorwaerts-Slots
     * (PriceCurve()) oder unregelmaessig lange rekonstruierte Bloecke
     * (Archiv-Reihe vergangener Tage) liefert. Dietmars Fund (29.07.2026):
     * "Heute" fuehlte sich im Tooltip richtig an, andere Tage nicht - eine
     * Zeitraum-Suche im Frontend (priceSlotAt()) hat das nur pro Hover
     * repariert, statt die eigentliche Ursache zu beseitigen: Strompreis und
     * Netzbezug lagen auf UNTERSCHIEDLICH feinen Zeitrastern. Mit exakt
     * demselben 15-Minuten-Raster wie SlotEnergyBars() verhaelt sich jeder
     * Tag identisch zu "Heute", ganz ohne Sonderfall-Code.
     */
    private function ResampleTo15Min(array $slots, int $dayStart, int $dayEnd): array
    {
        $out = [];
        for ($bucketStart = $dayStart; $bucketStart < $dayEnd; $bucketStart += 900) {
            $bucketEnd = $bucketStart + 900;
            $center = $bucketStart + 450;
            $price = null;
            foreach ($slots as $slot) {
                if ($center >= $slot[0] && $center < $slot[1]) {
                    $price = $slot[2];
                    break;
                }
            }
            if ($price !== null) {
                $out[] = [$bucketStart * 1000, $bucketEnd * 1000, $price];
            }
        }
        return $out;
    }

    private function PriceDaySlots(int $dayStart): array
    {
        // strtotime('+1 day', ...) statt +86400: an DST-Tagen (23h im
        // Maerz, 25h im Oktober) sonst 96 Slots, die nicht wirklich
        // Mitternacht-zu-Mitternacht abdecken (Verbund-DST-Audit, 27.08.2026).
        $dayEnd = strtotime('+1 day', $dayStart);
        if ($dayStart >= strtotime('today')) {
            $raw = [];
            foreach ($this->PriceCurve() as $slot) {
                $s = intdiv($slot[0], 1000);
                $e = intdiv($slot[1], 1000);
                if ($e > $dayStart && $s < $dayEnd) {
                    $raw[] = [max($s, $dayStart), min($e, $dayEnd), $slot[2]];
                }
            }
            return $this->ResampleTo15Min($raw, $dayStart, $dayEnd);
        }

        $tid = $this->TibberInstanceID();
        $aid = $this->ArchiveID();
        if ($tid <= 0 || $aid <= 0) {
            return [];
        }
        $vid = @IPS_GetObjectIDByIdent('CurrentPrice', $tid);
        if (!$vid || !IPS_VariableExists($vid) || !@AC_GetLoggingStatus($aid, $vid)) {
            return [];
        }

        // *100: CurrentPrice speichert EUR/kWh, unser Vertrag (wie
        // PriceCurve()) ist ct/kWh.
        $before = @AC_GetLoggedValues($aid, $vid, $dayStart - 7 * 86400, $dayStart, 1);
        $curVal = (is_array($before) && count($before) > 0) ? (float) $before[0]['Value'] * 100 : null;

        $rows = @AC_GetLoggedValues($aid, $vid, $dayStart, $dayEnd, 0);
        if (!is_array($rows)) {
            $rows = [];
        }
        usort($rows, function ($a, $b) { return (int) $a['TimeStamp'] <=> (int) $b['TimeStamp']; });

        $out = [];
        $curTs = $dayStart;
        foreach ($rows as $row) {
            $ts = (int) $row['TimeStamp'];
            $newVal = (float) $row['Value'] * 100;
            // Gleitkomma-Nachschreiben desselben Preises (z.B. wiederholtes
            // Schreiben von 18,00/18,01 ct/kWh) ist KEIN echter Slot-Wechsel -
            // ohne diese Toleranz entstehen viele winzige Falschstufen statt
            // sauberer Stunden-/Viertelstunden-Bloecke.
            if ($curVal !== null && abs($newVal - $curVal) < 0.1) {
                continue;
            }
            // Zu kurze Stufe (z.B. der "vor Tagesbeginn"-Ruecklauf-Wert,
            // Sekunden spaeter durch den ersten echten Log-Eintrag des Tages
            // ueberschrieben) erzeugt eine winzige Zeitluecke im Diagramm -
            // live gefunden (Dietmar 29.07.2026): ein 1-Sekunden-Slot um
            // Mitternacht liess ECharts'/Highcharts' automatische
            // Balkenbreiten-Berechnung fuer den Netzbezug (teilt sich
            // dieselbe Zeitachse) fuer den GESAMTEN Tag sichtbar schrumpfen.
            // Ein zu frueher Wechsel wird deshalb uebersprungen - der neue
            // Wert gilt einfach rueckwirkend als Fortsetzung der laufenden
            // Stufe, statt eine eigene (zu kurze) Stufe zu eroeffnen.
            if ($curVal !== null && ($ts - $curTs) < 60) {
                $curVal = $newVal;
                continue;
            }
            if ($curVal !== null) {
                $out[] = [$curTs, $ts, round($curVal, 2)];
            }
            $curTs = $ts;
            $curVal = $newVal;
        }
        if ($curVal !== null) {
            $out[] = [$curTs, $dayEnd, round($curVal, 2)];
        }
        return $this->ResampleTo15Min($out, $dayStart, $dayEnd);
    }

    /**
     * Jahresvergleich (SMA-Sunny-Portal-Vorbild, Dietmar, 31.07.2026): ein
     * Archivdurchlauf ueber die GESAMTE verfuegbare Historie (nicht auf
     * SPAN_YEARS/5 Jahre begrenzt wie DailyEnergyMap - hier soll bewusst
     * "seit Inbetriebnahme" verglichen werden koennen).
     *
     * GEAENDERT 01.09.2026 (Dietmar: "der August scheint keinen Wert mehr zu
     * haben. Und in der Demo geht 2025 erst ab September los. Ich moechte
     * aber auch dort 2025 komplett."): urspruenglich lief das ueber
     * Monats-Aggregation (level 3) UND verwarf beim Ausreisser-Fix den
     * KOMPLETTEN Monat, sobald irgendein einzelner archivierter Wert darin
     * implausibel war. Bei InverterHubs (mittlerweile behobenem) Modbus-
     * TID-Bug betraf das offenbar fast jeden Monat seit Inbetriebnahme -
     * die historischen Archivwerte bleiben trotz Fix fehlerhaft, deshalb
     * fehlte praktisch das ganze Jahr 2025. Jetzt: TAGES-Aggregation
     * (level 1) ueber dieselbe volle Historie in EINEM Archivdurchlauf,
     * nur der einzelne betroffene TAG wird verworfen und zu Monaten
     * aufsummiert - alle anderen Tage bleiben erhalten. Macht die vorherige
     * "tatsaechlich abgedeckte Stunden"-Hochrechnung (Fund 05.08.2026)
     * ueberfluessig: ein noch laufender Monat hat im Archiv ohnehin nur
     * Zeilen fuer die bereits vergangenen Tage, die Summe stimmt automatisch.
     */
    private function MonthlyEnergyMap(int $aid, int $vid, int $start, int $end): array
    {
        if ($vid <= 0 || !IPS_VariableExists($vid) || !@AC_GetLoggingStatus($aid, $vid)) {
            return [];
        }
        $data = @AC_GetAggregatedValues($aid, $vid, self::AGG_DAY, $start, $end, 0);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $row) {
            if (!isset($row['Avg'])) {
                continue;
            }
            if ($this->RowHasImplausiblePower($row)) {
                $this->SendDebug(
                    __FUNCTION__,
                    sprintf('Unplausibler Archivwert verworfen: Variable #%d, %s, Max=%.0f W', $vid, date('Y-m-d', (int) $row['TimeStamp']), (float) ($row['Max'] ?? 0)),
                    0
                );
                continue;
            }
            $kwh = ((float) $row['Avg']) * 24.0 / 1000.0;
            if (!is_finite($kwh) || $kwh < 0) {
                continue;
            }
            $ym = date('Y-n', (int) $row['TimeStamp']);
            $out[$ym] = ($out[$ym] ?? 0.0) + $kwh;
        }
        foreach ($out as $ym => $v) {
            $out[$ym] = round($v, 2);
        }
        return $out;
    }

    /**
     * Konfiguration fuer die "erwartete Leistung" im Jahresvergleich
     * (Dietmar, 31.07.2026: "Wie sieht es mit der Prognostizierten Leistung
     * aus?" - Prognose hat dafuer noch kein Konzept, siehe Recherche vom
     * selben Tag, deshalb bewusst zurueck in Monitor statt auf ein neues
     * Prognose-Feature zu warten). Attribut statt Property (Muster:
     * ReviewHintDismissed) - wird ueber einen eigenen Dialog IN der
     * Kachel gespeichert, nicht ueber die Instanz-Konfigurationsform.
     */
    private function YearCompareConfig(): array
    {
        $raw = $this->ReadAttributeString('YearCompareConfig');
        $cfg = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $cfg['specificYield'] = (float) ($cfg['specificYield'] ?? 0);
        $cfg['kwp'] = (float) ($cfg['kwp'] ?? 0);
        $cfg['useAutoKwp'] = (bool) ($cfg['useAutoKwp'] ?? true);
        $pct = is_array($cfg['monthlyPct'] ?? null) ? $cfg['monthlyPct'] : [];
        $cfg['monthlyPct'] = array_map(function ($i) use ($pct) {
            return (float) ($pct[$i] ?? 0);
        }, range(0, 11));
        return $cfg;
    }

    /**
     * Manuell nachgetragene Vorjahreswerte, siehe RegisterAttributeString()
     * in Create(). Rueckgabe: [Jahr(string) => [Monat 1-12(int) => kWh]].
     */
    private function ManualHistory(): array
    {
        $raw = $this->ReadAttributeString('ManualHistory');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $year => $months) {
            if (!is_array($months)) {
                continue;
            }
            $row = [];
            foreach ($months as $m => $kwh) {
                $m = (int) $m;
                if ($m >= 1 && $m <= 12 && is_numeric($kwh)) {
                    $row[$m] = (float) $kwh;
                }
            }
            if (count($row) > 0) {
                $out[(string) (int) $year] = $row;
            }
        }
        return $out;
    }

    private function SaveManualHistory(array $req): array
    {
        $year = (string) (int) ($req['year'] ?? 0);
        $months = is_array($req['months'] ?? null) ? $req['months'] : [];
        $history = $this->ManualHistory();
        if ($year === '0') {
            return $history;
        }
        $row = [];
        foreach (range(1, 12) as $m) {
            $v = $months[(string) $m] ?? $months[$m] ?? null;
            if ($v !== null && $v !== '' && is_numeric($v)) {
                $row[(string) $m] = round((float) $v, 2);
            }
        }
        if (count($row) > 0) {
            $history[$year] = $row;
        } else {
            unset($history[$year]);
        }
        $this->WriteAttributeString('ManualHistory', json_encode($history));
        return $this->ManualHistory();
    }

    private function SaveYearCompareConfig(array $req): array
    {
        $cfg = [
            'specificYield' => (float) ($req['specificYield'] ?? 0),
            'kwp'           => (float) ($req['kwp'] ?? 0),
            'useAutoKwp'    => (bool) ($req['useAutoKwp'] ?? true),
            'monthlyPct'    => array_map(function ($i) use ($req) {
                $p = is_array($req['monthlyPct'] ?? null) ? $req['monthlyPct'] : [];
                return (float) ($p[$i] ?? 0);
            }, range(0, 11)),
        ];
        $this->WriteAttributeString('YearCompareConfig', json_encode($cfg));
        return $cfg;
    }

    /**
     * kWh/kWp ("Spezifischer Anlagenertrag") braucht die Anlagenleistung -
     * bevorzugt aus dem Prognose-Modul (PVF_GetGenerators()), sonst aus der
     * manuellen Eingabe im Dialog ("useAutoKwp" schaltet um, nur anwaehlbar
     * wenn eine Prognose-Instanz gefunden wurde - Dietmar, 31.07.2026: "was
     * machen wir, wenn ein Nutzer die Prognose nicht installiert hat?").
     */
    private function BuildYearCompare(?array $cfg = null): array
    {
        $cfg = $cfg ?? $this->YearCompareConfig();
        $aid = $this->ArchiveID();
        $pvID = $this->PvPowerID();
        $end = time();
        // 20 Jahre zurueck ist grosszuegig genug fuer "seit Inbetriebnahme"
        // bei jeder realistischen PV-Anlage, ohne bei jedem Aufruf erst die
        // tatsaechlich fruehste Logzeile separat ermitteln zu muessen.
        $start = strtotime('-20 years', $end);
        $monthly = ($aid > 0) ? $this->MonthlyEnergyMap($aid, $pvID, $start, $end) : [];

        $years = [];
        foreach (array_keys($monthly) as $ym) {
            $y = (int) explode('-', $ym)[0];
            if (!in_array($y, $years, true)) {
                $years[] = $y;
            }
        }
        sort($years);

        $data = [];
        foreach ($years as $y) {
            $row = [];
            for ($m = 1; $m <= 12; $m++) {
                $row[] = $monthly[$y . '-' . $m] ?? null;
            }
            $data[(string) $y] = $row;
        }

        // Manuell nachgetragene Vorjahreswerte einmischen - nur dort, wo das
        // Archiv selbst KEINEN Wert liefert (echte Messwerte haben immer
        // Vorrang, ein manueller Nachtrag ueberschreibt nie eine reale
        // Zaehlerablesung). Ergaenzt bei Bedarf auch komplett neue Jahre
        // (z.B. die Zeit vor Inbetriebnahme des Archivs).
        foreach ($this->ManualHistory() as $y => $months) {
            if (!isset($data[$y])) {
                $data[$y] = array_fill(0, 12, null);
                if (!in_array((int) $y, $years, true)) {
                    $years[] = (int) $y;
                }
            }
            foreach ($months as $m => $kwh) {
                if ($data[$y][$m - 1] === null) {
                    $data[$y][$m - 1] = $kwh;
                }
            }
        }
        sort($years);

        // Laufendes Jahr NICHT in den Mittelwert einrechnen (Dietmar,
        // 05.08.2026: bei Monaten ohne jegliche Vorjahres-Historie - z.B.
        // kurz nach Inbetriebnahme - waere der "Mittelwert" sonst trivial
        // identisch mit dem laufenden Jahr selbst, weil es der einzige
        // eingerechnete Wert ist. Der Mittelwert soll ein Vergleichsmaßstab
        // aus abgeschlossenen Jahren sein, nicht teilweise sich selbst
        // enthalten.
        $currentYear = (int) date('Y', $end);
        $mittelwert = [];
        for ($m = 0; $m < 12; $m++) {
            $sum = 0.0;
            $n = 0;
            foreach ($data as $y => $row) {
                if ((int) $y === $currentYear) {
                    continue;
                }
                if ($row[$m] !== null) {
                    $sum += $row[$m];
                    $n++;
                }
            }
            $mittelwert[] = $n > 0 ? round($sum / $n, 2) : null;
        }

        $model = $this->PvfModel();
        $autoKwp = ($model !== null) ? (float) ($model['totalKwp'] ?? 0) : 0.0;
        $kwp = ($cfg['useAutoKwp'] && $autoKwp > 0) ? $autoKwp : $cfg['kwp'];

        // Erwartete Monatswerte = spezifischer Jahresertrag (kWh/kWp) x kWp x
        // Monatsanteil (%) - EINFACHE, unveraendert vom Nutzer eingegebene
        // Zielkurve (kein Wettermodell), analog zum SMA-Dialog. Nur wenn
        // beide Faktoren (kWp UND spezifischer Ertrag) tatsaechlich gesetzt
        // sind, sonst bleibt 'expected' leer statt einer Nulllinie.
        $expected = null;
        $expectedTotal = $cfg['specificYield'] * $kwp;
        if ($kwp > 0 && $cfg['specificYield'] > 0) {
            $expected = array_map(function ($pct) use ($expectedTotal) {
                return round($expectedTotal * $pct / 100.0, 2);
            }, $cfg['monthlyPct']);
        }

        return [
            'years'      => $years,
            'data'       => $data,
            'mittelwert' => $mittelwert,
            'kwp'        => $kwp > 0 ? $kwp : null,
            'autoKwp'    => $autoKwp > 0 ? $autoKwp : null,
            'expected'   => $expected,
            'config'     => $cfg,
            // Fuers Nachtrags-Dialog (Vorbelegung beim erneuten Bearbeiten
            // eines bereits nachgetragenen Jahres) - NICHT dasselbe wie
            // 'data', das enthaelt bereits die Archiv-Vorrang-Mischung.
            'manualHistory' => $this->ManualHistory(),
        ];
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

    private function EmsInstanceID(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(self::EMS_GUID);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    private function LfcInstanceID(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(self::LFC_GUID);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    private function StromGedachtInstanceID(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(self::STROMGEDACHT_GUID);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    // Farben/Namen je StromGedacht-Ampelzustand - 1:1 aus der StromGedacht-
    // Kachel uebernommen (Verbund-Abstimmung, 21.08.2026, fuer optische
    // Konsistenz). Zustand 2 (Gelb) ist laut StromGedacht veraltet und
    // kommt praktisch nicht mehr vor, bleibt aber definiert statt zu fehlen.
    private const AMPEL_COLORS = [
        -1 => ['name' => 'Supergrün — bevorzugt nutzen', 'color' => '#00BFA5'],
        1  => ['name' => 'Grün — wie gewohnt',            'color' => '#00C853'],
        2  => ['name' => 'Gelb',                          'color' => '#FFD600'],
        3  => ['name' => 'Orange — reduzieren',           'color' => '#FF6D00'],
        4  => ['name' => 'Rot — vermeiden',                'color' => '#D50000'],
    ];

    /**
     * StromGedacht-Netzampel-Vorschau (Dietmar, 21.08.2026, relayed ueber
     * die StromGedacht-Sitzung) - SGW_GetForecast() liefert IMMER alle
     * aktivierten Quellen gemischt, hier auf source==='stromgedacht'
     * gefiltert (Vertrag SGW_GetForecast, contractVersion 1.0). Horizont
     * fest 48h ab jetzt (StromGedachts eigene API-Grenze, nicht
     * veraenderbar). Lazy geladen wie BuildDayPlan() - kein Teil des
     * Haupt-Payloads.
     */
    private function BuildStromGedacht(): array
    {
        $id = $this->StromGedachtInstanceID();
        if ($id <= 0 || !function_exists('SGW_GetForecast')) {
            return ['hasStromGedacht' => false, 'segments' => []];
        }
        $start = time();
        $end = $start + 48 * 3600;
        $raw = @SGW_GetForecast($id, $start, $end);
        $segments = [];
        if (is_array($raw)) {
            foreach ($raw as $e) {
                if (($e['source'] ?? '') !== 'stromgedacht') {
                    continue;
                }
                $segments[] = [
                    'from'  => (int) ($e['from'] ?? 0) * 1000,
                    'to'    => (int) ($e['to'] ?? 0) * 1000,
                    'value' => (int) ($e['value'] ?? 1),
                ];
            }
        }
        return ['hasStromGedacht' => true, 'segments' => $segments];
    }

    // Farben/Namen je EMS_OP_*-Konstante - 1:1 aus EMS::getPlanActions()
    // uebernommen (Verbund-Abstimmung, 20.08.2026). Nur die 5 Werte, die im
    // Tagesplan-Kontext tatsaechlich vorkommen (Standby/Backup/GridRewards
    // sind Live-Betriebszustaende, keine geplanten) - unbekannte op-Werte
    // fallen auf denselben Grauton wie "Automatik" zurueck, statt zu fehlen.
    private const PLAN_OP_COLORS = [
        0 => ['name' => 'Automatik',                 'color' => '#AAAAAA'],
        1 => ['name' => 'PV-Eigenverbrauch (laden)',  'color' => '#4CAF50'],
        2 => ['name' => 'Netz laden',                 'color' => '#2196F3'],
        3 => ['name' => 'Eigenverbrauch (entladen)',  'color' => '#FF9800'],
        5 => ['name' => 'Einspeisen',                 'color' => '#9C27B0'],
    ];

    /**
     * EMS-Tagesplan (heute+morgen, Viertelstunden-Slots) + optional PV-/
     * Lastprognose direkt von PVPrognose/Lastprognose (NICHT ueber EMS
     * proxied - EMS' ausdruecklicher Wunsch, 20.08.2026, um keine
     * unnoetige Kopplung zwischen den Prognose-Modulen und EMS
     * einzufuehren). Alles in EINEM Aufruf, wie der Rest des Payloads -
     * kein Nachladen bei Reiterwechsel.
     */
    private function BuildDayPlan(): array
    {
        $out = ['hasEms' => false, 'slots' => [], 'pvForecast' => [], 'loadForecast' => []];

        $emsId = $this->EmsInstanceID();
        if ($emsId > 0 && function_exists('EMS_GetDayPlan')) {
            $plan = @EMS_GetDayPlan($emsId);
            if (is_array($plan) && isset($plan['slots']) && is_array($plan['slots'])) {
                $out['hasEms'] = true;
                // Selbstbeschreibender Umrechnungsfaktor statt einer hart codierten
                // Annahme (Lehre aus zwei aufeinanderfolgenden Skalierungsfehlern,
                // 20.08.2026 - einer bei uns, einer bei EMS selbst, beide unbemerkt
                // bis zum Live-Diagramm): EMS 0.22.3 liefert zusaetzlich 'priceUnit'
                // ("ct/kWh" oder "EUR/kWh"), genau fuer diesen Fall. Fehlt das Feld
                // (aeltere EMS-Version), bleibt ct/kWh die dokumentierte Default-
                // Annahme des urspruenglichen Vertrags.
                $priceUnit = (string) ($plan['priceUnit'] ?? 'ct/kWh');
                $priceFactor = (stripos($priceUnit, 'eur') !== false) ? 100.0 : 1.0;
                foreach ($plan['slots'] as $slot) {
                    $out['slots'][] = [
                        'time'  => (int) ($slot['time'] ?? 0) * 1000,
                        'op'    => (int) ($slot['op'] ?? 0),
                        'power' => (int) ($slot['power'] ?? 0),
                        'reason' => (string) ($slot['reason'] ?? ''),
                        'price' => isset($slot['price']) && $slot['price'] !== null ? round((float) $slot['price'] * $priceFactor, 2) : null,
                        'soc'   => isset($slot['soc']) && $slot['soc'] !== null ? (float) $slot['soc'] : null,
                    ];
                }
            }
        }

        $out['pvForecast'] = $this->ForecastSeries($this->PvfInstanceID(), 'PVF_GetForecast');
        $out['loadForecast'] = $this->ForecastSeries($this->LfcInstanceID(), 'LFC_GetForecast');

        // Vergangenheit als Ist statt als Prognose zeigen (Dietmar,
        // 24.08.2026: "wie wäre es, wenn man die Vergangenheit so
        // darstellen würde wie sie war und nicht mehr als Prognose?") -
        // die Prognose-Punkte VOR jetzt werden durch echte Archivwerte
        // ersetzt, NACH jetzt bleibt die Prognose stehen. Nur fuer den
        // Zeitraum vor jetzt sinnvoll (die Zukunft hat naturgemaess keine
        // Ist-Werte) - betrifft also praktisch nur den heutigen Tag.
        $now = time();
        $todayStart = strtotime('today 00:00:00');
        $aid = $this->ArchiveID();
        if ($aid > 0 && $now > $todayStart) {
            $pvID = $this->PvPowerID();
            $pvActual = $this->DaySeries($aid, $pvID, $todayStart, $now);
            $out['pvForecast'] = $this->SpliceActual($out['pvForecast'], $pvActual, $now * 1000);

            $batID = $this->BatPowerID();
            $gridID = $this->GridPowerID();
            $gridSign = $this->GridPowerSign($gridID);
            $loadActual = $this->ActualLoadSeries($aid, $pvID, $batID, $gridID, $gridSign, $todayStart, $now);
            $out['loadForecast'] = $this->SpliceActual($out['loadForecast'], $loadActual, $now * 1000);
        }

        return $out;
    }

    /**
     * Ersetzt in einer Prognose-Zeitreihe alle Punkte VOR $cutoffMs durch
     * $actual (Archivwerte), Punkte ab $cutoffMs bleiben unveraendert
     * Prognose - siehe BuildDayPlan(). Bewusst reine Punktkonkatenation
     * statt Interpolation/Resampling: PV-/Lastprognose sind stuendlich,
     * Ist-Werte 5-minuetig - ein Liniendiagramm verbindet unterschiedlich
     * dichte Abschnitte ohne weiteres Zutun sauber.
     */
    private function SpliceActual(array $forecast, array $actual, int $cutoffMs): array
    {
        if (count($actual) === 0) {
            return $forecast;
        }
        $future = array_values(array_filter($forecast, function ($p) use ($cutoffMs) {
            return $p[0] >= $cutoffMs;
        }));
        return array_merge($actual, $future);
    }

    /**
     * Tatsaechlicher Lastverlauf (Ist) aus PV/Batterie/Netz-Leistung
     * abgeleitet - dieselbe Aufteilungsformel wie DayBalanceCurve()
     * ("pvToLoad + Batterieentladung + Netzbezug"), hier aber als EINE
     * Last-Zeitreihe statt vier getrennter Kurven, fuer den Tagesplan-
     * Reiter (siehe SpliceActual()). Bewusst eigenstaendig statt
     * DayBalanceCurve() mitzubenutzen - andere Rueckgabeform, und
     * DayBalanceCurve() bleibt unangetastet, um dessen bestehendes
     * Verhalten nicht versehentlich zu veraendern.
     */
    private function ActualLoadSeries(int $aid, int $pvID, int $batID, int $gridID, int $gridSign, int $start, int $end): array
    {
        $pvPts = $this->DaySeries($aid, $pvID, $start, $end);
        $batPts = $this->DaySeries($aid, $batID, $start, $end);
        $gridPts = $this->DaySeries($aid, $gridID, $start, $end);

        $byTs = [];
        foreach ($pvPts as $p) { $byTs[$p[0]]['pv'] = $p[1]; }
        foreach ($batPts as $p) { $byTs[$p[0]]['bat'] = $p[1]; }
        foreach ($gridPts as $p) { $byTs[$p[0]]['grid'] = $p[1] * $gridSign; }
        ksort($byTs);

        $out = [];
        foreach ($byTs as $ts => $v) {
            $pv = max(0.0, (float) ($v['pv'] ?? 0));
            $bat = (float) ($v['bat'] ?? 0);
            $grid = (float) ($v['grid'] ?? 0);
            $batCh = max(0.0, -$bat);
            $batDis = max(0.0, $bat);
            $gridImp = max(0.0, -$grid);
            $gridExp = max(0.0, $grid);
            $pvToLoad = max(0.0, $pv - $gridExp - $batCh);
            $out[] = [$ts, round($pvToLoad + $batDis + $gridImp, 0)];
        }
        return $out;
    }

    /**
     * Heute+morgen aus GetForecast(0)/GetForecast(1) zu EINER [[tsMs,W],...]-
     * Zeitreihe zusammengefuegt - gleiches Vertragsformat bei PVF und LFC
     * ('resolution' als "<n>min"-String, 'mean' je Slot in W).
     */
    /**
     * PV-Prognose (heute+morgen) fuer die Sonnen-Tattoos, mit 15-Minuten-
     * Attribut-Cache: der Haupt-Payload wird alle 5 Minuten gebaut, und
     * PVF_GetForecast() gehoert laut der Tagesplan-Lazy-Load-Begruendung
     * (21.08.2026, "Laden benoetigt sehr lange") nicht in jeden eager-
     * Render - der Cache haelt die Prognose-Kopplung trotzdem aktuell
     * genug (die Prognose selbst aktualisiert sich nur stuendlich).
     */
    private function PvfSunForecast(): array
    {
        $pvf = $this->PvfInstanceID();
        if ($pvf <= 0 || !function_exists('PVF_GetForecast')) {
            return [];
        }
        // is_string()-Guard (31.08.2026, OCPPHub-Fund im Systemlog: "json_decode():
        // Argument #1 ($json) must be of type string, false given" genau in
        // dieser Funktion) - ReadAttributeString() sollte immer einen String
        // liefern, aber json_decode() reagiert auf jeden Nicht-String-Wert
        // mit einem Fatal Error statt eines harmlosen null - lieber hier
        // defensiv wie an jeder anderen Stelle dieser Datei (is_string()-
        // Muster) als der Ursache einzeln hinterherjagen.
        $raw = $this->ReadAttributeString('PvfSunCache');
        $cache = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($cache) && (time() - (int) ($cache['ts'] ?? 0)) < 900 && is_array($cache['pts'] ?? null)) {
            return $cache['pts'];
        }
        $pts = $this->ForecastSeries($pvf, 'PVF_GetForecast');
        $this->WriteAttributeString('PvfSunCache', json_encode(['ts' => time(), 'pts' => $pts]));
        return $pts;
    }

    private function ForecastSeries(int $instanceId, string $function): array
    {
        if ($instanceId <= 0 || !function_exists($function)) {
            return [];
        }
        $pts = [];
        foreach ([0, 1] as $offset) {
            $fc = @$function($instanceId, $offset);
            if (!is_array($fc) || !isset($fc['mean']) || !is_array($fc['mean'])) {
                continue;
            }
            $slotMin = (int) (rtrim((string) ($fc['resolution'] ?? '60min'), 'min') ?: 60);
            $slotSec = max(1, $slotMin) * 60;
            $dayStart = strtotime('today +' . $offset . ' days');
            foreach ($fc['mean'] as $i => $w) {
                $pts[] = [($dayStart + $i * $slotSec) * 1000, (float) $w];
            }
        }
        return $pts;
    }

    // Feste Knotenfarben, 1:1 aus InverterHubEnergy::COL_* uebernommen (deren
    // Sankey-Vorlage, mit denen abgestimmt) - Konsistenz, falls Dietmar die
    // Kachel je wieder nebeneinander sieht.
    private const COL_SOLAR = '#F2C230';
    private const COL_BAT   = '#5FCB6B';
    private const COL_GRID  = '#4AA3E0';
    private const COL_LOAD  = '#E8823C';

    // 1:1 aus InverterHubEnergy::CONSUMER_TYPES uebernommen (nur die Farben -
    // die Bezeichnung liefert MHUB_GetFunctions() bereits als eigenes Feld
    // "label", die muss hier nicht dupliziert werden).
    private const CONSUMER_COLORS = [
        'wallbox' => '#9575CD', 'heatpump' => '#FF7A18', 'ac' => '#26C6DA', 'aircon' => '#26C6DA',
        'poolheat' => '#FF8A50', 'poolpump' => '#26A69A', 'sauna' => '#F4511E', 'boiler' => '#FFA726',
        'dryer' => '#78909C', 'washer' => '#4DD0E1', 'dishwasher' => '#4DB6AC', 'oven' => '#EF6C00',
        'stove' => '#E64A19', 'fridge' => '#4FC3F7', 'kitchen' => '#FFB74D', 'heater' => '#FF7043',
        'vent' => '#80DEEA', 'light' => '#FFD54F', 'it' => '#7986CB', 'workshop' => '#8D6E63',
        'garage' => '#B39DDB', 'other' => '#90A4AE',
    ];

    /**
     * Alle Zuordnungen aller MeterHub-Instanzen, flach - "grid"/"house"
     * gesondert behandelt (s. EnergyFlow()), alles andere ist ein
     * generischer Verbraucher. Bevorzugt eine "billing"-Zuordnung, wo
     * mehrere Instanzen dieselbe Funktion melden (z.B. zwei Netzzaehler,
     * Inexogy=billing vs. PAC2200=auxiliary - live an Dietmars Anlage
     * bestaetigt, 29.07.2026).
     */
    private function MeterHubAssignments(): array
    {
        $ids = @IPS_GetInstanceListByModuleID(self::METERHUB_GUID);
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            if (!function_exists('MHUB_GetFunctions')) {
                break;
            }
            // MHUB_GetFunctions() liefert laut eigenem Vertrag (MeterHub/
            // CLAUDE.md: "liefert Modus, Zuordnungen und Variablen-IDs als
            // JSON") einen JSON-STRING, anders als IHUB_GetFunctions()
            // (liefert bereits ein natives PHP-Array) - der fehlende
            // json_decode() hier liess is_array($f) IMMER fehlschlagen,
            // MeterHubAssignments() also STETS leer zurueckgeben. Fund der
            // MeterHub-Sitzung, 23.08.2026 (urspruenglich beim Nachgehen
            // eines Netzbezug/Einspeisung-Vorzeichenfehlers): betraf nicht
            // nur GridPowerSign(), sondern auch FlowComponents()/
            // DayBalanceCurve() - beide nutzten dadurch bislang NIE die
            // echten MeterHub-Zaehler/-Zuordnungen, sondern immer nur den
            // IHUB-Fallback.
            $raw = @MHUB_GetFunctions($id);
            $f = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($f) || !isset($f['assignments']) || !is_array($f['assignments'])) {
                continue;
            }
            foreach ($f['assignments'] as $a) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /**
     * Beste Zuordnung fuer eine Funktion (z.B. "grid") - "billing" schlaegt
     * "auxiliary", sonst die erste gefundene.
     */
    private function BestAssignment(array $assignments, string $function): ?array
    {
        $best = null;
        foreach ($assignments as $a) {
            if (($a['function'] ?? '') !== $function) {
                continue;
            }
            if ($best === null || ($a['authority'] ?? '') === 'billing') {
                $best = $a;
                if (($a['authority'] ?? '') === 'billing') {
                    break;
                }
            }
        }
        return $best;
    }

    /**
     * Zaehlerstand-Differenz ueber einen Zeitraum (Muster: InverterHubEnergy::
     * PeriodEnergy() - mit deren Sitzung abgestimmt, 29.07.2026, dieselbe
     * Reset-sichere Logik: negative Differenz = Zaehlerruecksetzung/
     * Ausreisser, dann null statt eines falschen Werts).
     */
    private function PeriodEnergyCounter(int $vid, int $start, int $end): ?float
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return null;
        }
        $aid = $this->ArchiveID();
        if ($aid <= 0 || !@AC_GetLoggingStatus($aid, $vid)) {
            return null;
        }
        $endVal = ($end >= time() - 5) ? (float) GetValue($vid) : $this->ArchiveValueAt($aid, $vid, $end);
        if ($endVal === null) {
            return null;
        }
        $startVal = $this->ArchiveValueAt($aid, $vid, $start);
        if ($startVal === null) {
            $r = @AC_GetLoggedValues($aid, $vid, 0, $end, 0);
            $startVal = (is_array($r) && count($r) > 0) ? (float) $r[count($r) - 1]['Value'] : null;
        }
        if ($startVal === null) {
            return null;
        }
        $delta = $endVal - $startVal;
        return ($delta >= 0) ? $delta : null;
    }

    /**
     * Bezugsenergie heute/laufender Monat/laufendes Jahr (kWh) fuer die
     * Legende im Strompreis-Reiter (Dietmar, 27.08.2026). Bevorzugt den
     * ECHTEN Zaehlerstand des Netzzaehlers (MeterHub-"grid"-Zuordnung,
     * energyImportID, reset-sicher via PeriodEnergyCounter()) - nur ohne
     * Zaehler faellt der TAGES-Wert auf die Leistungs-Integration zurueck
     * (PowerToEnergy); Monat/Jahr bleiben dann bewusst null statt einen
     * teuren Jahres-Archivdurchlauf ueber 5-Minuten-Aggregate zu machen.
     * Zeitgrenzen ueber strtotime() (DST-Regel, siehe CLAUDE.md).
     */
    private function GridEnergySummary(): ?array
    {
        return $this->GridEnergySummaryFor(strtotime('today'));
    }

    /**
     * Bezugsenergie Tag/Monat/Jahr BEZOGEN AUF den angezeigten Tag
     * (Dietmar, 28.08.2026: "diese Werte [sollten] für genau den
     * eingestellten Wert gelten. z.B. Erzeugung vom Monatsanfang bis
     * einschl. dem angezeigten Tag, analog dazu der Jahreswert") -
     * Monat/Jahr laufen also vom jeweiligen Periodenanfang bis zum ENDE
     * des angezeigten Tages (bzw. "jetzt" beim heutigen Tag), nicht
     * pauschal bis jetzt.
     */
    private function GridEnergySummaryFor(int $dayStart): ?array
    {
        $dayEnd     = min(time(), strtotime('+1 day', $dayStart));
        $monthStart = strtotime(date('Y-m-01 00:00:00', $dayStart));
        $yearStart  = strtotime(date('Y-01-01 00:00:00', $dayStart));

        $gridA = $this->BestAssignment($this->MeterHubAssignments(), 'grid');
        $impID = (int) ($gridA['energyImportID'] ?? 0);
        if ($impID > 0) {
            $day   = $this->PeriodEnergyCounter($impID, $dayStart, $dayEnd);
            $month = $this->PeriodEnergyCounter($impID, $monthStart, $dayEnd);
            $year  = $this->PeriodEnergyCounter($impID, $yearStart, $dayEnd);
            if ($day !== null || $month !== null || $year !== null) {
                return ['day' => $day, 'month' => $month, 'year' => $year];
            }
        }

        $gridID = $this->GridPowerID();
        if ($gridID <= 0) {
            return null;
        }
        // Kanonisch "+ = Einspeisung": Bezug ist der negative Anteil. Bei
        // MeterHub-Rohwerten ("+ = Bezug", GridPowerSign() == -1) entsprechend
        // der positive - deshalb -GridPowerSign() als PowerToEnergy-Vorzeichen.
        $day = $this->PowerToEnergy($gridID, $dayStart, $dayEnd, -$this->GridPowerSign($gridID));
        return ['day' => $day, 'month' => null, 'year' => null];
    }

    private function ArchiveValueAt(int $aid, int $vid, int $t): ?float
    {
        if ($t <= 0) {
            return null;
        }
        $r = @AC_GetLoggedValues($aid, $vid, 0, $t, 1);
        return (is_array($r) && count($r) > 0) ? (float) $r[0]['Value'] : null;
    }

    /**
     * Energie (kWh) aus einer reinen LEISTUNGS-Variable ueber einen
     * Zeitraum - InverterHub liefert fuer PV/Batterie nur Leistung, keinen
     * kumulativen Zaehler (IHUB_GetFunctions() hat pvPowerID/batPowerID,
     * keine *EnergyID). $sign: 1 = nur positive Werte aufsummieren
     * (Erzeugung/Entladung), -1 = nur negative (Ladung), Vorzeichen
     * kanonisch wie ueberall im Verbund ("+ Einspeisung"/"+ Entladen").
     */
    private function PowerToEnergy(int $vid, int $start, int $end, int $sign): float
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return 0.0;
        }
        $aid = $this->ArchiveID();
        if ($aid <= 0 || !@AC_GetLoggingStatus($aid, $vid)) {
            return 0.0;
        }
        $data = @AC_GetAggregatedValues($aid, $vid, self::AGG_5MIN, $start, $end, 0);
        if (!is_array($data)) {
            return 0.0;
        }
        $kwh = 0.0;
        foreach ($data as $row) {
            $avg = (float) $row['Avg'];
            if ($this->RowHasImplausiblePower($row)) {
                $this->SendDebug(
                    __FUNCTION__,
                    sprintf('Unplausibler Archivwert verworfen: Variable #%d, %s, Max=%.0f W', $vid, date('Y-m-d H:i', (int) $row['TimeStamp']), (float) ($row['Max'] ?? 0)),
                    0
                );
                continue;
            }
            $part = ($sign > 0) ? max(0.0, $avg) : max(0.0, -$avg);
            $kwh += $part * (5.0 / 60.0) / 1000.0;
        }
        return $kwh;
    }

    /**
     * Sankey-Energiebilanz fuer einen Zeitraum - EIGENE Berechnung (nicht
     * mehr ueber InverterHubEnergy, die Instanz hat Dietmar bewusst
     * geloescht, 29.07.2026: "moechte nicht so viele Instanzen verwalten").
     * Mit InverterHub UND MeterHub abgestimmt: Solar/Batterie kommen als
     * LEISTUNG von der InverterHub-Kerninstanz (IHUB_GetFunctions(), per
     * PowerToEnergy() aufintegriert), Netzbezug/-einspeisung und alle
     * Verbraucher als ECHTE Zaehlerstaende von MeterHub (PeriodEnergyCounter(),
     * Netz bevorzugt "billing"-Zuordnung - Inexogy vor PAC2200 an Dietmars
     * Anlage). "Hausverbrauch" ist bei ihm ein ECHTER MeterHub-Zaehler
     * (function 'house'), keine Ableitung aus PV+Batterie+Netz mehr noetig,
     * sofern vorhanden. Aufteilungsformel (PV/Batterie/Netz-Anteile je
     * Verbraucher) 1:1 aus InverterHubEnergy::ComputeFlow() uebernommen -
     * das ist reine Rechenlogik, keine Zaehlerfrage, bewusst unveraendert
     * gelassen, um keine neuen Fallstricke einzubauen.
     */
    /**
     * Kernberechnung (1:1 InverterHubEnergy::ComputeFlow()), aus EnergyFlow()
     * herausgezogen (30.07.2026) - fuer den neuen "Bilanz"-Reiter (Flaechen-/
     * Balkendiagramm nach Dietmars SEMS-Vorbild) OHNE die Sankey-Knoten/
     * Kanten gebraucht, nur die fuenf benannten Groessen selbst. EnergyFlow()
     * (Sankey) und BalanceTotals() (Bilanz) rufen beide dieselbe Funktion,
     * damit die Zahlen zwischen beiden Ansichten nie auseinanderlaufen.
     */
    private function FlowComponents(int $start, int $end): array
    {
        $ihub = $this->singleInverterHubID();
        $data = ($ihub > 0 && function_exists('IHUB_GetFunctions')) ? @IHUB_GetFunctions($ihub) : null;
        $pvPowerID = is_array($data) ? (int) ($data['pvPowerID'] ?? 0) : 0;
        $batPowerID = is_array($data) ? (int) ($data['batPowerID'] ?? 0) : 0;
        $ihubGridPowerID = is_array($data) ? (int) ($data['gridPowerID'] ?? 0) : 0;

        $solar = $this->PowerToEnergy($pvPowerID, $start, $end, 1);
        $batCh = $this->PowerToEnergy($batPowerID, $start, $end, -1);
        $batDis = $this->PowerToEnergy($batPowerID, $start, $end, 1);

        $assignments = $this->MeterHubAssignments();
        $gridA = $this->BestAssignment($assignments, 'grid');
        $houseA = $this->BestAssignment($assignments, 'house');

        if ($gridA !== null) {
            $gridImp = $this->PeriodEnergyCounter((int) ($gridA['energyImportID'] ?? 0), $start, $end);
            $gridExp = $this->PeriodEnergyCounter((int) ($gridA['energyExportID'] ?? 0), $start, $end);
        } else {
            // Kein MeterHub-Netzzaehler getaggt - Rueckfall auf InverterHubs
            // Netzleistung (Naeherung ueber Leistungsintegration statt
            // echtem Zaehlerstand).
            $gridImp = $this->PowerToEnergy($ihubGridPowerID, $start, $end, -1);
            $gridExp = $this->PowerToEnergy($ihubGridPowerID, $start, $end, 1);
        }
        $gridImp = max(0.0, (float) $gridImp);
        $gridExp = max(0.0, (float) $gridExp);

        $houseE = ($houseA !== null) ? $this->PeriodEnergyCounter((int) ($houseA['energyImportID'] ?? 0), $start, $end) : null;

        // Aufteilungsmodell (1:1 InverterHubEnergy::ComputeFlow()): Netz-
        // einspeisung und Batterie-Ladung stammen aus PV; der PV-Rest sowie
        // Batterie-Entladung und Netzbezug decken den Verbrauch.
        $pvToLoad = max(0.0, $solar - $gridExp - $batCh);
        $load = ($houseE !== null && $houseE > 0) ? (float) $houseE : ($pvToLoad + $batDis + $gridImp);

        return [
            'solar' => $solar, 'batCh' => $batCh, 'batDis' => $batDis,
            'gridImp' => $gridImp, 'gridExp' => $gridExp, 'pvToLoad' => $pvToLoad,
            'load' => $load, 'assignments' => $assignments,
        ];
    }

    /**
     * Fuenf benannte Bilanz-Groessen fuer einen Zeitraum (Muster: Dietmars
     * SEMS-Vorbild-Screenshots, 30.07.2026) - Netzbezug/Batterieentladung/
     * Direktverbrauch fuer den "Verbrauch"-Teil, Direktverbrauch/
     * Batterieladung/Netzeinspeisung fuer den "Erzeugung"-Teil (Direkt-
     * verbrauch kommt bewusst in beiden vor, wie im Vorbild - er ist
     * sowohl Verbrauchs- als auch Erzeugungsanteil).
     */
    private function BalanceTotals(int $start, int $end): array
    {
        $c = $this->FlowComponents($start, $end);
        return [
            'netzbezug'         => round($c['gridImp'], 3),
            'batterieentladung' => round($c['batDis'], 3),
            'direktverbrauch'   => round($c['pvToLoad'], 3),
            'batterieladung'    => round($c['batCh'], 3),
            'netzeinspeisung'   => round($c['gridExp'], 3),
        ];
    }

    private function EnergyFlow(int $start, int $end): ?array
    {
        $c = $this->FlowComponents($start, $end);
        $solar = $c['solar']; $batCh = $c['batCh']; $batDis = $c['batDis'];
        $gridImp = $c['gridImp']; $gridExp = $c['gridExp']; $pvToLoad = $c['pvToLoad'];
        $load = $c['load']; $assignments = $c['assignments'];

        $consumers = [];
        $consSum = 0.0;
        foreach ($assignments as $a) {
            $fn = $a['function'] ?? '';
            if ($fn === '' || $fn === 'grid' || $fn === 'house' || $fn === 'pv' || $fn === 'battery') {
                continue;
            }
            $e = $this->PeriodEnergyCounter((int) ($a['energyImportID'] ?? 0), $start, $end);
            if ($e === null) {
                continue;
            }
            $e = max(0.0, (float) $e);
            $consumers[] = [
                'key' => 'c' . count($consumers),
                'label' => (string) ($a['label'] ?? $fn),
                'color' => self::CONSUMER_COLORS[$fn] ?? self::COL_LOAD,
                'val' => $e,
            ];
            $consSum += $e;
        }
        $rest = max(0.0, $load - $consSum);

        $nodes = [];
        $links = [];
        $batNode = ($batCh > 0 || $batDis > 0);

        if ($solar > 0) { $nodes[] = ['key' => 'solar', 'label' => 'Solar', 'color' => self::COL_SOLAR, 'column' => 0]; }
        if ($gridImp > 0) { $nodes[] = ['key' => 'gridimp', 'label' => 'Netzbezug', 'color' => self::COL_GRID, 'column' => 0]; }
        if ($batNode) { $nodes[] = ['key' => 'bat', 'label' => 'Batterie', 'color' => self::COL_BAT, 'column' => 1]; }
        foreach ($consumers as $c) {
            $nodes[] = ['key' => $c['key'], 'label' => $c['label'], 'color' => $c['color'], 'column' => 2];
        }
        if ($rest > 0) { $nodes[] = ['key' => 'rest', 'label' => ($consSum > 0 ? 'Sonstiger Verbrauch' : 'Hausverbrauch'), 'color' => self::COL_LOAD, 'column' => 2]; }
        if ($gridExp > 0) { $nodes[] = ['key' => 'gridexp', 'label' => 'Netzeinspeisung', 'color' => self::COL_GRID, 'column' => 2]; }

        $addLink = function ($from, $to, $val) use (&$links) {
            if ($val > 0.0001) {
                $links[] = ['from' => $from, 'to' => $to, 'value' => round($val, 3)];
            }
        };
        if ($solar > 0 && $batCh > 0) { $addLink('solar', 'bat', $batCh); }
        if ($solar > 0 && $gridExp > 0) { $addLink('solar', 'gridexp', $gridExp); }
        $sinkList = [];
        foreach ($consumers as $c) { $sinkList[$c['key']] = $c['val']; }
        if ($rest > 0) { $sinkList['rest'] = $rest; }
        if ($load > 0) {
            $fPv = $pvToLoad / $load;
            $fBat = $batDis / $load;
            $fGrid = $gridImp / $load;
            foreach ($sinkList as $k => $v) {
                if ($solar > 0 && $pvToLoad > 0) { $addLink('solar', $k, $v * $fPv); }
                if ($batNode && $batDis > 0) { $addLink('bat', $k, $v * $fBat); }
                if ($gridImp > 0) { $addLink('gridimp', $k, $v * $fGrid); }
            }
        }

        return [
            'contractVersion' => '1.0',
            'hasData' => (count($links) > 0),
            'totalIn' => round($solar + $gridImp, 2),
            'nodes' => $nodes,
            'links' => $links,
        ];
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
        $generatorKwp = [];
        foreach ($rows as $row) {
            if ($row['kwp'] > 0.0) {
                $eff = $row['kwp'] * (($row['factor'] > 0.0) ? $row['factor'] : 1.0);
                $totalKwp += $eff;
                $generatorKwp[] = $eff;
            }
        }
        // generatorKwp additiv fuer die MPP-Tracker-Erwartungskurve (28.07.2026,
        // Dietmars Wunsch) - je Generator effektive kWp (kwp*factor), in der
        // Reihenfolge von PVF_GetGenerators(). Aendert nichts an pr/totalKwp,
        // die der Solar-Reiter bereits nutzt.
        return ($totalKwp > 0.0) ? ['pr' => $pr, 'totalKwp' => $totalKwp, 'generatorKwp' => $generatorKwp] : null;
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
        $dayEnd = strtotime('+1 day', $dayStart);
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
            $w = (float) $row['Avg'];
            if ($this->RowHasImplausiblePower($row)) {
                $this->SendDebug(
                    __FUNCTION__,
                    sprintf('Unplausibler Archivwert verworfen: Variable #%d, %s, Max=%.0f W', $vid, date('Y-m-d H:i', (int) $row['TimeStamp']), (float) ($row['Max'] ?? 0)),
                    0
                );
                continue;
            }
            $pts[] = [(int) $row['TimeStamp'] * 1000, round($w, 1)];
        }
        return $pts;
    }

    /**
     * Netzbezug in 15-Minuten-Balken (Muster: InverterHubMonitor::
     * SlotEnergyBars() - Netzbezug im Strompreis-Reiter, mit deren Sitzung
     * abgestimmt). $vid ist eine kanonische Netzleistungs-Variable
     * (GridPowerID/IHUB_GetFunctions()['gridPowerID'], "+ Einspeisung",
     * bereits MeterInvert-korrigiert bei der Quelle - siehe InverterHub-
     * CLAUDE.md, "MeterInvert/BatInvert gehoeren NUR nach module.php").
     * Bezug ist der negative Anteil: draw = max(0, -Avg). 15-Minuten-Raster
     * an der vollen Stunde ausgerichtet (900s), passend zu den ueblichen
     * Tibber/EnWG-Slotlaengen - je Bucket werden die enthaltenen 5-Minuten-
     * Mittelwerte zu kWh aufintegriert (Avg * 5/60 / 1000, negative Werte
     * (=Einspeisung) auf 0 geklemmt). $sign kanonisiert $vid auf "+ =
     * Einspeisung", siehe GridPowerSign() (Fund der MeterHub-Sitzung,
     * 23.08.2026: MeterHub-Netzleistung ist "+ = Bezug", das Gegenteil).
     */
    private function SlotEnergyBars(int $aid, int $vid, int $start, int $end, int $sign = 1): array
    {
        if ($vid <= 0 || !IPS_VariableExists($vid) || !@AC_GetLoggingStatus($aid, $vid)) {
            return [];
        }
        $data = @AC_GetAggregatedValues($aid, $vid, self::AGG_5MIN, $start, $end, 0);
        if (!is_array($data)) {
            return [];
        }
        $buckets = [];
        foreach ($data as $row) {
            $ts = (int) $row['TimeStamp'];
            $bucketStart = $ts - ($ts % 900);
            $drawW = max(0.0, -$sign * (float) $row['Avg']);
            $kwh = $drawW * (5.0 / 60.0) / 1000.0;
            $buckets[$bucketStart] = ($buckets[$bucketStart] ?? 0.0) + $kwh;
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $bucketStart => $kwh) {
            // Zeitstempel der Slot-MITTE (bucketStart + 450s), nicht des
            // Slot-Anfangs - genau wie priceStepPoints()/PriceDaySlots()
            // fuers Frontend die Strompreis-Punkte zentriert. Highcharts
            // (und ECharts) zeichnen einen Balken standardmaessig zentriert
            // um seinen x-Wert - mit dem Slot-ANFANG als x wirkte der Balken
            // dadurch um eine halbe Viertelstunde nach frueh versetzt (live
            // gefunden, Dietmar 28.07.2026: "Netzbezug ist versetzt und wird
            // vom Cursor gefangen"). Mit der Slot-Mitte deckt sich der Balken
            // exakt mit dem echten 15-Minuten-Fenster UND liegt exakt auf
            // demselben Zeitstempel wie der zugehoerige Strompreis-Punkt -
            // das Frontend kann Kosten (Bezug x Preis) dadurch einfach ueber
            // gleiche x-Werte zuordnen.
            $out[] = [($bucketStart + 450) * 1000, round($kwh, 3)];
        }
        return $out;
    }

    /**
     * Hoechste Netzbezugsleistung (Ø je 15-Minuten-Slot, wie im "Ø kW"-
     * Anzeigemodus) im Kalendermonat, der $dayStart enthaelt - fuer die
     * gelbe Monats-Spitzenwert-Linie im Strompreis-Reiter (Dietmar,
     * 07.08.2026). ZWEISTUFIG statt eines einzelnen SlotEnergyBars()-Aufrufs
     * ueber den ganzen Monat: AC_GetAggregatedValues mit 5-Minuten-Aufloesung
     * ueber einen kompletten Monat (~30 Tage * 288 Zeilen) liefert schlicht
     * `false` statt Daten zurueck (Limit=0 bricht den Aufruf hier trotz
     * anderslautendem Kommentar bei SlotEnergyBars() - real gefunden,
     * 09.08.2026: die Linie blieb fuer August komplett aus). Erst per
     * Tagesaggregation (Min-Wert = staerkster Bezug des Tages) den
     * Spitzentag finden, dann NUR fuer diesen einen Tag die 15-Minuten-
     * Bucketing ueber SlotEnergyBars() - exakt dieselbe Rechnung wie vorher,
     * nur mit klein genug gehaltenen Einzelabfragen.
     * Rueckgabe null, wenn (noch) keine Daten im Monat vorliegen.
     */
    private function MonthlyPeakDraw(int $aid, int $vid, int $dayStart, int $sign = 1): ?array
    {
        if ($vid <= 0 || !IPS_VariableExists($vid) || !@AC_GetLoggingStatus($aid, $vid)) {
            return null;
        }
        $monthStart = strtotime(date('Y-m-01 00:00:00', $dayStart));
        $monthEnd = min(time(), strtotime('+1 month', $monthStart));
        if ($monthEnd <= $monthStart) {
            return null;
        }
        $dailyRows = @AC_GetAggregatedValues($aid, $vid, self::AGG_DAY, $monthStart, $monthEnd, 0);
        if (!is_array($dailyRows) || count($dailyRows) === 0) {
            return null;
        }
        $peakDayStart = null;
        $peakDrawW = 0.0;
        foreach ($dailyRows as $row) {
            // Vorzeichenkanonisierung wie in SlotEnergyBars(): bei $sign=-1
            // (MeterHub, "+ = Bezug") ist der taegliche BEZUGS-Spitzenwert
            // das Max statt das Min der 5-Minuten-Mittelwerte.
            $extreme = ($sign > 0) ? ($row['Min'] ?? null) : ($row['Max'] ?? null);
            if ($extreme === null) {
                continue;
            }
            $drawW = max(0.0, -$sign * (float) $extreme);
            if ($peakDayStart === null || $drawW > $peakDrawW) {
                $peakDrawW = $drawW;
                $peakDayStart = (int) $row['TimeStamp'];
            }
        }
        if ($peakDayStart === null || $peakDrawW <= 0.0) {
            return null;
        }
        $dayEnd = min($monthEnd, strtotime('+1 day', $peakDayStart));
        $bars = $this->SlotEnergyBars($aid, $vid, $peakDayStart, $dayEnd, $sign);
        if (count($bars) === 0) {
            return null;
        }
        $best = null;
        foreach ($bars as $b) {
            if ($best === null || $b[1] > $best[1]) {
                $best = $b;
            }
        }
        return [
            'kw' => round($best[1] * 4.0, 3),
            // Slot-MITTE in ms, identisch zur Konvention von gridDraw -
            // Frontend rechnet sich Slot-Start/-Ende (±450s) daraus zurueck.
            'ts' => $best[0],
        ];
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
            $avg = (float) $row['Avg'];
            if ($this->RowHasImplausiblePower($row)) {
                $this->SendDebug(
                    __FUNCTION__,
                    sprintf('Unplausibler Archivwert verworfen: Variable #%d, %s, Max=%.0f W', $vid, date('Y-m-d', (int) $row['TimeStamp']), (float) ($row['Max'] ?? 0)),
                    0
                );
                continue;
            }
            $kwh = round($avg * 24.0 / 1000.0, 2);
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
        // Fix (31.07.2026): ECharts (~618 KB) NICHT mehr einbetten - selbst
        // NUR bei gewaehlter ECharts-Engine hat das zusammen mit dem
        // restlichen Tage-Fenster-Payload (WINDOW_DAYS Tage x mehrere
        // Serien, inkl. des neuen Bilanz-Reiters) live "Output-Buffer
        // exceeds Limit (1048576 bytes)" ausgeloest. module.html laedt
        // ECharts jetzt per CDN nach (ensureECharts(), gleiches Muster wie
        // ensureHighcharts()) statt es einzubetten - Platzhalter bleibt
        // deshalb unbesetzt.
        $html = str_replace('/*__ECHARTS_JS__*/', '', $html);
        $html .= '<script>handleMessage(' . json_encode($this->buildPayload()) . ');</script>';
        return $html;
    }

    public function Render(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->buildPayload()));
    }

    /**
     * Live-Nachforderung aus der Kachel (WebFront-Bruecke requestAction() im
     * Tile-JS, siehe module.html) - fuer den Energiebilanz-Reiter mit einem
     * ANDEREN Zeitraum als "Tag" (Woche/Monat/Jahr/Gesamt/Benutzerdefiniert):
     * das Tage-Fenster im Payload deckt nur WINDOW_DAYS Tage ab, ein Sankey
     * fuer z.B. "dieses Jahr" braucht einen frischen IHUBNRG_GetFlow()-Aufruf
     * mit den tatsaechlichen Periodengrenzen (Dietmars Wunsch, 28.07.2026:
     * Energiebilanz auch fuer Woche/Monat/Jahr/Gesamt/Benutzerdefiniert
     * auswaehlbar, nicht nur Tag). Ergebnis kommt asynchron per
     * UpdateVisualizationValue() zurueck, ohne die Kachel neu zu laden -
     * das Tile-JS erkennt den Typ 'flowUpdate' und rendert bei Bedarf neu.
     */
    public function RequestAction($Ident, $Value)
    {
        // Doppelpfeil-Variable (siehe Create()) - Wert setzen, Kachel neu
        // rendern (Muster HeatSchema/Forecast::RequestAction()).
        if ($Ident === 'TabAnimation') {
            $this->SetValue('TabAnimation', (int) $Value);
            $this->Render();
            return;
        }
        if ($Ident === 'flowPeriod') {
            $req = json_decode((string) $Value, true);
            $start = (int) ($req['start'] ?? 0);
            $end = (int) ($req['end'] ?? 0);
            $flow = ($end > $start) ? $this->EnergyFlow($start, $end) : null;
            $this->UpdateVisualizationValue(json_encode([
                'ok'    => true,
                'type'  => 'flowUpdate',
                'start' => $start,
                'end'   => $end,
                'flow'  => $flow,
            ]));
            return;
        }
        if ($Ident === 'balancePeriod') {
            $req = json_decode((string) $Value, true);
            $key = (string) ($req['key'] ?? '');
            $result = $this->BuildBalancePeriod($req);
            $this->UpdateVisualizationValue(json_encode([
                'ok'      => true,
                'type'    => 'balanceUpdate',
                'key'     => $key,
                'balance' => $result,
            ]));
            return;
        }
        if ($Ident === 'yearCompare') {
            $this->UpdateVisualizationValue(json_encode([
                'ok'   => true,
                'type' => 'yearCompareUpdate',
                'data' => $this->BuildYearCompare(),
            ]));
            return;
        }
        if ($Ident === 'yearCompareConfig') {
            $req = json_decode((string) $Value, true);
            $cfg = $this->SaveYearCompareConfig(is_array($req) ? $req : []);
            $this->UpdateVisualizationValue(json_encode([
                'ok'   => true,
                'type' => 'yearCompareUpdate',
                'data' => $this->BuildYearCompare($cfg),
            ]));
            return;
        }
        if ($Ident === 'dayPlanLoad') {
            $this->UpdateVisualizationValue(json_encode([
                'ok'      => true,
                'type'    => 'dayPlanUpdate',
                'dayPlan' => $this->BuildDayPlan(),
            ]));
            return;
        }
        if ($Ident === 'stromgedachtLoad') {
            $this->UpdateVisualizationValue(json_encode([
                'ok'           => true,
                'type'         => 'stromgedachtUpdate',
                'stromgedacht' => $this->BuildStromGedacht(),
            ]));
            return;
        }
        if ($Ident === 'dayData') {
            // Einzelner Tag ausserhalb des eager mitgeschickten kleinen
            // Fensters (Dietmar, 23.08.2026: "in allen Reitern alle Tage
            // sehen", die Navigation soll nicht mehr an WINDOW_DAYS enden) -
            // gleiche Vorbereitung wie in buildPayload(), aber nur die
            // billigen Property-Lesungen, kein Archivzugriff bis BuildDayData().
            $req = json_decode((string) $Value, true);
            $k = (int) ($req['k'] ?? 0);
            $model = $this->PvfModel();
            $this->UpdateVisualizationValue(json_encode([
                'ok'   => true,
                'type' => 'dayDataUpdate',
                'k'    => $k,
                'day'  => $this->BuildDayData(
                    $k,
                    $this->ArchiveID(),
                    $this->PvPowerID(),
                    $this->readIntProperty('IrradianceID', 0),
                    $this->readIntProperty('TemperatureID', 0),
                    $this->readFloatProperty('TempCoeff', -0.40),
                    $this->BatPowerID(),
                    $this->SocID(),
                    $this->MpptPowerIDs(),
                    $model
                ),
            ]));
            return;
        }
        if ($Ident === 'yearCompareHistory') {
            $req = json_decode((string) $Value, true);
            $this->SaveManualHistory(is_array($req) ? $req : []);
            $this->UpdateVisualizationValue(json_encode([
                'ok'   => true,
                'type' => 'yearCompareUpdate',
                'data' => $this->BuildYearCompare(),
            ]));
            return;
        }
        throw new Exception('Invalid Ident: ' . $Ident);
    }

    /**
     * Neuer "Bilanz"-Reiter (Dietmar, 30.07.2026, nach SEMS-Vorbild-
     * Screenshots): eigener Reiter NEBEN dem bestehenden Sankey-
     * "Energiebilanz"-Reiter (Dietmars ausdrueckliche Entscheidung, Sankey
     * bleibt unangetastet). granularity 'day' liefert die 5-Minuten-Kurven
     * fuer das Flaechendiagramm, 'month'/'year'/'all' liefern je einen
     * Balken pro Tag/Monat/Jahr - IMMER ueber die feinste Einheit (ein
     * echter Kalendertag, per BalanceTotals() bei 5-Minuten-Aufloesung
     * berechnet) aufsummiert, damit Batterie-Ladung/-Entladung innerhalb
     * eines Tages nicht durch eine grobe Tagesmittelwert-Naeherung
     * gegeneinander wegfallen (dieselbe Falle, die PowerToEnergy() mit
     * sign=+-1 auf 5-Minuten-Basis genau vermeidet).
     */
    private function BuildBalancePeriod(array $req): array
    {
        $granularity = (string) ($req['granularity'] ?? 'day');
        $start = (int) ($req['start'] ?? 0);
        $end = (int) ($req['end'] ?? 0);
        if ($end <= $start) {
            return ['ok' => false];
        }

        if ($granularity === 'day') {
            $curve = $this->DayBalanceCurve($start, $end);
            $totals = $this->BalanceTotals($start, $end);
            return ['ok' => true, 'granularity' => 'day', 'curve' => $curve, 'totals' => $totals];
        }

        // Balken-Granularitaet der FEINSTEN Bucket-Einheit passend zur
        // Ansicht gestaffelt (Monat->Tage, Jahr->Monate, Gesamt->Jahre) -
        // NICHT immer Tage ueber den ganzen Zeitraum: "Gesamt" liefert laut
        // flowPeriodBounds() den Bereich seit Unix-Epoche (1970) - das waeren
        // sonst tausende Tages-Einzelabfragen und ein Zeitueberschreitungs-
        // Risiko fuer einen einzelnen WebFront-Request. Jeder Bucket wird
        // trotzdem intern bei 5-Minuten-Aufloesung berechnet (BalanceTotals()
        // -> FlowComponents()), nur die Bucket-GRENZEN sind groeber.
        $bars = [];
        $guardMax = 400;
        if ($granularity === 'month') {
            $cursor = $start;
            while ($cursor < $end && $guardMax-- > 0) {
                $dayStart = strtotime('midnight', $cursor);
                $dayEnd = min($end, strtotime('+1 day', $dayStart));
                $t = $this->BalanceTotals($dayStart, $dayEnd);
                $t['id'] = date('Y-m-d', $dayStart);
                $t['label'] = date('j', $dayStart);
                $bars[] = $t;
                $cursor = $dayEnd;
            }
        } elseif ($granularity === 'year') {
            $cursor = $start;
            while ($cursor < $end && $guardMax-- > 0) {
                $monthStart = (int) mktime(0, 0, 0, (int) date('n', $cursor), 1, (int) date('Y', $cursor));
                $monthEnd = min($end, (int) strtotime('+1 month', $monthStart));
                $t = $this->BalanceTotals($monthStart, $monthEnd);
                $t['id'] = date('Y-m', $monthStart);
                $t['label'] = date('M', $monthStart);
                $bars[] = $t;
                $cursor = $monthEnd;
            }
        } else {
            // 'all'/Gesamt: auf SPAN_YEARS zurueckdatieren (Date(0)/1970 aus
            // flowPeriodBounds() waere sonst jahrzehntelang leer), ein
            // Balken je Kalenderjahr.
            $cursor = $start;
            $earliestUseful = strtotime('-' . self::SPAN_YEARS . ' years', time());
            if ($cursor < $earliestUseful) {
                $cursor = (int) mktime(0, 0, 0, 1, 1, (int) date('Y', $earliestUseful));
            }
            while ($cursor < $end && $guardMax-- > 0) {
                $yearNum = (int) date('Y', $cursor);
                $yearStart = (int) mktime(0, 0, 0, 1, 1, $yearNum);
                $yearEnd = min($end, (int) mktime(0, 0, 0, 1, 1, $yearNum + 1));
                $t = $this->BalanceTotals($yearStart, $yearEnd);
                $t['id'] = (string) $yearNum;
                $t['label'] = (string) $yearNum;
                $bars[] = $t;
                $cursor = $yearEnd;
            }
        }
        return ['ok' => true, 'granularity' => $granularity, 'bars' => $bars];
    }

    /**
     * 5-Minuten-Kurven der fuenf Bilanz-Groessen fuer GENAU EINEN Tag
     * (Flaechendiagramm "Tag"-Ansicht) - leitet pro Zeitstempel-Bucket
     * dieselbe Aufteilung ab wie FlowComponents()/BalanceTotals() fuer
     * einen ganzen Zeitraum, hier aber punktweise statt aufsummiert.
     */
    private function DayBalanceCurve(int $start, int $end): array
    {
        $ihub = $this->singleInverterHubID();
        $data = ($ihub > 0 && function_exists('IHUB_GetFunctions')) ? @IHUB_GetFunctions($ihub) : null;
        $pvPowerID = is_array($data) ? (int) ($data['pvPowerID'] ?? 0) : 0;
        $batPowerID = is_array($data) ? (int) ($data['batPowerID'] ?? 0) : 0;
        $ihubGridPowerID = is_array($data) ? (int) ($data['gridPowerID'] ?? 0) : 0;

        $assignments = $this->MeterHubAssignments();
        $gridA = $this->BestAssignment($assignments, 'grid');
        $gridPowerID = $gridA['powerID'] ?? 0;
        $gridPowerID = ($gridPowerID > 0) ? (int) $gridPowerID : $ihubGridPowerID;
        // GridPowerSign() kanonisiert MeterHub-Netzleistung ("+ = Bezug")
        // auf unsere "+ = Einspeisung"-Konvention - ohne diese Korrektur
        // waren Netzbezug/Netzeinspeisung hier vertauscht, sobald ein
        // MeterHub-Netzzaehler getaggt ist (Fund der MeterHub-Sitzung,
        // 23.08.2026, identischer Fehler wie in GridPowerID()).
        $gridSign = $this->GridPowerSign($gridPowerID);

        $aid = $this->ArchiveID();
        $pvPts = ($aid > 0) ? $this->DaySeries($aid, $pvPowerID, $start, $end) : [];
        $batPts = ($aid > 0) ? $this->DaySeries($aid, $batPowerID, $start, $end) : [];
        $gridPts = ($aid > 0) ? $this->DaySeries($aid, $gridPowerID, $start, $end) : [];

        $byTs = [];
        foreach ($pvPts as $p) { $byTs[$p[0]]['pv'] = $p[1]; }
        foreach ($batPts as $p) { $byTs[$p[0]]['bat'] = $p[1]; }
        foreach ($gridPts as $p) { $byTs[$p[0]]['grid'] = $p[1] * $gridSign; }
        ksort($byTs);

        $netzbezug = []; $batterieentladung = []; $direktverbrauch = [];
        $batterieladung = []; $netzeinspeisung = [];
        foreach ($byTs as $ts => $v) {
            $pv = max(0.0, (float) ($v['pv'] ?? 0));
            $bat = (float) ($v['bat'] ?? 0);
            $grid = (float) ($v['grid'] ?? 0);
            $batCh = max(0.0, -$bat);
            $batDis = max(0.0, $bat);
            $gridImp = max(0.0, -$grid);
            $gridExp = max(0.0, $grid);
            $pvToLoad = max(0.0, $pv - $gridExp - $batCh);
            $netzbezug[] = [$ts, round($gridImp / 1000.0, 3)];
            $batterieentladung[] = [$ts, round($batDis / 1000.0, 3)];
            $direktverbrauch[] = [$ts, round($pvToLoad / 1000.0, 3)];
            $batterieladung[] = [$ts, round($batCh / 1000.0, 3)];
            $netzeinspeisung[] = [$ts, round($gridExp / 1000.0, 3)];
        }
        return [
            'netzbezug' => $netzbezug,
            'batterieentladung' => $batterieentladung,
            'direktverbrauch' => $direktverbrauch,
            'batterieladung' => $batterieladung,
            'netzeinspeisung' => $netzeinspeisung,
        ];
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
     * Alle Tagesserien fuer GENAU EINEN Tag (Ansicht "Tag (Verlauf)"),
     * $k Tage vor heute (negativ = in der Zukunft, aktuell nur $k=-1
     * "morgen" sinnvoll gefuellt - siehe PriceDaySlots()). Herausgeloest aus
     * buildPayload()'s ehemaliger Schleife (Dietmar, 23.08.2026: "warum
     * komme ich beim Strompreis nur bis zum 17.08.2026 zurueck? -
     * Prinzipiell moechte ich in allen Reitern alle Tage sehen") - vorher
     * war die Navigation durch das feste WINDOW_DAYS=8-Fenster gedeckelt,
     * das buildPayload() bei JEDEM Render()-Timer-Tick eager aufbaute. Jetzt
     * baut buildPayload() weiterhin ein kleines Fenster eager (fuer
     * verzoegerungsfreie Navigation der letzten paar Tage), alles darueber
     * hinaus holt RequestAction('dayData') einzeln, nach demselben Lazy-
     * Load-Muster wie dayPlanLoad/balancePeriod. Ein einzelner Tag ist immer
     * guenstig genug fuer eine Nachforderung (kein WINDOW_DAYS-Faktor mehr) -
     * die Navigation nach hinten ist dadurch unbegrenzt, nur durch
     * tatsaechlich vorhandene Archivdaten.
     */
    private function BuildDayData(
        int $k,
        int $aid,
        int $pvID,
        int $irrID,
        int $tempID,
        float $tc,
        int $batID,
        int $socID,
        array $mppt,
        ?array $model
    ): array {
        $todayStart = strtotime('today 00:00:00');
        // strtotime('-N day', ...) statt $todayStart - $k*86400: rechnet
        // ueber Kalendertage (respektiert Sommer-/Winterzeit), nicht ueber
        // eine feste Sekundenzahl - sonst landet $start ab dem naechsten
        // DST-Wechsel dauerhaft eine Stunde neben der echten Mitternacht
        // (Verbund-DST-Audit, 27.08.2026).
        $start = ($k > 0) ? strtotime('-' . $k . ' day', $todayStart) : $todayStart;
        $end   = min(time(), strtotime('+1 day', $start));
        // Ein Tag in der Zukunft (morgen) hat archivseitig grundsaetzlich
        // nichts zu bieten - $start läge dann NACH $end (min(time(),...)
        // bliebe bei "jetzt" haengen), das wuerde AC_GetAggregatedValues
        // mit vertauschten Grenzen aufrufen. Archivfelder bleiben dort
        // deshalb schlicht leer, nur PriceDaySlots() (siehe unten) deckt
        // auch morgen sinnvoll ab.
        $isFuture = $start > time();

        $pv  = (!$isFuture && $aid > 0) ? $this->DaySeries($aid, $pvID, $start, $end) : [];
        $irr = (!$isFuture && $aid > 0) ? $this->DaySeries($aid, $irrID, $start, $end) : [];
        $temp = (!$isFuture && $aid > 0) ? $this->DaySeries($aid, $tempID, $start, $end) : [];
        $bat = (!$isFuture && $aid > 0) ? $this->DaySeries($aid, $batID, $start, $end) : [];
        // SOC-Rauschen glaetten (Muster: InverterHubMonitor::SmoothPoints,
        // Fenster 15) - nur fuer die eigene Anzeige, kein Diagnostik-Wert.
        $soc = (!$isFuture && $aid > 0) ? $this->SmoothPoints($this->DaySeries($aid, $socID, $start, $end), 15) : [];

        $mpptSeries = [];
        foreach ($mppt as $n => $vid) {
            $mpptSeries[$n] = (!$isFuture && $aid > 0) ? $this->DaySeries($aid, $vid, $start, $end) : [];
        }

        // Temperaturwerte je Zeitstempel zuordnen (gleiches 5-Minuten-
        // Raster wie Einstrahlung, daher per Zeitstempel-Map statt Index
        // koppelbar) - fuer beide Erwartungskurven unten (Gesamt UND je
        // MPP-Tracker-Strang) gemeinsam einmal aufgebaut.
        $tempByTs = [];
        foreach ($temp as $tp) { $tempByTs[$tp[0]] = $tp[1]; }

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
            // Rechnung vorhersagt.
            foreach ($irr as $p) {
                $ta = $tempByTs[$p[0]] ?? null;
                $derate = ($ta !== null) ? $this->DerateFactor((float) $ta, (float) $p[1], $tc) : 1.0;
                $expected[] = [$p[0], round($p[1] * $model['totalKwp'] * $model['pr'] * $derate, 0)];
            }
        }

        $price = $this->PriceDaySlots($start);
        // Sankey-Energiebilanz nur fuer den Zeitraum, der wirklich schon
        // vergangen ist ($end = min(jetzt, Tagesende), s.o.) - fuer den
        // Zukunftstag (morgen) gibt es hier naturgemaess nichts.
        $flow = (!$isFuture) ? $this->EnergyFlow($start, $end) : null;
        // Netzbezug im Strompreis-Reiter (Dietmars Wunsch, 28.07.2026,
        // analog zu InverterHubMonitor) - 15-Minuten-Balken unter der
        // Preiskurve, gleicher $aid/$end wie die anderen Serien.
        // GridPowerSign() kanonisiert automatisch erkannte MeterHub-
        // Netzleistung ("+ = Bezug") auf unsere "+ = Einspeisung"-
        // Konvention (Fund der MeterHub-Sitzung, 23.08.2026).
        $gridID = $this->GridPowerID();
        $gridSign = $this->GridPowerSign($gridID);
        $gridDraw = (!$isFuture) ? $this->SlotEnergyBars($aid, $gridID, $start, $end, $gridSign) : [];
        // Monats-Spitzenwert der Netzbezugsleistung (Dietmar,
        // 07.08.2026) - je Tag identisch fuer alle Tage desselben
        // Monats, bewusst pro Tag mitgeschickt statt separat
        // nachgefordert (ein Archivdurchlauf mehr pro Tageswechsel ist
        // hier vertretbar, gleiche Groessenordnung wie gridDraw selbst).
        $gridMonthPeak = (!$isFuture) ? $this->MonthlyPeakDraw($aid, $gridID, $start, $gridSign) : null;

        $hasData = count($pv) > 0 || count($irr) > 0 || count($bat) > 0 || count($soc) > 0 || count($price) > 0
            || ($flow !== null && ($flow['hasData'] ?? false));
        foreach ($mpptSeries as $s) {
            $hasData = $hasData || count($s) > 0;
        }

        $sun = $this->SunRange($start);

        return [
            'k'        => $k,
            'id'       => date('Y-m-d', $start),
            'label'    => date('d.m.Y', $start),
            'hasData'  => $hasData,
            // Sonnenaufgang-1h/Sonnenuntergang+1h in ms - nur fuer den
            // Reiter "PV & Einstrahlung" als x-Achsen-Bereich genutzt
            // (Dietmars Wunsch: Nachtstunden ohne Erzeugung nicht anzeigen).
            'sunStart' => $sun[0] * 1000,
            'sunEnd'   => $sun[1] * 1000,
            // Kalendertag-Grenzen in ms (NICHT wie $end oben auf "jetzt"
            // gedeckelt) - der Batterie-Reiter soll auch am heutigen Tag
            // immer die vollen 0-24 Uhr zeigen, statt die x-Achse an der
            // aktuellen Uhrzeit abzuschneiden (Dietmars Wunsch,
            // 28.07.2026).
            'dayStart' => $start * 1000,
            // strtotime('+1 day', ...) statt +86400: an DST-Tagen sonst
            // eine falsche x-Achsen-Obergrenze fuer den Batterie-Reiter
            // (Verbund-DST-Audit, 27.08.2026).
            'dayEnd'   => strtotime('+1 day', $start) * 1000,
            'pv'       => $pv,
            'irr'      => $irr,
            'expected' => $expected,
            'bat'      => $bat,
            'soc'      => $soc,
            'mppt'     => $mpptSeries,
            'price'    => $price,
            'flow'     => $flow,
            'gridDraw' => $gridDraw,
            // Bezugsenergie Tag/Monat/Jahr bezogen auf DIESEN Tag (fuer die
            // Legende beim Navigieren, Dietmar 28.08.2026).
            'gridEnergy' => (!$isFuture) ? $this->GridEnergySummaryFor($start) : null,
            'gridMonthPeak' => $gridMonthPeak,
        ];
    }

    /**
     * Baut die Nutzlast fuer ein navigierbares Tage-Fenster (Ansicht
     * "Tag (Verlauf)") - Muster: InverterHubMonitor::BuildPayload(),
     * WINDOW_DAYS=8. Ein kleines Fenster wird weiterhin eager mit jedem
     * Render() mitgeschickt; alles darueber hinaus holt sich das Frontend
     * per requestAction('dayData') nach - siehe BuildDayData().
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
        // Nur wenn die Anzahl PVF-Generatoren zur Anzahl gefundener MPPT-
        // Straenge passt, ist die 1:1-Zuordnung "Generator N = Strang N"
        // (Reihenfolge von PVF_GetGenerators()) belastbar genug fuer eine
        // Erwartungskurve je Strang (Dietmars Wunsch, 28.07.2026).
        $mpptModelUsable = ($model !== null && isset($model['generatorKwp']) && count($mppt) > 0 && count($model['generatorKwp']) === count($mppt));
        // Anteil je Strang an der Gesamt-kWp - die Erwartungskurve je Strang
        // hat exakt dieselbe Form wie die bereits gesendete Gesamt-
        // Erwartungskurve ($expected), nur mit einem anderen kWp-Faktor
        // skaliert (Einstrahlung, PR und Temperaturableitung sind fuer alle
        // Straenge identisch). Statt je Strang eine komplett eigene 5-Minuten-
        // Zeitreihe ueber alle WINDOW_DAYS zu senden (hat den Ausgabepuffer
        // gesprengt: "Output-Buffer exceeds Limit", live gefunden 28.07.2026),
        // schickt das Backend nur DIESEN einen Skalar je Strang - das
        // Frontend multipliziert ihn clientseitig mit den ohnehin schon
        // vorhandenen Punkten von d.expected.
        $mpptShare = [];
        if ($mpptModelUsable) {
            $mpptShare = array_combine(array_keys($mppt), array_map(function ($kwp) use ($model) {
                return $model['totalKwp'] > 0.0 ? $kwp / $model['totalKwp'] : 0.0;
            }, $model['generatorKwp']));
        }

        $days = [];
        // k=-1 (morgen) zusaetzlich zu k=0..WINDOW_DAYS-2 (heute rueckwaerts) -
        // NUR der Strompreis-Reiter kann einen Folgetag ueberhaupt fuellen
        // (TIBBERGR_GetPriceCurve liefert morgen, sobald Tibber die Preise
        // veroeffentlicht hat); Dietmars Wunsch, 28.07.2026: "Morgen" muss
        // per Vor-Navigation erreichbar sein. Bewusst als ZUSAETZLICHER Tag
        // VOR dem bisherigen Fenster eingefuegt (Index 0 bleibt "heute" fuer
        // den JS-Default idx=1, nicht 0 - siehe module.html) statt das
        // Fenster nach vorn zu verschieben, damit alle anderen Reiter beim
        // Oeffnen der Kachel unveraendert mit "heute" starten. Dieses kleine
        // Fenster wird weiterhin EAGER mit jedem Render()-Timer-Tick
        // mitgeschickt (sofortige Vor/Zurueck-Navigation ohne Wartezeit);
        // alles darueber hinaus holt sich das Frontend seit Dietmars Fund
        // vom 23.08.2026 ("warum komme ich nur bis zum 17.08. zurueck? -
        // Prinzipiell moechte ich in allen Reitern alle Tage sehen")
        // einzeln per requestAction('dayData', {k}) nach - siehe BuildDayData()
        // und RequestAction(). Kein fixes Fenster mehr, das die Navigation
        // nach hinten begrenzt.
        for ($k = -1; $k < self::WINDOW_DAYS - 1; $k++) {
            $days[] = $this->BuildDayData($k, $aid, $pvID, $irrID, $tempID, $tc, $batID, $socID, $mppt, $model);
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
            // Manuelle Theme-Angabe statt Erkennung (siehe Kommentar bei
            // RegisterPropertyBoolean('LightTheme',...) in Create()).
            'lightTheme' => $this->ReadPropertyBoolean('LightTheme'),
            'hasPv'    => $pvID > 0,
            'hasIrr'   => $irrID > 0,
            'hasModel' => $model !== null,
            'hasMpptModel' => $mpptModelUsable,
            'hasEnergyFlow' => $this->singleInverterHubID() > 0 || count($this->MeterHubAssignments()) > 0,
            'hasGrid'  => $this->GridPowerID() > 0,
            // Quell-Verfuegbarkeit fuer die automatische Reiter-Sichtbarkeit
            // (Dietmar, 28.08.2026): Reiter ohne Datenquelle werden
            // ausgeblendet, Reiter mit Quelle aber ohne aktuelle Daten rot
            // eingefaerbt (siehe updateTabStates() in module.html).
            'hasTibber' => $this->TibberInstanceID() > 0,
            'hasEms'   => $this->EmsInstanceID() > 0,
            // Netzbezug/-einspeisung stammt bei verzoegert archivierenden
            // Zaehlern (Inexogy/MeterHub, MHUB_GetFunctions()-Feld
            // "latency"==="delayed") mit 15-45 Min. Nachlauf aus dem
            // Archiv - MeterHub-Sitzung, 27.08.2026: Feature-Wunsch
            // Dietmars, diese Backfill-Grenze im Strompreis-Reiter sichtbar
            // zu machen, statt den Lastgang implizit als "bis jetzt"
            // vollstaendig erscheinen zu lassen. null bei Echtzeit-Zaehlern
            // (kein Nachlauf, keine Anzeige noetig).
            'gridWatermarkTs' => $this->GridArchiveWatermarkTs(),
            // Tages-/Monats-/Jahres-Bezugsenergie fuer die Legende im
            // Strompreis-Reiter (Dietmar, 27.08.2026: "hinter dem
            // Netzbezug die Tages, Monats und Jahresenergie").
            'gridEnergy' => $this->GridEnergySummary(),
            // Animationsstil der Reiterleiste (Doppelpfeil-Variable, 0-3).
            'tabAnim'  => (int) $this->GetValue('TabAnimation'),
            // PV-Prognoseprofil (heute+morgen) fuer die Helligkeit der
            // Sonnenstand-Tattoos (Dietmar, 28.08.2026: "die Sonnen und
            // irgendwie auch deren Intensität an die PV Prognose koppeln")
            // - leer, wenn kein Prognose-Modul installiert ist.
            'pvfSun'   => $this->PvfSunForecast(),
            'mpptShare' => $mpptShare,
            'hasBat'   => $batID > 0,
            'hasSoc'   => $socID > 0,
            'mpptKeys' => array_keys($mppt),
            'days'     => $days,
            'energy'   => $energy,
            // Tagesplan NICHT mehr Teil des Haupt-Payloads (Dietmar,
            // 21.08.2026: "Laden benoetigt sehr lange") - PVF_GetForecast()/
            // LFC_GetForecast() koennen intern bis zu LFC_LookbackDays
            // (Default 365!) Kandidatentage durchsuchen, macht bei jedem
            // Render() (alle 5 Minuten, UNABHAENGIG vom gerade sichtbaren
            // Reiter) unnoetig teure Archivzugriffe. Nur noch 'hasEms' als
            // billiger Hinweis, ob der Reiter ueberhaupt sinnvoll ist -
            // die eigentlichen Daten holt requestAction('dayPlanLoad') erst,
            // wenn der Tagesplan-Reiter tatsaechlich geoeffnet wird (gleiches
            // Nachforder-Muster wie Bilanz/Jahresvergleich).
            'hasEms'   => $this->EmsInstanceID() > 0,
            'hasStromGedacht' => $this->StromGedachtInstanceID() > 0,
            'engine'   => ($this->readStringProperty('Engine', self::DEF_ENGINE) === 'highcharts') ? 'highcharts' : 'echarts',
            'bg'       => $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND)),
            'font'     => $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT)),
            // Einfuehrungs-Tour (29.08.2026) - Rueckkanal fuer die
            // Bestaetigung per WebHook, siehe ProcessHookData()/dismissTour()
            // in module.html.
            'hookPath' => '/hook/nrgdashpvmonitor' . $this->InstanceID,
            'showTour' => !$this->ReadAttributeBoolean('TourSeen'),
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
