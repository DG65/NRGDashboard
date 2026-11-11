<?php

declare(strict_types=1);

/**
 * NRGDashboardHeatSchema - Anlagenschema fuer den Heizkreislauf (Pumpen,
 * Ventile, Durchfluss, Temperaturen), Vorbild Kieback&Peter-Gebaeudeleit-
 * technik-Visualisierungen (Dietmar, 13.08.2026). Konsumiert den
 * gemeinsamen 'heatpump'-Vertragstyp generisch (HEISHA_GetFunctions() /
 * WPHUB_GetFunctions()) - JEDES Waermepumpen-Modul liefert nur, was seine
 * Datenquelle hergibt, die restlichen optionalen Felder bleiben 0/leer.
 * Kein Modul (auch nicht HeishaMon) wird vorausgesetzt - reine
 * WPHub-Installationen ohne Zusatzplatine zeigen nur die Basisfelder
 * (elektrische Leistung, sofern gemessen), kein Rohrschema. Wer weder
 * HeishaMon noch WPHub hat, kann im Formular unter "Manuelle
 * Datenanbindung" eigene, bereits vorhandene IPS-Variablen auf dieselben
 * Vertragsfelder verknuepfen (siehe buildManualHeatpumpEntry()) - der
 * manuelle Eintrag durchlaeuft danach denselben Mapping-Code wie jede
 * andere Quelle.
 *
 * Optionale Felder (contractVersion 1.3, mit HeishaMon abgestimmt, additiv
 * versioniert): pumpFlowID (l/min), pumpSpeedID (U/min), pumpDutyID
 * (Rohwert), threeWayValveStateID (0=Room/1=DHW), twoWayValveStateID
 * (bool), mainInletTempID/mainOutletTempID/z1WaterTempID/z2WaterTempID/
 * dhwTempID/bufferTempID/dischargeTempID (°C), compressorFreqID (Hz),
 * defrostingStateID (bool), sourceInTempID/sourceOutTempID (°C, Foerder-/
 * Rueckpumpwasser bei Sole/Wasser bzw. Wasser/Wasser - contractVersion
 * 1.5, additiv). Fehlt ein Feld (=0), wird es im Schema ausgeblendet statt
 * einer Nullanzeige.
 */
require_once __DIR__ . '/../libs/FormStatus.php';
require_once __DIR__ . '/../libs/NRGDASH_Text.php';

class NRGDashboardHeatSchema extends IPSModule
{
    use NRGDashFormStatus;

    private const HEISHA_GUID = '{1919151A-3C0F-4C09-B906-291638EC1469}';
    // WPHub-Vertrag (contractVersion 1.3, mit WPHub am 13.08.2026 bestaetigt):
    // liefert NIE die 14 Pumpen-/Ventilfelder (Cloud-API kennt sie nicht,
    // Schluessel fehlen komplett statt 0) - deshalb ueberall defensiv mit
    // ?? 0 statt fester Schluesselmenge gelesen (siehe DiscoverHeatpumps()).
    private const WPHUB_GUID = '{5BE429EA-3AAD-4A8B-85DE-5778CCA2E6BC}';
    // Waermepumpen-Quellen des heatpump-Vertrags (Discovery-Liste). Neu am
    // 18.09.2026 auf Bitte der WPHub-Sitzung (Dietmar hat es freigegeben):
    // WPModbusHub, WPModbusHubGateway (Symcons ModBus-Gateway, RS485/RTU) und
    // SamsungEhs (NASA-Protokoll) liefern denselben Vertrag wie WPHub
    // (contractVersion 1.15, PowerID/EnergyID immer 0). Datenlage laut
    // WPHub-Sitzung: nur SamsungEhs an echter Anlage bestaetigt, WPModbusHub
    // teilweise, WPModbusHubGateway ueber den Gateway-Weg an keiner Anlage
    // getestet - also Beta-Quellen. Bewusst eine feste Liste statt
    // generischer Suche ueber alle *_GetFunctions: die Vertragsfunktionen
    // fremder Module blind aufzurufen waere riskanter als ein Eintrag mehr.
    private const HEATPUMP_SOURCES = [
        '{1919151A-3C0F-4C09-B906-291638EC1469}' => 'HEISHA_GetFunctions',
        '{5BE429EA-3AAD-4A8B-85DE-5778CCA2E6BC}' => 'WPHUB_GetFunctions',
        '{E878B4D4-8E98-4E89-AE21-8636262EBC55}' => 'WPMBHUB_GetFunctions',
        '{70FBAC61-A1C0-47B7-8B56-BE047F7C0C6B}' => 'WPMBGW_GetFunctions',
        '{D2B2A1E8-2F94-426C-8761-505A2F226977}' => 'SAMEHS_GetFunctions',
        // WPBsbLan (24.09.2026, Meldung der WPHub-Sitzung) - BSB-LAN-Adapter
        // (Siemens RVS/LMU), erste Anlage Fujitsu Waterstage, im Store
        // veroeffentlicht und an echter Hardware bestaetigt (Forum-Thread
        // WPHub #4-#17). Gleicher heatpump-Vertrag, contractVersion 1.15.
        '{D077685F-50BF-4678-A42A-0A33CDEF8C89}' => 'WPBSBL_GetFunctions',
        // WPEEBUSHub (09.11.2026): Waermepumpe ueber EEBUS, heatpump-Vertrag.
        '{731C4932-48B3-4360-BFED-1CA034978BCB}' => 'WPEEB_GetFunctions',
    ];
    private const METERHUB_GUID = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';

    // Manuelle Datenanbindung (Dietmar, 17.08.2026): Vertragsfeld =>
    // Property-Name. Deckt GENAU dieselben *ID-Felder ab, die
    // DiscoverHeatpumps() auch von HeishaMon/WPHub liest (siehe
    // buildPayload()) - ein manuell verknuepfter Eintrag durchlaeuft
    // danach denselben Mapping-Code wie jeder andere Vertragslieferant.
    private const MANUAL_FIELDS = [
        'PowerID'                 => 'ManualPowerID',
        'pumpFlowID'              => 'ManualPumpFlowID',
        'pumpSpeedID'             => 'ManualPumpSpeedID',
        'pumpDutyID'              => 'ManualPumpDutyID',
        'threeWayValveStateID'    => 'ManualThreeWayValveStateID',
        'twoWayValveStateID'      => 'ManualTwoWayValveStateID',
        'mainInletTempID'         => 'ManualMainInletTempID',
        'mainOutletTempID'        => 'ManualMainOutletTempID',
        // Waermequelle bei Sole/Wasser oder Wasser/Wasser (Dietmar,
        // 10.09.2026: eigene Datenpunkte fuer Foerder-/Rueckpumpwasser,
        // NICHT ueber mainInletTemp/mainOutletTemp mitbenutzen - jene
        // sind bereits der Hauptkreis-Vor-/Ruecklauf Richtung Heizkreise,
        // siehe INNEN_PUF_CX in module.html).
        'sourceInTempID'          => 'ManualSourceInTempID',
        'sourceOutTempID'         => 'ManualSourceOutTempID',
        'z1WaterTempID'           => 'ManualZ1WaterTempID',
        'z2WaterTempID'           => 'ManualZ2WaterTempID',
        'dhwTempID'               => 'ManualDhwTempID',
        'bufferTempID'            => 'ManualBufferTempID',
        'compressorFreqID'        => 'ManualCompressorFreqID',
        'dischargeTempID'         => 'ManualDischargeTempID',
        'defrostingStateID'       => 'ManualDefrostingStateID',
        'fan1SpeedID'             => 'ManualFan1SpeedID',
        'fan2SpeedID'             => 'ManualFan2SpeedID',
        'internalHeaterStateID'   => 'ManualInternalHeaterStateID',
        'externalHeaterStateID'   => 'ManualExternalHeaterStateID',
        'suctionTempID'           => 'ManualSuctionTempID',
        'copEstimateID'           => 'ManualCopEstimateID',
        'copMeasuredID'           => 'ManualCopMeasuredID',
        'dailyPerformanceFactorID' => 'ManualDailyPerformanceFactorID',
        'z1PumpID'                => 'ManualZ1PumpID',
        'z1MixingValveID'         => 'ManualZ1MixingValveID',
        'z1MixingValvePositionID' => 'ManualZ1MixingValvePositionID',
        'z2PumpID'                => 'ManualZ2PumpID',
        'z2MixingValveID'         => 'ManualZ2MixingValveID',
        'z2MixingValvePositionID' => 'ManualZ2MixingValvePositionID',
        'indoorPipeTempID'        => 'ManualIndoorPipeTempID',
        'operatingModeNormID'     => 'ManualOperatingModeNormID',
        'operatingModeID'         => 'ManualOperatingModeID',
    ];

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';

