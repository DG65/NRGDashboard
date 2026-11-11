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
require_once __DIR__ . '/../libs/FormStatus.php';
require_once __DIR__ . '/../libs/NRGDASH_Text.php';

class NRGDashboardForecast extends IPSModule
{
    use NRGDashFormStatus;

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
    // Sanity-Obergrenze fuer archivierte Leistungswerte (01.09.2026, Fund
    // bei NRGDashboardTile/PVMonitor/WPMonitor: 261.554.185 W durch einen
    // Modbus-TID-Bug bei InverterHub, hat dort Tages-/Monatswerte auf
    // absurde Werte gezogen - derselbe Fehlermechanismus betrifft
    // readMeasured() hier 1:1, das den "Ist"-Vergleich zur Prognose aus
    // AC_GetAggregatedValues()-Stundenmittelwerten baut). Bewusst KEIN
    // anlagenspezifischer Wert (CLAUDE.md Kernprinzip 2) - urspruenglich
    // 1 MW, war aber selbst unausgesprochen anlagenspezifisch: Solarpark
    // Hofweier liefert live legitim >1 MW (18.09.2026, Fund MeterHub-
    // Sitzung, siehe NRGDashboardTile fuer den vollen Befund). 50 MW ist
    // fuer jede reale Anlage implausibel, faengt den urspruenglichen
    // Defektwert (261.554.185 W) weiterhin klar ab.
    private const IMPLAUSIBLE_POWER_W = 50_000_000.0;

    /** Siehe NRGDashboardPVMonitor::RowHasImplausiblePower() - identische
     *  Logik, 'Max'/'Min' statt 'Avg' pruefen (Avg verduennt einen
     *  einzelnen Ausreisser ueber den Aggregationszeitraum). */
    private function RowHasImplausiblePower(array $row): bool
    {
        $max = isset($row['Max']) ? abs((float) $row['Max']) : 0.0;
        $min = isset($row['Min']) ? abs((float) $row['Min']) : 0.0;
        return max($max, $min) > self::IMPLAUSIBLE_POWER_W;
    }

    private const FORUM_URL = 'https://community.symcon.de/t/modul-nrg-stack-dashboard-energiefluss-kachel-3d-karte-verlaufs-charts-fuer-den-ganzen-verbund/144394';
    private const LICENSE_URL = 'https://github.com/DG65/NRGDashboard/blob/ems-integration/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    // Verbund-Formularkonvention (EMS/SUITE.md "Einheitliche Formular-Optik",
    // Muster NRGDashboardPVMonitor/HeatSchema) - "Was ist Neu" (versionsscharf
    // dismissible, Version IN der Caption) + Doku-Panel mit dauerhafter
    // Versionszeile + GitHub-Hinweis. NEWS_VERSION bei jeder nutzersichtbaren
    // Aenderung erhoehen.
    // Versionsweise statt EINER flachen Liste (Dietmar, 23.09.2026: "nur die
    // Neuerungen zwischen der zuletzt verwendeten und der nun installierten
    // Version anzeigen") - newsBanner() zeigt jeden Schluessel-Eintrag, dessen
    // Version NEUER ist als das zuletzt bestaetigte Attribut SeenNews (siehe
    // dort), gruppiert nach Version. Die alte, gewachsene Liste bleibt als EIN
    // Eintrag unter ihrer letzten Vergabe ('0.2.7') erhalten statt sie
    // nachtraeglich (und unzuverlaessig) pro Zeile einer Version zuzuordnen -
    // neue Eintraege ab jetzt bekommen ihre EIGENE Versionsnummer als Schluessel.
    private const NEWS_VERSIONS = [
        '0.2.10' => [
            '〰️ New (forum, thanks!): the measured lines can now be shown solid instead of dashed (setting “Measured lines solid instead of dashed”). The default stays dashed: in this tile the forecast is the authoritative, solid line. Without a color of its own, the solid actual line is drawn slightly darker than the forecast curve.',
        ],
        '0.2.9' => [
            '📏 Picked up from the forum (thanks!): the note about the resolution in the bar view can be hidden (“Show resolution note in the bar view”), and the plant power can be shown as a dashed line (“Show plant power (module kWp) as a dashed line”, off by default). In the form, “Plant power for the dashed line” lets you enter a value of your own in kW; at 0 the sum of the module kWp from your PV forecast applies.',
            '🔗 Clearer connection lines (follow-up question from the forum, thanks!): if the settings contain an instance that no longer exists, the tile ignores it and uses automatic detection - this is now noted alongside, the line stays 🔗 (connected) and shows no ⚠️.',
            '🔤 Making labels readable (mirrored from the energy balance tile of the Prognose module, hint from the forum): new setting “Labels” in the WebFront (double arrow) - automatic (as before, by operating system/browser), light or dark. With a dark WebFront theme on a light operating system, choose “Light”.',
            '📊 New view "Bars per time slice" (mirrored from the energy balance tile of the Prognose module, idea from the forum): selectable in the WebFront via the double arrow under "View" - one forecast bar per quarter hour, filled with the actual value, consumption as a line. The default view stays the curve.',
            '🩹 The measured line no longer disappears when the hourly aggregate in the archive is defective - it is then built from the raw values instead. A line above the actual-value fields in the form shows whether the variable is archived, whether the line is switched on and how many measured values arrive today.',
        ],
        '0.2.8' => [
            '🎨 The measured lines now have legend entries of their own ("PV (actual)", "Consumption (actual)") and can be hidden individually; optionally with a color of their own (switch "Own color for the measured ... line"). Without the switch everything stays as before (suggestion from the forum).',
        ],
        '0.2.7' => [
            'Feedback panel now exactly as in MeterHub: a dedicated "To the forum thread" button instead of a plain link text.',
            '💬 The Symcon forum thread is now live - the previous GitHub note in the feedback panel now points there.',
            '🧡 New: "About this module" (license/donation note) at the very bottom of the form; the forum/GitHub note is now a panel of its own that can be dismissed, instead of a plain line.',
            '👋 New: a "What is this module for?" panel at the very top of the form briefly explains what this tile does and what it is good for - meant for first contact, can be dismissed once.',
            'Fix: source modules are now also detected automatically when there are several instances but only one of them is active - an additional, switched-off test or demo instance used to block the detection completely.',
            'New "?" button at the top right shows the introduction tour again at any time - regardless of whether it has been confirmed before. Intended for shared instances (e.g. a demo/showcase instance with a shared login) where every visitor should be able to start the tour themselves.',
            'Fix: a single defective archive value (e.g. a communication error in a partner module in the order of megawatts) used to distort the "actual" comparison with the forecast - such implausible values are now discarded instead of flowing into the display.',
            '1:1 takeover of the display of the energy balance tile of the Prognose module: scrolling from more than 3 days with a fixed y-axis, legend to hide individual curves, automatic chart height.',
            'All display settings (colors, font, engine, days, actual display, grid, legend, fixed y-axis ...) directly in the WebFront - pull the tile open via the double arrow instead of searching in the console.',
            'Own actual-power variables (console: "Actual values") with their own unit detection and archive cache interval - independent of the forecast module\'s own configuration.',
            'Data source is now two separate fields (PV forecast/load forecast instance) instead of one combined energy balance instance, exactly as in the form of the Prognose module itself - still detected automatically when exactly one instance of each is installed.',
        ],
    ];

