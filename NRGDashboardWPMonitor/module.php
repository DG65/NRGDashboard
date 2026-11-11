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
require_once __DIR__ . '/../libs/FormStatus.php';
require_once __DIR__ . '/../libs/NRGDASH_Text.php';

class NRGDashboardWPMonitor extends IPSModule
{
    use NRGDashFormStatus;

    /**
     * Texte der Kachel (module.html): englische Quelltexte, die dort mit tr('...') benutzt werden. PHP liefert die
     * Übersetzungen als Tabelle (window.NRGDASH_TEXTS), siehe NRGDASH_Text::kachelHtml(); der Prüfstand
     * .tools/locale-pruefen.php vergleicht diese Liste mit module.html und locale.json.
     */
    private const TILE_TEXTS = [
        'en-GB',
        'Show introduction tour',
        'Day (history)',
        'Week (energy)',
        'Month (energy)',
        'Year (energy)',
        'Total (energy)',
        'Custom',
        'Back',
        'Forward',
        'Show/hide tab bar',
        'History',
        'Heating curves',
        'Controls',
        'Automation',
        'Schedule',
        'Apply',
        'Emergency mode active - the unit is heating electrically with the heating element.',
        'Next',
        '✓ Understood, do not show again',
        'Current COP',
        'Daily SPF',
        'Op. hours',
        'Starts',
        'Ø runtime',
        'kWh electricity',
        'kWh heat',
        'El. power',
        'Therm. power',
        'Flow',
        'Return',
        'Outdoor temp.',
        'Outdoor temp. Ø1h',
        'Outdoor temp. Ø24h',
        '%1$s/%2$s/%3$s %4$s:%5$s',
        'Jan',
        'Feb',
        'Mar',
        'Apr',
        'May',
        'Jun',
        'Jul',
        'Aug',
        'Sep',
        'Oct',
        'Nov',
        'Dec',
        'January',
        'February',
        'March',
        'April',
        'June',
        'July',
        'August',
        'September',
        'October',
        'November',
        'December',
        'Mon',
        'Tue',
        'Wed',
        'Thu',
        'Fri',
        'Sat',
        'Sun',
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday',
        'El. energy',
        'Therm. energy',
        '%1$s %2$s/%3$s',
        '%1$s, %2$s/%3$s/%4$s',
        '%1$s %2$s %3$s',
        '%1$s/%2$s',
        '%1$s/%2$s/%3$s',
        'Day before yesterday',
        'Yesterday',
        'Today',
        'Week %s, %s',
        'Heating circuit 2',
        'Heating circuit 1',
        'Cooling',
        'Heating',
        'Sending shift…',
        'Quiet mode',
        'Off',
        'Shift',
        'ECO',
        'Normal',
        'Comfort',
        'Shift the curve in parallel',
        'Shift the curve in parallel. Board rules can override the value.',
        'Outdoor temperature',
        'Flow temperature',
        'with shift %s',
        'Heating curve editing is not available.',
        'Loading heating curve…',
        'No heating curve found.',
        'Saving…',
        'Heating on below',
        'Outdoor temperature mean from which the automation switches heating on.',
        'Heating off above',
        'Outdoor temperature mean from which the automation switches heating off (must be above the switch-on threshold, otherwise the module rejects it).',
        'Minimum room value',
        'If a monitored room falls below this, heating stays on or switches on.',
        'never',
        'just now',
        '%s min ago',
        '%s h ago',
        'Sending…',
        'On',
        'Automation not available.',
        'Loading heating automation…',
        'Heating automation',
        'Only switches heating on or off (never the whole heat pump), based on the outdoor temperature mean. Frost protection always takes priority.',
        'Feature in this installation: enabled',
        'Feature in this installation: not enabled (switch it on in the HeishaMon form)',
        'Switch automation on?',
        'Switch automation off?',
        'On the first run the automation can change the operating mode immediately (e.g. from heating to hot water only).',
        'When switched off, heating that the automation had turned off is switched back on.',
        'Yes, switch on',
        'Yes, switch off',
        'Cancel',
        'Your switch for the automation (default: off).',
        'Can only be operated after the feature has been enabled in the HeishaMon form.',
        'Heating mode',
        'Cooling mode',
        'Heating off (automation)',
        'Price block (heating paused)',
        'Fault',
        'Automation not running',
        'Last error: %s',
        'Electricity price (price block)',
        'active',
        'off',
        'yes',
        'no',
        'Now',
        'Threshold (expensive from)',
        'Median',
        'Currently expensive',
        'Blocks today',
        'Blocked since',
        'Battery covers the load',
        'Price source: %s. The price block pauses heating in expensive hours - only as long as the room temperature and (if present) the battery allow it.',
        'earliest %s',
        'any time',
        'Outdoor now',
        'Outdoor mean %s h',
        'Room mean',
        'Coolest room',
        'Dew point',
        'Last switch',
        'Next switch',
        'Last checked',
        'Settings',
        'Allow cooling',
        'Cooling only with dew point protection (humidity sources and safety margin in the HeishaMon form).',
        'Cooling not available: no humidity source was found, without which the dew point protection cannot work (choose the sources in the HeishaMon form).',
        'Price block',
        'Pause heating in expensive hours. Further settings for this (maximum duration, blocks per day, minimum interval, room reserve, battery) are in the HeishaMon form.',
        'Price block not available: no price source was found (choose one in the HeishaMon form).',
        'Discard',
        'own selection',
        'found automatically',
        'none found',
        'Monitored rooms',
        'Room %s',
        'No room temperatures were found - the automation then only uses the outdoor temperature.',
        'The room selection is maintained in the HeishaMon form (empty = automatic).',
        'Humidity (dew point protection)',
        'Relative humidity / dew point',
        'The selection of humidity sources is maintained in the HeishaMon form.',
        'Saved. The automation evaluates on its next run (that is not a switching action yet).',
        'Changed in the meantime - current values loaded, please check again.',
        'Not saved - please check the marked fields.',
        'Not saved - HeishaMon rejected it.',
        'Zone 1 heating',
        'Zone 1 cooling',
        'Zone 2 heating',
        'Zone 2 cooling',
        'Schedule active',
        'Command sent',
        'Changed manually or by the board',
        'Unit does not accept the value',
        'not usable',
        '%1$s: on %2$s at most %3$s segments are allowed - please merge them.',
        'Not sent.',
        'The schedule is being re-applied now…',
        'Schedule not available.',
        'Loading weekly schedule…',
        'Weekly schedule',
        'Switches the heating curve shift (ECO / Normal / Comfort) and quiet mode at fixed times. The schedule runs in the HeishaMon module; the unit itself cannot hold a schedule.',
        'Schedule off',
        'Schedule on',
        'Re-apply schedule now',
        'Re-apply schedule now?',
        'This ends manual changes and faults and resends the planned value to the unit IMMEDIATELY. The schedule itself stays unchanged.',
        'Yes, apply now',
        'Switch schedule on?',
        'When switching on, the planned value for now is sent to the unit IMMEDIATELY. When switching off, the last value set stays in the unit; nothing is reset.',
        'Yes, save and switch on',
        'No schedulable channels found (zone inactive or unit in direct mode).',
        '%1$s since %2$s',
        'Schedule now: %s',
        'Unit reports: %s',
        'next change %s',
        'Rules on the board can override the shift; the schedule then does not apply permanently.',
        'The unit has its own quiet-mode timer active - it may collide with this schedule.',
        'Channel',
        'Brush: ',
        '%s (default)',
        'Drag across the weekly grid with your finger or the mouse: the selected level is painted onto the quarter-hours you sweep over. The default (gray) applies everywhere nothing is painted; use the default brush to erase.',
        'Copy day: ',
        'to weekdays',
        'to weekend',
        'to all days',
        'Clear day',
        'More errors in: %s',
        'The planned value was resent.',
        'Not applied: %s',
        'Error',
        'Saved. The schedule takes effect from the next switching time (immediately when switched on); the unit does not acknowledge anything, the channel status shows it.',
        'The schedule was changed in the meantime - current values loaded, changes discarded. Please enter again.',
        'Not saved - please check the notes.',
        'Quiet',
        'Quieter',
        'Quietest',
        'Compressor output and fan are reduced - quieter, but less heating output.',
        'Powerful mode',
        'Raises the output for a limited time, after which the unit falls back by itself.',
        'Holiday',
        'Planned',
        'Active',
        'The unit heats at a reduced level and restricts hot water. The dashboard cannot set a period - enter it on the unit or in the Comfort Cloud. “Planned” is not “active”.',
        'Emergency mode (heating element)',
        'Like the heating element button on the remote control: the unit heats electrically instead of with the heat pump. The most expensive heat source, only for faults. HeishaMon never switches it off again by itself.',
        'Really switch on emergency mode? The unit will then heat electrically with the heating element (most expensive heat source) and will not switch itself off again.',
        'Hot water target temperature',
        'Above about 52 °C the efficiency (COP) of the heat pump drops noticeably.',
        'Controls not available.',
        'Loading controls…',
        'Caution: above 52 °C the COP drops noticeably.',
        'The unit reports no controls.',
        'Please select a from/to date',
        'No archive data for this day',
        'Confirmed by the heat pump.',
        'Sent - waiting for feedback (%s/10)…',
        'Not confirmed - the unit still reports the old value (HeishaMon in "listen only" mode? Check MQTT).',
        'Sent - waiting for feedback from the heat pump…',
        'Not sent - HeishaMon rejects it (value out of range, MQTT gateway disconnected or HeishaMon too old).',
        'Shift sent - waiting for feedback from the heat pump…',
        'Shift not sent - HeishaMon rejects it (zone/mode not active, direct setting instead of shift, MQTT gateway disconnected or HeishaMon too old).',
        'Shift confirmed by the heat pump.',
        'Shift sent - waiting for feedback (%s/10)…',
        'The heat pump still reports the old shift - not confirmed (HeishaMon in "listen only" mode? Check MQTT).',
        'Sent - waiting for feedback from the heat pump (%s/6)…',
        'The heat pump still reports the old values - transfer not confirmed (check gateway/MQTT).',
        'Not sent - HeishaMon rejects the command (zone/mode not active, MQTT gateway disconnected or HeishaMon too old).',
        'No data.',
        'This curve reveals when your heat pump defrosts',
        'In the daily chart, a blue area automatically marks every defrost phase of the compressor - the short dips in electrical power and flow temperature get an explanation right away instead of looking like a glitch. No run-of-the-mill monitoring shows that.',
        'If a key figure is missing, it is hidden - not faked as zero',
        'Whether COP, outdoor temperature, compressor starts or operating hours are shown depends on what your HeishaMon or WPHub installation actually delivers. If a field is missing, only the affected key figure or curve disappears - an invented zero reading never appears.',
        'Measured COP always takes priority over estimated',
        'If your heat pump delivers a real measured COP, that one is shown. Only if it is missing does the tile fall back to a rough estimate - an embellished figure is never silently passed off as “measured”. With a plain WPHub installation without HeishaMon the COP is missing entirely (no estimate either) - Panasonic’s Comfort Cloud API simply lacks the required flow/water volume information; this is not a fault of this tile.',
        'With WPHub the real daily counter counts, not the extrapolation',
        'Panasonic heat pumps via WPHub deliver no instantaneous power, but an energy counter that jumps back every day at midnight, straight from the Comfort Cloud. If it is available, the tile prefers it - only without it (e.g. with HeishaMon) is the electrical energy extrapolated from the power curve.',
        'Week, month, year, total - all without reloading',
        'A single archive pass when opening covers the daily, weekly, monthly, yearly, total and custom views. When switching or paging between periods, nothing is reloaded from the archive - everything is calculated directly in the browser.',
        'Flow, return and outdoor temperature on their own axis',
        'Electrical and thermal power run in watts on the left axis, flow/return temperature and outdoor temperature dashed or dotted on a separate °C axis on the right - clearly separated, yet comparable in the same chart.',
        'ECharts or Highcharts - you decide',
        'Under “Appearance” the drawing engine can be switched. ECharts needs no license of its own, Highcharts requires your own valid license for use - both are loaded from the internet when needed, not embedded in the module.',
        'Also usable outside the dashboard',
        'This tile also runs as a standalone web page via an automatically registered WebHook (address in the documentation panel) - ideal for IPSView or any browser tab, with automatic refresh every 30 seconds.',
    ];

