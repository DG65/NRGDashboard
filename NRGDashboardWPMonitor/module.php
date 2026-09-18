<?php

declare(strict_types=1);

/**
 * NRGDashboardWPMonitor - Verlaufs-/Diagnose-Kachel fuer die Waermepumpe,
 * Geschwistermodul zu NRGDashboardPVMonitor (PV/Batterie/Netz). Architektur
 * bewusst 1:1 von dort uebernommen (Dietmar, 18.08.2026: "alles Grundlegende
 * ... praezise analog bauen", inkl. Highcharts/ECharts-Wahl):
 * - EIN Payload deckt sowohl das rollierende Tage-Fenster (WINDOW_DAYS) als
 *   auch die komplette Woche/Monat/Jahr/Gesamt/Benutzerdefiniert-Ansicht ab
 *   (Tages-kWh-Karten ueber SPAN_YEARS) - das Frontend navigiert danach rein
 *   clientseitig, ohne bei jedem Ansichtswechsel erneut das Archiv
 *   abzufragen (siehe NRGDashboardPVMonitor::buildPayload()-Kommentar).
 * - Highcharts/ECharts wahlweise (Formular-Property "Engine"), beide per
 *   CDN nachgeladen statt eingebettet (Lizenz/Ausgabepuffer-Gruende, siehe
 *   NRGDashboardPVMonitor::ensureHighcharts()/ensureECharts()).
 * - Eigene, selbst kontrollierte Legende statt der eingebauten Klick-
 *   Ereignisse beider Bibliotheken (siehe module.html renderLegend()).
 *
 * Datenquelle wie NRGDashboardHeatSchema: der gemeinsame 'heatpump'-
 * Vertragstyp (HEISHA_GetFunctions()/WPHUB_GetFunctions()), generisch -
 * kein Modul wird vorausgesetzt, fehlende optionale Felder (contractVersion
 * 1.10: heatOutputPowerID/outsideTempID/compressorStartsID/
 * operationsHoursID; 1.9: copEstimateID/copMeasuredID/
 * dailyPerformanceFactorID; 1.11: dailyEnergyTotalID - WPHub, taeglich um
 * Mitternacht zurueckspringendes Zaehlerfeld aus der Panasonic Comfort
 * Cloud, bevorzugt gegenueber der aus PowerID hochgerechneten Schaetzung,
 * siehe DailyCounterMap()) blenden die jeweilige Kennzahl/Kurve einfach
 * aus, statt eine Nullanzeige zu zeigen.
 *
 * Kennzahlen-Kopfzeile + Tages-/Wochen-/Monats-/Jahres-/Gesamt-/
 * Benutzerdefiniert-Ansicht (elektrische/thermische Leistung bzw. -energie,
 * Vorlauf/Ruecklauf, Aussentemp, Abtau-Schattierung). Detail-Umschalter
 * (Verdichterfrequenz, Durchfluss, WW-Temp, Betriebsart-Farbcodierung) sind
 * bewusst noch nicht gebaut - naechster Ausbauschritt.
 */
class NRGDashboardWPMonitor extends IPSModule
{
    private const HEISHA_GUID  = '{1919151A-3C0F-4C09-B906-291638EC1469}';
    private const WPHUB_GUID   = '{5BE429EA-3AAD-4A8B-85DE-5778CCA2E6BC}';
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const AGG_5MIN     = 5;
    private const AGG_DAY      = 1;
    // Rollierendes Tage-Fenster fuer die Tagesansicht (5-Minuten-Kurven) -
    // 1:1 NRGDashboardPVMonitor::WINDOW_DAYS: EIN Archivdurchlauf pro Serie
    // deckt alle per Vor/Zurueck erreichbaren Tage ab, kein Nachladen bei
    // jedem Klick.
    private const WINDOW_DAYS  = 8;
    private const SPAN_YEARS   = 5;
    // Sanity-Obergrenze fuer archivierte Leistungswerte (01.09.2026, Fund
    // bei NRGDashboardTile/PVMonitor: 261.554.185 W durch einen Modbus-TID-
    // Bug bei InverterHub, hat dort einen Tagesbalken auf einen absurden
    // Wert gezogen - derselbe Fehlermechanismus betrifft DailyEnergyMap()
    // hier 1:1). Bewusst KEIN anlagenspezifischer Wert (CLAUDE.md
    // Kernprinzip 2) - 1 MW ist fuer jede denkbare Heim-Waermepumpe
    // implausibel.
    private const IMPLAUSIBLE_POWER_W = 1_000_000.0;