    // Verbund-Formularkonvention (EMS/SUITE.md "Einheitliche Formular-Optik",
    // Muster NRGDashboardMap/Topology/Tile): "Was ist Neu" (versionsscharf
    // dismissible, Version IN der Caption) + Doku-Panel mit dauerhafter
    // Versionszeile + GitHub-Hinweis (noch kein Forum-Thread, Modul
    // unveroeffentlicht - einmalig dismissible). NEWS_VERSION bei jeder
    // nutzersichtbaren Aenderung erhoehen.
    // Versionsweise statt EINER flachen Liste (Dietmar, 23.09.2026: "nur die
    // Neuerungen zwischen der zuletzt verwendeten und der nun installierten
    // Version anzeigen") - newsBanner() zeigt jeden Schluessel-Eintrag, dessen
    // Version NEUER ist als das zuletzt bestaetigte Attribut SeenNews (siehe
    // dort), gruppiert nach Version. Die alte, gewachsene Liste bleibt als EIN
    // Eintrag unter ihrer letzten Vergabe ('0.5.5') erhalten statt sie
    // nachtraeglich (und unzuverlaessig) pro Zeile einer Version zuzuordnen -
    // neue Eintraege ab jetzt bekommen ihre EIGENE Versionsnummer als Schluessel.
    private const NEWS_VERSIONS = [
        '0.5.5' => [
            'Feedback panel now exactly as in MeterHub: a dedicated "To the forum thread" button instead of a plain link text.',
            '💬 The Symcon forum thread is now live - the previous GitHub note in the feedback panel now points there.',
            'Introduction tour extended by four explanations checked by HeishaMon: compressor frequency, ΔT between flow and return, COP vs. performance factor, and what defrost mode means technically.',
            '🧡 New: "About this module" (license/donation note) at the very bottom of the form; the forum/GitHub note is now a panel of its own that can be dismissed, instead of a plain line.',
            '👋 New: a "What is this module for?" panel at the very top of the form briefly explains what this tile does and what it is good for - meant for first contact, can be dismissed once.',
            '✨ New: three further system types behind the double arrow - "Brine/water (borehole)", "Brine/water (ground collector)" and "Water/water (well)". Instead of an outdoor unit, the diagram draws a plain exchanger box for them with the matching underground piping (borehole, surface collector or intake/return wells), without the fan and defrost mode that do not exist for these sources. Two new optional data points for the source inlet/return temperature can be linked manually.',
            'Fix: in the simulation the heating element was shown in EVERY operating mode, even in cooling mode (technically wrong) - it is now off by default and only visible in simulated defrost mode, where a booster heater is realistic.',
            'Fix: in the "Hot water mode" simulation both heating circuits kept running although the three-way valve is set to hot water - HC1/HC2 now stand still (as in standby), and the flow shows the higher tank charging temperature.',
            'New "?" button at the top right shows the introduction tour again at any time - regardless of whether it has been confirmed before. Intended for shared instances (e.g. a demo/showcase instance with a shared login) where every visitor should be able to start the tour themselves.',
            'New: current COP + daily performance factor in the plant diagram (if the heat pump module supplies the values).',
            'New: flow rate display on the pump circuit.',
            'New: manual data connection for heat pumps without HeishaMon/WPHub.',
        ],
        '0.9.147' => [
            '✨ New: third storage type "Fresh water station" behind the double arrow - the buffer stays a completely normal, classic buffer (unlike with the combi tank); an external fresh water module with a plate heat exchanger prepares hot water on demand instead of in a tank of its own. Drawn as a separate component next to the buffer (buffer flow/return + cold/hot water connection), no new data points needed, the existing buffer temperature sensor is enough.',
        ],
    ];
    /**
     * Alle Texte der Kachel (module.html, tr('...')): die englischen Quelltexte. NRGDASH_Text::kachelHtml() liefert ihre
     * Uebersetzungen als Tabelle (window.NRGDASH_TEXTS) an Kachel und Standalone-Seite; der Pruefstand vergleicht beide Listen.
     */
    private const TILE_TEXTS = [
        'decimal point',
        'Borehole',
        'Ground collector',
        'Return well',
        'Intake well',
        'BOREHOLE',
        'GROUND COLLECTOR',
        'GROUNDWATER',
        'OUTDOOR UNIT',
        'Power',
        'Compressor',
        'Source in',
        'Source out',
        'Fan 1',
        'Fan',
        'rpm',
        'Fan 2',
        'COP now',
        'Daily COP',
        'Hot gas',
        'Suction gas',
        'DEFROST MODE',
        'INDOOR UNIT',
        'Defrost mode',
        'Hot water',
        'Cooling mode',
        'Heating mode',
        'Circulation',
        'Standby',
        'Heating element',
        'HX',
        'Evaporator',
        'DHW tank',
        'Pump',
        'Speed',
        'unknown',
        'Heating',
        'Cooling',
        'Heating + DHW',
        'Cooling + DHW',
        'Heating only',
        'Cooling only',
        'Auto (heating)',
        'Hot water only',
        'Auto (heating) + DHW',
        'Auto (cooling)',
        'Auto (cooling) + DHW',
        'Mode',
        '3-way valve',
        'DHW',
        'Room',
        '2-way valve',
        'Open',
        'Closed',
        'Cold water',
        'Shower + bath',
        'Cooling output',
        'Thermal output',
        'Combi tank',
        'Buffer',
        '→ Hot water',
        'DHW top',
        'DHW bottom',
        'Stratification',
        'Return',
        'Heat storage',
        'Cold',
        'water →',
        '← Warm',
        'water',
        '→ Warm',
        'Fresh water station',
        'opening',
        'closing',
        'idle',
        'Mixer',
        'Pump (no status)',
        'Radiators',
        'Underfloor heating',
        'HEATING CIRCUIT',
        'No plant diagram data (pumps/valves/flow) available - basic values only.',
        'Not a grid of readings - a real pipe diagram',
        'This tile draws your heat pump as an animated plant diagram, the kind usually found only in professional building management systems: pumps, valves and flow direction live in motion - instead of a table of numbers you have to interpret yourself.',
        'Works with any connected heat pump',
        'The tile finds your heat pump automatically through HeishaMon or WPHub - no device type is hard-wired. If you have neither, you can link your own existing variables to the same fields under “Manual data connection”.',
        'Shows only what your system actually provides',
        'If your data source supplies no pump/valve data (e.g. a pure WPHub cloud connection without HeishaMon), only the basic values appear instead of an incomplete diagram that looks wrong. Individual missing sensors (e.g. no second heating circuit) are also hidden automatically instead of faking a zero.',
        'Compressor frequency: is it running, and how hard?',
        '0 Hz means the compressor is stopped (standby or between two cycles). A value above 0 shows how fast it is currently turning - a higher frequency means more heating/cooling output, but also higher electrical consumption. The exact scale depends on the compressor model, so it cannot be compared 1:1 between systems.',
        'ΔT between flow and return shows how hard the system is heating',
        'The difference between flow and return temperature (ΔT) grows the more heat the system is currently delivering. An underfloor heating circuit typically runs with a small spread (a few kelvin), radiators or hot water preparation with considerably more. A permanently very small ΔT with the compressor running can indicate too high a flow rate or air in the system - a heating professional can assess that if in doubt; the tile does not rate it automatically.',
        'Performance factor at a glance',
        'If your heat pump supplies it, the diagram shows the current COP (the manufacturer’s estimate or a real measurement via an external meter) as well as the daily performance factor - deliberately without decimal places, so as not to fake an accuracy the source does not provide. COP is a snapshot (the efficiency right now), while the performance factor averages over the whole day - with strongly changing outdoor temperatures or frequent cycling the two values can differ considerably; that is normal.',
        'Two heating circuits, adjustable independently',
        'Radiators and underfloor heating can be shown or hidden separately for each heating circuit - for systems with only one type of heating surface or only one instead of two heating circuits. Switch directly in the expanded tile view (double arrow), without reconfiguring the instance.',
        'Adapts to your system type',
        'Split or monoblock, buffer tank, hot water tank with its own volume - everything adjustable through switches in the expanded tile view. If you set monoblock, the hot water tank options disappear automatically, because such a unit never has an integrated tank anyway.',
        'Water flow to your taste',
        'Five different styles for the animated water flow (organic wave, dots, dashes, liquid gloss, digital signal pulses) as well as motion type and speed selectable separately - also behind the double arrow.',
        'Testing without a running system',
        'The simulation (double-arrow view) lets you play through heating, cooling, hot water, standby and defrost mode with fixed sample values - handy for checking the display without waiting for the matching real operating state. (Defrost mode: the system briefly reverses the refrigeration cycle to melt frost off the outdoor unit - normal in freezing weather, not a fault.)',
        'Show introduction tour',
        'Back',
        'Next',
        '✓ Understood, do not show again',
        'No data available.',
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
        // Uebersetzer fuer NRGDASH_Text eintragen (Merkliste-Cache, Stolperstein 24)
        NRGDASH_Text::setzen(fn (string $text): string => $this->Translate($text));

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
        // "Hat wirklich 2 Luefter"-Gedaechtnis (HeishaMon-Fund, 25.08.2026):
        // fan2SpeedID liegt bei JEDER HeishaMon-Installation an einer festen
        // Byte-Position im CN-CNT-Protokoll und wird IMMER dekodiert/
        // gesendet, unabhaengig von der tatsaechlichen Geraete-Hardware -
        // die reine ID-Existenz ist also KEIN verlaesslicher Hinweis auf
        // einen echten zweiten Luefter (Dietmar, 25.08.2026: "meine
        // Panasonic hat nur ein Lüfter"). Das Protokoll selbst liefert kein
        // Meta-Feld dafuer (HeishaMon-Rueckmeldung), einzig verlaesslicher
        // Hinweis ist ein tatsaechlich beobachteter Wert > 0 - einmal
        // gesehen, bleibt es dauerhaft gemerkt (JSON-Map Quell-Instanz-ID
        // => true), sonst wuerde die 2-Luefter-Darstellung bei jedem
        // Idle-Zustand des Verdichters wieder verschwinden.
        $this->RegisterAttributeString('Fan2Confirmed', '{}');

        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        // Standalone-Webseite (Hook) abschaltbar (09.11.2026, Forum doctee #232):
        // Standard an, damit bestehende Installationen unveraendert laufen.
        $this->RegisterPropertyBoolean('StandaloneEnabled', true);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);
        // Symcon bietet einer Kachel keine automatische Theme-Erkennung
        // an, deshalb stellt der Nutzer die Oberflaechenhelligkeit
        // einmalig selbst ein - gleiche Konvention wie im Monitor.
        $this->RegisterPropertyBoolean('LightTheme', false);
        // Freitext je Heizkreis (z. B. "Radiatoren", "Fussbodenheizung").
        // Leer = es wird nur "Heizkreis" bzw. "Heizkreis 1/2" angezeigt -
        // die Heizflaechen unterscheiden sich von Anlage zu Anlage.
        $this->RegisterPropertyString('Zone1Caption', '');
        $this->RegisterPropertyString('Zone2Caption', '');
        // Manuelle Datenanbindung fuer Waermepumpen OHNE HeishaMon/WPHub
        // (Dietmar, 17.08.2026: "Diese Hydraulikschemen, die sollten
        // natuerlich auch anderen zur Verfuegung stehen die keine
        // HeishaMon haben ... manuell einzurichtende Datenpunkte ... die
        // man mit etwas anderem verbinden kann") - ein zusaetzlicher
        // Eintrag im 'heatpump'-Vertrag (siehe DiscoverHeatpumps()),
        // dessen *ID-Felder auf beliebige, bereits vorhandene IPS-
        // Variablen verweisen statt auf ein liefendes Erzeuger-Modul.
        // SelectVariable-Formularelemente gibt es nur im Formular
        // (Admin-Konsole), nicht als Instanz-Variable mit EnableAction -
        // deshalb bewusst Form-Properties statt Doppelpfeil-Variablen,
        // anders als Bauart/Puffer/WW-Tank/Simulation oben.
        $this->RegisterPropertyBoolean('ManualEnabled', false);
        $this->RegisterPropertyString('ManualCaption', '');
        foreach (self::MANUAL_FIELDS as $propertyName) {
            $this->RegisterPropertyInteger($propertyName, 0);
        }
        // Kombispeicher-Sensoren (24.09.2026, Forum-Wunsch von Christian
        // "kollaps" ueber die WPHub-Sitzung: ein Kombispeicher/Hygienik-
        // Speicher statt getrenntem Puffer+WW-Tank braucht eigene
        // Temperaturpunkte - WW oben, WW unten, Waermespeicher (Pufferzone),
        // Ruecklauf) - bewusst NICHT in MANUAL_FIELDS: dort geht es um
        // Auto+Manuell-Merge fuer bestehende heatpump-Vertragsfelder, diese
        // vier Punkte sind ausschliesslich manuell verknuepfbar (es gibt
        // keinen Anbieter-Vertrag dafuer).
        $this->RegisterPropertyInteger('ComboTopTempID', 0);
        $this->RegisterPropertyInteger('ComboBottomTempID', 0);
        $this->RegisterPropertyInteger('ComboMidTempID', 0);
        $this->RegisterPropertyInteger('ComboReturnTempID', 0);
        // Heizkoerper und Fussbodenheizung je Kreis unabhaengig ein-
        // /ausblendbar (Dietmar, 16.08.2026: "manche haben nur
        // Fussbodenheizung ... auch in den Versionen mit nur einem HK
        // oder mit 2 HK" - eine Kachel mit Schaltern statt mehrerer
        // Kachel-Varianten). Default beide an.
        //
        // ALS ECHTE VARIABLEN statt Properties (Dietmar, 16.08.2026: "ich
        // haette sie gerne wie bei den Eurotronic Comet WiFi auf der
        // vergroesserten Kachelseite") - Befund aus der CometWiFi-Sitzung
        // (Cross-Session-Nachricht, 16.08.2026): das Aufziehen einer
        // Kachel zeigt NIE das eigene Kachel-HTML, sondern immer die
        // Standardansicht der Instanz-KINDER (Variablen/Verknuepfungen).
        // Ein per ResizeObserver im Kachel-HTML versteckter/gezeigter
        // Schalter (erster, verworfener Versuch) wuerde dort nie
        // erscheinen. Eigene Boolean-Variablen mit EnableAction()
        // rendern dagegen als normale Schalter in genau dieser
        // aufgezogenen Ansicht - gleiches Muster wie EMS_GridRewards im
        // EMS-Modul (RegisterVariableXXX() in Create() ist idempotent
        // und laut SUITE.md-Konvention der richtige Ort dafuer - anders
        // als ein Aufruf in ApplyChanges(), siehe SUITE.md Punkt 3).
        //
        // Create() laeuft bei JEDEM Symcon-Neustart erneut fuer jede
        // bestehende Instanz, nicht nur bei echter Neuanlage (Hinweis
        // der CometWiFi-Sitzung, 16.08.2026, hat einen echten Bug hier
        // aufgedeckt) - ein unbedingtes SetValue() haette Dietmars
        // manuelle Umschaltungen bei jedem Neustart stillschweigend auf
        // "an" zurueckgesetzt. Der Default wird deshalb nur gesetzt,
        // wenn die Variable hier tatsaechlich NEU angelegt wird.
        $zoneToggles = [
            'Zone1ShowRadiator' => NRGDASH_Text::t('Heating circuit 1: radiators'),
            'Zone1ShowFloor'    => NRGDASH_Text::t('Heating circuit 1: underfloor heating'),
            'Zone2ShowRadiator' => NRGDASH_Text::t('Heating circuit 2: radiators'),
            'Zone2ShowFloor'    => NRGDASH_Text::t('Heating circuit 2: underfloor heating'),
        ];
        $pos = 10;
        foreach ($zoneToggles as $ident => $caption) {
            $isNew = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->RegisterVariableBoolean($ident, $caption, '', $pos);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, true);
            }
            $pos += 10;
        }

        // Wasserfluss-Darstellung waehlbar (Dietmar, 16.08.2026: "kannst
        // du mehrere Wasserflussmechanismen zur Auswahl hinter dem
        // Doppelpfeil stellen?") - gleicher Grund wie bei den Emitter-
        // Schaltern: eine Auswahl-Variable mit Profil-Assoziationen statt
        // Formular-Property, damit sie in der aufgezogenen Kachelansicht
        // als Dropdown erscheint. Optionen und deren CSS-Umsetzung siehe
        // module.html (".pipe-dots"-Varianten).
        if (!IPS_VariableProfileExists('NRGDASHHEAT.FlowStyle')) {
            IPS_CreateVariableProfile('NRGDASHHEAT.FlowStyle', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowStyle', 0, NRGDASH_Text::t('Organic wave'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowStyle', 1, NRGDASH_Text::t('Even dots'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowStyle', 2, NRGDASH_Text::t('Flowing dashes'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowStyle', 3, NRGDASH_Text::t('Liquid gloss'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowStyle', 4, NRGDASH_Text::t('Digital signal pulses'), '', -1);
        $flowIsNew = @IPS_GetObjectIDByIdent('FlowStyle', $this->InstanceID) === false;
        $this->RegisterVariableInteger('FlowStyle', NRGDASH_Text::t('Water flow style'), 'NRGDASHHEAT.FlowStyle', 50);
        $this->EnableAction('FlowStyle');
        if ($flowIsNew) {
            $this->SetValue('FlowStyle', 0);
        }

        // Bewegungsart und Geschwindigkeit UNABHAENGIG von der Optik
        // waehlbar (Dietmar, 16.08.2026: "einmal so wie die Organische
        // Welle und der Fluessigkeitsglanz laeuft, oder ... laufe
        // konstant, du koenntest sogar noch die Geschwindigkeit zur
        // Auswahl stellen") - gelten fuer ALLE vier Optik-Stile
        // gleichermassen (siehe module.html), keine Ausnahme fuer
        // Punkte/Striche mehr (Dietmar, 16.08.2026: "wenn ich es
        // aktivieren kann, dann sollte es auch funktionieren").
        if (!IPS_VariableProfileExists('NRGDASHHEAT.FlowMotion')) {
            IPS_CreateVariableProfile('NRGDASHHEAT.FlowMotion', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowMotion', 0, NRGDASH_Text::t('Breathing'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowMotion', 1, NRGDASH_Text::t('Constant'), '', -1);
        $flowMotionIsNew = @IPS_GetObjectIDByIdent('FlowMotion', $this->InstanceID) === false;
        $this->RegisterVariableInteger('FlowMotion', NRGDASH_Text::t('Water flow motion'), 'NRGDASHHEAT.FlowMotion', 51);
        $this->EnableAction('FlowMotion');
        if ($flowMotionIsNew) {
            $this->SetValue('FlowMotion', 0);
        }

        if (!IPS_VariableProfileExists('NRGDASHHEAT.FlowSpeed')) {
            IPS_CreateVariableProfile('NRGDASHHEAT.FlowSpeed', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowSpeed', 0, NRGDASH_Text::t('Slow'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowSpeed', 1, NRGDASH_Text::t('Normal'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowSpeed', 2, NRGDASH_Text::t('Fast'), '', -1);
        $flowSpeedIsNew = @IPS_GetObjectIDByIdent('FlowSpeed', $this->InstanceID) === false;
        $this->RegisterVariableInteger('FlowSpeed', NRGDASH_Text::t('Water flow speed'), 'NRGDASHHEAT.FlowSpeed', 52);
        $this->EnableAction('FlowSpeed');
        if ($flowSpeedIsNew) {
            $this->SetValue('FlowSpeed', 1);
        }

        // Anlagenbauart und optionale Komponenten (Dietmar, 17.08.2026:
        // "manche Waermepumpen haben einen groesseren Puffer" + "Anlagen
        // ohne Innenteil" (= Monoblock, hat NIE einen integrierten
        // WW-Tank) + "moeglich ist auch ein Splitgeraet ohne WW-Tank"),
        // ebenfalls als Instanz-Variablen statt Formular-Properties
        // (Dietmar, 17.08.2026: "die Anlagenbauart kannst du auch hinter
        // den Doppelpfeil stecken") - selbes Muster wie die Emitter-
        // Schalter oben: Anlagendaten aendern sich zwar selten, aber der
        // Doppelpfeil ist trotzdem der Ort, den Dietmar dafuer will,
        // statt in der Admin-Konsole zu suchen.
        if (!IPS_VariableProfileExists('NRGDASHHEAT.Bauart')) {
            IPS_CreateVariableProfile('NRGDASHHEAT.Bauart', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.Bauart', 0, NRGDASH_Text::t('Split (with indoor unit)'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.Bauart', 1, NRGDASH_Text::t('Monoblock (outdoor unit only)'), '', -1);
        // Erdsonde und Brunnen (Dietmar, 10.09.2026, Forum-Rueckfrage
        // "somm": "wie schaut es aus wenn man zb wie ich eine
        // Tiefenbohrung hat und keinen Pufferspeicher" - der Puffer war
        // schon vorher optional (HasBuffer), die Waermequelle aber bis
        // hierher immer eine Aussen-Luft-Einheit. Beide neuen Bauarten
        // ersetzen im Schema nur das Aussengeraet durch die jeweilige
        // Waermequelle; WT, Innengeraet, Puffer/WW-Tank und Heizkreise
        // bleiben unveraendert, weil mainInletTemp/mainOutletTemp bereits
        // quellenneutral die Temperatur vor/nach dem Verdampfer
        // beschreiben (nicht "Luft"-spezifisch).
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.Bauart', 2, NRGDASH_Text::t('Brine/water (borehole)'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.Bauart', 3, NRGDASH_Text::t('Water/water (well)'), '', -1);
        // Erdkollektor (Dietmar, 10.09.2026, per Referenzbild: "es ist
        // doch so, dass ... bei den 3 moeglichen anderen
        // Waermepumpentypen ... eine recht einfache Verdichterbox
        // steht" - das Referenzbild zeigt Tiefenbohrung, Erdkollektoren
        // und Grundwasser als die drei Nicht-Luft-Varianten) - horizontal
        // im Untergrund flach verlegte Rohrschleifen statt einer
        // Tiefenbohrung, braucht mehr Grundstuecksflaeche statt Tiefe.
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.Bauart', 4, NRGDASH_Text::t('Brine/water (ground collector)'), '', -1);
        $bauartIsNew = @IPS_GetObjectIDByIdent('Bauart', $this->InstanceID) === false;
        $this->RegisterVariableInteger('Bauart', NRGDASH_Text::t('System type'), 'NRGDASHHEAT.Bauart', 60);
        $this->EnableAction('Bauart');
        if ($bauartIsNew) {
            $this->SetValue('Bauart', 0);
        }

        // Speicherart (24.09.2026, Forum-Wunsch Christian "kollaps") - ein
        // Kombispeicher (z.B. "Hygienik 2.0") ersetzt den getrennten
        // Puffer+WW-Tank durch EINEN Tank mit 3 Abgaengen (oben WW, Mitte
        // Heizen-Vorlauf, unten Ruecklauf). Default 0 (Klassisch) haelt das
        // bisherige Verhalten fuer alle bestehenden Instanzen unveraendert -
        // keine erfundene Verhaltensaenderung fuer Bestandsnutzer.
        if (!IPS_VariableProfileExists('NRGDASHHEAT.StorageType')) {
            IPS_CreateVariableProfile('NRGDASHHEAT.StorageType', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.StorageType', 0, NRGDASH_Text::t('Classic (buffer / separate DHW tank)'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.StorageType', 1, NRGDASH_Text::t('Combi tank (1 tank, 3 outlets)'), '', -1);
        // Frischwasserstation (29.09.2026, Forum-Wunsch cbeham im WPHub-
        // Thread: "Eine Option 'Frischwasserstation' beim Puffer wäre noch
        // Cool!") - anders als der Kombispeicher (EIN Tank ersetzt Puffer+
        // WW-Tank) bleibt hier der Puffer ein ganz normaler, klassischer
        // Puffer; zusaetzlich haengt ein externes Frischwassermodul (Platten-
        // waermetauscher + Pumpe) am oberen Pufferanschluss und bereitet
        // Warmwasser im Durchlauf statt in einem eigenen Speicher - deshalb
        // hasDhwTank wie beim Kombispeicher erzwungen aus (siehe unten), aber
        // OHNE die Kombispeicher-spezifische Tank-/Chip-Geometrie zu
        // beeinflussen. Legionellen-/Hygienevorteil ist der uebliche Grund
        // fuer diese Bauart.
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.StorageType', 2, NRGDASH_Text::t('Fresh water station (on the buffer, no DHW tank)'), '', -1);
        $storageTypeIsNew = @IPS_GetObjectIDByIdent('StorageType', $this->InstanceID) === false;
        $this->RegisterVariableInteger('StorageType', NRGDASH_Text::t('Storage type'), 'NRGDASHHEAT.StorageType', 61);
        $this->EnableAction('StorageType');
        if ($storageTypeIsNew) {
            $this->SetValue('StorageType', 0);
        }

        $componentToggles = [
            'HasBuffer'  => [NRGDASH_Text::t('Buffer tank present'), 62, true],
            'HasDhwTank' => [NRGDASH_Text::t('DHW tank present'), 65, true],
        ];
        foreach ($componentToggles as $ident => $spec) {
            $isNew = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->RegisterVariableBoolean($ident, $spec[0], '', $spec[1]);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, $spec[2]);
            }
        }
        // Literzahlen als einfache editierbare Zahl-Variablen (kein
        // Profil noetig) - Position direkt hinter dem zugehoerigen
        // Schalter.
        $literToggles = [
            'BufferLiters' => [NRGDASH_Text::t('Buffer tank: volume (l)'), 63, 100],
            'DhwLiters'    => [NRGDASH_Text::t('DHW tank: volume (l)'), 66, 185],
        ];
        foreach ($literToggles as $ident => $spec) {
            $isNew = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->RegisterVariableInteger($ident, $spec[0], '', $spec[1]);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, $spec[2]);
            }
        }

        // Bei Monoblock gibt es NIE einen WW-Tank (siehe hasDhwTank in
        // buildBasePayload) - die beiden zugehoerigen Einstellungen
        // (Vorhanden-Schalter + Literzahl) dann auch aus der Doppelpfeil-
        // Ansicht ausblenden, statt sie wirkungslos stehen zu lassen
        // (Dietmar, 17.08.2026: "wenn man Monoblock ankreuzt, dann kann
        // auch der WW-Tank in den Einstellmoeglichkeiten verschwinden").
        $this->ApplyDhwVisibility();

        // Simulation (Dietmar, 17.08.2026: "die Buttons fuer die
        // Simulation genauso [hinter den Doppelpfeil]") - dieselben
        // Betriebsmodus-Szenarien wie im lokalen Pruefstand
        // (.tools/test-scenarios.html), aber direkt in der echten
        // Kachel/aufgezogenen Ansicht schaltbar: ersetzt bei aktivierter
        // Simulation die echten Sensordaten durch feste Beispielwerte
        // (siehe buildSimulatedUnit()), ohne die Instanz neu
        // konfigurieren zu muessen. Die Optionen haengen von der Bauart
        // ab (siehe ApplySimulationProfile()) und werden deshalb bei
        // jedem Bauart-Wechsel neu aufgebaut.
        $this->ApplySimulationProfile();

        $this->RegisterTimer('Refresh', 0, 'NRGDASHHEAT_Render($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Uebersetzer fuer NRGDASH_Text eintragen (Merkliste-Cache, Stolperstein 24)
        NRGDASH_Text::setzen(fn (string $text): string => $this->Translate($text));
        $this->SetVisualizationType(1);
        $this->SetTimerInterval('Refresh', 5 * 60 * 1000);
        // Standalone-Webseite fuer IPSView/Browser (WebView/Popup) - Muster
        // Prognoses Energiebilanz-Modul (Dietmar, 27.08.2026: "alle Kacheln
        // ... auch so vorbereiten, dass man sie in IPSView einbinden kann").
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->SyncHook('/hook/nrgdashheatschema' . $this->InstanceID);
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
     * Liefert die Kachel als eigenstaendige Webseite (fuer IPSView-WebView/
     * Popup oder jeden Browser). Aufruf: /hook/nrgdashheatschema<InstanzID>.
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

    /** Verbindungsstatus zu den Waermepumpen (SUITE.md "Verbund-Verbindungen sichtbar machen"). */
    private function heatpumpStatusLine(): string
    {
        $entries = $this->DiscoverHeatpumps();
        if (count($entries) === 0) {
            return NRGDASH_Text::t('ℹ️ No heat pump found (HeishaMon, WPHub, WPModbusHub, gateway, SamsungEhs) - the manual data connection below applies, otherwise the diagram only shows a note.');
        }
        $ids = array_values(array_unique(array_map(function ($e) {
            return (int) ($e['_instanceID'] ?? 0);
        }, $entries)));
        $manual = in_array($this->InstanceID, $ids, true);
        $ids = array_values(array_filter($ids, function ($id) {
            return $id !== $this->InstanceID;
        }));
        if (count($ids) === 0) {
            return NRGDASH_Text::t('ℹ️ No heat pump instance found - the manual data connection below applies.');
        }
        $names = array_map(function ($id) {
            return $this->formInstanceLabel($id) . ' (' . $this->formInstanceState($id) . ')';
        }, $ids);
        // Einzahl/Mehrzahl als zwei vollstaendige Saetze (nie Satzteile kleben)
        $line = count($ids) === 1
            ? NRGDASH_Text::t('✅ %d heat pump detected automatically: %s.', count($ids), implode(', ', $names))
            : NRGDASH_Text::t('✅ %d heat pumps detected automatically: %s.', count($ids), implode(', ', $names));
        return $line . ($manual ? ' ' . NRGDASH_Text::t('Additionally the manual data connection applies.') : '');
    }

    public function GetConfigurationForm()
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }
        // Label mit eingesetztem Wert: die Konsole findet den Text danach nicht mehr, deshalb hier selbst uebersetzt
        $this->setFormCaption($form['elements'], 'HookInfo', NRGDASH_Text::t('IPSView/popup: the tile also runs as a standalone web page at http://<Symcon-IP>:3777%s (auto-refresh every 30 s). In IPSView, place a WebView element on a popup page and enter this URL. Note: WebHooks can be reached on the local network without logging in.', '/hook/nrgdashheatschema' . $this->InstanceID));

        $this->injectVersionIntoDocPanel($form);
        $this->setFormStatusLine($form['elements'], 'HeatpumpStatus', $this->heatpumpStatusLine());

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
                    ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHHEAT_DismissReviewHint($id);'],
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
            ? NRGDASH_Text::t('ℹ️ NRG-Stack Dashboard version %s (build %s)', $lib['Version'], $lib['Build'] ?? '?')
            : 'ℹ️ NRG-Stack Dashboard';
        foreach ($form['elements'] as &$el) {
            // Panel ueber den Namen finden, nie ueber die (uebersetzte) Caption
            if (($el['type'] ?? '') === 'ExpansionPanel' && ($el['name'] ?? '') === 'PanelDoc') {
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
                ['type' => 'Label', 'caption' => 'Draws the structure of a heat pump - outdoor/indoor unit, buffer tank, hot water, heating circuits - as a schematic diagram with live values (temperatures, flow, operating mode).'],
                ['type' => 'Label', 'caption' => 'The benefit: see at a glance how the system is running and where the heat flows, instead of comparing individual temperature values in table form. Missing sensors (e.g. no second heating circuit) are hidden automatically.'],
                ['type' => 'Label', 'caption' => 'The data comes from HeishaMon or WPHub through the shared "heatpump" contract. NRGDashboardWPMonitor additionally shows history and key figures of the same heat pump as a chart.'],
                ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHHEAT_AckPurposeIntro($id);'],
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
                // Zeilen stehen englisch in NEWS_VERSIONS; die Aufzaehlungspunkt-Marke ist kein Text
                $items[] = ['type' => 'Label', 'caption' => '• ' . NRGDASH_Text::t($line)];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHHEAT_AckNews($id);'];
        $latest = array_key_last($pending);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => NRGDASH_Text::t('🆕 New up to version %s', $latest), 'expanded' => true, 'items' => $items];
    }

    // Eigene Modul-GUID (14.09.2026, "geteiltes Ausblenden ueber
    // Geschwister-Instanzen") - fuer die Geschwistersuche unten, NICHT
    // fuer die Discovery anderer Verbund-Module (dafuer stehen eigene
    // GUID-Konstanten je Partnermodul).
    private const SELF_MODULE_GUID = '{424584D6-4402-4A56-BDF3-924DB66DE01D}';

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
            $fn = 'NRGDASHHEAT_' . $applyMethod;
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

    /** Konsolen-Gegenstueck zur WebFront-Dismiss-Tour. */
    public function ResetTour(): string
    {
        $this->WriteAttributeBoolean('TourSeen', false);
        // Store-Checkliste Punkt 13 (13.09.2026): der Button gab bisher keine
        // sichtbare Rueckmeldung in der Konsole - die Wirkung zeigte sich nur
        // beim naechsten Oeffnen der WebFront-Kachel.
        return NRGDASH_Text::t('✅ The tour will be shown again the next time the tile is opened.');
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

    private function readBoolProperty(string $name, bool $default): bool
    {
        $v = @$this->ReadPropertyBoolean($name);
        return is_bool($v) ? $v : $default;
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
     * Baut/aktualisiert das Simulation-Profil abhaengig von der aktuellen
     * Bauart (Dietmar, 17.08.2026: "Stellt man bei einem Monoblockgeraet
     * die Warmwasserbereitung ein, die es ja gar nicht gibt, dann wird
     * der Teil zwischen Ausseneinheit und gedachtem Puffer der Vor- und
     * Rueckflussleitungen nicht animiert. ... ich wuerde eher tendieren,
     * den Schalter Warmwasserbetrieb in der Simulation zu entfernen, wenn
     * man auf Monoblock eingestellt hat"). Das Profil ist INSTANZ-
     * spezifisch (Name traegt die InstanceID), damit unterschiedliche
     * Bauarten in verschiedenen Instanzen sich nicht gegenseitig die
     * Dropdown-Optionen ueberschreiben. Eine einzelne Assoziation laesst
     * sich in IPS nicht entfernen, nur das ganze Profil - deshalb wird es
     * bei jedem Aufruf komplett neu aufgebaut.
     */
    /**
     * Blendet die WW-Tank-Einstellungen (Vorhanden-Schalter + Literzahl)
     * im Objektbaum aus, solange Bauart=Monoblock ist - dort gibt es nie
     * einen WW-Tank, siehe hasDhwTank in buildBasePayload() (Dietmar,
     * 17.08.2026: "wenn man Monoblock ankreuzt, dann kann auch der
     * WW-Tank in den Einstellmoeglichkeiten verschwinden"). Seit 24.09.2026
     * zusaetzlich bei StorageType=Kombispeicher ausgeblendet - der eine
     * Kombispeicher-Tank ERSETZT den getrennten WW-Tank, der Schalter waere
     * dann wirkungslos (siehe hasDhwTank in buildBasePayload()). Seit
     * 29.09.2026 ebenso bei StorageType=Frischwasserstation - die bereitet
     * Warmwasser im Durchlauf statt in einem eigenen Speicher, derselbe
     * Grund wie beim Kombispeicher.
     */
    private function ApplyDhwVisibility(): void
    {
        $monoblock = ((int) $this->GetValue('Bauart')) === 1;
        $storageType = (int) $this->GetValue('StorageType');
        $hide = $monoblock || $storageType === 1 || $storageType === 2;
        foreach (['HasDhwTank', 'DhwLiters'] as $ident) {
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($id !== false) {
                IPS_SetHidden($id, $hide);
            }
        }
    }

    private function ApplySimulationProfile(): void
    {
        $profile = 'NRGDASHHEAT.SimulationMode.' . $this->InstanceID;
        $bauart = (int) $this->GetValue('Bauart');
        $monoblock = $bauart === 1;
        // Erdsonde/Brunnen (Bauart 2/3) frieren an der Waermequelle nicht
        // wie eine Aussenluft-Einheit ein - "Abtaubetrieb" ist dort kein
        // realistischer Betriebszustand und faellt aus der Simulation weg
        // (analog zur bestehenden Monoblock-Ausnahme fuer Warmwasser).
        $groundOrWater = in_array($bauart, [2, 3, 4], true);
        if (IPS_VariableProfileExists($profile)) {
            IPS_DeleteVariableProfile($profile);
        }
        IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileAssociation($profile, 0, NRGDASH_Text::t('Off (real data)'), '', -1);
        IPS_SetVariableProfileAssociation($profile, 1, NRGDASH_Text::t('Heating mode'), '', -1);
        IPS_SetVariableProfileAssociation($profile, 2, NRGDASH_Text::t('Cooling mode'), '', -1);
        if (!$monoblock) {
            IPS_SetVariableProfileAssociation($profile, 3, NRGDASH_Text::t('Hot water mode'), '', -1);
        }
        IPS_SetVariableProfileAssociation($profile, 4, NRGDASH_Text::t('Standby'), '', -1);
        if (!$groundOrWater) {
            IPS_SetVariableProfileAssociation($profile, 5, NRGDASH_Text::t('Defrost mode'), '', -1);
        }
        $simIsNew = @IPS_GetObjectIDByIdent('SimulationMode', $this->InstanceID) === false;
        $this->RegisterVariableInteger('SimulationMode', NRGDASH_Text::t('Simulation'), $profile, 70);
        $this->EnableAction('SimulationMode');
        if ($simIsNew) {
            $this->SetValue('SimulationMode', 0);
        }
        // Stand bisher auf Warmwasserbetrieb und die Anlage ist jetzt
        // Monoblock: der Wert existiert im Profil nicht mehr - auf "Aus"
        // zurueckfallen statt eine ungueltige Auswahl stehen zu lassen.
        if ($monoblock && (int) $this->GetValue('SimulationMode') === 3) {
            $this->SetValue('SimulationMode', 0);
        }
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
    }

    /**
     * Emitter-Schalter (Dietmar, 16.08.2026: "ich haette sie gerne wie
     * bei den Eurotronic Comet WiFi auf der vergroesserten Kachelseite").
     * Ein erster Versuch zeichnete eigene Schalter INS Kachel-HTML und
     * blendete sie per ResizeObserver ein - Fehlschlag, siehe
     * Cross-Session-Befund aus der CometWiFi-Sitzung (16.08.2026): das
     * Aufziehen einer Kachel zeigt NIE das eigene HTML, sondern immer
     * die Standardansicht der Instanz-Kinder. Die vier Boolean-
     * Variablen (siehe Create()) erscheinen dort deshalb als normale
     * Schalter; WebFront ruft beim Bedienen automatisch RequestAction()
     * mit dem Variablen-Ident auf.
     */
    public function RequestAction($Ident, $Value)
    {
        // Einfuehrungs-Tour bestaetigt: die Kachel meldet das ueber requestAction()
        // zurueck, nicht ueber den offenen WebHook (der kann abgeschaltet sein).
        if ($Ident === 'dismissTour') {
            $this->WriteAttributeBoolean('TourSeen', true);
            return;
        }
        if (in_array($Ident, ['Zone1ShowRadiator', 'Zone1ShowFloor', 'Zone2ShowRadiator', 'Zone2ShowFloor'], true)) {
            $this->SetValue($Ident, (bool) $Value);
            $this->Render();
            return;
        }
        if (in_array($Ident, ['FlowStyle', 'FlowMotion', 'FlowSpeed', 'Bauart', 'StorageType', 'BufferLiters', 'DhwLiters', 'SimulationMode'], true)) {
            $this->SetValue($Ident, (int) $Value);
            if ($Ident === 'Bauart') {
                // Simulation-Optionen und WW-Tank-Sichtbarkeit haengen
                // von der Bauart ab - bei einem Wechsel sofort neu
                // aufbauen, statt erst beim naechsten Kernelstart.
                $this->ApplySimulationProfile();
                $this->ApplyDhwVisibility();
            }
            if ($Ident === 'StorageType') {
                // Kombispeicher ersetzt den getrennten WW-Tank - dessen
                // Einstellungen dann ebenfalls ausblenden (analog Monoblock).
                $this->ApplyDhwVisibility();
            }
            $this->Render();
            return;
        }
        if (in_array($Ident, ['HasBuffer', 'HasDhwTank'], true)) {
            $this->SetValue($Ident, (bool) $Value);
            $this->Render();
        }
    }

    private function updateInstanceStatus(array $payload): void
    {
        $this->SetStatus(($payload['ok'] ?? false) ? 102 : 104);
    }

    /**
     * Alle Waermepumpen-Instanzen ueber den gemeinsamen 'heatpump'-Vertrag
     * (additiv um die Anlagenschema-Felder erweitert) einsammeln - JEDES
     * liefernde Modul wird generisch behandelt, kein Modulname fest
     * verdrahtet ausser fuer die Discovery selbst (IPS_GetInstanceListBy-
     * ModuleID). Reale elektrische Leistung kommt bevorzugt aus dem Vertrag
     * selbst (Measured=true); ist dort keine echte Messung hinterlegt, wird
     * zusaetzlich ueber MeterHub (function==='heatpump', bevorzugt
     * authority='billing') gemerged - Muster: NRGDashboardPVMonitor::
     * GridPowerID(), MeterHubs eigener Vorschlag vom 13.08.2026 (Merge beim
     * Konsumenten statt Kopplung zwischen den Erzeuger-Modulen).
     */
    private function DiscoverHeatpumps(): array
    {
        $entries = [];
        foreach (self::HEATPUMP_SOURCES as $guid => $fn) {
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
                // Manche Vertraege liefern EIN Objekt, manche eine Liste
                // (HeishaMon: Liste mit einem Eintrag je Instanz) - beide
                // Formen einheitlich als Liste behandeln.
                $list = (isset($data['Type']) || isset($data['type']) || isset($data['function'])) ? [$data] : $data;
                foreach ($list as $e) {
                    if (!is_array($e) || (($e['Type'] ?? $e['type'] ?? $e['function'] ?? '') !== 'heatpump')) {
                        continue;
                    }
                    $e['_instanceID'] = (int) $id;
                    $entries[] = $e;
                }
            }
        }
        $manual = $this->buildManualHeatpumpEntry();
        if ($manual !== null) {
            $entries[] = $manual;
        }
        return $entries;
    }

    /**
     * Manuell verknuepfter 'heatpump'-Eintrag (Dietmar, 17.08.2026) - fuer
     * Anlagen ohne HeishaMon/WPHub. ADDITIV zur Auto-Erkennung (nicht
     * exklusiv): wer keines der beiden Module hat, bekommt genau diesen
     * einen Eintrag; wer zusaetzlich manuell eine zweite Waermepumpe
     * einbinden will, kann das ebenso tun.
     */
    private function buildManualHeatpumpEntry(): ?array
    {
        if (!(bool) $this->ReadPropertyBoolean('ManualEnabled')) {
            return null;
        }
        $e = [
            '_instanceID' => $this->InstanceID,
            // manuell verknuepfte Felder sind eine bewusste Entscheidung
            // des Nutzers (er waehlt die Variable explizit im Formular) -
            // im Gegensatz zu automatisch von HeishaMon/WPHub gemeldeten
            // *ID-Feldern braucht das KEINE "war der Wert je > 0"-
            // Bestaetigung (siehe resolveFan2Speed()), das waere hier nur
            // unnoetige Verzoegerung fuer eine ohnehin willentliche Angabe.
            '_manual'     => true,
            'Caption'     => (string) $this->ReadPropertyString('ManualCaption') ?: NRGDASH_Text::t('Heat pump (manual)'),
            'Measured'    => (int) $this->ReadPropertyInteger('ManualPowerID') > 0,
        ];
        foreach (self::MANUAL_FIELDS as $contractKey => $propertyName) {
            $e[$contractKey] = (int) $this->ReadPropertyInteger($propertyName);
        }
        return $e;
    }

    private function ResolveMeterHubPower(): array
    {
        // [instanceID => ['powerID'=>int,'authority'=>string,'latency'=>string]]
        $out = [];
        if (!function_exists('MHUB_GetFunctions')) {
            return $out;
        }
        foreach (@IPS_GetInstanceListByModuleID(self::METERHUB_GUID) as $id) {
            $f = @MHUB_GetFunctions((int) $id);
            if (is_string($f)) {
                $f = json_decode($f, true);
            }
            if (!is_array($f) || !isset($f['assignments']) || !is_array($f['assignments'])) {
                continue;
            }
            foreach ($f['assignments'] as $a) {
                if (($a['function'] ?? '') !== 'heatpump') {
                    continue;
                }
                $out[] = [
                    'powerID'   => (int) ($a['powerID'] ?? 0),
                    'authority' => (string) ($a['authority'] ?? 'auxiliary'),
                ];
            }
        }
        return $out;
    }

    private function num(int $vid): ?float
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return null;
        }
        $v = GetValue($vid);
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * Kanonisiert einen automatisch gemeldeten fan2Speed-Rohwert auf einen
     * verlaesslichen "hat wirklich 2 Luefter"-Status (HeishaMon-Fund,
     * 25.08.2026 - siehe Fan2Confirmed-Attribut in Create()). $manual
     * ueberspringt die Bestaetigung (siehe buildManualHeatpumpEntry()).
     * Rueckgabe null = Ein-Luefter-Darstellung, sonst der (ggf. aktuell 0
     * betragende) Rohwert.
     */
    private function resolveFan2Speed(int $sourceInstanceId, ?float $raw, bool $manual): ?float
    {
        if ($manual) {
            return $raw;
        }
        if ($raw === null) {
            return null;
        }
        $map = json_decode((string) $this->ReadAttributeString('Fan2Confirmed'), true);
        if (!is_array($map)) {
            $map = [];
        }
        $key = (string) $sourceInstanceId;
        if ($raw > 0.0) {
            if (($map[$key] ?? false) !== true) {
                $map[$key] = true;
                $this->WriteAttributeString('Fan2Confirmed', json_encode($map));
            }
            return $raw;
        }
        return ($map[$key] ?? false) === true ? $raw : null;
    }

    /**
     * Wie num(), aber fuer Temperaturfelder: HeishaMon meldet einen fehlenden
     * Sensor (z.B. Zone 2 nicht verbaut) nicht als 0/null, sondern als
     * physikalisch unmoegliche Sentinel-Temperatur wie -78 °C - real bei
     * Dietmar gefunden, 13.08.2026 (Zone 2 hat eine gueltige Variablen-ID,
     * der Wert war aber -78). Alles unter -50°C/ueber 120°C gilt als
     * "kein echter Messwert" statt es als Zahl anzuzeigen.
     */
    private function numTemp(int $vid): ?float
    {
        $v = $this->num($vid);
        if ($v === null || $v < -50.0 || $v > 120.0) {
            return null;
        }
        return $v;
    }

    private function boolVal(int $vid): ?bool
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return null;
        }
        $v = GetValue($vid);
        return is_bool($v) ? $v : (is_numeric($v) ? ((float) $v != 0.0) : null);
    }

    /**
     * Kombiniert internen/externen Heizstab-Status zu EINEM Wert fuers
     * Schema-Symbol (siehe DiscoverHeatpumps()) - null nur, wenn WEDER
     * intern noch extern verfuegbar ist (Symbol bleibt dann ganz aus,
     * statt faelschlich "aus" zu zeigen); sonst true, sobald mindestens
     * einer der verfuegbaren aktiv ist.
     */
    private function heaterActive(int $internalVid, int $externalVid): ?bool
    {
        $internal = $this->boolVal($internalVid);
        $external = $this->boolVal($externalVid);
        if ($internal === null && $external === null) {
            return null;
        }
        return ($internal === true) || ($external === true);
    }

    // lastSeenAt aelter als das = keine aktuelle Messung (13.09.2026),
    // gleiche Schwelle wie NRGDashboardWPMonitor.
    private const STALE_AFTER_SEC = 900;

    private function staleText(array $unit): string
    {
        $lastSeen = (int) ($unit['lastSeenAt'] ?? 0);
        // Liefert die Quelle ihr Abfrageintervall (pollInterval, z. B. WPHub
        // ab Vertrag 1.14), gilt das Dreifache davon - nie weniger als 900 s.
        $limit = max(self::STALE_AFTER_SEC, 3 * (int) ($unit['pollInterval'] ?? 0));
        if ($lastSeen <= 0 || time() - $lastSeen <= $limit) {
            return '';
        }
        // Datumsformat gehoert zur Sprache: 'Y-m-d H:i' (englisch) bzw. 'd.m.Y H:i' (deutsch)
        return NRGDASH_Text::t('No current measurement since %s - the displayed values are stale.', date(NRGDASH_Text::t('Y-m-d H:i'), $lastSeen));
    }

    private function buildPayload(): array
    {
        // Simulation (Dietmar, 17.08.2026: "die Buttons fuer die
        // Simulation genauso [hinter den Doppelpfeil]") - ersetzt die
        // echte Geraete-Erkennung komplett durch feste Beispielwerte,
        // solange SimulationMode != 0 ist. Config-Felder (Zonen-
        // Beschriftung, Emitter-Schalter, Wasserfluss, Bauart/Puffer/
        // WW-Tank) bleiben dabei die ECHTEN eingestellten Werte - nur
        // die Sensordaten der Waermepumpe selbst werden simuliert.
        $simMode = (int) $this->GetValue('SimulationMode');
        if ($simMode !== 0) {
            $bauart = (int) $this->GetValue('Bauart');
            return $this->buildBasePayload([$this->buildSimulatedUnit($simMode, $bauart)]);
        }

        $heatpumps = $this->DiscoverHeatpumps();
        if (count($heatpumps) === 0) {
            return [
                'ok'       => false,
                'error'    => NRGDASH_Text::t('No heat pump found - install and configure HeishaMon or WPHub, or link your own variables in the form under “Manual data connection”.'),
                'hookPath' => '/hook/nrgdashheatschema' . $this->InstanceID,
                'showTour' => !(bool) $this->ReadAttributeBoolean('TourSeen'),
            ];
        }
        $meterhubPower = $this->ResolveMeterHubPower();

        $units = [];
        foreach ($heatpumps as $e) {
            $powerID = (int) ($e['PowerID'] ?? $e['powerID'] ?? 0);
            $measured = (bool) ($e['Measured'] ?? $e['measured'] ?? false);
            if (!$measured || $powerID <= 0) {
                // Eigene Messung fehlt/unecht - MeterHub-Fallback (bevorzugt
                // authority=billing, sonst der erste gefundene Eintrag).
                $billing = null;
                $any = null;
                foreach ($meterhubPower as $mp) {
                    if ($mp['powerID'] <= 0) {
                        continue;
                    }
                    $any = $any ?? $mp;
                    if ($mp['authority'] === 'billing') {
                        $billing = $mp;
                        break;
                    }
                }
                $pick = $billing ?? $any;
                if ($pick !== null) {
                    $powerID = $pick['powerID'];
                    $measured = true;
                }
            }

            // Heizkreis-Vorlauf (z1/z2WaterTempID) genuegt ebenfalls: Vaillant-
            // Anlagen ohne system_flow_temperature (Forum froema, VRC720,
            // 04.10.2026) liefern nur den Kreis-Vorlauf, mainOutletTempID
            // bleibt 0 - sie bekamen sonst dauerhaft nur die Basiskarte.
            $hasPipeSchema = (int) ($e['pumpFlowID'] ?? 0) > 0
                || (int) ($e['pumpSpeedID'] ?? 0) > 0
                || (int) ($e['mainInletTempID'] ?? 0) > 0
                || (int) ($e['mainOutletTempID'] ?? 0) > 0
                || (int) ($e['z1WaterTempID'] ?? 0) > 0
                || (int) ($e['z2WaterTempID'] ?? 0) > 0;

            $units[] = [
                'id'              => (int) $e['_instanceID'],
                'label'           => (string) ($e['Caption'] ?? $e['caption'] ?? $e['label'] ?? IPS_GetName((int) $e['_instanceID'])),
                // Veraltete Messung (lastSeenAt, heatpump-Vertrag 1.13) - leer = aktuell
                'staleText'       => $this->staleText($e),
                'hasPipeSchema'   => $hasPipeSchema,
                'power'           => $this->num($powerID),
                'pumpFlow'        => $this->num((int) ($e['pumpFlowID'] ?? 0)),
                'pumpSpeed'       => $this->num((int) ($e['pumpSpeedID'] ?? 0)),
                'pumpDuty'        => $this->num((int) ($e['pumpDutyID'] ?? 0)),
                'threeWayValve'   => $this->num((int) ($e['threeWayValveStateID'] ?? 0)),
                'twoWayValve'     => $this->boolVal((int) ($e['twoWayValveStateID'] ?? 0)),
                'mainInletTemp'   => $this->numTemp((int) ($e['mainInletTempID'] ?? 0)),
                'mainOutletTemp'  => $this->numTemp((int) ($e['mainOutletTempID'] ?? 0)),
                'sourceInTemp'    => $this->numTemp((int) ($e['sourceInTempID'] ?? 0)),
                'sourceOutTemp'   => $this->numTemp((int) ($e['sourceOutTempID'] ?? 0)),
                'z1WaterTemp'     => $this->numTemp((int) ($e['z1WaterTempID'] ?? 0)),
                'z2WaterTemp'     => $this->numTemp((int) ($e['z2WaterTempID'] ?? 0)),
                'dhwTemp'         => $this->numTemp((int) ($e['dhwTempID'] ?? 0)),
                'bufferTemp'      => $this->numTemp((int) ($e['bufferTempID'] ?? 0)),
                'compressorFreq'  => $this->num((int) ($e['compressorFreqID'] ?? 0)),
                'dischargeTemp'   => $this->numTemp((int) ($e['dischargeTempID'] ?? 0)),
                'defrosting'      => $this->boolVal((int) ($e['defrostingStateID'] ?? 0)),
                // contractVersion 1.4 (HeishaMon, 13.08.2026) - externe
                // Pumpe/Mischventil an der 2. Steuerplatine, additiv. Fehlt
                // bei aelteren Installationen (contractVersion 1.3) einfach
                // (Schluessel nicht gesetzt), Felder bleiben dann null.
                // contractVersion 1.5 (HeishaMon, 14.08.2026): Luefter-
                // drehzahl des Aussengeraets, additiv - fehlt bei aelteren
                // Staenden einfach (?? 0 -> null).
                'fanSpeed'        => $this->num((int) ($e['fan1SpeedID'] ?? 0)),
                // Zweiter Luefter (Dietmar, 24.08.2026: "Es gibt auch
                // Aussengeraete und Monoblockgeraete mit 2 Ventilatoren",
                // 25.08.2026 widerlegt an seiner eigenen Ein-Luefter-Anlage:
                // "meine Panasonic hat nur ein Lüfter") - HeishaMon-Fund,
                // 25.08.2026: fan2SpeedID liegt bei JEDER Installation an
                // fester Byte-Position im CN-CNT-Protokoll und wird IMMER
                // gemeldet, unabhaengig von der echten Geraete-Hardware.
                // Reine ID-Existenz beweist also NICHTS - resolveFan2Speed()
                // verlangt einen tatsaechlich beobachteten Wert > 0
                // (dauerhaft gemerkt, siehe Fan2Confirmed-Attribut).
                'fan2Speed'       => $this->resolveFan2Speed(
                    (int) $e['_instanceID'],
                    $this->num((int) ($e['fan2SpeedID'] ?? 0)),
                    (bool) ($e['_manual'] ?? false)
                ),
                // Sauggas-/Kaltgastemperatur (Gegenstueck zum Heissgas):
                // additives Feld, bei HeishaMon angefragt (14.08.2026) -
                // bis der Vertrag es liefert, bleibt das Feld null.
                'suctionTemp'     => $this->numTemp((int) ($e['suctionTempID'] ?? 0)),
                // contractVersion 1.9 (HeishaMon, 17.08.2026, "COP-
                // Vertragsrunde Stufe 1"): Arbeitszahl-Felder. copMeasured
                // ist die echte Messung (0, wenn kein externer Zaehler
                // konfiguriert ist), copEstimate die WP-eigene Schaetzung
                // in ~200-W-Stufen - bewusst OHNE Nachkommastelle
                // dargestellt (siehe module.html), sonst waere das eine
                // Scheingenauigkeit, die die Quelle gar nicht hergibt.
                // Monats-/Jahres-Arbeitszahl kommt NICHT ueber den
                // Vertrag (EMS-Entscheid: Zeitraum-Aggregation ist
                // Konsumentensache, kein Duplikat je Modul).
                'copEstimate'     => $this->num((int) ($e['copEstimateID'] ?? 0)),
                'copMeasured'     => $this->num((int) ($e['copMeasuredID'] ?? 0)),
                'dailyPerformanceFactor' => $this->num((int) ($e['dailyPerformanceFactorID'] ?? 0)),
                'extPump'         => $this->boolVal((int) ($e['z1PumpID'] ?? 0)),
                // 0=Aus, 1=Zu (schliesst gerade), 2=Auf (oeffnet gerade) -
                // Stellrichtung, KEINE absolute Position (HeishaMon-Hinweis).
                'extMixingValve'  => $this->num((int) ($e['z1MixingValveID'] ?? 0)),
                // contractVersion 1.7 (HeishaMon, 14.08.2026, angefragt nach
                // Fund im Variablenbaum): echte Mischerstellung 0-100 % statt
                // blosser Stellrichtung, und die Rohrtemperatur der Innen-
                // einheit - im KUEHLBETRIEB die tatsaechlich kalte Kaelte-
                // mittelseite (Eva_Outlet liegt dann am Verfluessiger).
                // Fehlen die Felder, bleiben sie null und werden nicht gezeigt.
                // KONFIGURIERTE Betriebsart (Enum 0-8), NICHT der aktuelle
                // Zustand - ob gerade gelaufen wird, sagt compressorFreq,
                // ob gerade WW bereitet wird, threeWayValveStateID
                // (Semantikhinweis HeishaMon, contractVersion 1.7).
                // Verbund-Enum (contractVersion 1.8, in EMS/SUITE.md
                // festgeschrieben): 0=standby, 1=heating, 2=cooling,
                // 3=dhw, 4=heating+dhw, 5=cooling+dhw, -1=unbekannt.
                // Herstellerneutral - jedes heatpump-Modul mappt seinen
                // eigenen Enum darauf.
                'operatingModeNorm' => $this->num((int) ($e['operatingModeNormID'] ?? 0)),
                // Roher Herstellerwert, nur fuer die Diagnose
                'operatingMode'   => $this->num((int) ($e['operatingModeID'] ?? 0)),
                'mixValvePos'     => $this->num((int) ($e['z1MixingValvePositionID'] ?? 0)),
                // Zone 2 - gleiche Felder wie Zone 1. Anlagen ohne zweiten
                // Heizkreis liefern hier nichts (oder die bekannte
                // Sentinel-Temperatur), dann bleibt Zone 2 im Schema aus.
                'z2Pump'          => $this->boolVal((int) ($e['z2PumpID'] ?? 0)),
                'z2MixingValve'   => $this->num((int) ($e['z2MixingValveID'] ?? 0)),
                'z2MixValvePos'   => $this->num((int) ($e['z2MixingValvePositionID'] ?? 0)),
                'indoorPipeTemp'  => $this->numTemp((int) ($e['indoorPipeTempID'] ?? 0)),
                // Heizstab (Dietmar, 24.08.2026: "was wir auch vergessen
                // haben, den Heizstab") - HeishaMon contractVersion 1.12,
                // 24.08.2026: intern (im Innengeraet verbaut) und extern
                // (separater Zusatz-/Boosterheizer) getrennt gemeldet,
                // NICHT WW/Raum wie urspruenglich vermutet - hydraulisch
                // ist die Einbaulage (intern/extern) die relevante
                // Unterscheidung, nicht der Verwendungszweck. EIN
                // Heizstab-Symbol statt zwei getrennter (Vorschlag
                // HeishaMon, deren Erfahrung zur hydraulischen Einbaulage):
                // aktiv, wenn irgendein verfuegbarer Heizstab laeuft; null
                // (Symbol bleibt aus), wenn KEINER der beiden Datenpunkte
                // vorhanden ist, statt faelschlich "aus" zu zeigen.
                'heaterActive'    => $this->heaterActive(
                    (int) ($e['internalHeaterStateID'] ?? 0),
                    (int) ($e['externalHeaterStateID'] ?? 0)
                ),
            ];
        }

        return $this->buildBasePayload($units);
    }

    /**
     * Gemeinsamer Nutzlast-Rahmen (Config-Felder + uebergebene $units) fuer
     * den echten Geraete-Pfad UND den Simulationspfad in buildPayload() -
     * beide unterscheiden sich nur darin, WOHER $units kommt.
     */
    private function buildBasePayload(array $units): array
    {
        return [
            'ok'          => true,
            'uid'         => (string) $this->InstanceID,
            'bg'          => $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND)),
            'font'        => $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT)),
            'lightTheme'  => $this->readBoolProperty('LightTheme', false),
            'zone1Caption' => $this->readStringProperty('Zone1Caption', ''),
            'zone2Caption' => $this->readStringProperty('Zone2Caption', ''),
            'zone1ShowRadiator' => (bool) $this->GetValue('Zone1ShowRadiator'),
            'zone1ShowFloor'    => (bool) $this->GetValue('Zone1ShowFloor'),
            'zone2ShowRadiator' => (bool) $this->GetValue('Zone2ShowRadiator'),
            'zone2ShowFloor'    => (bool) $this->GetValue('Zone2ShowFloor'),
            'flowStyle'   => (int) $this->GetValue('FlowStyle'),
            'flowMotion'  => (int) $this->GetValue('FlowMotion'),
            'flowSpeed'   => (int) $this->GetValue('FlowSpeed'),
            'bauart'      => [0 => 'split', 1 => 'monoblock', 2 => 'sole', 3 => 'wasser', 4 => 'kollektor'][(int) $this->GetValue('Bauart')] ?? 'split',
            // Kombispeicher (24.09.2026) ERSETZT den getrennten Puffer/WW-
            // Tank durch einen Tank - hasDhwTank wird deshalb bei
            // storageType=1 zwingend false erzwungen (unabhaengig vom
            // HasDhwTank-Schalter, der dann ohnehin ausgeblendet ist, siehe
            // ApplyDhwVisibility()), damit module.html nie beide Tanks
            // gleichzeitig zeichnet. hasBuffer bleibt unangetastet: der
            // Kombispeicher-Tank wird im Frontend an SEINER Stelle
            // gezeichnet (siehe hasCombiTank), unabhaengig vom klassischen
            // Puffer-Zweig.
            'storageType' => (int) $this->GetValue('StorageType'),
            'hasCombiTank' => ((int) $this->GetValue('StorageType')) === 1,
            // Frischwasserstation (29.09.2026) - normaler Puffer bleibt
            // unangetastet (siehe hasBuffer unten), zusaetzlich zeichnet das
            // Frontend nur den dritten Abgang/das Modul am oberen
            // Pufferanschluss (siehe hasFreshWaterStation dort).
            'hasFreshWaterStation' => ((int) $this->GetValue('StorageType')) === 2,
            // Kombispeicher-Temperaturen (24.09.2026) - EIN Tank fuer die
            // ganze Anlage statt je Waermepumpen-Einheit, deshalb hier auf
            // Payload-Ebene statt in der Pro-Einheit-Schleife (dort stehen
            // dhwTemp/bufferTemp je WP-Einheit, hier ist es konzeptionell
            // ein einzelner Tank).
            'comboTopTemp'    => $this->numTemp($this->readIntProperty('ComboTopTempID', 0)),
            'comboBottomTemp' => $this->numTemp($this->readIntProperty('ComboBottomTempID', 0)),
            'comboMidTemp'    => $this->numTemp($this->readIntProperty('ComboMidTempID', 0)),
            'comboReturnTemp' => $this->numTemp($this->readIntProperty('ComboReturnTempID', 0)),
            'hasBuffer'   => (bool) $this->GetValue('HasBuffer'),
            'bufferLiters' => (int) $this->GetValue('BufferLiters'),
            'hasDhwTank'  => in_array((int) $this->GetValue('StorageType'), [1, 2], true) ? false : (bool) $this->GetValue('HasDhwTank'),
            'dhwLiters'   => (int) $this->GetValue('DhwLiters'),
            'renderedAt'  => time(),
            'units'       => $units,
            'hookPath'    => '/hook/nrgdashheatschema' . $this->InstanceID,
            'showTour'    => !(bool) $this->ReadAttributeBoolean('TourSeen'),
        ];
    }

    /**
     * Baut eine synthetische Einheit fuer die Simulation (Dietmar,
     * 17.08.2026) - dieselben Betriebsmodus-Szenarien wie im lokalen
     * Pruefstand .tools/test-scenarios.html, hier aber als PHP-Gegenstueck
     * fuer die echte Kachel.
     */
    private function buildSimulatedUnit(int $mode, int $bauart = 0): array
    {
        $u = [
            'id' => $this->InstanceID, 'label' => NRGDASH_Text::t('Heat pump (simulation)'),
            'hasPipeSchema' => true, 'power' => 1500,
            'pumpFlow' => 15.0, 'pumpSpeed' => 1450.0, 'pumpDuty' => null,
            'threeWayValve' => 0, 'twoWayValve' => true,
            'mainInletTemp' => 36.0, 'mainOutletTemp' => 42.0,
            // Waermequelle Sole/Wasser (Tiefenbohrung/Erdkollektor) bzw.
            // Wasser/Wasser (Dietmar, 10.09.2026) - realistische
            // Beispielwerte fuer die Demo/Simulation: Tiefenbohrung ca.
            // 0..-3 °C Spreizung im Heizbetrieb, ein flacher Erdkollektor
            // reagiert staerker auf die Jahreszeit und liegt etwas
            // kaelter, Brunnenwasser ganzjaehrig ca. 8..12 °C mit ca. 4 K
            // Auskuehlung ueber den Waermetauscher.
            'sourceInTemp'  => $bauart === 3 ? 10.0 : ($bauart === 2 ? 2.0 : ($bauart === 4 ? 0.0 : null)),
            'sourceOutTemp' => $bauart === 3 ? 6.0 : ($bauart === 2 ? -1.5 : ($bauart === 4 ? -3.0 : null)),
            'z1WaterTemp' => 38.5, 'z2WaterTemp' => 33.0,
            'dhwTemp' => 44.0, 'bufferTemp' => 40.0,
            'compressorFreq' => 34.0, 'dischargeTemp' => 82.0,
            'defrosting' => false, 'fanSpeed' => 400.0,
            // Zweiter Luefter + Heizstab (Dietmar, 25.08.2026: "Kannst Du
            // das den Heizstab in der Simmulation auch sichtbar machen,
            // damit ich wenigstens darüber urteilen kann ob das so
            // richtig ist?") - die Simulation hatte beide neuen Felder
            // bisher schlicht nicht gesetzt (blieben undefined, keins der
            // beiden Elemente erschien je in der Simulationsansicht).
            // fan2Speed hier immer gesetzt (reales Doppelluefter-Szenario),
            // heaterActive per Default an - realistischer waere er meist
            // aus, aber Dietmar soll das Badge SEHEN koennen, ohne extra
            // danach suchen zu muessen.
            // heaterActive standardmaessig AUS (Dietmar, 03.09.2026: "den
            // Heizstab wuerde ich auf gar keinen Fall im Kuehlbetrieb
            // eingeblendet lassen, generell fuer die Demo ausgeblendet") -
            // sichtbar nur noch im Abtaubetrieb (Fall 5), wo ein Zuheizer
            // realistisch ist. So bleibt das Badge demonstrierbar, ohne in
            // Heiz-/Kuehl-/WW-Betrieb ein falsches Bild zu zeichnen.
            'fan2Speed' => 380.0, 'heaterActive' => false,
            'suctionTemp' => 17.0,
            'copEstimate' => 4.2, 'copMeasured' => 4.0, 'dailyPerformanceFactor' => 3.8,
            'extPump' => true, 'extMixingValve' => 0,
            'operatingModeNorm' => 1, 'operatingMode' => null,
            // Zweiten Heizkreis standardmaessig MIT simulieren (Dietmar,
            // 17.08.2026: "ich habe immer noch kein 2. HK!?" - die
            // Simulation hatte bisher IMMER nur einen Heizkreis gezeigt,
            // unabhaengig von der Betriebsart, weil alle z2-Felder fest
            // auf null standen). hasZone2 im Kachel-HTML erkennt eine
            // zweite Zone schon, sobald IRGENDEINES der z2-Felder gesetzt
            // ist, nicht nur bei echten Werten.
            'mixValvePos' => 40.0, 'z2Pump' => true, 'z2MixingValve' => 0,
            'z2MixValvePos' => 20.0, 'indoorPipeTemp' => null,
        ];
        switch ($mode) {
            case 2: // Kuehlbetrieb: Vorlauf kaelter als Ruecklauf (Panasonic dreht den Kreis um)
                $u['mainOutletTemp'] = 15.5;
                $u['mainInletTemp']  = 16.0;
                $u['operatingModeNorm'] = 2;
                $u['compressorFreq'] = 28.0;
                break;
            case 3: // Warmwasserbetrieb
                $u['threeWayValve'] = 1;
                $u['operatingModeNorm'] = 3;
                // Dreiwegeventil steht auf WW - der gesamte Volumenstrom
                // geht in den Warmwasser-Tank, KEIN Heizkreis kann dann
                // aktiv sein (Dietmar, 03.09.2026: "Wenn die Warmwasser-
                // bereitung laeuft, darf aber kein Heizkreis aktiv sein").
                // Bisher blieben extPump (HK1) und z2Pump (HK2) auf ihrem
                // Basiswert true und animierten beide Kreise weiter -
                // dieselbe Falle wie frueher im Standby (siehe Fall 4).
                $u['extPump'] = false;
                $u['z2Pump'] = false;
                // Speicher wird gerade geladen: Vorlauf hoeher als im
                // Heizbetrieb (WW-Ziel ~50 °C statt Heizkreis ~35 °C).
                $u['mainOutletTemp'] = 52.0;
                $u['mainInletTemp']  = 45.0;
                break;
            case 4: // Standby: kein Durchfluss, Verdichter aus
                $u['compressorFreq'] = 0.0;
                $u['pumpFlow'] = 0.0;
                $u['operatingModeNorm'] = 0;
                // extPump (HK1) UND z2Pump (HK2) werden UNABHAENGIG von
                // pumpFlow ausgewertet (module.html: pumpOn = pumpKnown
                // ? !!unit.extPump : ...; zones[1].pumpOn analog ueber
                // z2Pump) - blieben beide auf ihrem Basiswert true,
                // animierten HK1/HK2 trotz pumpFlow=0 weiter, obwohl im
                // Standby nichts fliessen soll (Dietmar, 17.08.2026: "im
                // Standby vermutlich in keinem der beiden HK etwas
                // fliessen sollte").
                $u['extPump'] = false;
                $u['z2Pump'] = false;
                $u['heaterActive'] = false;
                break;
            case 5: // Abtaubetrieb
                $u['defrosting'] = true;
                // Einzige Betriebsart, in der der Heizstab in der
                // Simulation gezeigt wird (Zuheizer waehrend des Abtauens
                // ist realistisch, siehe Basis-Kommentar zu heaterActive).
                $u['heaterActive'] = true;
                break;
            case 1: // Heizbetrieb - Default oben bereits gesetzt
            default:
                break;
        }
        return $u;
    }
}