    /**
     * Texte der Kachel (module.html): englische Quelltexte, die dort mit tr('...') benutzt werden. PHP liefert die
     * Übersetzungen als Tabelle (window.NRGDASH_TEXTS), siehe NRGDASH_Text::kachelHtml(); der Prüfstand
     * .tools/locale-pruefen.php vergleicht diese Liste mit module.html und locale.json.
     */
    private const TILE_TEXTS = [
        'Show introduction tour',
        'Back',
        'Next',
        '✓ Understood, do not show again',
        'This tile remembers what it promised yesterday',
        'Most forecast widgets only show “what is coming”. This one also stores a snapshot of yesterday’s forecast and places it next to the actually measured course under “Yesterday” – you see at a glance how well the PV and consumption forecasts really hit the mark, not just what is expected for today.',
        'PV against consumption – the gap is your grid import',
        'Two curves in one chart: PV generation and house consumption as a forecast (P50) over up to 5 days. The tooltip on tap/hover also shows the balance at that time of day – exactly the EMS view of whether grid import or export is coming up.',
        'Uncertainty band for the load forecast',
        'A P10–P90 band surrounds the consumption curve – the range in which the actual load is very likely to fall. The PV forecast, in contrast, is physics-based (no spread) and deliberately stays a plain line.',
        'Actual values overlaid live',
        'With two actual-power variables of your own (console: “Actual values”), the current value appears in the legend, a dot on the “now” line and the course of the day so far as a dashed line – below the forecast kWh it also shows what has actually flowed already.',
        'Clickable legend',
        'A click on “PV” or “Consumption” in the legend hides that curve including its band and actual line – handy to look at just one side for a moment when curves overlap.',
        'More than 3 days? It scrolls instead of squeezing',
        'With more than 3 days shown, the chart becomes proportionally wider instead of squeezing the curves together – the y-axis on the left stays visible while scrolling (arrows left/right indicate that there is more).',
        'Two chart engines to choose from',
        'ECharts (open source, also usable commercially) or Highcharts (private/non-commercial only) – switchable directly in the expanded tile view via the double arrow, without the form.',
        'Usable outside Symcon too',
        'This tile also runs as a standalone web page with auto-refresh – ideal for IPSView via WebView/popup or any browser on the network. The address can be found in the console form under “Documentation & help”.',
        'No data',
        'Bars: the forecast is available at %d min resolution - to get 15-minute bars, set the time resolution to 15 min in the PV and load forecasts.',
        'Max. %s kW',
        'Plant %s kWp',
        'Balance',
        'Consumption',
        '%s forecast',
        '%s actual',
        'Click: hide/show',
        'Actual now',
        'PV (forecast)',
        'Consumption (forecast)',
        'PV (actual)',
        'Consumption (actual)',
        'Forecast',
        'Actual',
        'light = forecast, solid = actual so far',
        '%s forecast %s kWh, actual %s kWh',
        '%s forecast %s kWh',
    ];

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
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of genuine enthusiasm for my own installation — and a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Do you like the module and still want to leave something? I would be happy about a small donation — completely voluntary, nothing owed in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    public function Create()
    {
        parent::Create();
        // Gemeinsame Texte aus libs/FormLayout.php und libs/FormStatus.php (NRGDASH_Text): Uebersetzer eintragen, bis das Modul selbst umgestellt ist
        NRGDASH_Text::setzen(fn (string $text): string => $this->Translate($text));

        // Reine Verdrahtung (Quell-/Variablenauswahl) bleibt Property - nur
        // die Konsole bietet SelectInstance/SelectVariable, eine
        // Doppelpfeil-Variable kann das nicht abbilden (Dashboard-Muster,
        // 1:1 wie Prognoses eigenes Energiebilanz-Formular: PVSource +
        // LoadSource statt einer zusammengefassten Energiebilanz-Instanz).
        // Standalone-Webseite (Hook) abschaltbar (09.11.2026, Forum doctee #232):
        // Standard an, damit bestehende Installationen unveraendert laufen.
        $this->RegisterPropertyBoolean('StandaloneEnabled', true);
        $this->RegisterPropertyInteger('PVSource', 0);
        // Eigener Wert fuer die gestrichelte Anlagenleistungs-Linie (kW, 0 = Summe kWp der PV-Prognose) - Forum Ghostraider #54
        $this->RegisterPropertyFloat('MaxLineKw', 0.0);
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
        // Zuletzt von diesem Modul gesetzte Variablennamen (Ident => Name), damit bei einem Sprachwechsel nur Namen
        // nachgezogen werden, die der Nutzer nicht selbst geändert hat.
        $this->RegisterAttributeString('VarNames', '');
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

        // Kein eigener Timer mehr (1:1 wie Prognoses Energiebilanz): wir
        // lesen PVF_Today/LFC_Today & Co. jetzt direkt und reagieren rein
        // ereignisgesteuert auf deren VM_UPDATE (siehe ApplyChanges()),
        // statt archivbasiert alle 5 Minuten zu pollen.

        // Alle Darstellungseinstellungen als echte Instanz-Variablen statt
        // Formular-Properties (1:1 aus Prognoses Energiebilanz uebernommen,
        // siehe Klassendoku oben) - Symcon zeigt sie automatisch als
        // Schalter/Dropdown/Zahlenfeld in der aufgezogenen Kachelansicht.
        $this->ensureProfiles();

        $specs = $this->variableSpecs();
        foreach ($specs['bool'] as $ident => $spec) {
            [$caption, $pos, $default] = $spec;
            $isNew = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->RegisterVariableBoolean($ident, $caption, '', $pos);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, $default);
            }
        }

        foreach ($specs['int'] as $ident => $spec) {
            [$caption, $profile, $pos, $default] = $spec;
            $isNew = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->RegisterVariableInteger($ident, $caption, $profile, $pos);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, $default);
            }
        }

        foreach ($specs['float'] as $ident => $spec) {
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

    /** Instanz-Variablen der Doppelpfeil-Einstellungen: Ident => [Beschriftung, (Profil,) Position, Standardwert]; Beschriftung in der aktuellen Sprache. */
    private function variableSpecs(): array
    {
        $bool = [
            'ShowPV'         => [NRGDASH_Text::t('Show PV generation'), 10, true],
            'ShowLoad'       => [NRGDASH_Text::t('Show consumption'), 11, true],
            'ShowActualPV'   => [NRGDASH_Text::t('Show measured PV course of the day (today) as a line'), 31, false],
            'ShowActualLoad' => [NRGDASH_Text::t('Show measured consumption course of the day (today) as a line'), 32, false],
            // Forum Ghostraider (10.10.2026): gemessene Linien mit eigener Farbe + eigenem Legendeneintrag.
            // Aus = Farbe der Prognosekurve (bisheriges Verhalten, keine Aenderung fuer bestehende Instanzen).
            'ActualOwnPV'        => [NRGDASH_Text::t('Own color for the measured PV line'), 35, false],
            'ActualOwnLoad'      => [NRGDASH_Text::t('Own color for the measured consumption line'), 36, false],
            // Forum Horst (02.11.2026): gemessene Linien durchgezogen statt gestrichelt. Standard aus = gestrichelt, wie im Prognose-Modul (die Prognose ist hier die massgebliche, durchgezogene Linie)
            'ActualSolid'        => [NRGDASH_Text::t('Measured lines solid instead of dashed'), 37, false],
            'ShowYesterday'  => [NRGDASH_Text::t('Also show yesterday'), 33, false],
            'Smooth'         => [NRGDASH_Text::t('Smooth curves (against angular lines)'), 49, self::DEF_SMOOTH],
            'ShowBand'       => [NRGDASH_Text::t('Show uncertainty band (P10-P90)'), 50, self::DEF_BAND],
            'ShowGrid'       => [NRGDASH_Text::t('Show grid & axis labels'), 52, self::DEF_GRID],
            'ShowLegend'     => [NRGDASH_Text::t('Show legend'), 55, true],
            'ShowIstRow'     => [NRGDASH_Text::t('Show "actual" row in the day strip'), 56, true],
            // Forum Ghostraider #52 (14.10.2026), gespiegelt von Prognoses Energiebilanz Build 143/144: Hinweis zur Auflösung
            // in der Balkenansicht ausblendbar; Anlagenleistung (kWp der PV-Prognose oder eigener Wert) als gestrichelte Linie.
            'ShowViewHint'   => [NRGDASH_Text::t('Show resolution note in the bar view'), 57, true],
            'ShowMaxLine'    => [NRGDASH_Text::t('Show plant power (module kWp) as a dashed line'), 58, false],
            'ColorBackgroundAuto' => [NRGDASH_Text::t('Automatic background (Symcon theme/transparent)'), 43, true],
        ];
        $int = [
            'Days'             => [NRGDASH_Text::t('Days to show'), 'NRGDASHFC.Days', 20, self::DEF_DAYS],
            'PowerUnit'        => [NRGDASH_Text::t('Unit of the actual power variables'), 'NRGDASHFC.PowerUnit', 30, 2],
            'MeasuredCacheSec' => [NRGDASH_Text::t('Recalculate actual course every ... s (archive cache)'), 'NRGDASHFC.CacheSec', 34, 120],
            // Darstellung (Forum Ghostraider #48, gespiegelt von Prognoses Energiebilanz Build 142): Kurve oder Balken je Slot (15 min)
            'ChartView'        => [NRGDASH_Text::t('View (curve, bars per time slice or daily bars)'), 'NRGDASHFC.View', 39, 0],
            // Eigene Wahl der Schriftfarbe (Forum lungo #62, gespiegelt von Prognoses Energiebilanz Build 145)
            'LabelTone'        => [NRGDASH_Text::t('Labels (text color: automatic, light or dark)'), 'NRGDASHFC.LabelTone', 43, 0],
            'ChartEngine'      => [NRGDASH_Text::t('Chart engine'), 'NRGDASHFC.Engine', 40, 0],
            'ColorPV'          => [NRGDASH_Text::t('PV generation color'), '~HexColor', 41, self::DEF_PV],
            'ColorLoad'        => [NRGDASH_Text::t('Consumption color'), '~HexColor', 42, self::DEF_LOAD],
            'ColorActualPV'        => [NRGDASH_Text::t('Measured PV line color'), '~HexColor', 37, self::DEF_PV],
            'ColorActualLoad'        => [NRGDASH_Text::t('Measured consumption line color'), '~HexColor', 38, self::DEF_LOAD],
            'ColorBackground'  => [NRGDASH_Text::t('Background color (if not automatic)'), '~HexColor', 44, 0xFFFFFF],
            'FontFamily'       => [NRGDASH_Text::t('Font'), 'NRGDASHFC.Font', 45, 0],
        ];
        $float = [
            'FontScale'   => [NRGDASH_Text::t('Font size (factor, applies to all labels)'), 'NRGDASHFC.Scale', 46, self::DEF_SCALE],
            'LineWidth'   => [NRGDASH_Text::t('Line width'), 'NRGDASHFC.LineWidth', 48, self::DEF_LW],
            'BandOpacity' => [NRGDASH_Text::t('Band transparency (0 = invisible ... 0.6)'), 'NRGDASHFC.BandOpacity', 51, self::DEF_BANDOP],
            'YMaxManual'  => [NRGDASH_Text::t('Fixed y-axis maximum (kW, 0 = automatic)'), 'NRGDASHFC.YMax', 53, self::DEF_YMAX],
        ];
        return ['bool' => $bool, 'int' => $int, 'float' => $float];
    }

    /**
     * Variablennamen in der aktuellen Sprache nachziehen (Sprachwechsel, SUITE.md Punkt 9b). Nur Namen, die noch so lauten, wie dieses
     * Modul sie zuletzt gesetzt hat (oder der englische Quelltext): vom Nutzer umbenannte Variablen bleiben unangetastet.
     */
    private function syncVariableNames(): void
    {
        $gemerkt = json_decode((string) $this->ReadAttributeString('VarNames'), true);
        if (!is_array($gemerkt)) {
            $gemerkt = [];
        }
        $neu = [];
        foreach ($this->variableSpecs() as $gruppe) {
            foreach ($gruppe as $ident => $spec) {
                $soll = (string) $spec[0];
                $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
                if ($vid === false || $vid <= 0) {
                    continue;
                }
                $ist = IPS_GetName($vid);
                if ($ist !== $soll && isset($gemerkt[$ident]) && $ist === $gemerkt[$ident]) {
                    IPS_SetName($vid, $soll);
                    $ist = $soll;
                }
                if ($ist === $soll) {
                    $neu[$ident] = $soll;
                }
            }
        }
        if ($neu !== $gemerkt) {
            $this->WriteAttributeString('VarNames', json_encode($neu, JSON_UNESCAPED_UNICODE));
        }
    }

    /** Legt/aktualisiert alle NRGDASHFC.*-Variablenprofile (idempotent), 1:1 Werte wie Prognoses EFTILE.*-Profile. */
    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('NRGDASHFC.Days')) { IPS_CreateVariableProfile('NRGDASHFC.Days', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileAssociation('NRGDASHFC.Days', 1, NRGDASH_Text::t('Today'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Days', 2, NRGDASH_Text::t('Today + tomorrow'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Days', 3, NRGDASH_Text::t('Today + 2 days'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Days', 4, NRGDASH_Text::t('Today + 3 days'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Days', 5, NRGDASH_Text::t('Today + 4 days (full horizon)'), '', -1);

        if (!IPS_VariableProfileExists('NRGDASHFC.PowerUnit')) { IPS_CreateVariableProfile('NRGDASHFC.PowerUnit', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileAssociation('NRGDASHFC.PowerUnit', 0, NRGDASH_Text::t('W (watt)'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.PowerUnit', 1, NRGDASH_Text::t('kW (kilowatt)'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.PowerUnit', 2, NRGDASH_Text::t('Detect automatically (profile/order of magnitude)'), '', -1);

        if (!IPS_VariableProfileExists('NRGDASHFC.View')) { IPS_CreateVariableProfile('NRGDASHFC.View', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileAssociation('NRGDASHFC.View', 0, NRGDASH_Text::t('Curve (lines with uncertainty band)'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.View', 1, NRGDASH_Text::t('Bars per time slice (15 min, forecast filled with actual)'), '', -1);
        // Prognose Build 155 (Forum hfichtinger #94): ein Balken je Tag mit der Tagesenergie zur schnellen Einschätzung
        IPS_SetVariableProfileAssociation('NRGDASHFC.View', 2, NRGDASH_Text::t('Daily bars (kWh per day)'), '', -1);

        if (!IPS_VariableProfileExists('NRGDASHFC.LabelTone')) { IPS_CreateVariableProfile('NRGDASHFC.LabelTone', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileAssociation('NRGDASHFC.LabelTone', 0, NRGDASH_Text::t('Automatic (by operating system/browser)'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.LabelTone', 1, NRGDASH_Text::t('Light (for dark tiles)'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.LabelTone', 2, NRGDASH_Text::t('Dark (for light tiles)'), '', -1);

        if (!IPS_VariableProfileExists('NRGDASHFC.CacheSec')) { IPS_CreateVariableProfile('NRGDASHFC.CacheSec', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileValues('NRGDASHFC.CacheSec', 15, 900, 5);
        IPS_SetVariableProfileText('NRGDASHFC.CacheSec', '', ' s');

        if (!IPS_VariableProfileExists('NRGDASHFC.Engine')) { IPS_CreateVariableProfile('NRGDASHFC.Engine', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileAssociation('NRGDASHFC.Engine', 0, NRGDASH_Text::t('ECharts (open source, also commercial)'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHFC.Engine', 1, NRGDASH_Text::t('Highcharts (private/non-commercial only)'), '', -1);

        if (!IPS_VariableProfileExists('NRGDASHFC.Font')) { IPS_CreateVariableProfile('NRGDASHFC.Font', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileAssociation('NRGDASHFC.Font', 0, NRGDASH_Text::t('System'), '', -1);
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
        // Gemeinsame Texte aus libs/FormLayout.php und libs/FormStatus.php (NRGDASH_Text): Uebersetzer eintragen, bis das Modul selbst umgestellt ist
        NRGDASH_Text::setzen(fn (string $text): string => $this->Translate($text));
        // Profile auch hier (idempotent): neue Zuordnungen in geteilten Profilen (z. B. NRGDASHFC.View 2 "Tagesbalken") kommen sonst erst bei
        // einer NEUEN Instanz an, ein Bibliotheks-Update plus "Übernehmen" bei bestehenden Instanzen reichte nicht (Forum hfichtinger #99).
        $this->ensureProfiles();
        $this->syncVariableNames();
        $this->SetVisualizationType(1);
        $this->WriteAttributeString('MeasuredCache', ''); // Cache bei Konfig-Aenderung verwerfen

        // Standalone-Webseite fuer IPSView/Browser (WebView/Popup) - Muster
        // Prognoses Energiebilanz-Modul (Dietmar, 27.08.2026: "alle Kacheln
        // ... auch so vorbereiten, dass man sie in IPSView einbinden kann").
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->SyncHook('/hook/nrgdashforecast' . $this->InstanceID);
        } else {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        }

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
            $vid = (int) $this->ReadPropertyInteger($prop);
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
        if ($Message === IPS_KERNELMESSAGE && isset($Data[0]) && $Data[0] === KR_READY) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === VM_UPDATE) {
            $this->Render();
        }
    }

    /** Hook je nach Einstellung "Standalone-Webseite" registrieren oder entfernen. */
    private function SyncHook(string $WebHook): void
    {
        if ($this->ReadPropertyBoolean('StandaloneEnabled')) {
            $this->RegisterHook($WebHook);
        } else {
            $this->UnregisterHook($WebHook);
        }
    }

    /** Eigenen Eintrag aus dem WebHook-Control entfernen (nur wenn er auf diese Instanz zeigt). */
    private function UnregisterHook(string $WebHook): void
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        if (!is_array($hooks)) {
            return;
        }
        $kept = [];
        foreach ($hooks as $hook) {
            if (($hook['Hook'] ?? '') === $WebHook && (int) ($hook['TargetID'] ?? 0) === $this->InstanceID) {
                continue;
            }
            $kept[] = $hook;
        }
        if (count($kept) === count($hooks)) {
            return;
        }
        IPS_SetProperty($ids[0], 'Hooks', json_encode($kept));
        IPS_ApplyChanges($ids[0]);
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
     * WebFront-Bedienung der Doppelpfeil-Variablen (siehe Create()) - Muster
     * Prognoses Energiebilanz::RequestAction()/NRGDashboardHeatSchema: Wert
     * setzen, Kachel neu rendern.
     */
    public function RequestAction($Ident, $Value)
    {
        // Einfuehrungs-Tour bestaetigt: die Kachel meldet das ueber requestAction()
        // zurueck, nicht ueber den offenen WebHook (der kann abgeschaltet sein).
        if ($Ident === 'dismissTour') {
            $this->WriteAttributeBoolean('TourSeen', true);
            return;
        }
        $boolIdents = ['ShowPV', 'ShowLoad', 'ShowActualPV', 'ShowActualLoad', 'ShowYesterday',
                       'Smooth', 'ShowBand', 'ShowGrid', 'ShowLegend', 'ShowIstRow', 'ShowViewHint', 'ShowMaxLine', 'ColorBackgroundAuto', 'ActualOwnPV', 'ActualOwnLoad', 'ActualSolid'];
        $intIdents  = ['Days', 'PowerUnit', 'MeasuredCacheSec', 'ChartView', 'LabelTone', 'ChartEngine', 'ColorPV', 'ColorLoad', 'ColorActualPV', 'ColorActualLoad', 'ColorBackground', 'FontFamily'];
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

    /** Property zuerst, sonst Auto-Discovery (eine Instanz, bzw. die einzige aktive). */
    private function ResolveSource(string $guid, string $prop): int
    {
        $configured = (int) $this->ReadPropertyInteger($prop);
        if ($configured > 0 && IPS_InstanceExists($configured)) { return $configured; }
        return $this->pickSingleActiveInstance(IPS_GetInstanceListByModuleID($guid));
    }

    /**
     * Automatische Partner-Erkennung (Dietmar, 12.09.2026): bisher nur bei
     * GENAU EINER Instanz - eine zusaetzliche, abgeschaltete Instanz (z. B.
     * eine Demo-/Testinstanz) blockierte die Erkennung komplett. Jetzt: eine
     * Instanz -> diese; mehrere -> die einzige AKTIVE (Status 102); mehrere
     * aktive -> 0 (nicht raten, dann gilt die Instanz-Eigenschaft).
     */
    private function pickSingleActiveInstance($ids): int
    {
        if (!is_array($ids) || count($ids) === 0) {
            return 0;
        }
        if (count($ids) === 1) {
            return (int) $ids[0];
        }
        $active = array_values(array_filter($ids, function ($id) {
            return @IPS_InstanceExists((int) $id) && (int) (IPS_GetInstance((int) $id)['InstanceStatus'] ?? 0) === 102;
        }));
        return count($active) === 1 ? (int) $active[0] : 0;
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

    /** Wochentag (Kürzel in der Anzeigesprache) + Datum fuer einen Tages-Offset ab heute (0=heute). */
    private function dayLabel(int $offsetDays): string
    {
        $ts = strtotime($offsetDays . ' days');
        $wd = [NRGDASH_Text::t('Mon'), NRGDASH_Text::t('Tue'), NRGDASH_Text::t('Wed'), NRGDASH_Text::t('Thu'), NRGDASH_Text::t('Fri'), NRGDASH_Text::t('Sat'), NRGDASH_Text::t('Sun')][((int) date('N', $ts)) - 1];
        // Datumsformat je Sprache (PHP-date()-Formatstring, deutsch Tag.Monat., englisch Tag Monatskürzel)
        return $wd . ' ' . date(NRGDASH_Text::t('j M'), $ts);
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
        $html = NRGDASH_Text::kachelHtml((string) file_get_contents(__DIR__ . '/module.html'), self::TILE_TEXTS);
        $html .= '<script>handleMessage(' . $this->buildPayload() . ');</script>';
        return $html;
    }

    /**
     * Liefert die Kachel als eigenstaendige Webseite (fuer IPSView-WebView/
     * Popup oder jeden Browser). Aufruf: /hook/nrgdashforecast<InstanzID>.
     * Mit ?json=1 werden nur die Daten geliefert (fuer die Auto-Aktualisierung).
     */
    public function ProcessHookData()
    {
        // Einfuehrungs-Tour bestaetigt (28.08.2026) - vom Tour-Overlay in
        // module.html per fetch() aufgerufen, siehe Create().
        if (isset($_GET['dismissTour']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $this->WriteAttributeBoolean('TourSeen', true);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            return;
        }
        if (isset($_GET['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo $this->buildPayload();
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        $html = NRGDASH_Text::kachelHtml((string) file_get_contents(__DIR__ . '/module.html'), self::TILE_TEXTS);
        $html .= '<script>handleMessage(' . $this->buildPayload() . ');'
               . 'setInterval(function(){fetch(window.location.pathname+"?json=1")'
               . '.then(function(r){return r.text();}).then(function(t){handleMessage(t);})'
               . '.catch(function(){});},30000);</script>';
        echo $html;
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
            'actualPvColor'   => $this->GetValue('ActualOwnPV') ? sprintf('#%06x', (int) $this->GetValue('ColorActualPV')) : '',
            'actualSolid'     => (bool) $this->GetValue('ActualSolid'),
            'actualLoadColor' => $this->GetValue('ActualOwnLoad') ? sprintf('#%06x', (int) $this->GetValue('ColorActualLoad')) : '',
            'bg'         => $this->GetValue('ColorBackgroundAuto') ? '' : sprintf('#%06x', (int) $this->GetValue('ColorBackground')),
            'scale'      => $this->FontScaleValue(),
            'lineWidth'  => max(0.5, min(6.0, (float) $this->GetValue('LineWidth'))),
            'smooth'     => (bool) $this->GetValue('Smooth'),
            'showBand'   => (bool) $this->GetValue('ShowBand'),
            'bandOp'     => max(0.0, min(0.6, (float) $this->GetValue('BandOpacity'))),
            'showGrid'   => (bool) $this->GetValue('ShowGrid'),
            'showLegend' => (bool) $this->GetValue('ShowLegend'),
            'showIstRow' => (bool) $this->GetValue('ShowIstRow'),
            'showViewHint' => (bool) $this->GetValue('ShowViewHint'),
            'showMaxLine'  => (bool) $this->GetValue('ShowMaxLine'),
            'maxKwp'       => $this->GetValue('ShowMaxLine') ? $this->maxLineValue() : 0.0,
            'maxManual'    => ((float) $this->ReadPropertyFloat('MaxLineKw') > 0),
            'yMaxManual' => max(0.0, (float) $this->GetValue('YMaxManual')),
            'font'       => $this->FontStack((int) $this->GetValue('FontFamily')),
            'engine'     => ((int) $this->GetValue('ChartEngine') === 1) ? 'highcharts' : 'echarts',
            'view'       => ['curve', 'bars', 'days'][max(0, min(2, (int) $this->GetValue('ChartView')))],
            'labelTone'  => max(0, min(2, (int) $this->GetValue('LabelTone'))),
            'hookPath'   => '/hook/nrgdashforecast' . $this->InstanceID,
            // Dezimalzeichen der Anzeigesprache für die kWh-Werte der Tagesbalken (Kachel)
            'decSep'     => mb_substr(NRGDASH_Text::t('Decimal separator: .'), -1),
            'showTour'   => !(bool) $this->ReadAttributeBoolean('TourSeen'),
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

        // Logik hängt am Schlüssel ('key'), nie am angezeigten Label
        $labels = [NRGDASH_Text::t('today')];
        $keys = ['today'];
        for ($i = 1; $i <= self::MAX_OFFSET; $i++) { $labels[] = NRGDASH_Text::t('%s (today +%d)', $this->dayLabel($i), $i); $keys[] = 'plus' . $i; }

        $days = [];

        // Gestern: Soll aus gespeichertem Snapshot (Ist kommt spaeter aus
        // unseren eigenen ActualPV/ActualLoad-Variablen, trimToOwnSettings()).
        $yDate = date('Y-m-d', strtotime('yesterday'));
        $gpv = $this->snapshotToDay($pvSrc,   'PVF_GetSnapshot', $yDate);
        $glo = $this->snapshotToDay($loadSrc, 'LFC_GetSnapshot', $yDate);
        if ($gpv !== null || $glo !== null) {
            $days[] = ['key' => 'yesterday', 'label' => NRGDASH_Text::t('yesterday'), 'pv' => $gpv, 'load' => $glo,
                       'pvMeas' => null, 'loMeas' => null, 'pvKwhIst' => null, 'loKwhIst' => null];
        }

        $hasData = false;
        for ($i = 0; $i < $limit; $i++) {
            $pv   = $pvDays[$i]   ?? null;
            $load = $loadDays[$i] ?? null;
            if ($pv !== null || $load !== null) { $hasData = true; }
            $days[] = ['key' => $keys[$i], 'label' => $labels[$i], 'pv' => $pv, 'load' => $load,
                       'pvMeas' => null, 'loMeas' => null, 'pvKwhIst' => null, 'loKwhIst' => null];
        }

        return [
            'hasData' => $hasData,
            'message' => $hasData ? '' : NRGDASH_Text::t('No forecast data'),
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
            $isYesterday = ($d['key'] ?? '') === 'yesterday';
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
        $pvVar = (int) $this->ReadPropertyInteger('ActualPV');
        $loVar = (int) $this->ReadPropertyInteger('ActualLoad');
        $today = strtotime('today');
        foreach ($out as &$d) {
            $isYesterday = ($d['key'] ?? '') === 'yesterday';
            $isToday     = ($d['key'] ?? '') === 'today';
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
            'message'    => $hasData ? '' : (string) ($data['message'] ?? NRGDASH_Text::t('No forecast data')),
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
        $vid = (int) $this->ReadPropertyInteger($prop);
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

        $cache = json_decode((string) $this->ReadAttributeString('MeasuredCache'), true);
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

    /** Variablen, bei denen das Stunden-Aggregat defekt war (fuer die Formular-Statuszeile). */
    private $aggFault = [];

    /**
     * AC_GetAggregatedValues (stuendlich) mit abgefangener nativer Warnung: bei einem defekten Stunden-Aggregat
     * liefert Symcon false UND gibt eine PHP-Warnung aus. Rueckgabe: Array (ggf. leer) oder false bei Fehler.
     */
    private function aggregatedHourly(int $aid, int $vid, int $start, int $end)
    {
        set_error_handler(function () { return true; });
        $rows = AC_GetAggregatedValues($aid, $vid, 0, $start, $end, 0);
        restore_error_handler();
        return is_array($rows) ? $rows : false;
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
            $rows = $this->aggregatedHourly($aid, $vid, $start, strtotime('+1 day', $start) - 1);
            if ($rows === false) {
                $this->aggFault[$vid] = true;
                // Defektes Stunden-Aggregat im Archiv ("Ungueltige Aggregation hour"): AC_GetAggregatedValues
                // liefert fuer den GANZEN Tag false - die gemessene Linie verschwand komplett (Forum hbraun #47,
                // gespiegelt von Prognoses Energiebilanz Build 141). Ersatzweise aus den Rohwerten bilden.
                $fine = $this->measuredFine($aid, $vid, $start, $slots);
                if (is_array($fine) && $f !== 1.0) {
                    foreach ($fine as $i => $v) { if ($v !== null) { $fine[$i] = $v * $f; } }
                }
                return $fine;
            }
            if (!is_array($rows) || count($rows) === 0) { return null; }
            $out = array_fill(0, $slots, null);
            foreach ($rows as $r) {
                if ($this->RowHasImplausiblePower($r)) {
                    $this->SendDebug(__FUNCTION__, NRGDASH_Text::t('Implausible archive value discarded: variable #%d, %s, max=%.0f W', $vid, date('d.m.Y H:i', (int) $r['TimeStamp']), (float) ($r['Max'] ?? 0)), 0);
                    continue;
                }
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

        // Rohwerte (kein Max/Min einer Aggregationszeile hier, siehe
        // RowHasImplausiblePower()) - ein einzelner unplausibler Wert wird
        // direkt beim Einlesen verworfen, statt zeitgewichtet mit einzufliessen
        // (01.09.2026, siehe IMPLAUSIBLE_POWER_W-Docblock oben).
        $carry = null;
        $pre = AC_GetLoggedValues($aid, $vid, 0, $start - 1, 1);
        if (is_array($pre) && count($pre) > 0 && abs((float) $pre[0]['Value']) <= self::IMPLAUSIBLE_POWER_W) {
            $carry = (float) $pre[0]['Value'];
        }

        $rows = AC_GetLoggedValues($aid, $vid, $start, $until, 0);
        if (!is_array($rows)) { $rows = []; }
        $rows = array_filter($rows, function ($r) use ($vid) {
            $ok = abs((float) $r['Value']) <= self::IMPLAUSIBLE_POWER_W;
            if (!$ok) {
                $this->SendDebug(__FUNCTION__, NRGDASH_Text::t('Implausible archive value discarded: variable #%d, %s, %.0f', $vid, date('d.m.Y H:i', (int) $r['TimeStamp']), (float) $r['Value']), 0);
            }
            return $ok;
        });
        $rows = array_values($rows);
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

    /** Summe der Modulleistung (kWp) aus der aufgeloesten PV-Prognose-Instanz, 0 = unbekannt. */
    private function pvMaxKwp(): float
    {
        $pv = $this->ResolveSource(self::SOURCE_PV, 'PVSource');
        if ($pv <= 0 || !function_exists('PVF_GetGenerators')) { return 0.0; }
        $g = @PVF_GetGenerators($pv);
        if (is_string($g)) { $g = json_decode($g, true); }
        return is_array($g) ? max(0.0, (float) ($g['totalKwp'] ?? 0)) : 0.0;
    }

    /** Wert der gestrichelten Linie: eigener Wert (kW) aus der Konfiguration, sonst die Summe der Modul-kWp. */
    private function maxLineValue(): float
    {
        $manual = (float) $this->ReadPropertyFloat('MaxLineKw');
        return ($manual > 0) ? $manual : $this->pvMaxKwp();
    }

    private function getArchiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return (count($ids) > 0) ? (int) $ids[0] : 0;
    }

    /** Statuszeile je Prognose-Quelle (SUITE.md "Verbund-Verbindungen sichtbar machen" + "Wert kommt automatisch"). */
    private function sourceStatusPart(array &$elements, string $field, string $label, string $guid, string $prop, string $noneText): string
    {
        $ids = @IPS_GetInstanceListByModuleID($guid);
        $ids = is_array($ids) ? $ids : [];
        $cfg = (int) $this->ReadPropertyInteger($prop);
        $explicit = ($cfg > 0 && IPS_InstanceExists($cfg)) ? $cfg : 0;
        if ($explicit === 0 && count($ids) === 0) {
            return NRGDASH_Text::t('ℹ️ %s: no instance found - %s', $label, $noneText);
        }
        $id = $this->ResolveSource($guid, $prop);
        if ($id <= 0) {
            return NRGDASH_Text::t('⚠️ %s: %d instances found, several of them active and none selected - no guessing. Please choose an instance below.', $label, count($ids));
        }
        $line = $this->autoFieldLine($elements, $field, $label, $explicit, $id, NRGDASH_Text::t('the instance search'), $noneText);
        // Eine nicht mehr vorhandene gespeicherte Instanz ist kein Fehler, solange die Automatik die Quelle findet
        // (Forum somm, 18.10.2026, gespiegelt von Prognoses Energiebilanz Build 147): 🔗 steht schon, hier nur der Hinweis.
        $staleNote = ($cfg > 0 && $explicit === 0)
            ? ' ' . NRGDASH_Text::t('Note: the settings contain instance #%d, which no longer exists; it is ignored - automatic detection applies, which is harmless.', $cfg)
            : '';
        return $line . ' (' . $this->formInstanceState($id) . ')' . ($this->formInstanceActive($id) ? '' : ' ' . NRGDASH_Text::t('⚠️ not active')) . $staleNote;
    }

    private function forecastSourceStatusLine(array &$elements): string
    {
        return $this->sourceStatusPart($elements, 'PVSource', NRGDASH_Text::t('PV forecast'), '{257DD4E8-9705-462E-89FC-56D0A1038353}', 'PVSource', NRGDASH_Text::t('the PV curve is missing in the tile.'))
            . "\n" . $this->sourceStatusPart($elements, 'LoadSource', NRGDASH_Text::t('Load forecast'), '{DC5AD508-507F-40EA-8630-0959AED83050}', 'LoadSource', NRGDASH_Text::t('the tile shows only the PV forecast (the load forecast is optional).'));
    }

    /**
     * Statuszeile zur gemessenen Linie (gespiegelt von Prognoses Energiebilanz Build 141, Forum hbraun #47):
     * Variable gewaehlt/existiert/archiviert, ob die Linie eingeschaltet ist und wie viele Messpunkte heute ankommen.
     */
    private function actualStatusLine(string $prop, string $name, string $showIdent, string $showCaption): string
    {
        $vid = (int) $this->ReadPropertyInteger($prop);
        if ($vid <= 0) {
            return NRGDASH_Text::t('ℹ️ %s: no variable selected (field below) - without it there is neither a current value nor a measured line.', $name);
        }
        if (!IPS_VariableExists($vid)) {
            return NRGDASH_Text::t('⚠️ %s: the selected variable #%d no longer exists.', $name, $vid);
        }
        $vname = NRGDASH_Text::t('“%s” (#%d)', IPS_GetName($vid), $vid);
        $on = (bool) $this->GetValue($showIdent);
        $aid = $this->getArchiveID();
        if ($aid <= 0 || !@AC_GetLoggingStatus($aid, $vid)) {
            return NRGDASH_Text::t('⚠️ %s: variable %s is not archived - the measured line needs archiving (the current value works without it).', $name, $vname);
        }
        $this->aggFault = [];
        $data = $this->readMeasured($vid, 24, (int) strtotime('today'));
        $n = 0;
        if (is_array($data)) { foreach ($data as $x) { if ($x !== null) { $n++; } } }
        $switch = $on
            ? NRGDASH_Text::t('Line is switched on')
            : NRGDASH_Text::t('Line is switched OFF (switch “%s” in the WebFront double arrow or as an instance variable)', $showCaption);
        if (!empty($this->aggFault[$vid])) {
            // Wert-Satzteil: Einzahl/Mehrzahl und "keine Werte" als vollständige Teile
            if ($n === 0) {
                $heute = NRGDASH_Text::t('but with no values today');
            } elseif ($n === 1) {
                $heute = NRGDASH_Text::t('1 hour with values today');
            } else {
                $heute = NRGDASH_Text::t('%d hours with values today', $n);
            }
            return NRGDASH_Text::t('⚠️ %s: variable %s - the hourly aggregate in the archive is defective (“Invalid aggregation”). The line is built from the raw values instead (%s). To fix it permanently: use “Re-aggregate” for this variable in the Archive Control. %s.', $name, $vname, $heute, $switch);
        }
        if ($n === 0) {
            return NRGDASH_Text::t('⚠️ %s: variable %s is archived, but has no measured values in the archive today - the line stays empty. %s.', $name, $vname, $switch);
        }
        $heute = ($n === 1) ? NRGDASH_Text::t('1 hour with measured values today') : NRGDASH_Text::t('%d hours with measured values today', $n);
        return ($on ? '✅ ' : 'ℹ️ ') . NRGDASH_Text::t('%s: variable %s archived, %s. %s.', $name, $vname, $heute, $switch);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }

        // Das Label mit dem Hook-Pfad enthält einen eingesetzten Wert: die Konsole fände den Text so nicht mehr, deshalb selbst übersetzen.
        $this->setFormCaption($form['elements'], 'HookInfo', NRGDASH_Text::t('IPSView/popup: the tile also runs as a standalone web page at http://<Symcon-IP>:3777%s (auto-refresh every 30 s). In IPSView, place a WebView element on a popup page and enter this URL. Note: WebHooks can be reached on the local network without logging in.', '/hook/nrgdashforecast' . $this->InstanceID));
        $this->translateForceApply($form);
        $this->injectVersionIntoDocPanel($form);
        $this->setFormStatusLine($form['elements'], 'SourceStatus', $this->forecastSourceStatusLine($form['elements']));
        $this->setFormStatusLine($form['elements'], 'ActualStatusPV', $this->actualStatusLine('ActualPV', NRGDASH_Text::t('PV (actual)'), 'ShowActualPV', NRGDASH_Text::t('Show measured PV course of the day (today) as a line')));
        $this->setFormStatusLine($form['elements'], 'ActualStatusLoad', $this->actualStatusLine('ActualLoad', NRGDASH_Text::t('Consumption (actual)'), 'ShowActualLoad', NRGDASH_Text::t('Show measured consumption course of the day (today) as a line')));

        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }
        $purposeIntro = $this->PurposeIntro();
        if ($purposeIntro !== null) {
            array_unshift($form['elements'], $purposeIntro);
        }

        if (!@$this->ReadAttributeBoolean('ReviewHintDismissed')) {
            $form['elements'][] = [
                'type' => 'ExpansionPanel', 'name' => 'ReviewHint', 'expanded' => true,
                'caption' => '💬  Feedback in the Symcon forum',
                'items' => [
                    ['type' => 'Label', 'caption' => 'NRGDashboard is in beta — feedback, bug reports and wishes are explicitly welcome in the forum thread.'],
                    ['type' => 'Button', 'caption' => 'To the forum thread', 'onClick' => "echo '" . self::FORUM_URL . "';", 'link' => true],
                    ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHFC_DismissReviewHint($id);'],
                ],
            ];
        }
        $form['elements'][] = $this->LicenseHint();

        return json_encode($form);
    }

    /**
     * Der Knopf "Übernehmen erzwingen" gibt nach IPS_ApplyChanges() einen Text aus, den die Konsole nicht übersetzt
     * (Teil von onClick): erkannt am Beginn des Handlers, nie am Anzeigetext, und mit dem übersetzten Text neu gesetzt.
     */
    private function translateForceApply(array &$form): void
    {
        foreach (['elements', 'actions'] as $bereich) {
            foreach ($form[$bereich] ?? [] as $i => $el) {
                if (($el['type'] ?? '') === 'Button' && str_starts_with((string) ($el['onClick'] ?? ''), 'IPS_ApplyChanges($id); echo')) {
                    $text = str_replace(['\\', "'"], ['\\\\', "\\'"], NRGDASH_Text::t('✅ ApplyChanges() executed.'));
                    $form[$bereich][$i]['onClick'] = 'IPS_ApplyChanges($id); echo \'' . $text . '\';';
                }
            }
        }
    }

    private function injectVersionIntoDocPanel(array &$form): void
    {
        $lib = @IPS_GetLibrary('{8D4E7A2C-1F6B-4C93-A5D8-3E9F1B6C7D02}');
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? NRGDASH_Text::t('ℹ️ NRG-Stack Dashboard version %s (build %s)', $lib['Version'], $lib['Build'] ?? '?')
            : NRGDASH_Text::t('ℹ️ NRG-Stack Dashboard');
        foreach ($form['elements'] as &$el) {
            if (($el['type'] ?? '') === 'ExpansionPanel' && ($el['name'] ?? '') === 'PanelDoc') {
                // Panel über den Namen finden, nie über die (übersetzte) Caption
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
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Shows the PV generation and load forecast compared with the actually measured values - as a chart over several days.'],
                ['type' => 'Label', 'caption' => 'The benefit: see at a glance how well the forecast hits the mark and what generation or consumption to expect in the coming hours/days - without having to evaluate the raw forecast data yourself.'],
                ['type' => 'Label', 'caption' => 'The forecast values are supplied by the NRGPrognose module (PV/load forecast instance) - detected automatically when exactly one instance is installed.'],
                ['type' => 'Label', 'caption' => 'Relies on the Prognose module as its data source - please do not uninstall it while this tile is in use.'],
                ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHFC_AckPurposeIntro($id);'],
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

    /** Reine Versionszahl ohne Beta-/Build-Zusatz ("0.9.73-beta.1" -> "0.9.73")
     *  - Vergleichsbasis fuer NEWS_VERSIONS, damit z.B. "0.9.73" (Schluessel) und
     *  "0.9.73-beta.1" (soeben bestaetigte Bibliotheksversion) als GLEICH gelten
     *  statt "-beta.1" faelschlich als "neuer" zu werten. */
    private function BaseVersion(string $v): string
    {
        return preg_replace('/-.*$/', '', $v) ?? $v;
    }

    /**
     * "Was ist Neu"-Banner: erscheint nach einem Update (Attribut SeenNews
     * startet leer), bis der Nutzer "Verstanden" klickt - zeigt NUR die
     * Versionen, die NEUER sind als die zuletzt bestaetigte (Dietmar,
     * 23.09.2026: "nur die Neuerungen zwischen der zuletzt verwendeten und
     * der nun installierten Version"), gruppiert nach Version.
     */
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
        $items = [['type' => 'Label', 'caption' => '🆕 New since the version you last saw — please take a quick look and check your settings if needed:']];
        $multi = count($pending) > 1;
        foreach ($pending as $ver => $lines) {
            if ($multi) {
                $items[] = ['type' => 'Label', 'caption' => NRGDASH_Text::t('Version %s:', $ver)];
            }
            foreach ($lines as $line) {
                // Zeilen stehen englisch in NEWS_VERSIONS; die Aufzählungspunkt-Marke ist kein Text
                $items[] = ['type' => 'Label', 'caption' => '• ' . NRGDASH_Text::t($line)];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHFC_AckNews($id);'];
        $latest = array_key_last($pending);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => NRGDASH_Text::t('🆕 New up to version %s', $latest), 'expanded' => true, 'items' => $items];
    }

    // Eigene Modul-GUID (14.09.2026, "geteiltes Ausblenden ueber
    // Geschwister-Instanzen") - fuer die Geschwistersuche unten, NICHT
    // fuer die Discovery anderer Verbund-Module (dafuer stehen eigene
    // GUID-Konstanten je Partnermodul).
    private const SELF_MODULE_GUID = '{C9C30229-C36F-4B1C-A474-C0F5C7C4CF30}';

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
            $fn = 'NRGDASHFC_' . $applyMethod;
            if (!function_exists($fn)) {
                continue;
            }
            try {
                call_user_func($fn, $sib);
            } catch (\Throwable $e) {
                $this->SendDebug(__FUNCTION__, NRGDASH_Text::t('Propagating to instance #%d (%s) failed: %s', $sib, $applyMethod, $e->getMessage()), 0);
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
        // Die tatsaechlich installierte Bibliotheksversion merken, NICHT nur
        // den letzten NEWS_VERSIONS-Schluessel - stellt sicher, dass ein
        // spaeteres Update (neuer NEWS_VERSIONS-Eintrag) den Banner sicher
        // wieder zeigt, auch wenn zwischen den beiden Versionen kein News-
        // Eintrag lag.
        $lib = @IPS_GetLibrary('{8D4E7A2C-1F6B-4C93-A5D8-3E9F1B6C7D02}');
        $ver = is_array($lib) ? $this->BaseVersion((string) ($lib['Version'] ?? '')) : '';
        if ($ver === '') {
            $ver = (string) array_key_last(self::NEWS_VERSIONS);
        }
        $this->WriteAttributeString('SeenNews', $ver);
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
        $this->WriteAttributeString('ReviewHintDismissed', '1');
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
        return NRGDASH_Text::t('✅ The tour will be shown again the next time the tile is opened.');
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
        $this->SetValue('ChartView', 0);
        $this->SetValue('LabelTone', 0);
        $this->SetValue('ChartEngine', 0);
        $this->SetValue('ColorPV', self::DEF_PV);
        $this->SetValue('ColorLoad', self::DEF_LOAD);
        $this->SetValue('ColorActualPV', self::DEF_PV);
        $this->SetValue('ColorActualLoad', self::DEF_LOAD);
        $this->SetValue('ActualOwnPV', false);
        $this->SetValue('ActualOwnLoad', false);
        $this->SetValue('ActualSolid', false);
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
        $this->SetValue('ShowViewHint', true);
        $this->SetValue('ShowMaxLine', false);
        $this->SetValue('YMaxManual', self::DEF_YMAX);
        $this->Render();
    }
}