    /**
     * NACHTRAG 01.09.2026 (siehe NRGDashboardPVMonitor::RowHasImplausiblePower(),
     * derselbe Fund/Fix) - 'Avg' allein verduennt einen einzelnen Ausreisser
     * ueber Tag/Monat zu stark, 'Max'/'Min' zeigen den Rohwert unverduennt.
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

    // Verbund-Formularkonvention (EMS/SUITE.md "Einheitliche Formular-Optik",
    // Muster NRGDashboardMap/Topology/Tile): "Was ist Neu" (versionsscharf
    // dismissible, Version IN der Caption) + Doku-Panel mit dauerhafter
    // Versionszeile + GitHub-Hinweis (noch kein Forum-Thread, Modul
    // unveroeffentlicht - einmalig dismissible). NEWS_VERSION bei jeder
    // nutzersichtbaren Aenderung erhoehen.
    private const NEWS_VERSION = '0.2.7';
    private const NEWS_ITEMS = [
        'Neu: Reiter "Heizkurven" (nur bei HeishaMon) - Vorlauftemperatur je Außentemperatur für bis zu 2 Heizkreise und Heizen/Kühlen direkt zum Ziehen im Diagramm, mit "Übernehmen"-Knopf zum Speichern auf der Wärmepumpe.',
        'Feedback-Panel jetzt 1:1 wie bei MeterHub: eigener "Zum Forums-Thread"-Knopf statt eines reinen Link-Textes.',
        '💬 Der Symcon-Forum-Thread ist jetzt live - der bisherige GitHub-Hinweis im Feedback-Panel verweist ab sofort dorthin.',
        '🧡 Neu: "Über dieses Modul" (Lizenz/Spenden-Hinweis) ganz unten im Formular, der Forum/GitHub-Hinweis ist jetzt ein eigenes, dismissibles Panel statt einer schlichten Zeile.',
        '👋 Neu: ein "Wozu dieses Modul?"-Panel ganz oben im Formular erklärt kurz, was diese Kachel tut und welchen Nutzen sie stiftet - gedacht für den ersten Kontakt, einmalig wegklickbar.',
        'Neuer "?"-Knopf oben rechts zeigt die Einführungs-Tour jederzeit erneut - unabhängig davon, ob sie schon einmal bestätigt wurde. Gedacht für gemeinsam genutzte Instanzen (z. B. eine Demo-/Vorstellungs-Instanz mit einem geteilten Zugang), wo jeder Besucher die Tour selbst starten können soll.',
        'Fix: ein einzelner defekter Archivwert (z. B. ein Kommunikationsfehler bei einem Partnermodul in der Größenordnung von Megawatt bei einer Heim-Wärmepumpe) verzerrte bisher Tagesansicht und Energiebilanz - solche unplausiblen Werte werden jetzt verworfen statt in die Darstellung einzufließen.',
        'Architektur an NRGDashboardPVMonitor angeglichen: Wochen-/Monats-/Jahres-/Gesamt-/Benutzerdefiniert-Ansicht laufen jetzt rein clientseitig (kein Nachladen bei jedem Ansichtswechsel mehr), plus wahlweise Highcharts oder ECharts als Zeichen-Engine (Formular "Darstellung").',
    ];
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';
    private const FORUM_URL = 'https://community.symcon.de/t/modul-nrg-stack-dashboard-energiefluss-kachel-3d-karte-verlaufs-charts-fuer-den-ganzen-verbund/144394';
    private const LICENSE_URL = 'https://github.com/DG65/NRGDashboard/blob/ems-integration/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    /**
     * "Ueber dieses Modul" (SUITE.md "Einheitliche Formular-Optik" Punkt 5) -
     * ganz unten, NACH dem Forum-Hinweis, bewusst NICHT dismissible (kein
     * Attribut/Ack-Methode) - eine Lizenz ist kein einmaliger Hinweis.
     * Wortlaut verbundweit identisch ("Variante A"), nur LICENSE_URL zeigt
     * auf das eigene Repo. Eingeklappt by default.
     */
    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);
        $this->RegisterPropertyString('Engine', self::DEF_ENGINE);
        $this->RegisterPropertyBoolean('LightTheme', false);
        // Manuelle Waermepumpen-Auswahl fuer den Fall mehrerer gefundener
        // Instanzen (Dietmar hat bei sich nur eine - Default 0 = automatisch
        // die erste gefundene nehmen, wie schon in DiscoverHeatpumps()
        // dokumentiert).
        $this->RegisterPropertyInteger('HeatpumpInstance', 0);

        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        // "Wozu dieses Modul?" (SUITE.md "Einheitliche Formular-Optik" Punkt 0,
        // 14.09.2026) - einmalig dismissible, nicht versioniert.
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        // Einfuehrungs-Tour bei erster Benutzung (28.08.2026, Dietmar:
        // "eine Tour die bei der ersten Benutzung eingeblendet und nur per
        // Haken ausgeblendet werden kann") - je Instanz einmalig, WebFront-
        // seitig. Bestaetigung kommt ueber den WebHook zurueck
        // (ProcessHookData(), ?dismissTour=1) - die Kachel selbst hat als
        // sandboxed HTML-SDK-Tile keinen anderen Rueckkanal in die Instanz.
        $this->RegisterAttributeBoolean('TourSeen', false);

        $this->RegisterTimer('Refresh', 0, 'NRGDASHWPMON_Render($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);
        $this->SetTimerInterval('Refresh', 5 * 60 * 1000);
        // Standalone-Webseite fuer IPSView/Browser (WebView/Popup) - Muster
        // Prognoses Energiebilanz-Modul (Dietmar, 27.08.2026: "alle Kacheln
        // ... auch so vorbereiten, dass man sie in IPSView einbinden kann").
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->RegisterHook('/hook/nrgdashwpmonitor' . $this->InstanceID);
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
     * Popup oder jeden Browser). Aufruf: /hook/nrgdashwpmonitor<InstanzID>.
     * Mit ?json=1 werden nur die Daten geliefert (fuer die Auto-Aktualisierung).
     */
    public function ProcessHookData()
    {
        // Einfuehrungs-Tour bestaetigt (28.08.2026) - vom Tour-Overlay in
        // module.html per fetch() aufgerufen, siehe Create()/dismissTour().
        if (isset($_GET['dismissTour'])) {
            $this->WriteAttributeBoolean('TourSeen', true);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            return;
        }
        $payload = $this->buildPayload();
        if (isset($_GET['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($payload) . ');'
               . 'setInterval(function(){fetch(window.location.pathname+"?json=1")'
               . '.then(function(r){return r.text();}).then(function(t){handleMessage(t);})'
               . '.catch(function(){});},30000);</script>';
        echo $html;
    }

    public function GetConfigurationForm()
    {
        $raw = str_replace('%%HOOK%%', '/hook/nrgdashwpmonitor' . $this->InstanceID, file_get_contents(__DIR__ . '/form.json'));
        $form = json_decode($raw, true);
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }

        $this->injectVersionIntoDocPanel($form);

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
                'caption' => '💬  Feedback im Symcon-Forum',
                'items' => [
                    ['type' => 'Label', 'caption' => 'NRGDashboard ist Beta — Rückmeldungen, Fehler und Wünsche sind ausdrücklich willkommen im Forum-Thread.'],
                    ['type' => 'Button', 'caption' => 'Zum Forums-Thread', 'onClick' => "echo '" . self::FORUM_URL . "';", 'link' => true],
                    ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'NRGDASHWPMON_DismissReviewHint($id);'],
                ],
            ];
        }
        $form['elements'][] = $this->LicenseHint();

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
     * "Wozu dieses Modul?" (SUITE.md "Einheitliche Formular-Optik" Punkt 0) -
     * ganz oben, VOR dem News-Panel, einmalig dismissible.
     */
    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Zeigt Verlauf und Kennzahlen einer Wärmepumpe - elektrische/thermische Leistung, Vorlauf/Rücklauf, Außentemperatur - als Tages- bis Jahresansicht, wahlweise mit Highcharts oder ECharts.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen: Effizienz und Betriebsverhalten der Wärmepumpe über die Zeit verstehen, ohne selbst Diagramme aus den Rohdaten bauen zu müssen.'],
                ['type' => 'Label', 'caption' => 'Die Daten liefert HeishaMon oder WPHub über den gemeinsamen "heatpump"-Vertrag - fehlt eines davon, entfallen nur einzelne Kennzahlen, nicht die ganze Kachel. NRGDashboardHeatSchema zeigt ergänzend den Aufbau der Anlage als Prinzipschema.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'NRGDASHWPMON_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntroApply(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function AckPurposeIntro(): void
    {
        $this->AckPurposeIntroApply();
        $this->propagateDismiss('AckPurposeIntroApply');
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
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'NRGDASHWPMON_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
    }

    // Eigene Modul-GUID (14.09.2026, "geteiltes Ausblenden ueber
    // Geschwister-Instanzen") - fuer die Geschwistersuche unten, NICHT
    // fuer die Discovery anderer Verbund-Module (dafuer stehen eigene
    // GUID-Konstanten je Partnermodul).
    private const SELF_MODULE_GUID = '{86574F22-636A-4208-A252-FEECB20789E9}';

    /**
     * Ruft dieselbe (parameterlose) Methode auf jeder anderen Instanz
     * DESSELBEN Kachel-Moduls auf - "Was ist Neu"/Review-Hinweis gelten
     * pro Modul, nicht pro Instanz, ein Nutzer mit mehreren Instanzen
     * (z. B. Wohnhaus + Demo) soll sie nur einmal bestaetigen muessen.
     * $propagate=false auf der Geschwister-Seite verhindert, dass diese
     * ihrerseits wieder alle anderen (inkl. uns) anstoesst - keine
     * Rekursion, ein Durchlauf pro Klick.
     */
    /**
     * Ruft eine reine "Uebernehmen"-Methode (kein $propagate-Flag mehr,
     * MeterHub/EMS-Verfeinerung 14.09.2026: getrennte Funktionen statt
     * Prozessmerker schliessen Ping-Pong STRUKTURELL aus, nicht nur durch
     * Konvention) auf jeder anderen Instanz DESSELBEN Kachel-Moduls auf.
     * try/catch(\Throwable) statt @ (EMS/MeterHub-Fund): @ haelt keinen
     * Fatal Error auf - eine defekte Geschwister-Instanz durfte sonst
     * nicht die ganze Kette (und die eigene Aktion) mitreissen.
     */
    private function propagateDismiss(string $applyMethod): void
    {
        foreach (@IPS_GetInstanceListByModuleID(self::SELF_MODULE_GUID) as $sib) {
            $sib = (int) $sib;
            if ($sib === $this->InstanceID || !@IPS_InstanceExists($sib)) {
                continue;
            }
            $fn = 'NRGDASHWPMON_' . $applyMethod;
            if (!function_exists($fn)) {
                continue;
            }
            try {
                call_user_func($fn, $sib);
            } catch (\Throwable $e) {
                $this->SendDebug(__FUNCTION__, sprintf('Propagieren an Instanz #%d (%s) fehlgeschlagen: %s', $sib, $applyMethod, $e->getMessage()), 0);
            }
        }
    }

    /**
     * "Uebernehmen": setzt NUR den lokalen Zustand, propagiert NIE selbst -
     * das ist genau das, was propagateDismiss() auf jeder Geschwister-
     * Instanz aufruft. Oeffentlich, weil IPS Cross-Instanz-Aufrufe nur ueber
     * die generierte Praefix-Funktion einer PUBLIC Methode erlaubt.
     */
    public function AckNewsApply(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    /**
     * "Was ist Neu" bestaetigen (Button) - Verbund-Muster "geteiltes
     * Ausblenden ueber Geschwister-Instanzen" (SUITE.md, 14.09.2026,
     * EMS/Dietmar): mehrere Instanzen desselben Kachel-Moduls sollen den
     * Hinweis nur einmal zeigen, nicht je Instanz einzeln. Ruft AckNewsApply()
     * lokal auf und propagiert an alle Geschwister - die rufen dort ebenfalls
     * nur AckNewsApply() auf, nie AckNews() selbst, Ping-Pong ist damit
     * strukturell ausgeschlossen (nicht nur per Laufzeit-Flag).
     */
    public function AckNews(): void
    {
        $this->AckNewsApply();
        $this->propagateDismiss('AckNewsApply');
    }

    /** "Uebernehmen": siehe AckNewsApply() - nur lokaler Zustand, keine Propagation. */
    public function DismissReviewHintApply(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
    }

    /** Store-Review-Hinweis wegklicken (Button) - siehe AckNews(). */
    public function DismissReviewHint(): void
    {
        $this->DismissReviewHintApply();
        $this->propagateDismiss('DismissReviewHintApply');
    }

    /** Konsolen-Gegenstueck zur WebFront-Dismiss-Tour. */
    public function ResetTour(): string
    {
        $this->WriteAttributeBoolean('TourSeen', false);
        // Store-Checkliste Punkt 13 (13.09.2026): der Button gab bisher keine
        // sichtbare Rueckmeldung in der Konsole - die Wirkung zeigte sich nur
        // beim naechsten Oeffnen der WebFront-Kachel.
        return '✅ Tour wird beim nächsten Öffnen der Kachel wieder angezeigt.';
    }

    private function readIntProperty(string $name, int $default): int
    {
        try {
            $v = (int) $this->ReadPropertyInteger($name);
            return $v !== 0 ? $v : $default;
        } catch (Exception $e) {
            return $default;
        }
    }

    private function readStringProperty(string $name, string $default): string
    {
        try {
            $v = (string) $this->ReadPropertyString($name);
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

    /**
     * Heizkurven-Reiter (18.09.2026, EMS-Verbundabstimmung mit HeishaMon/
     * WPHub) - v1 NUR gegen HeishaMon, das als einziges der vier
     * Waermepumpen-Quellmodule heute einen geprueften Lese-UND-Schreibweg
     * hat (Zwei-Punkt-Modell je Zone/Heizen-Kuehlen, HEISHA_GetHeatingCurve/
     * HEISHA_SetHeatingCurve). Panasonic Cloud (WPHub) kennt gar kein
     * Kurvenkonzept, Vaillant/NIBE/Stiebel Eltron (WPHub/WPModbusHub) sind
     * an keiner echten Anlage verifiziert - WPHub baut dort bewusst noch
     * keine Schreibzugriffe. Defensiv per function_exists() geprueft, damit
     * der Reiter automatisch erscheint, sobald HeishaMon den Vertrag
     * ausliefert, ohne dass wir hier nochmal etwas anpassen muessen.
     */
    private function HeatingCurveInstanceID(): int
    {
        $unit = $this->SelectedHeatpump();
        if ($unit === null) {
            return 0;
        }
        $id = (int) ($unit['_instanceID'] ?? 0);
        if ($id <= 0 || !@IPS_InstanceExists($id)) {
            return 0;
        }
        $moduleID = @IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '';
        return ($moduleID === self::HEISHA_GUID) ? $id : 0;
    }

    private function HeatingCurveAvailable(): bool
    {
        return $this->HeatingCurveInstanceID() > 0 && function_exists('HEISHA_GetHeatingCurve');
    }

    private function BuildHeatingCurve(): array
    {
        $id = $this->HeatingCurveInstanceID();
        if ($id <= 0 || !function_exists('HEISHA_GetHeatingCurve')) {
            return ['curveModel' => 'none', 'curveWritable' => false, 'zones' => []];
        }
        try {
            $result = @HEISHA_GetHeatingCurve($id);
        } catch (\Throwable $e) {
            return ['curveModel' => 'none', 'curveWritable' => false, 'zones' => []];
        }
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        return is_array($result) ? $result : ['curveModel' => 'none', 'curveWritable' => false, 'zones' => []];
    }

    // 1:1 NRGDashboardPVMonitor::ColorOrEmpty()/FontStack() - dieselbe
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
     * 1:1 Muster NRGDashboardPVMonitor::DaySeries().
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
                    sprintf('Unplausibler Archivwert verworfen: Variable #%d, %s, Max=%.0f W', $vid, date('d.m.Y H:i', (int) $row['TimeStamp']), (float) ($row['Max'] ?? 0)),
                    0
                );
                continue;
            }
            $pts[] = [(int) $row['TimeStamp'] * 1000, round($w, 1)];
        }
        return $pts;
    }

    /**
     * Zusammenhaengende "aktiv"-Intervalle einer boolschen/0-1-Groesse
     * (hier: Abtaubetrieb) als [[startMs,endMs],...] - fuer die
     * Schattierung im Chart (ECharts/Highcharts markArea/plotBand). Bucket
     * gilt als aktiv, wenn der 5-Minuten-Mittelwert > 0.5 ist.
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
     * NRGDashboardPVMonitor::PowerToEnergy().
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
            $avg = (float) $row['Avg'];
            if ($this->RowHasImplausiblePower($row)) {
                $this->SendDebug(
                    __FUNCTION__,
                    sprintf('Unplausibler Archivwert verworfen: Variable #%d, %s, Max=%.0f W', $vid, date('d.m.Y H:i', (int) $row['TimeStamp']), (float) ($row['Max'] ?? 0)),
                    0
                );
                continue;
            }
            $kwh += max(0.0, $avg) * (5.0 / 60.0) / 1000.0;
        }
        return $kwh;
    }

    /**
     * Tages-kWh-Karte (Datum => kWh) ueber SPAN_YEARS, 1:1 Muster
     * NRGDashboardPVMonitor::DailyEnergyMap() - EIN Archivdurchlauf pro
     * Serie, das Frontend gruppiert daraus Woche/Monat/Jahr/Gesamt/
     * Benutzerdefiniert selbst (kein weiterer Archivzugriff bei jedem
     * Ansichtswechsel, siehe module.html buildEnergyRows()).
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
                    sprintf('Unplausibler Archivwert verworfen: Variable #%d, %s, Max=%.0f W', $vid, date('d.m.Y', (int) $row['TimeStamp']), (float) ($row['Max'] ?? 0)),
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
     * DailyEnergyMap) - fuer nicht-energetische Groessen wie Aussentemp,
     * 1:1 Muster NRGDashboardPVMonitor::DailyAverageMap().
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
     * Tages-kWh-Karte aus einem taeglich um Mitternacht auf 0
     * zurueckspringenden Zaehlerfeld (WPHub `dailyEnergyTotalID`, seit
     * heatpump-Vertrag 1.11 - Panasonic Cloud liefert keinen echten
     * kumulativen Zaehler, siehe SUITE.md "NRG.kWh nur aus echten
     * kumulativen Zaehlern"). Der Tageswert ist das Tagesmaximum (Max je
     * Tagesbucket), NICHT der Durchschnitt wie bei DailyEnergyMap() -
     * WPHub-Hinweis, 17.08.2026: "das sind Tageswerte, kein Verlauf zum
     * Aufsummieren ... fuer einen Tagesvergleich braeuchtet ihr den
     * archivierten Verlauf, nicht eine simple Differenzbildung".
     */
    private function DailyCounterMap(int $aid, int $vid): array
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
            if (!isset($row['Max']) || !is_finite((float) $row['Max'])) {
                continue;
            }
            $out[date('Y-m-d', (int) $row['TimeStamp'])] = round((float) $row['Max'], 2);
        }
        return $out;
    }

    /**
     * Erster Schreibzugriff dieser Kachel ueberhaupt (18.09.2026, Heizkurven-
     * Reiter) - bisher kam WPMonitor komplett ohne RequestAction() aus (ein
     * Payload pro Refresh deckte alles ab, siehe buildPayload()-Kommentar).
     * Ein Nutzer-Klick auf "Übernehmen" ist aber ein echter Schreibvorgang,
     * kein Nachladen - dafuer braucht es diesen eigenen Weg.
     */
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'heatingCurveLoad') {
            $this->UpdateVisualizationValue(json_encode([
                'ok'    => true,
                'type'  => 'heatingCurveUpdate',
                'curve' => $this->BuildHeatingCurve(),
            ]));
            return;
        }
        if ($Ident === 'heatingCurveSave') {
            $req = json_decode((string) $Value, true);
            $id = $this->HeatingCurveInstanceID();
            $ok = false;
            if (is_array($req) && $id > 0 && function_exists('HEISHA_SetHeatingCurve')) {
                $zone = (string) ($req['zone'] ?? '');
                $mode = (string) ($req['mode'] ?? '');
                if (in_array($zone, ['z1', 'z2'], true) && in_array($mode, ['heat', 'cool'], true)) {
                    try {
                        $ok = (bool) @HEISHA_SetHeatingCurve(
                            $id,
                            $zone,
                            $mode,
                            (int) ($req['targetHighC'] ?? 0),
                            (int) ($req['targetLowC'] ?? 0),
                            (int) ($req['outsideHighC'] ?? 0),
                            (int) ($req['outsideLowC'] ?? 0)
                        );
                    } catch (\Throwable $e) {
                        $ok = false;
                    }
                }
            }
            $this->UpdateVisualizationValue(json_encode([
                'ok'    => true,
                'type'  => 'heatingCurveSaved',
                'saved' => $ok,
                // Frische Werte direkt zurueck (statt eines zweiten
                // Roundtrips) - falls HEISHA_SetHeatingCurve() intern rundet/
                // begrenzt, sieht der Nutzer sofort den tatsaechlich
                // uebernommenen Wert, nicht nur seinen eigenen Drag-Stand.
                'curve' => $this->BuildHeatingCurve(),
            ]));
            return;
        }
        parent::RequestAction($Ident, $Value);
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

    /**
     * EIN Payload pro Refresh - siehe Klassenkommentar. Rollierendes
     * Tage-Fenster (WINDOW_DAYS) + Tages-kWh-Karten (SPAN_YEARS) fuer die
     * Energie-Ansichten decken jede Navigation ab, ohne dass die Kachel
     * dafuer je RequestAction()/UpdateVisualizationValue() bemuehen muss
     * (1:1 Muster NRGDashboardPVMonitor::buildPayload()).
     */
    // lastSeenAt aelter als das = keine aktuelle Messung (13.09.2026).
    // Grosszuegiger als im Energiefluss (300 s): Waermepumpen-Clouds werden
    // teils nur alle paar Minuten abgefragt (WPHub-Vorgabe 60 s, erhoehbar).
    private const STALE_AFTER_SEC = 900;

    /**
     * Hinweistext, wenn die Quelle (heatpump-Vertrag, Feld lastSeenAt -
     * generisch wie ChargerHub/OCPPHub) laenger keine echte Messung mehr
     * hatte; leer, wenn aktuell oder die Quelle das Feld nicht liefert.
     */
    private function staleText(array $unit): string
    {
        $lastSeen = (int) ($unit['lastSeenAt'] ?? 0);
        // Liefert die Quelle ihr Abfrageintervall (pollInterval, z. B. WPHub
        // ab Vertrag 1.14), gilt das Dreifache davon - nie weniger als 900 s.
        $limit = max(self::STALE_AFTER_SEC, 3 * (int) ($unit['pollInterval'] ?? 0));
        if ($lastSeen <= 0 || time() - $lastSeen <= $limit) {
            return '';
        }
        return 'Keine aktuelle Messung seit ' . date('d.m.Y H:i', $lastSeen) . ' - die angezeigten Werte sind veraltet.';
    }

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
        $dailyEnergyTotalID = (int) ($unit['dailyEnergyTotalID'] ?? 0);

        $aid = $this->ArchiveID();
        $todayStart = strtotime('today 00:00:00');

        // Woche/Monat/Jahr/Gesamt/Benutzerdefiniert: EIN Archivdurchlauf pro
        // Serie ueber SPAN_YEARS Jahre (Tages-kWh), das Frontend gruppiert
        // daraus die jeweilige Ansicht selbst - kein erneuter Archivzugriff
        // bei jedem Ansichts-/Zeitraumwechsel (1:1 Muster
        // NRGDashboardPVMonitor::buildPayload(), Abschnitt $energy). Vorab
        // berechnet, damit die Tage-Schleife unten dieselben Karten fuer
        // die Tages-kWh-KPI-Zeilen wiederverwenden kann statt ein zweites
        // Mal je Tag zu integrieren.
        // Bevorzugt der echte, taeglich zurueckspringende Zaehlerwert
        // (WPHub dailyEnergyTotalID) statt der aus PowerID hochgerechneten
        // Schaetzung - bei WPHub ist PowerID ohnehin immer 0 (Panasonic
        // Cloud liefert keine Momentanleistung), bei HeishaMon fehlt das
        // Zaehlerfeld und die Hochrechnung bleibt die einzige Quelle.
        $electricCounter = ($aid > 0 && $dailyEnergyTotalID > 0) ? $this->DailyCounterMap($aid, $dailyEnergyTotalID) : [];
        $energy = [
            'electric'    => (count($electricCounter) > 0) ? $electricCounter : (($aid > 0) ? $this->DailyEnergyMap($aid, $powerID) : []),
            'thermal'     => ($aid > 0) ? $this->DailyEnergyMap($aid, $heatOutID) : [],
            'outsideTemp' => ($aid > 0) ? $this->DailyAverageMap($aid, $outsideTempID) : [],
        ];

        $days = [];
        for ($k = 0; $k < self::WINDOW_DAYS; $k++) {
            // strtotime('-N day', ...) statt $todayStart - $k*86400: rechnet
            // ueber Kalendertage (respektiert Sommer-/Winterzeit), nicht
            // ueber eine feste Sekundenzahl - sonst landet $start ab dem
            // naechsten DST-Wechsel dauerhaft eine Stunde neben der echten
            // Mitternacht (Verbund-DST-Audit, 27.08.2026).
            $start = ($k > 0) ? strtotime('-' . $k . ' day', $todayStart) : $todayStart;
            $end = min(time(), strtotime('+1 day', $start));
            $dateKey = date('Y-m-d', $start);

            $electricPower = ($aid > 0) ? $this->DaySeries($aid, $powerID, $start, $end) : [];
            $thermalPower = ($aid > 0) ? $this->DaySeries($aid, $heatOutID, $start, $end) : [];
            $mainOutletTemp = ($aid > 0) ? $this->DaySeries($aid, $mainOutletID, $start, $end) : [];
            $mainInletTemp = ($aid > 0) ? $this->DaySeries($aid, $mainInletID, $start, $end) : [];
            $outsideTemp = ($aid > 0) ? $this->DaySeries($aid, $outsideTempID, $start, $end) : [];
            $defrostEpisodes = ($aid > 0) ? $this->ActiveEpisodes($aid, $defrostID, $start, $end) : [];

            $hasData = count($electricPower) > 0 || count($thermalPower) > 0
                || count($mainOutletTemp) > 0 || count($mainInletTemp) > 0;

            $days[] = [
                'id'              => $dateKey,
                'label'           => date('d.m.Y', $start),
                'hasData'         => $hasData,
                'dayStart'        => $start * 1000,
                // strtotime('+1 day', ...) statt +86400: an DST-Tagen sonst
                // eine falsche x-Achsen-Obergrenze (Verbund-DST-Audit,
                // 27.08.2026).
                'dayEnd'          => strtotime('+1 day', $start) * 1000,
                'electricPower'   => $electricPower,
                'thermalPower'    => $thermalPower,
                'mainOutletTemp'  => $mainOutletTemp,
                'mainInletTemp'   => $mainInletTemp,
                'outsideTemp'     => $outsideTemp,
                'defrostEpisodes' => $defrostEpisodes,
                'electricEnergyKwh' => $energy['electric'][$dateKey] ?? null,
                'thermalEnergyKwh'  => $energy['thermal'][$dateKey] ?? null,
            ];
        }

        $staleText = $this->staleText($unit);
        $copMeasured = $this->num($copMeasuredID);
        $copEstimate = $this->num($copEstimateID);
        $starts = $this->num($startsID);
        $hours = $this->num($hoursID);

        return [
            'ok'         => true,
            // Instanz-ID als Namensraum fuer die Legenden-Sichtbarkeit
            // (localStorage im Frontend) - Muster NRGDashboardPVMonitor.
            'uid'        => (string) $this->InstanceID,
            'label'      => (string) ($unit['Caption'] ?? $unit['caption'] ?? IPS_GetName((int) $unit['_instanceID'])),
            // Veraltete Messung (lastSeenAt, heatpump-Vertrag 1.13) - leer = aktuell
            'staleText'  => $staleText,
            // Wie NRGDashboardPVMonitor: Symcon bietet einer Kachel keinen
            // Weg, das aktuelle Hell/Dunkel-Theme zu erkennen - der Nutzer
            // setzt es einmalig selbst (Formular "Darstellung").
            'lightTheme' => (bool) $this->ReadPropertyBoolean('LightTheme'),
            'hasElectric' => $powerID > 0 || $dailyEnergyTotalID > 0,
            'hasThermal'  => $heatOutID > 0,
            'hasFlowTemps' => $mainOutletID > 0 && $mainInletID > 0,
            'hasOutsideTemp' => $outsideTempID > 0,
            'hasHeatingCurve' => $this->HeatingCurveAvailable(),
            'bg'         => $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND)),
            'font'       => $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT)),
            // Engine-Wahl 1:1 NRGDashboardPVMonitor (Dietmar, 18.08.2026:
            // "Natuerlich auch mit der Auswahl Highchart und Echart").
            'engine'     => ($this->readStringProperty('Engine', self::DEF_ENGINE) === 'highcharts') ? 'highcharts' : 'echarts',
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
            'days'   => $days,
            'energy' => $energy,
            // Bestaetigung per WebHook, siehe ProcessHookData()/dismissTour()
            // in module.html - die Kachel selbst hat als sandboxed HTML-SDK-
            // Tile keinen anderen Rueckkanal in die Instanz.
            'hookPath' => '/hook/nrgdashwpmonitor' . $this->InstanceID,
            'showTour' => !(bool) $this->ReadAttributeBoolean('TourSeen'),
        ];
    }
}