    private const HEISHA_GUID  = '{1919151A-3C0F-4C09-B906-291638EC1469}';
    private const WPHUB_GUID   = '{5BE429EA-3AAD-4A8B-85DE-5778CCA2E6BC}';
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
    // 18.09.2026: von 1 MW auf 50 MW angehoben - war selbst unausgesprochen
    // anlagenspezifisch, Solarpark Hofweier liefert live legitim >1 MW
    // (Fund MeterHub-Sitzung, siehe NRGDashboardTile fuer den vollen
    // Befund). 50 MW faengt den urspruenglichen Defektwert (261.554.185 W)
    // weiterhin klar ab.
    private const IMPLAUSIBLE_POWER_W = 50_000_000.0;

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
    // Versionsweise statt EINER flachen Liste (Dietmar, 23.09.2026: "nur die
    // Neuerungen zwischen der zuletzt verwendeten und der nun installierten
    // Version anzeigen") - newsBanner() zeigt jeden Schluessel-Eintrag, dessen
    // Version NEUER ist als das zuletzt bestaetigte Attribut SeenNews (siehe
    // dort), gruppiert nach Version. Die alte, gewachsene Liste bleibt als EIN
    // Eintrag unter ihrer letzten Vergabe ('0.2.8') erhalten statt sie
    // nachtraeglich (und unzuverlaessig) pro Zeile einer Version zuzuordnen -
    // neue Eintraege ab jetzt bekommen ihre EIGENE Versionsnummer als Schluessel.
    private const NEWS_VERSIONS = [
        '0.9.170' => [
            'New: "Schedule" tab (HeishaMon 1.39.0 and later only) - graphical weekly editor for ECO / Normal / Comfort of the heating curve (zone 1 and 2, heating and cooling) and quiet mode: paint segments on a 15-minute grid with your finger or the mouse, copy days, switch channels and the whole schedule on and off. The schedule runs in the HeishaMon module; switching on asks first, because the planned value is sent immediately.',
            'Fix: the vertical shift slider in the "Heating curves" tab never saved (the tile passed the wrong mode to HeishaMon) - it works now. New next to it: quick buttons ECO / Normal / Comfort for the shift and a bar for quiet mode (Off/1/2/3).',
            'New: "Automation" tab (HeishaMon 1.35.0 and later only) - the heating automation shows state, reason and measured values and lets you set the on/off threshold, minimum room value, cooling and the automation switch. Switching on asks first, because the operating mode can change immediately.',
        ],
        '0.2.8' => [
            'New: "Controls" page (HeishaMon only) - quiet mode, powerful mode, holiday, emergency mode (with confirmation and a warning dot on the tab) and hot water target temperature directly in the tile. The heat pump does not acknowledge commands - the tile waits for the feedback and honestly shows whether it was confirmed.',
            'New: vertical slider to the right of the heating curve chart shifts the heating curve in parallel (-5 to +5 K), as a dashed line above the set curve. Only in the unit\'s shift mode, not with direct flow temperature setting.',
            'New: "Heating curves" tab (HeishaMon only) - flow temperature per outdoor temperature for up to 2 heating circuits and heating/cooling, to drag directly in the chart, with an "Apply" button to save to the heat pump.',
            'Feedback panel now exactly as in MeterHub: a dedicated "To the forum thread" button instead of a plain link text.',
            '💬 The Symcon forum thread is now live - the previous GitHub note in the feedback panel now points there.',
            '🧡 New: "About this module" (license/donation note) at the very bottom of the form; the forum/GitHub note is now a panel of its own that can be dismissed, instead of a plain line.',
            '👋 New: a "What is this module for?" panel at the very top of the form briefly explains what this tile does and what it is good for - meant for first contact, can be dismissed once.',
            'New "?" button at the top right shows the introduction tour again at any time - regardless of whether it has been confirmed before. Intended for shared instances (e.g. a demo/showcase instance with a shared login) where every visitor should be able to start the tour themselves.',
            'Fix: a single faulty archive value (e.g. a communication error at a partner module in the order of megawatts for a home heat pump) previously distorted the daily view and the energy balance - such implausible values are now discarded instead of flowing into the display.',
            'Architecture aligned with NRGDashboardPVMonitor: week/month/year/total/custom views now run purely client-side (no reloading on every view change), plus a choice of Highcharts or ECharts as the drawing engine (form "Appearance").',
        ],
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
        // Uebersetzer fuer NRGDASH_Text::t() eintragen (Symcons Translate() dieser Instanz, Merkliste siehe libs/NRGDASH_Text.php)
        NRGDASH_Text::setzen(fn (string $text): string => $this->Translate($text));

        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        // Standalone-Webseite (Hook) abschaltbar (09.11.2026, Forum doctee #232):
        // Standard an, damit bestehende Installationen unveraendert laufen.
        $this->RegisterPropertyBoolean('StandaloneEnabled', true);
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

        // Reiterleisten-Animation 1:1 NRGDashboardPVMonitor (Dietmar,
        // 18.09.2026: Reitermenue "exakt analog wie beim PV Monitor") -
        // gleiches Profil (NRGDASHMON.TabAnim), eigene Instanzvariable.
        if (!IPS_VariableProfileExists('NRGDASHMON.TabAnim')) {
            IPS_CreateVariableProfile('NRGDASHMON.TabAnim', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation('NRGDASHMON.TabAnim', 0, NRGDASH_Text::t('Springy slide-in'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHMON.TabAnim', 1, NRGDASH_Text::t('3D cascade flip'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHMON.TabAnim', 2, NRGDASH_Text::t('Corner by corner into the arrow'), '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHMON.TabAnim', 3, NRGDASH_Text::t('Blur morph'), '', -1);
        $animIsNew = @IPS_GetObjectIDByIdent('TabAnimation', $this->InstanceID) === false;
        $this->RegisterVariableInteger('TabAnimation', NRGDASH_Text::t('Tab bar animation'), 'NRGDASHMON.TabAnim', 10);
        $this->EnableAction('TabAnimation');
        if ($animIsNew) {
            $this->SetValue('TabAnimation', 0);
        }

        $this->RegisterTimer('Refresh', 0, 'NRGDASHWPMON_Render($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Uebersetzer fuer NRGDASH_Text::t() eintragen (Symcons Translate() dieser Instanz, Merkliste siehe libs/NRGDASH_Text.php)
        NRGDASH_Text::setzen(fn (string $text): string => $this->Translate($text));
        $this->SetVisualizationType(1);
        $this->SetTimerInterval('Refresh', 5 * 60 * 1000);
        // Standalone-Webseite fuer IPSView/Browser (WebView/Popup) - Muster
        // Prognoses Energiebilanz-Modul (Dietmar, 27.08.2026: "alle Kacheln
        // ... auch so vorbereiten, dass man sie in IPSView einbinden kann").
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->SyncHook('/hook/nrgdashwpmonitor' . $this->InstanceID);
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
     * Popup oder jeden Browser). Aufruf: /hook/nrgdashwpmonitor<InstanzID>.
     * Mit ?json=1 werden nur die Daten geliefert (fuer die Auto-Aktualisierung).
     */
    public function ProcessHookData()
    {
        // Einfuehrungs-Tour bestaetigt (28.08.2026) - vom Tour-Overlay in
        // module.html per fetch() aufgerufen, siehe Create()/dismissTour().
        if (isset($_GET['dismissTour']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $this->WriteAttributeBoolean('TourSeen', true);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            return;
        }
        // Nachforderungen der eigenstaendigen Webseite (dort fehlt requestAction() des
        // Symcon-Rahmens). Nur lesend - Heizkurve/Bedienung SCHREIBEN gehen bewusst nicht
        // ueber den offenen WebHook.
        if (isset($_GET['action'])) {
            header('Content-Type: application/json; charset=utf-8');
            $ident = (string) $_GET['action'];
            if (!in_array($ident, ['heatingCurveLoad', 'operationsLoad'], true)) {
                echo json_encode([]);
                return;
            }
            $this->hookCapture = [];
            $this->RequestAction($ident, (string) ($_GET['value'] ?? '{}'));
            $out = $this->hookCapture;
            $this->hookCapture = null;
            echo '[' . implode(',', $out) . ']';
            return;
        }
        $payload = $this->buildPayload();
        if (isset($_GET['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        $html = NRGDASH_Text::kachelHtml((string) file_get_contents(__DIR__ . '/module.html'), self::TILE_TEXTS);
        $html .= '<script>function requestAction(ident,value){fetch(window.location.pathname+"?action="+encodeURIComponent(ident)'
               . '+"&value="+encodeURIComponent(value||"{}")).then(function(r){return r.json();})'
               . '.then(function(l){l.forEach(function(m){handleMessage(m);});}).catch(function(){});}</script>';
        $html .= '<script>handleMessage(' . json_encode($payload) . ');'
               . 'setInterval(function(){fetch(window.location.pathname+"?json=1")'
               . '.then(function(r){return r.text();}).then(function(t){handleMessage(t);})'
               . '.catch(function(){});},30000);</script>';
        echo $html;
    }

    /** Verbindungsstatus zur Waermepumpe (SUITE.md "Verbund-Verbindungen sichtbar machen"). */
    private function heatpumpStatusLine(array &$elements): string
    {
        $entries = $this->DiscoverHeatpumps();
        if (count($entries) === 0) {
            return NRGDASH_Text::t('ℹ️ No heat pump found (HeishaMon, WPHub, WPModbusHub, gateway, SamsungEhs) - the tile only shows a note until a source is installed.');
        }
        $ids = array_values(array_unique(array_map(function ($e) {
            return (int) $e['_instanceID'];
        }, $entries)));
        $want = $this->readIntProperty('HeatpumpInstance', 0);
        $explicit = $want > 0 && in_array($want, $ids, true);
        $sel = $this->SelectedHeatpump();
        $id = (int) ($sel['_instanceID'] ?? 0);
        $head = $this->formInstanceLabel($id) . ' (' . $this->formInstanceState($id) . ')';
        if (!$explicit && count($ids) > 1) {
            return NRGDASH_Text::t('⚠️ %d heat pumps found, none selected - %s is used. Please choose one below.', count($ids), $head);
        }
        $vals = [];
        foreach (['powerID', 'energyID'] as $k) {
            $v = (int) ($sel[$k] ?? $sel[ucfirst($k)] ?? 0);
            if ($v > 0) {
                // zwei vollständige Texte statt Satzteile (Ternary außerhalb von t())
                $vals[] = ($k === 'powerID') ? NRGDASH_Text::t('Power #%d', $v) : NRGDASH_Text::t('Energy #%d', $v);
            }
        }
        $taken = count($vals) ? implode(', ', $vals) : NRGDASH_Text::t('only temperature/status fields (no power/energy)');
        $line = $explicit
            ? NRGDASH_Text::t('✏️ Heat pump: %s (own entry) - taken over: %s.', $head, $taken)
            : NRGDASH_Text::t('🔗 Heat pump: %s (detected automatically) - taken over: %s.', $head, $taken);
        if ($this->HeatingCurveInstanceID() > 0) {
            $line .= ' ' . NRGDASH_Text::t('Heating curve tab available.');
        }
        if ($this->OperationsAvailable()) {
            $line .= ' ' . NRGDASH_Text::t('Controls available.');
        }
        if (!$explicit) {
            $this->hideFormField($elements, 'HeatpumpInstance');
        }
        return $line;
    }

    public function GetConfigurationForm()
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }
        // Das Label mit dem Hook-Pfad enthält einen eingesetzten Wert: die Konsole fände den Text so nicht mehr, deshalb selbst übersetzen.
        $this->setFormCaption($form['elements'], 'HookInfo', NRGDASH_Text::t('IPSView/popup: the tile also runs as a standalone web page at http://<Symcon-IP>:3777%s (auto-refresh every 30 s). In IPSView, place a WebView element on a popup page and enter this URL. Note: WebHooks can be reached on the local network without logging in.', '/hook/nrgdashwpmonitor' . $this->InstanceID));

        $this->injectVersionIntoDocPanel($form);
        $this->setFormStatusLine($form['elements'], 'HeatpumpStatus', $this->heatpumpStatusLine($form['elements']));

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
                    ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHWPMON_DismissReviewHint($id);'],
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
            : NRGDASH_Text::t('ℹ️ NRG-Stack Dashboard');
        foreach ($form['elements'] as &$el) {
            // Panel über den Namen finden, nie über die (übersetzte) Caption
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
                ['type' => 'Label', 'caption' => 'Shows history and key figures of a heat pump - electrical/thermal power, flow/return, outdoor temperature - as daily to yearly views, optionally with Highcharts or ECharts.'],
                ['type' => 'Label', 'caption' => 'The benefit: understand the efficiency and operating behavior of the heat pump over time without having to build charts from the raw data yourself.'],
                ['type' => 'Label', 'caption' => 'The data comes from HeishaMon or WPHub via the shared "heatpump" contract - if one of them is missing, only individual key figures drop out, not the whole tile. NRGDashboardHeatSchema additionally shows the layout of the installation as a schematic diagram.'],
                ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHWPMON_AckPurposeIntro($id);'],
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
        $items = [['type' => 'Label', 'caption' => NRGDASH_Text::t('🆕 New since the version you last saw — please take a quick look and check your settings if needed:')]];
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
        $items[] = ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHWPMON_AckNews($id);'];
        $latest = array_key_last($pending);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => NRGDASH_Text::t('🆕 New up to version %s', $latest), 'expanded' => true, 'items' => $items];
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
        return NRGDASH_Text::t('✅ The tour will be shown again the next time the tile is opened.');
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

    /**
     * Bedienfunktionen (Fluesterbetrieb, Leistungsbetrieb, Urlaub, Notbetrieb,
     * Warmwasser-Soll) - HeishaMon-Vertrag 1.14 (HEISHA_GetOperations/
     * HEISHA_SetOperation), wie der Heizkurven-Vertrag nur bei HeishaMon
     * und defensiv per function_exists() geprueft.
     */
    private function OperationsAvailable(): bool
    {
        return $this->HeatingCurveInstanceID() > 0 && function_exists('HEISHA_GetOperations');
    }

    /**
     * Heizungs-Automatik (17.10.2026) - HeishaMon-Vertrag heatpump 1.15
     * (HEISHA_GetHeatingAutomation/HEISHA_SetHeatingAutomation). Wie die
     * Bedienfunktionen nur bei HeishaMon und defensiv per function_exists().
     */
    private function AutomationAvailable(): bool
    {
        return $this->HeatingCurveInstanceID() > 0 && function_exists('HEISHA_GetHeatingAutomation') && function_exists('HEISHA_SetHeatingAutomation');
    }

    private function BuildAutomation(): array
    {
        $id = $this->HeatingCurveInstanceID();
        if ($id <= 0 || !function_exists('HEISHA_GetHeatingAutomation')) {
            return [];
        }
        try {
            $result = @HEISHA_GetHeatingAutomation($id);
        } catch (\Throwable $e) {
            return [];
        }
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        return is_array($result) ? $result : [];
    }

    /**
     * Wochen-Zeitplan (18.10.2026) - HeishaMon-Vertrag heatpump 1.16 (HEISHA_GetSchedule/HEISHA_SetSchedule). Der Plan liegt
     * im HeishaMon-Modul (die Anlage kann keinen Plan aufnehmen); dieser Reiter ist nur der Editor. Defensiv per function_exists().
     */
    private function ScheduleAvailable(): bool
    {
        return $this->HeatingCurveInstanceID() > 0 && function_exists('HEISHA_GetSchedule') && function_exists('HEISHA_SetSchedule');
    }

    private function BuildSchedule(): array
    {
        $id = $this->HeatingCurveInstanceID();
        if ($id <= 0 || !function_exists('HEISHA_GetSchedule')) {
            return [];
        }
        try {
            $result = @HEISHA_GetSchedule($id);
        } catch (\Throwable $e) {
            return [];
        }
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        return is_array($result) ? $result : [];
    }

    private function BuildOperations(): array
    {
        $id = $this->HeatingCurveInstanceID();
        if ($id <= 0 || !function_exists('HEISHA_GetOperations')) {
            return [];
        }
        try {
            $result = @HEISHA_GetOperations($id);
        } catch (\Throwable $e) {
            return [];
        }
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        return is_array($result) ? $result : [];
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
                    NRGDASH_Text::t('Implausible archive value discarded: variable #%d, %s, max=%.0f W', $vid, date(NRGDASH_Text::t('d/m/Y H:i'), (int) $row['TimeStamp']), (float) ($row['Max'] ?? 0)),
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
                    NRGDASH_Text::t('Implausible archive value discarded: variable #%d, %s, max=%.0f W', $vid, date(NRGDASH_Text::t('d/m/Y H:i'), (int) $row['TimeStamp']), (float) ($row['Max'] ?? 0)),
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
                    NRGDASH_Text::t('Implausible archive value discarded: variable #%d, %s, max=%.0f W', $vid, date(NRGDASH_Text::t('d/m/Y'), (int) $row['TimeStamp']), (float) ($row['Max'] ?? 0)),
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
    /** Antworten von RequestAction(): normal an die Kachel, ueber den WebHook (?action=) stattdessen einsammeln. */
    private ?array $hookCapture = null;

    private function PushMessage(string $json): void
    {
        if ($this->hookCapture !== null) {
            $this->hookCapture[] = $json;
            return;
        }
        $this->UpdateVisualizationValue($json);
    }

    public function RequestAction($Ident, $Value)
    {
        // Einfuehrungs-Tour bestaetigt: die Kachel meldet das ueber requestAction()
        // zurueck, nicht ueber den offenen WebHook (der kann abgeschaltet sein).
        if ($Ident === 'dismissTour') {
            $this->WriteAttributeBoolean('TourSeen', true);
            return;
        }
        if ($Ident === 'TabAnimation') {
            $this->SetValue('TabAnimation', (int) $Value);
            $this->Render();
            return;
        }
        if ($Ident === 'heatingCurveLoad') {
            $this->PushMessage(json_encode([
                'ok'    => true,
                'type'  => 'heatingCurveUpdate',
                'curve' => $this->BuildHeatingCurve(),
            ]));
            return;
        }
        if ($Ident === 'heatingCurveShiftSave') {
            // Verschiebung ("Heizanforderung verschieben") - nur Modus 'shift'
            // (-5..+5); im Direktmodus waere derselbe Wert eine Vorlauf-
            // Solltemperatur, dort sendet die Kachel nichts (HeishaMon lehnt
            // ohnehin ab).
            $req = json_decode((string) $Value, true);
            $id = $this->HeatingCurveInstanceID();
            $ok = false;
            if (is_array($req) && $id > 0 && function_exists('HEISHA_SetHeatingCurveShift')) {
                $zone = (string) ($req['zone'] ?? '');
                // Dritter Parameter ist der Heiz-/Kuehlmodus ('heat'|'cool'), NICHT der Reglermodus 'shift' (HeishaMon-Sitzung,
                // 18.10.2026: mit 'shift' lieferte die Funktion immer false, der Regler speicherte nie).
                $shiftMode = (string) ($req['mode'] ?? 'heat');
                if (in_array($zone, ['z1', 'z2'], true) && in_array($shiftMode, ['heat', 'cool'], true)) {
                    try {
                        $ok = (bool) @HEISHA_SetHeatingCurveShift($id, $zone, $shiftMode, (int) ($req['shiftC'] ?? 0));
                    } catch (\Throwable $e) {
                        $ok = false;
                    }
                }
            }
            $this->PushMessage(json_encode([
                'ok'    => true,
                'type'  => 'heatingCurveShiftSaved',
                'saved' => $ok,
                'curve' => $this->BuildHeatingCurve(),
            ]));
            return;
        }
        if ($Ident === 'automationLoad') {
            $this->PushMessage(json_encode(['ok' => true, 'type' => 'automationUpdate', 'auto' => $this->BuildAutomation()]));
            return;
        }
        if ($Ident === 'automationSave') {
            // Nur die vereinbarten Felder weiterreichen (SUITE.md "Heizungs-Automatik"): Schwellen,
            // Raum-Mindestwert und die beiden Schalter. featureEnabled und alles andere schreibt die
            // Kachel nie. Pruefung, Grenzen und Teil-Update liegen im HeishaMon-Modul.
            $req = json_decode((string) $Value, true);
            $id = $this->HeatingCurveInstanceID();
            $result = ['ok' => false, 'errors' => [['field' => '', 'message' => NRGDASH_Text::t('Not sent - HeishaMon function not available (HeishaMon too old?).')]]];
            if (is_array($req) && $id > 0 && function_exists('HEISHA_SetHeatingAutomation')) {
                $payload = [];
                foreach (['onBelowC', 'offAboveC', 'roomMinC'] as $k) {
                    if (array_key_exists($k, $req) && is_numeric($req[$k])) {
                        $payload[$k] = (float) $req[$k];
                    }
                }
                foreach (['enabled', 'coolingEnabled', 'priceBlockEnabled'] as $k) {
                    if (array_key_exists($k, $req)) {
                        $payload[$k] = (bool) $req[$k];
                    }
                }
                if (isset($req['configRevision']) && is_numeric($req['configRevision'])) {
                    $payload['configRevision'] = (int) $req['configRevision'];
                }
                try {
                    $r = @HEISHA_SetHeatingAutomation($id, json_encode($payload));
                    if (is_string($r)) {
                        $r = json_decode($r, true);
                    }
                    $result = is_array($r) ? $r : $result;
                } catch (\Throwable $e) {
                    $result = ['ok' => false, 'errors' => [['field' => '', 'message' => NRGDASH_Text::t('Not sent - %s', $e->getMessage())]]];
                }
            }
            $this->PushMessage(json_encode(['ok' => true, 'type' => 'automationSaved', 'result' => $result, 'auto' => $this->BuildAutomation()]));
            return;
        }
        if ($Ident === 'scheduleLoad') {
            $this->PushMessage(json_encode(['ok' => true, 'type' => 'scheduleUpdate', 'schedule' => $this->BuildSchedule()]));
            return;
        }
        if ($Ident === 'scheduleSave') {
            // Nur vereinbarte Felder (SUITE.md "Wochen-Zeitplan"): enabled, revision, je Kanal enabled/default/segments.
            // featureEnabled schreibt die Kachel nie. Pruefung, Obergrenzen und alles-oder-nichts liegen im HeishaMon-Modul.
            $req = json_decode((string) $Value, true);
            $id = $this->HeatingCurveInstanceID();
            $result = ['ok' => false, 'errors' => [['field' => '', 'message' => NRGDASH_Text::t('Not sent - HeishaMon function not available (HeishaMon too old?).')]]];
            if (is_array($req) && $id > 0 && function_exists('HEISHA_SetSchedule')) {
                $payload = [];
                if (($req['applyNow'] ?? null) === true) {
                    // Eigene Aktion (heatpump 1.16, HeishaMon ab 1.39.2): Plan jetzt neu anwenden, Plan und revision bleiben
                    $payload['applyNow'] = true;
                    $req = [];
                }
                if (array_key_exists('enabled', $req)) {
                    $payload['enabled'] = (bool) $req['enabled'];
                }
                if (isset($req['revision']) && is_numeric($req['revision'])) {
                    $payload['revision'] = (int) $req['revision'];
                }
                if (isset($req['channels']) && is_array($req['channels'])) {
                    $payload['channels'] = [];
                    foreach (['z1Heat', 'z1Cool', 'z2Heat', 'z2Cool', 'quiet'] as $k) {
                        if (!isset($req['channels'][$k]) || !is_array($req['channels'][$k])) {
                            continue;
                        }
                        $in = $req['channels'][$k];
                        $ch = [];
                        if (array_key_exists('enabled', $in)) {
                            $ch['enabled'] = (bool) $in['enabled'];
                        }
                        if (isset($in['default']) && is_numeric($in['default'])) {
                            $ch['default'] = (int) $in['default'];
                        }
                        if (isset($in['segments']) && is_array($in['segments'])) {
                            $ch['segments'] = [];
                            foreach ($in['segments'] as $seg) {
                                if (is_array($seg)) {
                                    $ch['segments'][] = ['day' => (int) ($seg['day'] ?? 0), 'from' => (int) ($seg['from'] ?? 0), 'to' => (int) ($seg['to'] ?? 0), 'value' => (int) ($seg['value'] ?? 0)];
                                }
                            }
                        }
                        $payload['channels'][$k] = $ch;
                    }
                }
                try {
                    $r = @HEISHA_SetSchedule($id, json_encode($payload));
                    if (is_string($r)) {
                        $r = json_decode($r, true);
                    }
                    $result = is_array($r) ? $r : $result;
                } catch (\Throwable $e) {
                    $result = ['ok' => false, 'errors' => [['field' => '', 'message' => NRGDASH_Text::t('Not sent - %s', $e->getMessage())]]];
                }
            }
            $this->PushMessage(json_encode(['ok' => true, 'type' => 'scheduleSaved', 'result' => $result, 'schedule' => $this->BuildSchedule()]));
            return;
        }
        if ($Ident === 'operationsLoad') {
            $this->PushMessage(json_encode([
                'ok'  => true,
                'type' => 'operationsUpdate',
                'ops' => $this->BuildOperations(),
            ]));
            return;
        }
        if ($Ident === 'operationSave') {
            $req = json_decode((string) $Value, true);
            $id = $this->HeatingCurveInstanceID();
            $name = is_array($req) ? (string) ($req['name'] ?? '') : '';
            $ok = false;
            // Nur bekannte Namen weiterreichen (HeishaMon prueft ebenfalls).
            if ($id > 0 && function_exists('HEISHA_SetOperation')
                && in_array($name, ['quiet', 'powerful', 'holiday', 'emergencyHeater', 'dhwTargetC'], true)) {
                try {
                    $ok = (bool) @HEISHA_SetOperation($id, $name, (int) ($req['value'] ?? 0));
                } catch (\Throwable $e) {
                    $ok = false;
                }
            }
            $this->PushMessage(json_encode([
                'ok'    => true,
                'type'  => 'operationSaved',
                'saved' => $ok,
                'name'  => $name,
                'ops'   => $this->BuildOperations(),
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
            $this->PushMessage(json_encode([
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
        $html = NRGDASH_Text::kachelHtml((string) file_get_contents(__DIR__ . '/module.html'), self::TILE_TEXTS);
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
        return NRGDASH_Text::t('No current measurement since %s - the displayed values are outdated.', date(NRGDASH_Text::t('d/m/Y H:i'), $lastSeen));
    }

    private function buildPayload(): array
    {
        $unit = $this->SelectedHeatpump();
        if ($unit === null) {
            return [
                'ok'    => false,
                'error' => NRGDASH_Text::t('No heat pump found - install and configure HeishaMon or WPHub.'),
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
            'label'      => (string) ($unit['Caption'] ?? $unit['caption'] ?? $unit['label'] ?? IPS_GetName((int) $unit['_instanceID'])),
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
            'hasOperations'   => $this->OperationsAvailable(),
            'hasAutomation'   => $this->AutomationAvailable(),
            'hasSchedule'     => $this->ScheduleAvailable(),
            'bg'         => $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND)),
            'font'       => $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT)),
            // Engine-Wahl 1:1 NRGDashboardPVMonitor (Dietmar, 18.08.2026:
            // "Natuerlich auch mit der Auswahl Highchart und Echart").
            'engine'     => ($this->readStringProperty('Engine', self::DEF_ENGINE) === 'highcharts') ? 'highcharts' : 'echarts',
            'tabAnim'    => (int) $this->GetValue('TabAnimation'),
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
