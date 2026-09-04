<?php

declare(strict_types=1);

// GUID    : {6B2F8C41-9E3A-4D6B-8F1C-5A7D9E2B4C61}
// Verbund : NRG-Stack (DG65) - siehe https://github.com/DG65/NRGEMS/blob/main/SUITE.md
//
// Phase 1: automatische Geräte-Discovery über die *_GetFunctions-Verträge
// des Verbunds. Kein Hardcoding von Variablen-IDs - genau das Problem, das
// dieses Modul lösen soll (feste IPS View-Verknüpfungen brechen beim
// Löschen der referenzierten Instanz). Keine Darstellung in dieser Phase,
// nur die Erfassung und Normalisierung der Geräteliste.

// Partnermodul-GUIDs (für automatische Discovery). Kein Modul setzt ein
// anderes voraus - jeder Aufruf steht hinter function_exists().
define('NRGDASH_GUID_INVERTERHUB',    '{BBE2C593-1A91-426D-A714-29A9C7E87589}');
define('NRGDASH_GUID_INVERTERHUBMON', '{7B1F9A34-6C52-4E8D-9A1B-4F3E2D7C6A19}');
define('NRGDASH_GUID_INVERTERHUBTILE', '{9A2E5C7F-3B1D-4A6E-8C9F-2D5B7E1A4C8F}');
define('NRGDASH_GUID_METERHUB',       '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}');
define('NRGDASH_GUID_METERHUBV',      '{ADF18291-2E60-4354-92F5-B96863C127C8}');
define('NRGDASH_GUID_CHARGERHUB',     '{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}');
define('NRGDASH_GUID_OCPPHUB',        '{81D3E328-9E12-43A9-825A-F7888530868C}');
define('NRGDASH_GUID_HEISHAMON',      '{1919151A-3C0F-4C09-B906-291638EC1469}');
define('NRGDASH_GUID_TESSIE',         '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}');
define('NRGDASH_GUID_TIBBERGRIDREWARD', '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}');
define('NRGDASH_GUID_EMS',            '{31C61A7B-28C4-4F97-9651-1A64B3469E3C}');
define('NRGDASH_GUID_STROMGEDACHT',   '{D5A8C3A1-2222-4A55-8888-123456789003}');
define('NRGDASH_GUID_PVPROGNOSE',     '{257DD4E8-9705-462E-89FC-56D0A1038353}');
define('NRGDASH_GUID_LASTPROGNOSE',   '{DC5AD508-507F-40EA-8630-0959AED83050}');
// Schwester-Kachel im selben Repo (NRGDashboardPVMonitor) - fuer den
// IrradianceID-Rueckfall (Dietmar, 29.07.2026: "Einstrahlungswerte nicht
// mehr als einmal irgendwo eintragen").
define('NRGDASH_GUID_MONITOR',        '{E1A674D1-F48F-492D-B172-F8B9390BFEB3}');

class NRGDashboardTile extends IPSModule
{
    // Kategorien für die spätere Anordnung (Erzeugung -> Speicher ->
    // Verteilung -> Verbraucher), siehe Phase 2. functionCategory() ordnet
    // jeden gefundenen function-Wert einer dieser vier Kategorien zu.
    private const CATEGORY_ORDER = ['erzeugung', 'speicher', 'verteilung', 'verbraucher'];

    // Darstellungs-Einstellungen 1:1 aus InverterHubTile übernommen (gleiche
    // Namen/Defaults/Wertebereiche - Dietmars Anspruch war volle Parität zur
    // InverterHub-Konfiguration, nicht eine reduzierte Fassung). Die
    // Renderings-Engine in module.html ist dieselbe, diese Einstellungen
    // greifen dort identisch (--font/--trans CSS-Variablen, FLOW_REF_W).
    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';
    private const DEF_TRANSITION = 800;
    private const DEF_FLOWREF    = 10000;
    private const DEF_MATCH_TOLERANCE = 300;

    // Formular-Konvention des Verbunds (SUITE.md "Einheitliche Formular-
    // Optik", Referenz InverterHub) - "Was ist Neu"/Doku/Forum-Hinweis.
    // Pflege-Pflicht bei jedem Fix/Update: pruefen, ob etwas ins News-Panel
    // gehoert (Ergebnis darf "nichts Relevantes" sein, aber die Pruefung ist
    // Pflicht). Kein Forum-Thread vorhanden (Modul noch nicht veroeffentlicht)
    // - Hinweis zeigt vorerst auf GitHub, Muster: ChargerHub vor Forum-Post.
    private const NEWS_VERSION = '0.8.9';
    private const NEWS_ITEMS = [
        'Die automatische Vorführung wartet jetzt 8 statt 20 Sekunden Inaktivität, bis sie startet - bei vorhandenen Sammelknoten schachtelt sie bevorzugt einen davon auf, zeigt eine Detailseite darin und schachtelt wieder zu.',
        'Fix: die Brotkrumen-Zeile (Aufschachteln) saß zu weit oben und geriet in eingebetteten Ansichten in Kollision mit dem WebFront-eigenen Kachel-Rahmen - 20px tiefer gerückt.',
        'Neu: Schaltgruppen (MeterHubVirtual-Vertrag 1.4) - ein kleiner Schalt-Knopf am Knoten schaltet ein einzelnes Mitglied (z. B. eine Z-Wave-Leuchte) oder eine ganze Gruppe (aus/teilweise/an) direkt in der Kachel, transportneutral über dieselbe RequestAction-Bindung wie bei Wallboxen - respektiert den Vorführmodus.',
        'Neu: Aufschachteln - Sammelknoten (virtuelle Zähler mit Unterzählern, MeterHubVirtual-Vertrag 1.3 oder verschachtelte "Weitere Verbraucher") tragen ein Zähler-Badge; kurzer Klick öffnet die nächste Ebene (der Knoten wird zur Mittelpille, seine Mitglieder ordnen sich darum an - beliebig tief), Klick auf die Pille oder die Brotkrumen-Zeile führt zurück. Langer Klick (500 ms, Füllring) öffnet weiterhin die Detailseite. Abgezogene Mitglieder (negativer Faktor) erscheinen gestrichelt mit Minus.',
        'Neu: "Automatische Vorführung" (Instanz-Eigenschaft, Standard aus) - die Kachel öffnet bei Inaktivität von selbst Detailseiten und schachtelt Sammelknoten auf/zu, pausiert bei jeder Berührung. Für Vorstellungs-Instanzen.',
        'Fix: die Blitzbögen am Haus-Knoten wirkten bei einer sehr breiten Pille (viele Geräte) zappelig, weil ihr Sampling entlang des Randes bei Rundung und Geradseite unterschiedlich große Sprünge macht - Amplitude/Feinheit passen sich jetzt dem Seitenverhältnis an, bleiben bei kreisrunder Form unverändert.',
        'Neu: eine manuell eingetragene Wallbox oder ein sonstiger Verbraucher ("Weitere Verbraucher") kann jetzt beliebig viele zusätzliche Wertfelder tragen (JSON-Zeile, z.B. "vinID"/"rangeKmID") - erscheinen automatisch in der "Aktuelle Werte"-Tabelle der Detailseite, exakt wie bei automatisch erkannten Geräten.',
        'Fix: bei sehr vielen Geräten wirkte der breit gezogene Haus-Tisch wie ein dünner Balken - die Höhe wächst jetzt bei extremer Breite proportional mit, bleibt also ein rundliches Rechteck.',
        'Fix: bei einer breiten Kachel blieb links/rechts viel Platz ungenutzt (die Anordnung durfte nie breiter als hoch werden) - die Leinwand passt sich jetzt der tatsächlichen Kachel-Breite an, wodurch bei vielen Geräten (Chip-Form) deutlich mehr davon Platz finden, ohne kleiner zu werden.',
        'Neu: "Isolierter Demo-Modus" (Instanz-Eigenschaft) schaltet jede automatische Geräte-Erkennung ab - Netz/PV/Batterie/Haus kommen dann ausschließlich aus den manuellen Kern-Feldern. Für eine reine Vorstellungs-Instanz mit erfundenen, aber in sich rechnerisch stimmigen Werten (eine Mischung aus echten Live-Messwerten und erfundenen Zusatzverbrauchern geht sonst rechnerisch nicht auf).',
        'Neu: eine manuell eingetragene Wallbox ("Weitere Verbraucher") kann jetzt zusätzlich ein SocID tragen (JSON-Zeile, kein eigenes Formularfeld) - zeigt dann wie bei automatisch erkannten Fahrzeugen den Ladestand am Knoten.',
        'Neu: die Kachel zeigt jetzt unten ein kleines Feld mit der aktuellen EMS-Schaltentscheidung inkl. Begründung (z. B. "Netzladen – Grid Rewards (Tibber)"), sofern das EMS-Modul den Vertrag EMS_GetCurrentDecision() bereitstellt. Rein informativ, ohne Rückwirkung.',
        'Fix: bei bestimmten Knotenbeschriftungen brach die komplette Kachel-Darstellung in Safari ab ("Invalid value for <text> attribute textLength"), während Chrome/Firefox den fehlerhaften Wert stillschweigend ignorierten - ein Rundungsfehler nahe der Kreisgrenze wird jetzt zuverlässig abgefangen.',
        'Neuer "?"-Knopf oben rechts zeigt die Einführungs-Tour jederzeit erneut - unabhängig davon, ob sie schon einmal bestätigt wurde. Gedacht für gemeinsam genutzte Instanzen (z. B. eine Demo-/Vorstellungs-Instanz mit einem geteilten Zugang), wo jeder Besucher die Tour selbst starten können soll.',
        'Leistungsdiagramm, Energie-Balken der letzten 14 Tage und Geisterring/Autarkiegrad/PV-Prognose-Ring verwerfen jetzt einzelne unplausible Archivwerte (z. B. ein defekter Messwert in der Größenordnung von Megawatt bei einer Haushaltsanlage) statt sie ungeprüft in die Darstellung einfließen zu lassen.',
        'Vorführmodus erkennt jetzt auch, wenn OCPPHub selbst im Vorführmodus ist (OHUB_IsDemoMode()) - unsere Wallbox-Steuerung blendet sich dann für dieses Gerät ebenfalls aus, statt Buttons zu zeigen, die beim Klick ohnehin nur abgelehnt würden.',
        'Neuer "Vorführmodus" (Instanz-Eigenschaft): eine so markierte Instanz zeigt weiterhin alle Geräte an, blendet aber jede Wallbox-Steuerung komplett aus und lehnt Steuerbefehle auch serverseitig ab - für Demo-/Vorstellungs-Instanzen ohne Auswirkung auf echte Geräte.',
        'Neuer Verbund-Vertrag NRGDASH_GetPriceAt()/NRGDASH_GetPriceSeries(): andere Module können jetzt den zu jedem Zeitpunkt gültigen Strompreis (echte Tibber-Slots, sonst aus unserer eigenen BDEW-Preishistorie rekonstruiert) direkt bei uns abrufen, statt Preisermittlung ein zweites Mal zu bauen - Grundlage für Kostenauswertungen über beliebige Zeiträume (Tag/Monat/Jahr/Lebenszeit), nicht mehr nur den heutigen Tag.',
        'Der Stromlimit-Schieberegler der Wallbox-Steuerung zeigt jetzt zusätzlich den Prozentwert der maximalen Ladeleistung an, hat ein Raster (Tick-Striche) und markiert die halbe Leistung als eigenen Punkt - Ober-/Untergrenze kommen dabei weiterhin ausschließlich vom jeweiligen Vertrag (echte Gerätegrenzen, kein pauschaler Wert).',
        'Das Leistungsdiagramm der Geräte-Detailseite aktiviert die Archivierung der zugrunde liegenden Variable jetzt selbst, statt nur "keine Archivdaten" zu melden - ab dem ersten Aufruf sammelt sich der Verlauf automatisch.',
        'Wallbox-Steuerung (Start/Stopp/Freigabe/Limit) meldet jetzt sichtbar zurück, ob der Befehl gesendet wurde und ob die Wallbox danach tatsächlich reagiert - statt wie bisher bei Erfolg komplett stumm zu bleiben. Liefert das Partnermodul einen konkreten Ablehnungsgrund (z. B. warum ein Ladebefehl gerade nicht wirkt), erscheint dieser jetzt prominent direkt über den Steuer-Schaltflächen.',
        'Wallboxen über OCPPHub (OCPP 1.6J) werden jetzt automatisch erkannt. Geräte-Detailseite bietet jetzt herstellerunabhängige Wallbox-Steuerung (Ladefreigabe, Stromlimit für ChargerHub UND OCPPHub, zusätzlich Start/Stopp/Tages-Override bei OCPP-Ladepunkten), sofern keine andere Instanz die Regelhoheit hält.',
        'Haus-Knoten wechselt ab vielen Verbrauchern automatisch von der Kreis- in eine „Chip“-Form, damit die einzelnen Knoten nicht immer weiter schrumpfen müssen.',
        'Kostenersparnis-Berechnung nutzt jetzt echte Strompreise (Tibber Grid Rewards, sonst automatisch der BDEW-Haushaltsdurchschnitt) statt eines festen Werts, inklusive Aufschlüsselung nach Netzentgelt/Tarif/Grid-Reward-Erlös auf der Geräte-Detailseite.',
        'Detailseite zeigt jetzt eine Tabelle aller relevanten Stromwerte je Gerät, auch je MPPT-Strang und je Batterie-Turm.',
        'Batterie-Knoten zeigt den Ladestand zusätzlich als steigenden Füllstand, Diagnose-Warnungen erscheinen jetzt direkt am betroffenen Knoten statt nur im separaten Diagnose-Badge.',
        'Gestrichelter „Geisterring“ vergleicht die aktuelle Leistung jedes Knotens mit gestern zur gleichen Uhrzeit.',
        'Solar-Knoten zeigt einen Fortschrittsring (heutiger Ertrag vs. Tagesprognose), Netz-Knoten eine Strompreis-Sparkline mit Kosten-Ticker, Haus-Knoten einen Autarkiegrad-Bogen.',
        'Verbindungslinien werden mit der Leistung dicker und markieren die heutige Tagesspitze; bei installierter StromGedacht-Instanz färbt die Netzampel den Kachelhintergrund dezent ein.',
        'Anzeige-Feinheiten (inaktive Knoten aus-/einblenden, Blitz-/Leuchtschein-Kopplung an die Leistung, Effekt-Intensität) stellt jeder Nutzer selbst im WebFront ein - Kachel über den Doppelpfeil aufziehen.',
        'Ereignisgesteuerte Aktualisierung (sofortiger Push bei jeder Wertänderung) statt reinem 5-Minuten-Takt.',
        'Echte Hauslast (IHUBTILE_GetHouseLoad) bevorzugt vor der berechneten Näherung, sofern konfiguriert.',
        'Manuelle Datenpunkte und frei editierbare Verbraucherliste - die Kachel läuft jetzt auch ganz ohne installiertes Partnermodul.',
        'Vollständige Darstellungs-Einstellungen (Hintergrundfarbe, Schriftart, Übergangszeit, Fluss-Tempo) wie InverterHubTile.',
        'Einzelne automatisch gefundene Geräte lassen sich jetzt ein-/ausblenden, ohne sie manuell neu einzutragen.',
        'Eingesteckte, nicht ladende Wallbox animiert keinen Fluss-Pfeil mehr ohne echte Leistung.',
        'Gesundheits-/Diagnose-Anzeige (Ertrag vs. Prognose, MPPT-Strangvergleich, Isolationswiderstand) selbst berechnet, keine InverterHubMonitor-Instanz mehr nötig.',
        'Fahrzeug-Erkennung an Wallboxen: Tesla-Fahrzeuge über Tessie vollautomatisch erkannt (Name + Ladestand), auch ChargerHub-Wallboxen jetzt korrekt einbezogen.',
        'Modul heißt jetzt „NRG-Stack Dashboard Energiefluss" (bisher „NRG Dashboard“/„Energiefluss-Kachel“) - bestehende Instanzen bleiben unverändert funktionsfähig.',
        'Klick auf einen Geräte-Knoten öffnet dessen Details als eigene, kachelfüllende Seite (Leistungsverlauf, Energiebilanz, alle Vertragsfelder inkl. Phasenwerte) - komplett automatisch aus den vorhandenen Verträgen, ohne zusätzliche Einrichtung.',
    ];
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';
    private const GITHUB_URL = 'https://github.com/DG65/NRGDashboard';

    /** Cache fuer legacyValue() - IPS_GetConfiguration() ist teuer genug,
     *  um sie nicht je Doppelpfeil-Variable erneut aufzurufen. */
    private ?array $legacyConfigCache = null;

    public function Create()
    {
        parent::Create();

        $this->RegisterAttributeString('DeviceCache', '[]');
        $this->RegisterAttributeString('DiagnosticsCache', '[]');
        $this->RegisterAttributeInteger('LastDiscoveryTs', 0);
        $this->RegisterAttributeString('SeenNews', '');
        // Gestern-Vergleich als Geisterring (28.08.2026, Dietmar: "alles
        // direkt umsetzen") - je powerID der zuletzt ermittelte Wert von
        // "gestern zur gleichen Uhrzeit" + Abrufzeitpunkt, damit
        // buildPayload() (ereignisgesteuert, sehr haeufig via MessageSink())
        // nicht bei jedem Aufruf eine Archivabfrage ausloest.
        $this->RegisterAttributeString('YesterdayCache', '{}');
        // PV-Prognose-Fortschrittsring (28.08.2026) - Tagesertrag bislang
        // vs. Prognose-Tagesgesamtwert, throttled aus demselben Grund wie
        // YesterdayCache (Tagesintegration ueber DaySeries() waere sonst bei
        // jedem ereignisgesteuerten buildPayload()-Aufruf zu teuer).
        $this->RegisterAttributeString('PvForecastCache', '{}');
        // Peak-Marker auf der Speiche + Autarkiegrad-Ring am Haus-Knoten
        // (28.08.2026) - dieselbe Throttle-Begruendung wie YesterdayCache.
        $this->RegisterAttributeString('PeakTodayCache', '{}');
        $this->RegisterAttributeString('AutarkyCache', '{}');
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        // Einfuehrungs-Tour bei erster Benutzung (28.08.2026, Dietmar:
        // "eine Tour die bei der ersten Benutzung eingeblendet und nur per
        // Haken ausgeblendet werden kann") - je Instanz einmalig, WebFront-
        // seitig (nicht nur Konsole, da die Kachel-Feinheiten gerade dort
        // erlebt werden). Bestaetigung kommt ueber den WebHook zurueck
        // (ProcessHookData(), ?dismissTour=1) - die Kachel selbst hat als
        // sandboxed HTML-SDK-Tile keinen anderen Rueckkanal in die Instanz.
        $this->RegisterAttributeBoolean('TourSeen', false);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);
        $this->RegisterPropertyInteger('TransitionMs', self::DEF_TRANSITION);
        $this->RegisterPropertyInteger('FlowRefW', self::DEF_FLOWREF);

        // Anzeige-Feinheiten "hinter dem Doppelpfeil" (28.08.2026, Dietmar:
        // "ich möchte, dass Du solche Einstellungen hinter den Doppelpfeil
        // oben rechts auf der Kachel stellst" - NICHT ein selbstgebautes
        // Panel in der Kachel, sondern echte Instanz-Variablen mit
        // EnableAction(). Muster: Prognose/Energiebilanz (Dietmar dort,
        // 26.08.2026: "Bau alles um" - alle bisher konsolenpflichtigen
        // Einstellungen hinter den Doppelpfeil legen). Eine aufgezogene
        // Kachel zeigt beim Klick auf den WebFront-Doppelpfeil NIE das
        // eigene Kachel-HTML, sondern die Standardansicht der Instanz-
        // Kinder (SUITE.md Punkt 10) - eigene Variablen mit EnableAction()
        // erscheinen dort automatisch als Schalter/Zahlenfeld, bedienbar
        // OHNE Konsolenzugriff (WebFront-Nutzer haben keinen).
        //
        // HideInactive war bis hierher eine Property - Migration beim
        // erstmaligen Anlegen der Variable uebernimmt den zuvor
        // gespeicherten Property-Wert aus der rohen Konfiguration
        // (legacyValue()), damit eine bereits angepasste Einstellung beim
        // Umbau nicht stillschweigend zurueckfaellt.
        $doppelpfeil = [
            'HideInactive'    => ['Inaktive Knotenpunkte ausblenden statt nur ausgrauen', 200, false],
            'CoupleBoltPower' => ['Blitzbögen an Leistung koppeln', 201, true],
            'CoupleGlowPower' => ['Leuchtschein an Leistung koppeln', 202, true],
        ];
        foreach ($doppelpfeil as $ident => [$caption, $pos, $default]) {
            $isNew = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->RegisterVariableBoolean($ident, $caption, '', $pos);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, (bool) $this->legacyValue($ident, $default));
            }
        }
        $isNewIntensity = @IPS_GetObjectIDByIdent('EffectIntensity', $this->InstanceID) === false;
        $this->RegisterVariableInteger('EffectIntensity', 'Effekt-Intensität (Blitze/Leuchtschein)', '~Intensity.100', 203);
        $this->EnableAction('EffectIntensity');
        if ($isNewIntensity) {
            $this->SetValue('EffectIntensity', (int) $this->legacyValue('EffectIntensity', 100));
        }
        // Manuelle Konfiguration (Dietmar, 27.07.2026: volle Parität zu
        // InverterHubTile - die Kachel muss auch OHNE jedes installierte
        // Partnermodul laufen können, rein über manuell zugewiesene
        // Variablen). Wird zusätzlich zur automatischen Discovery ausgewertet,
        // nicht als Ersatz dafür - wer nichts eintraegt, merkt nichts davon.
        $this->RegisterPropertyInteger('ManualPvID', 0);
        $this->RegisterPropertyInteger('ManualGridID', 0);
        $this->RegisterPropertyBoolean('ManualGridInvert', false);
        $this->RegisterPropertyInteger('ManualBatID', 0);
        $this->RegisterPropertyBoolean('ManualBatInvert', false);
        $this->RegisterPropertyInteger('ManualSocID', 0);
        $this->RegisterPropertyInteger('ManualHouseID', 0);
        // Weitere Verbraucher (Dietmar, 27.07.2026: "nicht jeder Haushalt hat
        // dieselben Geräte" - frei editierbare Liste, unabhängig von jedem
        // Hub-Modul, analog InverterHubTiles Consumers-Property).
        $this->RegisterPropertyString('Consumers', '[]');
        // Fahrzeug-Zuordnung fuer Wallboxen (1:1 aus InverterHubTile
        // uebernommen, Verbund-Absprache 29.07.2026: Dietmar wollte bei
        // eingestecktem Auto Name+Ladestand des ERKANNTEN Fahrzeugs sehen,
        // nicht nur "Wallbox aktiv" - die Zuordnung wandert komplett zu uns,
        // InverterHubTile streicht ihre eigene Fassung).
        $this->RegisterPropertyString('Vehicles', '[]');
        $this->RegisterPropertyInteger('MatchToleranceSec', self::DEF_MATCH_TOLERANCE);
        // Eigene Gesundheits-/Diagnose-Berechnung (Dietmar, 29.07.2026: die
        // Anzeige war nach dem Loeschen der InverterHubMonitor-Instanz leer,
        // gleicher Grund wie beim Sankey - Datenquelle war eine Zwischen-
        // Instanz, die er nicht mehr haben wollte). Ertrag-vs-Prognose
        // braucht Einstrahlung + PV-Prognose-Instanz, Riso nur die Schwelle
        // (kein Herstellerdefault ohne Bestaetigung - Tester-Wunsch,
        // uebernommen von InverterHubMonitor).
        $this->RegisterPropertyInteger('IrradianceID', 0);
        $this->RegisterPropertyInteger('PvfInstance', 0);
        $this->RegisterPropertyInteger('RisoWarnKOhm', 0);
        // Strompreis-Quelle fuer die "Kosten heute"-Kennzahl auf den
        // Detailseiten (Dietmar, 28.08.2026: "schau mal welche Module wir
        // haben" - TibberGridReward liefert TIBBERGR_GetPriceCurve()).
        // Leer = automatisch bei genau einer installierten Instanz, analog
        // PvfInstance/IrradianceID.
        $this->RegisterPropertyInteger('TibberInstance', 0);
        // Ersatz-Strompreis fuer Kosten-Kennzahlen OHNE Tibber-Instanz
        // (Dietmar, 28.08.2026: "Quartalsweise den Haushalts-Durchschnitts-
        // preis bei BDEW anfragen und in eine DB eintragen ... das sollte
        // auch das ausgelieferte Modul ganz ohne Dich koennen"). Die BDEW-
        // Strompreisanalyse (bdew.de, vierteljaehrlich Jan/Apr/Jul/Okt) hat
        // KEINE API/PDF-Parsing noetig - die Uebersichtsseite selbst nennt
        // die aktuelle Kennzahl bereits als Klartext-Satz ("... betraegt
        // ... durchschnittlich XX,X ct/kWh"), siehe FetchBdewPrice(). Die
        // "DB" ist ein einfaches Attribut (JSON-Array aus {fetchedAt,
        // priceCtPerKWh}), damit auch nach Jahren noch der zuletzt bekannte
        // Wert vorliegt, selbst wenn ein spaeterer Abruf fehlschlaegt (Seite
        // umgebaut, kein Internet etc.).
        $this->RegisterAttributeString('BdewPriceHistory', '[]');
        $this->RegisterAttributeInteger('BdewLastTry', 0);
        $this->RegisterTimer('NRGDASH_BdewCheck', 0, 'NRGDASH_CheckBdewPrice($_IPS[\'TARGET\']);');
        // Ein-/Ausblenden bereits automatisch gefundener Geraete (Dietmar,
        // 27.07.2026: "man könnte auch durchaus eine Liste anbieten und
        // dann einschalten... oder umgekehrt ausschalten" - passt besser zu
        // unserem Discovery-Modell als erneutes manuelles Eintragen). Nur
        // die Spalten Key+Enabled werden ausgewertet (siehe
        // deviceVisibilityMap()); Geraet/Quelle sind reine Anzeige, werden
        // bei jedem Formular-Oeffnen frisch aus dem Discovery-Cache befuellt
        // (Store-Review-Regel: berechnete Anzeigespalten nicht zurueckschreiben,
        // "loadValuesFromConfiguration": false in form.json).
        $this->RegisterPropertyString('DeviceVisibility', '[]');
        // Vorfuehr-/Demomodus (01.09.2026, Dietmar: "EMS Modulvorstellung" -
        // eine zusaetzliche Instanz zur Praesentation des ganzen Verbunds,
        // "sie duerfen keine Auswirkungen auf das System haben"). Schaltet
        // die Wallbox-Steuerung (Start/Stopp/Freigabe/Limit,
        // ChargerControlInfo()) instanzweise hart ab, statt sie nur zu
        // verstecken - kuenftige Steuerbefehle muessen hier NICHT einzeln
        // ergaenzt werden, sie laufen alle ueber denselben ChargerControlInfo()-
        // Rueckgabewert.
        $this->RegisterPropertyBoolean('DemoMode', false);
        // Isolierter Demo-Modus (03.09.2026) - eigene Property, bewusst
        // getrennt von DemoMode (das blendet nur Steuer-Buttons aus, lässt
        // aber echte Live-Werte durch). Bei aktivem Schalter überspringt
        // Discover() JEDE automatische Geräte-Erkennung; Netz/PV/Batterie/
        // Haus kommen dann ausschließlich aus den manuellen Kern-Feldern.
        $this->RegisterPropertyBoolean('DemoIsolated', false);
        // Automatische Vorfuehrung (03.09.2026, Dietmar: "Es sollten auch
        // Detailseiten in der Demo eingeblendet werden, damit ein Interessent
        // vollumfaenglich sieht was ihn erwarten kann"): die Kachel oeffnet
        // bei Inaktivitaet von selbst Detailseiten und schachtelt Sammel-
        // knoten auf/zu. Rein clientseitig (module.html), pausiert bei jeder
        // Nutzerinteraktion. Nur fuer Vorstellungs-Instanzen gedacht.
        $this->RegisterPropertyBoolean('DemoAutoTour', false);
        $this->RegisterTimer('NRGDASH_Refresh', 0, 'NRGDASH_Discover($_IPS[\'TARGET\']);');
        // Deklariert die Instanz als HTML-SDK-Kachel (GetVisualizationTile()
        // liefert den Inhalt). Ohne diesen Aufruf bindet WebFront die
        // Visualisierung nicht - die Kachel bleibt leer, unabhaengig von
        // Browser/Cache (Muster: InverterHubTile::Create()/ApplyChanges()).
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('NRGDASH_Refresh', 5 * 60 * 1000);
        // Taeglich pruefen, ob ein neuer BDEW-Quartalswert faellig ist (die
        // Pruefung selbst ist billig, der eigentliche HTTP-Abruf laeuft nur
        // gedrosselt - siehe CheckBdewPrice()).
        $this->SetTimerInterval('NRGDASH_BdewCheck', 24 * 60 * 60 * 1000);
        $this->SetVisualizationType(1);
        // Standalone-Webseite fuer IPSView/Browser (WebView/Popup) - Muster
        // Prognoses Energiebilanz-Modul (Dietmar, 27.08.2026: "alle Kacheln
        // ... auch so vorbereiten, dass man sie in IPSView einbinden kann").
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->RegisterHook('/hook/nrgdashtile' . $this->InstanceID);
        } else {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        }
        // Baseline-Status auch VOR dem ersten Discover()-Lauf sichtbar setzen -
        // sonst zeigt die Instanz bis zum ersten Timer-Tick keinen definierten
        // Zustand (Verbund-Konvention: Zustand sichtbar melden, nicht nur im Log).
        $this->SetStatus(102);
        // Sofortiger Discover()-Lauf statt auf den ersten 5-Minuten-Timer zu
        // warten (Fehlerbild 27.07.2026: nach einem Symcon-Neustart zeigte die
        // Kachel bis zu 5 Minuten lang veraltete Werte, weil RegisterMessage-
        // Abos den Neustart nicht ueberleben und ApplyChanges() bisher nur den
        // Timer stellte, ohne selbst neu zu abonnieren/zu rendern - IPS ruft
        // ApplyChanges() bei JEDEM Kernel-Start fuer jede Instanz auf, das ist
        // also der richtige Ort dafuer, nicht nur der Timer).
        $this->Discover();
        $this->CheckBdewPrice();
    }

    /**
     * "Stil zurücksetzen" (Muster: InverterHubTile::ResetStyle()). Setzt NUR
     * die Feldwerte der geöffneten Maske zurück, kein IPS_SetProperty +
     * IPS_ApplyChanges - Store-Review-Regel: ein Formular-Button darf nie
     * selbst persistieren, sonst hätte ein Fehlklick sofortige Wirkung statt
     * erst mit "Übernehmen" bestätigt zu werden.
     */
    public function ResetStyle(): void
    {
        $this->UpdateFormField('ColorBackground', 'value', self::DEF_BACKGROUND);
        $this->UpdateFormField('FontFamily', 'value', self::DEF_FONT);
        $this->UpdateFormField('TransitionMs', 'value', self::DEF_TRANSITION);
        $this->UpdateFormField('FlowRefW', 'value', self::DEF_FLOWREF);
        // Seit 28.08.2026 echte Variablen statt Formularfeld (Doppelpfeil),
        // deshalb SetValue() statt UpdateFormField() - ueber den
        // Konsolen-Button weiterhin erreichbar.
        $this->SetValue('HideInactive', false);
        $this->SetValue('CoupleBoltPower', true);
        $this->SetValue('CoupleGlowPower', true);
        $this->SetValue('EffectIntensity', 100);
        $this->Render();
    }

    private function legacyValue(string $name, $default)
    {
        if ($this->legacyConfigCache === null) {
            $cfg = json_decode(IPS_GetConfiguration($this->InstanceID), true);
            $this->legacyConfigCache = is_array($cfg) ? $cfg : [];
        }
        return array_key_exists($name, $this->legacyConfigCache) ? $this->legacyConfigCache[$name] : $default;
    }

    private function Render(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->buildPayload()));
    }

    /**
     * WebFront-Bedienung der Doppelpfeil-Variablen (siehe Create()) - Wert
     * setzen, Kachel neu rendern. Muster: Prognose/Energiebilanz.
     */
    public function RequestAction($Ident, $Value)
    {
        $boolIdents = ['HideInactive', 'CoupleBoltPower', 'CoupleGlowPower'];
        if (in_array($Ident, $boolIdents, true)) {
            $this->SetValue($Ident, (bool) $Value);
            $this->Render();
            return;
        }
        if ($Ident === 'EffectIntensity') {
            $this->SetValue($Ident, max(50, min(150, (int) $Value)));
            $this->Render();
        }
    }

    /**
     * Fuegt das "Was ist Neu"-Panel (versionsscharf dismissible) und den
     * Forum/GitHub-Hinweis (einmalig dismissible) um die statische form.json
     * herum ein, traegt die Versionsnummer ins Doku-Panel ein - exakte
     * Struktur wie InverterHubTile (Muster fuer den ganzen Verbund).
     */
    public function GetConfigurationForm()
    {
        $raw = str_replace('%%HOOK%%', '/hook/nrgdashtile' . $this->InstanceID, file_get_contents(__DIR__ . '/form.json'));
        $form = json_decode($raw, true);
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }

        $this->injectVersionIntoDocPanel($form);
        $this->injectDeviceToggleValues($form);
        $this->injectDiscoveryResultLabel($form);

        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        if (!@$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'RowLayout',
                'name' => 'ReviewHint',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 NRGDashboard ist Beta — Rückmeldungen sind willkommen:'],
                    ['type' => 'Label', 'link' => true, 'caption' => self::GITHUB_URL],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'NRGDASH_DismissReviewHint($id);'],
                ],
            ];
        }

        return json_encode($form);
    }

    private function injectVersionIntoDocPanel(array &$form): void
    {
        $lib = @IPS_GetLibrary('{8D4E7A2C-1F6B-4C93-A5D8-3E9F1B6C7D02}');
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ NRGDashboard Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ NRGDashboard';
        foreach ($form['elements'] as &$el) {
            if (($el['type'] ?? '') === 'ExpansionPanel' && str_contains($el['caption'] ?? '', 'Dokumentation')) {
                array_unshift($el['items'], ['type' => 'Label', 'caption' => $verTxt]);
                return;
            }
        }
        unset($el);
    }

    /**
     * Einheitliche Verbund-Status-Kopfzeile (SUITE.md "Einheitliche
     * Verbund-Status-Kopfzeile", 20.08.2026, Referenz EMS::
     * getDiscoverySummaryLine()) - EINE Zeile Icon+Zahl+Zeitstempel, kein
     * Aufzaehlungssatz. Nutzt den persistierten Stand (DeviceCache/
     * LastDiscoveryTs), damit ein erneutes OEFFNEN des Formulars den
     * letzten Suchlauf zeigt, nicht nur ein frischer Klick auf den Button.
     */
    private function getDiscoverySummaryLine(): string
    {
        $ts = $this->ReadAttributeInteger('LastDiscoveryTs');
        if ($ts === 0) {
            return 'ℹ️ Noch nicht gesucht — Button oben drücken.';
        }
        $devices = json_decode($this->ReadAttributeString('DeviceCache'), true) ?: [];
        $count = count($devices);
        $icon = $count > 0 ? '✅' : '⚠️';
        return sprintf('%s %d Geräte gefunden (zuletzt %s Uhr).', $icon, $count, date('H:i:s', $ts));
    }

    private function injectDiscoveryResultLabel(array &$form): void
    {
        foreach ($form['elements'] as &$el) {
            if (($el['name'] ?? '') === 'DiscoveryResult') {
                $el['caption'] = $this->getDiscoverySummaryLine();
                return;
            }
        }
        unset($el);
    }

    /**
     * Stabiler Schluessel je Geraet ueber Discovery-Laeufe hinweg (fuer die
     * Zuordnung "war diese Zeile schon mal da, was hatte der Nutzer
     * eingestellt"). Bewusst NICHT die powerID - die kann sich bei manchen
     * Quellen aendern, Quelle+Instanz+Rolle+Bezeichnung ist stabiler.
     */
    /**
     * Normalisiert unterschiedliche function-Bezeichnungen fuer dieselbe
     * Geraeteart auf einen gemeinsamen Schluessel (Muster: CONSUMER_TYPE_MAP
     * in module.html, hier fuer die Dubletten-Erkennung zwischen Hub-Quellen
     * und InverterHubTiles Uebergangs-Consumers gebraucht). ChargerHub liefert
     * z.B. 'charger', ein manueller Tile-Eintrag 'wallbox' - beides dieselbe
     * Kategorie.
     */
    private function normalizeDeviceCategory(string $function): string
    {
        $map = [
            'charger' => 'wallbox', 'vehicle' => 'wallbox',
            'wallbox1' => 'wallbox', 'wallbox2' => 'wallbox', 'wallbox3' => 'wallbox',
            'wallbox4' => 'wallbox', 'wallbox5' => 'wallbox',
            'hotwater' => 'boiler', 'aircon' => 'ac', 'ventilation' => 'vent', 'pool' => 'poolpump',
        ];
        return $map[$function] ?? $function;
    }

    private function deviceKey(array $d): string
    {
        return ($d['source'] ?? '') . '|' . ($d['instanceID'] ?? 0) . '|' . ($d['function'] ?? '') . '|' . ($d['label'] ?? '');
    }

    /**
     * Liest die gespeicherte Sichtbarkeits-Liste (Key->Enabled). Fehlt ein
     * Schluessel (neues Geraet, erste Suche), gilt konservativ sichtbar=true
     * - Ausblenden ist eine bewusste Nutzerentscheidung, kein Vorgabezustand.
     */
    /**
     * Liest die gespeicherten Nutzer-Overrides je Geraet (Key -> Enabled +
     * optionaler Name). "Name" leer = Vorgabe-Bezeichnung der Quelle
     * verwenden (Muster: InverterHubTiles Consumers-Liste, "Bezeichnung
     * leer = Vorgabe der Art").
     */
    private function deviceOverrideMap(): array
    {
        $rows = json_decode($this->readStringProperty('DeviceVisibility', '[]'), true);
        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['Key'])) {
                    $map[$row['Key']] = [
                        'enabled' => !empty($row['Enabled']),
                        'name'    => trim((string) ($row['Name'] ?? '')),
                    ];
                }
            }
        }
        return $map;
    }

    private function deviceVisibilityMap(): array
    {
        $map = [];
        foreach ($this->deviceOverrideMap() as $key => $o) {
            $map[$key] = $o['enabled'];
        }
        return $map;
    }

    /**
     * Befuellt die "Automatisch gefundene Geräte"-Liste im Formular frisch
     * aus dem letzten Discovery-Stand (Muster: Tessie VisibleVars) - Rolle/
     * ID/Quelle sind reine Anzeige und werden NIE aus der Property gelesen
     * (Store-Review-Regel), nur "Enabled"/"Name" werden aus der vorherigen
     * Einstellung uebernommen, gematcht ueber deviceKey().
     */
    private function injectDeviceToggleValues(array &$form): void
    {
        $overrides = $this->deviceOverrideMap();
        $rows = [];
        foreach ($this->GetDevices() as $d) {
            $key = $this->deviceKey($d);
            $o = $overrides[$key] ?? ['enabled' => true, 'name' => ''];
            $instanceID = (int) ($d['instanceID'] ?? 0);
            $rows[] = [
                'Key'      => $key,
                'Enabled'  => $o['enabled'],
                'Name'     => $o['name'],
                'Rolle'    => $d['function'] ?? '?',
                'ID'       => (int) ($d['powerID'] ?? 0),
                'Quelle'   => $d['source'] ?? '?',
                // Instanzbezeichnung (z. B. "InverterHub WR1 (GoodWe)") - bei
                // mehreren gleichartigen Partnerinstanzen (z. B. 2 MeterHub-
                // Zaehler mit Rolle "grid") sonst nicht unterscheidbar, welche
                // Zeile zu welchem physischen Geraet gehoert.
                'Instanz'  => $instanceID > 0 && @IPS_InstanceExists($instanceID)
                    ? IPS_GetName($instanceID)
                    : '',
            ];
        }
        $walk = function (array &$elements) use (&$walk, $rows) {
            foreach ($elements as &$el) {
                if (!is_array($el)) {
                    continue;
                }
                if (($el['name'] ?? '') === 'DeviceVisibility') {
                    $el['values'] = $rows;
                }
                if (isset($el['items']) && is_array($el['items'])) {
                    $walk($el['items']);
                }
            }
            unset($el);
        };
        $walk($form['elements']);
    }

    /**
     * "Was ist Neu"-Banner: erscheint nach einem Update (Attribut startet
     * leer), bis der Nutzer "Verstanden" klickt - taucht bei jeder neuen
     * NEWS_VERSION mit Eintrag automatisch wieder auf.
     */
    private function newsBanner(): ?array
    {
        if (@$this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 Neu in diesem Modul — bitte kurz ansehen und ggf. die Einstellungen prüfen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'NRGDASH_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    /** Konsolen-Gegenstueck zur WebFront-Dismiss-Tour - fuer den Fall, dass
     *  ein Nutzer sich die Feinheiten nochmal zeigen lassen will. */
    public function ResetTour(): void
    {
        $this->WriteAttributeBoolean('TourSeen', false);
    }

    public function DismissReviewHint(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
    }

    /**
     * Durchsucht alle installierten Partnerinstanzen und normalisiert deren
     * *_GetFunctions-Verträge auf ein gemeinsames Geräte-Schema. Ergebnis
     * wird gecacht (Attribut DeviceCache), damit die Kachel nicht bei jedem
     * Rendern erneut alle Instanzen abfragen muss.
     */
    public function Discover(): array
    {
        $devices = [];

        // Isolierter Demo-Modus (03.09.2026, Dietmar: "die Summe aller
        // Ströme sind natürlich nicht logisch") - eine reine Vorstellungs-
        // Instanz braucht ein in sich stimmiges Bild, keine Mischung aus
        // echten Live-Messwerten und erfundenen Verbrauchern. Bei aktivem
        // Schalter wird JEDE automatische Geraete-Erkennung uebersprungen
        // (kein Zugriff auf reale Hub-Module) - Netz/PV/Batterie/Haus kommen
        // dann ausschliesslich aus den ohnehin vorhandenen manuellen
        // Kern-Feldern (discoverManualCore(), fuer Haushalte ganz ohne
        // Hub-Modul gedacht) weiter unten; Rest von Discover() (Caching,
        // Live-Abonnements, Status) laeuft unveraendert weiter.
        $demoIsolated = $this->readBoolProperty('DemoIsolated', false);

        // Jede Quelle einzeln einsammeln UND sofort auf stille Vertragsbrueche
        // pruefen (checkSourceCoverage) - Verbund-Zielbild "Zuverlaessigkeit
        // ohne KI-Krücke" (SUITE.md, 27.07.2026): genau das haette den realen
        // Fall vom 27.07.2026 (IHUB_GetFunctions liefert ein Objekt statt der
        // erwarteten Liste, PV/Batterie/Netz fielen dadurch still raus)
        // automatisch im Log gemeldet, statt erst durch eine Live-Sitzung beim
        // Nutzer aufzufallen. Ein Endnutzer hat keine Sitzung, die das nachtraeglich
        // repariert.
        if (!$demoIsolated) {
        $inverterHub = $this->discoverInverterHub();
        $this->checkSourceCoverage('InverterHub', NRGDASH_GUID_INVERTERHUB, count($inverterHub));
        $devices = array_merge($devices, $inverterHub);

        // Architektur-Korrektur (Dietmar, 27.07.2026): InverterHubTile ist
        // eine ABLOESENDE Kachel, kein Dauerbestandteil des Verbunds - sie
        // "wird verschwinden, weil wir keine 2 gleichen Panels brauchen".
        // IHUBTILE_GetConsumers/GetHouseLoad duerfen deshalb KEINE dauerhafte
        // Abhaengigkeit sein, nur ein Uebergangs-Lueckenfueller fuer das, was
        // sonst nirgends herkommt. Die permanenten Hub-Module (MeterHub,
        // ChargerHub, HeishaMon) laufen deshalb jetzt IMMER direkt, nicht nur
        // als Rueckfall - wer InverterHubTile spaeter loescht, verliert
        // dadurch nichts, was ueber ein eigenes Hub-Modul verfuegbar ist.
        $meterHub = $this->discoverListContract(NRGDASH_GUID_METERHUB, 'MHUB_GetFunctions', 'meterhub');
        $this->checkSourceCoverage('MeterHub', NRGDASH_GUID_METERHUB, count($meterHub));
        $devices = array_merge($devices, $meterHub);

        $devices = array_merge($devices, $this->discoverListContract(
            NRGDASH_GUID_METERHUBV, 'MHUBV_GetFunctions', 'meterhub'
        ));

        $heishaMon = $this->discoverHeishaMon();
        $this->checkSourceCoverage('HeishaMon', NRGDASH_GUID_HEISHAMON, count($heishaMon));
        $devices = array_merge($devices, $heishaMon);

        $chargerHub = $this->discoverListContract(NRGDASH_GUID_CHARGERHUB, 'CHUB_GetFunctions', 'chargerhub');
        $this->checkSourceCoverage('ChargerHub', NRGDASH_GUID_CHARGERHUB, count($chargerHub));
        $devices = array_merge($devices, $chargerHub);

        // OCPPHub (30.08.2026) - Geschwistermodul zu ChargerHub (OCPP statt
        // Modbus), feldgleicher Vertrag (contractVersion 1.1: liefert die
        // eigene Ladepunkt-instanceID je Eintrag mit, NICHT die des
        // aufrufenden Splitters - siehe normalizeEntry()).
        $ocppHub = $this->discoverListContract(NRGDASH_GUID_OCPPHUB, 'OHUB_GetFunctions', 'ocpphub');
        $this->checkSourceCoverage('OCPPHub', NRGDASH_GUID_OCPPHUB, count($ocppHub));
        $devices = array_merge($devices, $ocppHub);

        // IHUBTILE_GetConsumers NUR noch fuer Eintraege, die durch KEINE der
        // permanenten Quellen oben abgedeckt sind (z.B. eine manuell in der
        // Kachel eingetragene Klimaanlage ohne eigenes Hub-Modul). Abgleich
        // NICHT per Label (unzuverlaessig - z.B. traegt HeishaMon selbst
        // "HeishaMon" als Label ein, waehrend Dietmars manueller Eintrag in
        // InverterHubTile "Wärmepumpe" heisst - beide function=heatpump,
        // unterschiedliches Label, live als Dublette aufgefallen). Stattdessen:
        // ein Tile-Eintrag gilt als bereits abgedeckt, wenn ein Geraet
        // DERSELBEN normalisierten Kategorie schon von einer echten Hub-Quelle
        // (MeterHub/ChargerHub/HeishaMon) kommt - unabhaengig vom Label und
        // unabhaengig davon, ob der function-Wert wortgleich ist (charger vs.
        // wallbox meinen dieselbe Geraeteart).
        $tileConsumers = $this->discoverInverterHubTileConsumers();
        $realHubSources = ['meterhub', 'heishamon', 'chargerhub'];
        $coveredCategories = [];
        foreach ($devices as $d) {
            if (in_array($d['source'] ?? '', $realHubSources, true)) {
                $coveredCategories[] = $this->normalizeDeviceCategory($d['function'] ?? '');
            }
        }
        foreach ($tileConsumers as $entry) {
            if (!in_array($this->normalizeDeviceCategory($entry['function'] ?? ''), $coveredCategories, true)) {
                $devices[] = $entry;
            }
        }

        $devices = array_merge($devices, $this->discoverTessie());
        } // !$demoIsolated

        // Manuelle Konfiguration IMMER zusaetzlich auswerten (kein Hub-Modul
        // vorausgesetzt) - fuer Haushalte ganz ohne InverterHub/MeterHub/etc.
        $devices = array_merge($devices, $this->discoverManualCore());
        $devices = array_merge($devices, $this->discoverManualConsumers());

        // Echter Hauslast-Zaehler: eigene manuelle Konfiguration hat Vorrang;
        // IHUBTILE_GetHouseLoad ist nur der Uebergangs-Lueckenfueller, solange
        // Dietmar den echten Zaehler noch nicht selbst in "Manuelle
        // Datenpunkte" > "Echter Hauslastzähler" eingetragen hat.
        $hasManualHouse = (bool) array_filter($devices, function (array $d) {
            return ($d['function'] ?? '') === 'house';
        });
        if (!$hasManualHouse && !$demoIsolated) {
            $devices = array_merge($devices, $this->discoverInverterHubTileHouseLoad());
        }

        // Generische Redundanz-Erkennung ueber ALLE bis hierhin gefundenen
        // Geraete (nicht nur den urspruenglichen Waermepumpen-Einzelfall) -
        // laeuft bei jedem Discover(), also alle 5 Minuten neu, nicht nur
        // beim ersten Scan.
        $devices = $this->mergeRedundantSources($devices);

        // Aufschachteln (03.09.2026): Mitglieder eines Sammelzaehlers auf EINE
        // Normalform bringen (MeterHubVirtual-Vertrag 1.3 'members' bzw.
        // verschachtelte 'Members' manueller Verbraucher) und je Mitglied
        // vermerken, ob es selbst wieder Mitglieder hat - die Kachel zeigt
        // dafuer ein Zaehler-Badge und erlaubt den Klick in die naechste Ebene.
        $devices = array_map(function (array $d) { return $this->attachMembers($d); }, $devices);

        $diagnostics = $this->discoverDiagnostics();

        $this->WriteAttributeString('DeviceCache', json_encode($devices));
        $this->WriteAttributeString('DiagnosticsCache', json_encode($diagnostics));
        $this->WriteAttributeInteger('LastDiscoveryTs', time());
        $this->SetStatus(102);
        $this->LogMessage(
            sprintf('NRG Dashboard: %d Geräte, %d Diagnose-Einträge gefunden', count($devices), count($diagnostics)),
            KL_MESSAGE
        );
        // Sichtbare Rueckmeldung im Konfigurationsformular - vorher lief der
        // "Geraete jetzt suchen"-Button komplett ins Leere (nur Log-Eintrag,
        // keine Rueckmeldung im Formular selbst). UpdateFormField ist ein
        // No-Op, wenn kein Formular gerade offen ist.
        $this->UpdateFormField('DiscoveryResult', 'caption', $this->getDiscoverySummaryLine());

        // Ereignisgesteuert statt gepollt (Muster: InverterHubTile - RegisterMessage
        // je Quellvariable, sofortiger Push bei jeder Aenderung). Der 5-Minuten-
        // Timer laeuft nur noch fuer Discover() selbst (neue/entfernte Geraete
        // erkennen); Werte werden NICHT mehr auf den naechsten Timer-Tick
        // vertroestet. Vorheriger Fehler: UpdateVisualizationValue() lief NUR
        // hier drin, alle 5 Minuten - Dietmar sah dadurch bis zu 5 Minuten alte
        // Werte und hielt es fuer einen Refresh-Bug (war keiner, aber zurecht
        // bemaengelt: InverterHub aktualisiert instantan, wir nicht).
        $this->subscribeToDeviceVariables($devices);
        $this->UpdateVisualizationValue(json_encode($this->buildPayload()));

        return $devices;
    }

    /**
     * Registriert VM_UPDATE-Nachrichten auf jede powerID/socID der aktuellen
     * Geraeteliste, damit MessageSink() bei jeder Aenderung sofort einen
     * frischen Payload pusht - keine Wartezeit auf den naechsten Discover()-
     * Timer-Tick. Alte Registrierungen werden zuerst vollstaendig entfernt
     * (Discover() laeuft selten genug, dass ein kompletter Neuaufbau billig
     * ist und keine verwaisten Registrierungen zurueckbleiben, z.B. wenn eine
     * Wallbox entfernt wurde).
     */
    private function subscribeToDeviceVariables(array $devices): void
    {
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }
        foreach ($devices as $d) {
            foreach (['powerID', 'socID'] as $field) {
                $vid = $d[$field] ?? 0;
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $this->RegisterMessage($vid, VM_UPDATE);
                }
            }
        }
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        if ($message === IPS_KERNELMESSAGE && isset($data[0]) && $data[0] === KR_READY) {
            $this->ApplyChanges();
            return;
        }
        if ($message === VM_UPDATE) {
            // Guard gegen "InstanceInterface is not available": VM_UPDATE-
            // Nachrichten fuer beobachtete Geraete-Variablen (subscribeToDeviceVariables())
            // koennen noch zugestellt werden, waehrend der Kernel diese eigene
            // Instanz gerade neu laedt (z.B. Modulverwaltung-Update eines
            // Partnermoduls loest einen Kernel-Zyklus aus) - in diesem Fenster
            // ist $this->buildPayload() (ReadAttribute/GetValue) nicht
            // aufrufbar. Gefunden 31.08.2026 (Systemlog-Fund EMS-Sitzung),
            // wiederholt waehrend eines OCPPHub-Modulupdates in der Nacht.
            if (IPS_GetKernelRunlevel() !== KR_READY || !IPS_InstanceExists($this->InstanceID)) {
                return;
            }
            try {
                $this->UpdateVisualizationValue(json_encode($this->buildPayload()));
            } catch (\Throwable $e) {
                // Kein Absturz der Instanz durch ein einzelnes verpasstes
                // Update - der naechste VM_UPDATE oder Discover()-Tick holt
                // den aktuellen Stand ohnehin nach.
            }
        }
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($this->buildPayload()) . ');</script>';
        return $html;
    }

    /**
     * Fuehrt einen Steuerbefehl an einem Partnermodul (ChargerHub/OCPPHub)
     * ab und faengt sowohl echte Exceptions als auch jede unerwartet
     * ausgegebene Textausgabe (PHP-Warning/Notice, die per try/catch NICHT
     * abgefangen wuerde) auf - beides koennte sonst unsere JSON-Antwort im
     * Hook korrumpieren (Fund 31.08.2026, OCPPHubLadepunkt::RemoteStart()-
     * ArgumentCountError haette ohne diesen Schutz die Antwort verstuemmelt).
     * Rueckgabe: null bei Erfolg, sonst Fehlertext fuer die JSON-Antwort.
     */
    /**
     * Loest zu einer RequestAction-gebundenen Variablen-ID das Paar
     * [InstanzID, Ident] auf, das IPS_RequestAction() tatsaechlich braucht.
     * KORRIGIERT 31.08.2026 (OCPPHub-Befund): IPS_RequestAction() hat KEINE
     * Variablen-ID+Wert-Form - der Kernel-Einstiegspunkt ist immer
     * IPS_RequestAction($InstanceID, $Ident, $Value), siehe der gleiche Fund
     * bei ChargerHub/InverterHub (25.07.2026, EMS::setGoodweMode()-
     * Kommentar). Unser bisheriger 2-Parameter-Aufruf war schlicht falsch.
     */
    private function resolveActionTarget(int $vid): ?array
    {
        $obj = IPS_GetObject($vid);
        $ident = (string) ($obj['ObjectIdent'] ?? '');
        $parentID = (int) ($obj['ParentID'] ?? 0);
        if ($ident === '' || $parentID <= 0) {
            return null;
        }
        return [$parentID, $ident];
    }

    private function runPartnerCall(callable $fn): ?string
    {
        ob_start();
        try {
            $fn();
        } catch (\Throwable $e) {
            ob_end_clean();
            return $e->getMessage();
        }
        $stray = ob_get_clean();
        if ($stray !== '') {
            IPS_LogMessage('NRGDashboardTile', 'Unerwartete Ausgabe bei Wallbox-Steuerbefehl: ' . substr($stray, 0, 500));
            return 'Unerwartete Antwort vom Partnermodul (siehe IPS-Systemlog).';
        }
        return null;
    }

    /**
     * Liefert die Kachel als eigenstaendige Webseite (fuer IPSView-WebView/
     * Popup oder jeden Browser). Aufruf: /hook/nrgdashtile<InstanzID>.
     * Mit ?json=1 werden nur die Daten geliefert (fuer die Auto-Aktualisierung).
     * 1:1 Muster Prognoses Energiebilanz::ProcessHookData().
     */
    public function ProcessHookData()
    {
        // Geraete-Detailseite (Klick auf einen Knoten der Energiefluss-
        // Kachel): bildschirmfuellende Ansicht mit allen Vertragsfeldern
        // des Geraets + Leistungs-/Energie-Diagrammen. Bewusst ueber den
        // WebHook statt in der Kachel selbst - die Kachel kann im Grid zu
        // klein fuer Diagramme sein (Dietmar, 28.08.2026).
        // Einfuehrungs-Tour bestaetigt (28.08.2026) - vom Tour-Overlay in
        // module.html per fetch() aufgerufen, siehe Create().
        if (isset($_GET['dismissTour'])) {
            $this->WriteAttributeBoolean('TourSeen', true);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            return;
        }
        // Wallbox-Steuerung von der Detailseite aus (30.08.2026) - die
        // Detailseite laeuft in einem eigenen iframe/Kontext ohne direkten
        // PHP-Rueckkanal, deshalb ueber denselben WebHook wie dismissTour.
        // Zielinstanz kommt IMMER vom Aufrufer mit (detail.html kennt sie
        // aus dem 'control'-Feld ihres eigenen Payloads) - wir vertrauen
        // ihr hier NICHT blind: FindDeviceByKey() muss dieselbe instanceID
        // fuer den mitgesendeten Geraete-Key bestaetigen, sonst koennte
        // jeder beliebige Aufrufer eine fremde Instanz-ID unterschieben.
        if (isset($_GET['wallboxAction'])) {
            header('Content-Type: application/json; charset=utf-8');
            // Demomodus-Verteidigung (01.09.2026) - ChargerControlInfo()
            // liefert im Demomodus zwar kein 'control'-Feld mehr, die
            // Detailseite zeigt also keine Buttons an, aber eine bereits
            // im Browser offene/gecachte Seite koennte den Request trotzdem
            // absetzen. Serverseitig zusaetzlich hart blockieren, damit
            // "keine Auswirkungen auf das System" (Dietmar) auch dann gilt.
            if ($this->readBoolProperty('DemoMode', false)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Steuerung im Vorführmodus deaktiviert.']);
                return;
            }
            $key = (string) ($_GET['key'] ?? '');
            $d = $this->FindDeviceByKey($key);
            $instanceID = (int) ($d['instanceID'] ?? 0);
            $expected = (int) ($_GET['instanceId'] ?? 0);
            if ($d === null || $instanceID <= 0 || $instanceID !== $expected || ($d['function'] ?? '') !== 'charger' || !empty($d['externallyManaged'])) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Ungültiges oder nicht steuerbares Gerät.']);
                return;
            }
            // OCPPHub-eigener Vorfuehrmodus (01.09.2026) - OCPPHub lehnt den
            // Befehl ohnehin an der eigenen Absendestelle ab (unser
            // runPartnerCall() faengt das sauber ab), hier nur fuer eine
            // klare Fehlermeldung statt eines generischen Partnermodul-Fehlers.
            if (($d['transport'] ?? '') === 'ocpp' && function_exists('OHUB_IsDemoMode')) {
                $splitterId = (int) @IPS_GetProperty($instanceID, 'SplitterID');
                if ($splitterId > 0 && @OHUB_IsDemoMode($splitterId)) {
                    http_response_code(403);
                    echo json_encode(['ok' => false, 'error' => 'Steuerung im Vorführmodus deaktiviert (OCPPHub).']);
                    return;
                }
            }
            $action = (string) $_GET['wallboxAction'];
            // Transportneutral, ueber die vom Vertrag gelieferte Variable
            // (chargeEnableID/currentLimitID) - wirkt gleichermassen bei
            // ChargerHub und OCPPHub, ohne dass wir hier deren jeweiliges
            // Praefix kennen muessen. IPS_RequestAction() braucht InstanzID+
            // Ident, nicht die VariablenID - resolveActionTarget() loest das
            // aus dem Variablenobjekt auf (siehe deren Docblock).
            if ($action === 'setEnable') {
                $vid = (int) ($d['chargeEnableID'] ?? 0);
                if ($vid <= 0 || !IPS_VariableExists($vid) || (IPS_GetVariable($vid)['VariableAction'] ?? 0) <= 0) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Ladefreigabe an diesem Gerät nicht steuerbar.']);
                    return;
                }
                $target = $this->resolveActionTarget($vid);
                if ($target === null) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Ladefreigabe an diesem Gerät nicht steuerbar (kein Ident ermittelbar).']);
                    return;
                }
                $err = $this->runPartnerCall(function () use ($target) {
                    IPS_RequestAction($target[0], $target[1], ($_GET['active'] ?? '1') === '1');
                });
            } elseif ($action === 'setCurrent') {
                $vid = (int) ($d['currentLimitID'] ?? 0);
                if ($vid <= 0 || !IPS_VariableExists($vid) || (IPS_GetVariable($vid)['VariableAction'] ?? 0) <= 0) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Stromlimit an diesem Gerät nicht steuerbar.']);
                    return;
                }
                $target = $this->resolveActionTarget($vid);
                if ($target === null) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Stromlimit an diesem Gerät nicht steuerbar (kein Ident ermittelbar).']);
                    return;
                }
                $min = (int) ($d['minCurrent'] ?? 6);
                $max = (int) ($d['maxCurrent'] ?? 32);
                $amps = max($min, min($max, (int) ($_GET['amps'] ?? $min)));
                $err = $this->runPartnerCall(function () use ($target, $amps) {
                    IPS_RequestAction($target[0], $target[1], $amps);
                });
            } elseif ($action === 'start' && function_exists('OHUBL_ManualStart')) {
                $err = $this->runPartnerCall(function () use ($instanceID) {
                    OHUBL_ManualStart($instanceID, 0);
                });
            } elseif ($action === 'stop' && function_exists('OHUBL_ManualStop')) {
                $err = $this->runPartnerCall(function () use ($instanceID) {
                    OHUBL_ManualStop($instanceID);
                });
            } elseif ($action === 'override' && function_exists('OHUBL_SetDailyOverride')) {
                $err = $this->runPartnerCall(function () use ($instanceID) {
                    OHUBL_SetDailyOverride($instanceID, ($_GET['active'] ?? '1') === '1');
                });
            } else {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Unbekannte oder nicht verfügbare Aktion.']);
                return;
            }
            if ($err !== null) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Aktion am Partnermodul fehlgeschlagen: ' . $err]);
                return;
            }
            echo json_encode(['ok' => true]);
            return;
        }
        // Aufschachteln (03.09.2026): Mitglieder eines Sammelknotens fuer die
        // naechste Ebene - Schluessel ist ein Top-Level-Geraet ODER selbst
        // schon ein Mitglied (<Key>'>'<Index>...), beliebig tief.
        // Schaltgruppen (MeterHub-Vertrag 1.4, 03.09.2026): switchID ist eine
        // ganz normale, per EnableAction() RequestAction-gebundene Bool-
        // Variable - derselbe transportneutrale Weg wie chargeEnableID
        // (resolveActionTarget() liest Parent-Instanz+Ident direkt aus dem
        // Variablenobjekt, kein Partnermodul-Wissen noetig). Funktioniert
        // identisch fuer Top-Level-Geraete UND Mitglieder jeder Tiefe, weil
        // beide ueber FindDeviceByKey()/'switchID' aufgeloest werden.
        if (isset($_GET['switchAction'])) {
            header('Content-Type: application/json; charset=utf-8');
            if ($this->readBoolProperty('DemoMode', false)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Steuerung im Vorführmodus deaktiviert.']);
                return;
            }
            $key = (string) ($_GET['key'] ?? '');
            $d = $this->FindDeviceByKey($key);
            $vid = (int) ($d['switchID'] ?? 0);
            if ($d === null || $vid <= 0 || !IPS_VariableExists($vid) || (IPS_GetVariable($vid)['VariableAction'] ?? 0) <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Ungültiges oder nicht schaltbares Gerät.']);
                return;
            }
            $target = $this->resolveActionTarget($vid);
            if ($target === null) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Ziel-Instanz konnte nicht ermittelt werden.']);
                return;
            }
            $value = ($_GET['value'] ?? '1') === '1';
            $err = $this->runPartnerCall(function () use ($target, $value) {
                IPS_RequestAction($target[0], $target[1], $value);
            });
            if ($err !== null) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Schalten fehlgeschlagen: ' . $err]);
                return;
            }
            echo json_encode(['ok' => true]);
            return;
        }
        if (isset($_GET['members'])) {
            header('Content-Type: application/json; charset=utf-8');
            $key = (string) $_GET['members'];
            $parent = $this->FindDeviceByKey($key);
            echo json_encode([
                'ok'      => $parent !== null,
                'key'     => $key,
                'label'   => (string) ($parent['label'] ?? ''),
                'value'   => $parent !== null ? $this->resolvePowerValue($parent) : null,
                'members' => $this->MembersForKey($key),
            ]);
            return;
        }
        if (isset($_GET['detail'])) {
            $key = (string) $_GET['detail'];
            $day = isset($_GET['day']) ? (string) $_GET['day'] : '';
            if (isset($_GET['json'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($this->BuildDetailPayload($key, $day));
                return;
            }
            header('Content-Type: text/html; charset=utf-8');
            $html = file_get_contents(__DIR__ . '/detail.html');
            echo str_replace('/*%%PAYLOAD%%*/', 'handleDetail(' . json_encode($this->BuildDetailPayload($key, $day)) . ');', $html);
            return;
        }
        if (isset($_GET['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->buildPayload());
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($this->buildPayload()) . ');'
               . 'setInterval(function(){fetch(window.location.pathname+"?json=1")'
               . '.then(function(r){return r.text();}).then(function(t){handleMessage(t);})'
               . '.catch(function(){});},30000);</script>';
        echo $html;
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
     * Baut die Nutzlast fuer die Kachel (Phase 2: Energiefluss-Diagramm).
     * Ergaenzt jedes Geraet um seinen aktuellen Leistungswert (aufgeloest
     * aus powerID) - der Discovery-Cache selbst speichert nur die Referenz,
     * damit ein Cache-Alter die Werte nie veralten laesst (Discover() laeuft
     * nur alle 5 Minuten, Werte werden bei jedem Rendern/UpdateVisualizationValue
     * frisch gelesen).
     */
    private function buildPayload(): array
    {
        // Key VOR jeder Umbenennung berechnen (deviceKey() nutzt das Label -
        // wird der Anzeigename unten ueberschrieben, muss der Abgleich mit der
        // gespeicherten Einstellung trotzdem auf der URSPRUENGLICHEN,
        // discovery-stabilen Bezeichnung beruhen, sonst geht der Bezug beim
        // naechsten Rendern sofort wieder verloren).
        $overrides = $this->deviceOverrideMap();
        $devices = array_map(function (array $d) use ($overrides) {
            $key = $this->deviceKey($d);
            $o = $overrides[$key] ?? null;
            $d['_visible'] = $o['enabled'] ?? true;
            // Stabiler Schluessel fuer die Klick-Detailansicht (Knoten ->
            // /hook/...?detail=<key>) - bewusst der discovery-stabile
            // deviceKey() VOR jeder Umbenennung, damit der Link auch nach
            // einer Nutzer-Umbenennung dasselbe Geraet trifft.
            $d['detailKey'] = $key;
            // Nutzer-Bezeichnung (Formular "Automatisch gefundene Geräte",
            // Spalte "Bezeichnung") - leer = Vorgabe der Quelle behalten.
            if (!empty($o['name'])) {
                $d['label'] = $o['name'];
            }
            $d['value'] = $this->resolvePowerValue($d);
            // Manueller Invert-Schalter (Netz/Batterie) - nur bei manuell
            // konfigurierten Geraeten gesetzt (discoverManualCore()).
            if (!empty($d['invert']) && $d['value'] !== null) {
                $d['value'] = -$d['value'];
            }
            if (!empty($d['socID'])) {
                $d['soc'] = $this->resolveVariableValue((int) $d['socID']);
            }
            // Schaltgruppen (MeterHub-Vertrag 1.4, 03.09.2026) - switchID/
            // switchStateID sind *ID-Referenzen, hier auf fertige Werte
            // aufgeloest (Muster: socID -> soc direkt darueber).
            $switchVid = (int) ($d['switchID'] ?? 0);
            if ($switchVid > 0 && IPS_VariableExists($switchVid)) {
                $d['switchable'] = true;
                $d['switchOn'] = (bool) GetValueBoolean($switchVid);
            }
            $switchStateVid = (int) ($d['switchStateID'] ?? 0);
            if ($switchStateVid > 0 && IPS_VariableExists($switchStateVid)) {
                $d['switchState'] = (int) GetValue($switchStateVid);
            }
            // Wallbox-Sonderfall (InverterHub, 27.07.2026): "eingesteckt aber
            // nicht ladend" (0 W) gilt trotzdem als aktiv/volle Farbe, nicht
            // ausgegraut. plugStateID kommt entweder unveraendert aus
            // CHUB_GetFunctions durch (normalizeEntry() entfernt keine Felder)
            // oder aus der manuellen Verbraucherliste samt plugOp/plugVal.
            if (!empty($d['plugStateID'])) {
                $d['plugged'] = $this->resolvePluggedCondition($d);
            }
            // Gestern-Vergleich als Geisterring (28.08.2026) - nur bei
            // tatsaechlich vorhandener powerID und Archivwert, sonst bleibt
            // das Feld weg und der Ring entfaellt in der Darstellung.
            if (!empty($d['powerID'])) {
                $yv = $this->GetYesterdayValue((int) $d['powerID']);
                if ($yv !== null) {
                    $d['yesterdayValue'] = $yv;
                }
            }
            return $d;
        }, $this->GetDevices());

        // Strompreis-Trend-Sparkline am Netz-Knoten (28.08.2026, Dietmar:
        // "alles direkt umsetzen") - nutzt dieselbe Preisquelle wie die
        // Detailseite (Tibber, sonst BDEW-Naeherung) - PriceSlotsForDay()
        // liest nur bereits vorliegende/gecachte Werte, keine eigene
        // Netzabfrage, daher ohne zusaetzlichen Throttle bei jedem
        // buildPayload()-Aufruf vertretbar.
        $gridIdx = null;
        foreach ($devices as $i => $dd) {
            if (($dd['function'] ?? '') === 'grid') {
                $gridIdx = $i;
                break;
            }
        }
        if ($gridIdx !== null) {
            $dayStart = strtotime('today');
            $dayEnd = strtotime('+1 day', $dayStart);
            $slots = $this->PriceSlotsForDay($dayStart, $dayEnd);
            if (count($slots) > 0) {
                $trend = [];
                foreach ($slots as $s) {
                    $trend[] = ['t' => (int) ($s['start'] ?? 0), 'price' => (float) ($s['price'] ?? 0)];
                }
                $devices[$gridIdx]['priceTrend'] = $trend;
                $devices[$gridIdx]['priceNow'] = $this->PriceAt($slots, time());
            }
        }

        // PV-Prognose-Fortschrittsring am Solar-Knoten (28.08.2026).
        $pvIdx = null;
        foreach ($devices as $i => $dd) {
            if (($dd['function'] ?? '') === 'pv') {
                $pvIdx = $i;
                break;
            }
        }
        if ($pvIdx !== null) {
            $pvPowerID = (int) (!empty($devices[$pvIdx]['usingFallback'])
                ? ($devices[$pvIdx]['fallbackPowerID'] ?? 0)
                : ($devices[$pvIdx]['powerID'] ?? 0));
            $ring = $this->PvForecastRing($pvPowerID);
            if ($ring !== null) {
                $devices[$pvIdx]['forecastRatio'] = $ring['ratio'];
            }
        }

        // Peak-Marker auf der Speiche - je Geraet mit powerID, throttled.
        foreach ($devices as $i => $dd) {
            $pid = (int) (!empty($dd['usingFallback']) ? ($dd['fallbackPowerID'] ?? 0) : ($dd['powerID'] ?? 0));
            if ($pid > 0) {
                $peak = $this->GetPeakTodayW($pid);
                if ($peak !== null) {
                    $devices[$i]['peakTodayW'] = $peak;
                }
            }
        }

        // Autarkiegrad-Ringsegment am Haus-Knoten.
        $houseIdx = null;
        foreach ($devices as $i => $dd) {
            if (($dd['function'] ?? '') === 'house') {
                $houseIdx = $i;
                break;
            }
        }
        if ($houseIdx !== null && !empty($devices[$houseIdx]['powerID'])) {
            $autarky = $this->AutarkyRatioToday((int) $devices[$houseIdx]['powerID']);
            if ($autarky !== null) {
                $devices[$houseIdx]['autarkyRatio'] = $autarky;
            }
        }

        // Fahrzeug-Zuordnung fuer Wallboxen (Dietmar, 29.07.2026: bei
        // eingestecktem Auto sollen subText/SOC-Ring das ERKANNTE Fahrzeug
        // zeigen, nicht nur "Wallbox aktiv") - muss VOR dem Ausblenden/
        // Reindizieren unten laufen, AssignVehicles() liefert Indizes auf
        // dieses $devices-Array.
        $vehicles = $this->AllVehicles();
        if (count($vehicles) > 0) {
            $assign = $this->AssignVehicles($devices, $vehicles);
            foreach ($assign as $wbIdx => $a) {
                $v = $vehicles[$a['v']];
                $devices[$wbIdx]['socHave'] = true;
                $devices[$wbIdx]['soc'] = round((float) GetValue($v['socID']));
                $devices[$wbIdx]['sub'] = $v['name'];
                // Nur bei automatisch erkannten Tessie-Fahrzeugen vorhanden
                // (manuelle Fahrzeug-Zeilen kennen keine Reichweite) - fuer die
                // "Für mich"-Kennzahl auf der Wallbox-Detailseite.
                if (!empty($v['rangeKm'])) {
                    $devices[$wbIdx]['vehicleRangeKm'] = $v['rangeKm'];
                }
                $this->PublishVehicleNameToChargerHub($devices[$wbIdx], $v['name'], $a['correlated']);
            }
        }

        // Diagnose-Warnstufe direkt am betroffenen Knoten sichtbar machen
        // (Dietmar, 28.08.2026: "Diagnose-Warnungen direkt am Knoten" -
        // bisher nur im separaten Diagnose-Badge unten rechts, dort leicht
        // zu uebersehen). Alle aktuell existierenden Diagnose-Eintraege
        // (yield_vs_forecast/mppt_string_compare/riso) beziehen sich auf
        // die PV-Anlage - deshalb hier bewusst pauschal auf 'pv'-Geraete
        // angewendet statt an eine einzelne powerID zu binden, die es in
        // keinem der drei Eintragstypen einheitlich gibt. Schlechteste
        // Stufe gewinnt (kritisch > auffaellig > null).
        $diagnostics = $this->resolveDiagnostics();
        $worstLevel = null;
        foreach ($diagnostics as $diag) {
            $lvl = $diag['level'] ?? null;
            if ($lvl === 'kritisch') {
                $worstLevel = 'kritisch';
                break;
            }
            if ($lvl === 'auffaellig' && $worstLevel === null) {
                $worstLevel = 'auffaellig';
            }
        }
        if ($worstLevel !== null) {
            foreach ($devices as &$dRef) {
                if (($dRef['function'] ?? '') === 'pv') {
                    $dRef['warnLevel'] = $worstLevel;
                }
            }
            unset($dRef);
        }

        // Vom Nutzer ausgeblendete Geraete entfernen (siehe "Automatisch
        // gefundene Geräte"-Liste im Formular) - erst hier bei der Anzeige,
        // NICHT schon beim Discover()/Cache: ein ausgeblendetes Geraet soll
        // beim naechsten Formular-Oeffnen weiterhin in der Liste auftauchen,
        // nur eben abgewaehlt, nicht spurlos verschwinden.
        $devices = array_values(array_filter($devices, function (array $d) {
            return $d['_visible'];
        }));

        return [
            'ok'          => true,
            'devices'     => $devices,
            // Zeitpunkt des letzten erfolgreichen Discover()-Laufs (Struktur:
            // neue/entfernte Geraete) - NICHT der Wert-Aktualisierung, die
            // laeuft ereignisgesteuert und viel haeufiger (MessageSink()).
            'updatedAt'   => $this->ReadAttributeInteger('LastDiscoveryTs'),
            // Zeitpunkt DIESES Payload-Aufbaus - Dietmar (27.07.2026): weicht
            // die Statuszeile von InverterHubTiles statischem "Verbunden" ab
            // und zeigt einen Zeitstempel, muss der auch sekundengenau die
            // tatsaechliche (ereignisgesteuerte) Aktualisierung wiedergeben,
            // nicht nur den seltenen Discover()-Takt.
            'renderedAt'  => time(),
            // Darstellungs-Einstellungen (1:1 InverterHubTile-Feldnamen,
            // module.html liest dieselben Schluessel).
            'bg'          => $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND)),
            'font'        => $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT)),
            'transMs'     => $this->TransitionValue(),
            'flowRefW'    => $this->FlowRefValue(),
            // "Hinter dem Doppelpfeil" (28.08.2026) - echte Instanz-
            // Variablen statt Formular-Property/localStorage, siehe Create().
            'hideInactive' => (bool) $this->GetValue('HideInactive'),
            'coupleBolt'   => (bool) $this->GetValue('CoupleBoltPower'),
            'coupleGlow'   => (bool) $this->GetValue('CoupleGlowPower'),
            'effectIntensity' => (int) $this->GetValue('EffectIntensity'),
            // Pfad des eigenen WebHooks - die Kachel oeffnet darueber bei
            // Klick auf einen Geraete-Knoten die bildschirmfuellende
            // Detailseite (?detail=<key>) in einem neuen Browser-Tab.
            'hookPath'    => '/hook/nrgdashtile' . $this->InstanceID,
            'diagnostics' => $diagnostics,
            // Netzampel-Farbwaesche im Hintergrund (28.08.2026) - null, wenn
            // keine StromGedacht-Instanz vorhanden/aktiviert ist.
            'gridAmpel'   => $this->GridAmpel(),
            // Einfuehrungs-Tour bei erster Benutzung (28.08.2026).
            'showTour'    => !$this->ReadAttributeBoolean('TourSeen'),
            // Aktuelle EMS-Schaltentscheidung inkl. Begruendung (03.09.2026,
            // Dietmar: "was und warum das EMS schaltet") - Vertrag
            // EMS_GetCurrentDecision(), rein lesend. null, wenn kein EMS
            // installiert/aktiv oder der Vertrag (noch) fehlt.
            'emsDecision' => $this->ReadEmsDecision(),
            // Automatische Vorfuehrung (03.09.2026) - nur Vorstellungs-Instanzen.
            'autoTour'    => $this->readBoolProperty('DemoAutoTour', false),
        ];
    }

    /**
     * Liest EMS_GetCurrentDecision() (Verbund-Vertrag, contractVersion "1.0")
     * - mode/reason/source/since. function_exists()-Waechter (Kernprinzip:
     * kein Modul setzt ein anderes voraus), zusaetzlich try/catch gegen
     * einen Fehler auf EMS-Seite. Type-neutral: reicht das Feld einfach
     * durch, keine eigene Interpretation von source/modeCode.
     */
    private function ReadEmsDecision(): ?array
    {
        if (!function_exists('EMS_GetCurrentDecision')) {
            return null;
        }
        $ids = @IPS_GetInstanceListByModuleID(NRGDASH_GUID_EMS);
        if (!is_array($ids) || count($ids) !== 1) {
            return null;
        }
        try {
            $raw = @EMS_GetCurrentDecision((int) $ids[0]);
        } catch (Throwable $e) {
            return null;
        }
        $state = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($state) || !isset($state['mode'], $state['reason'])) {
            return null;
        }
        return [
            'active' => (bool) ($state['active'] ?? true),
            'mode'   => (string) $state['mode'],
            'reason' => (string) $state['reason'],
            'source' => (string) ($state['source'] ?? ''),
            'since'  => (int) ($state['since'] ?? 0),
        ];
    }

    /**
     * Loest die in GetDiagnostics() gecachten Referenzen (measuredPowerID/
     * measuredID/stringPowerIDs) auf aktuelle Live-Werte auf - bewusst LIVE
     * gelesen statt geglaettet, exakt wie InverterHubMonitor selbst
     * (Rueckmeldung 27.07.2026: Riso/MPPT-Werte sind bei ihnen ungeglaettet,
     * ein einzelner Ausreisser darf durchschlagen). level/threshold/reason
     * kommen bereits fertig bewertet vom Anbieter - hier wird NICHTS davon
     * neu berechnet, nur der/die Anzeige-Wert(e) ergaenzt. Type-neutral:
     * kennt keinen der `type`-Werte, sondern nur, dass Feldnamen auf
     * ID/IDs enden (Muster: fruehere renderDiagnostics()-Fassung).
     */
    private function resolveDiagnostics(): array
    {
        $entries = $this->GetDiagnostics();
        return array_map(function (array $e) {
            if (isset($e['measuredPowerID'])) {
                $e['measured'] = $this->resolveVariableValue((int) $e['measuredPowerID']);
            }
            if (isset($e['measuredID'])) {
                $e['measuredValue'] = $this->resolveVariableValue((int) $e['measuredID']);
            }
            if (isset($e['stringPowerIDs']) && is_array($e['stringPowerIDs'])) {
                $vals = [];
                foreach ($e['stringPowerIDs'] as $n => $vid) {
                    $vals[$n] = $this->resolveVariableValue((int) $vid);
                }
                $e['stringValues'] = $vals;
            }
            return $e;
        }, $entries);
    }

    /**
     * ReadPropertyInteger()/ReadPropertyString() liefern `false` statt des
     * Standardwerts, wenn die Property (noch) nicht registriert ist - real
     * aufgetreten am 27.07.2026: ein reiner Datei-Pull + ApplyChanges() auf
     * eine BEREITS existierende Instanz reicht nicht, um neu in Create()
     * hinzugefuegte RegisterPropertyX-Aufrufe zu registrieren (das passiert
     * nur bei einem echten Modul-Reload ueber die Modulverwaltung). Ohne
     * diese Absicherung fuehrte das zu einem Fatal Error (TypeError: false
     * an einen int-Parameter). Diese Helfer machen das Fehlen einer
     * Property robust, statt sich auf einen rechtzeitigen Modul-Reload zu
     * verlassen.
     */
    private function readIntProperty(string $name, int $default): int
    {
        // @ unterdrueckt gezielt NUR die "Eigenschaft ... nicht gefunden"-Warnung
        // dieses einen Aufrufs (fehlende Property auf einer Instanz, die vor der
        // Formular-Erweiterung angelegt wurde - siehe Kommentar oben). Der
        // Rueckgabewert wird trotzdem korrekt geprueft; kein anderer Fehler
        // dieser Zeile wird verschluckt. Ohne das flutet jedes VM_UPDATE-
        // Ereignis (mehrmals pro Minute) das Systemprotokoll mit vier
        // identischen Warnzeilen.
        $v = @$this->ReadPropertyInteger($name);
        return is_int($v) ? $v : $default;
    }

    private function readStringProperty(string $name, string $default): string
    {
        $v = @$this->ReadPropertyString($name);
        return is_string($v) ? $v : $default;
    }

    private function readBoolProperty(string $name, bool $default = false): bool
    {
        $v = @$this->ReadPropertyBoolean($name);
        return is_bool($v) ? $v : $default;
    }

    private function ColorOrEmpty(int $color): string
    {
        return $color < 0 ? '' : sprintf('#%06x', $color);
    }

    private function FontStack(string $family): string
    {
        if ($family === 'system' || $family === '') {
            return '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        }
        return $family;
    }

    private function FlowRefValue(): int
    {
        $v = $this->readIntProperty('FlowRefW', self::DEF_FLOWREF);
        return ($v >= 500 && $v <= 100000) ? $v : self::DEF_FLOWREF;
    }

    private function TransitionValue(): int
    {
        $v = $this->readIntProperty('TransitionMs', self::DEF_TRANSITION);
        return ($v >= 0 && $v <= 5000) ? $v : self::DEF_TRANSITION;
    }

    /**
     * Liest den aktuellen Leistungswert eines Geraete-Eintrags aus seiner
     * powerID (Referenz, kein gecachter Wert - siehe *_GetFunctions-
     * Konvention). null, wenn keine powerID vorhanden oder die Variable
     * zwischenzeitlich geloescht wurde (genau der Fall, den dieses Modul
     * gegenueber IPS View robust machen soll).
     */
    // Schwelle, ab der ein Primaerwert als "haengengeblieben" statt nur
    // ruhig gilt und auf den Fallback umgeschaltet wird (Dietmar, 30.07.2026:
    // "fuer eine Steuerung sehr sehr wichtig" - ein eingefrorener statt ein
    // fehlender Wert ist fuer einen Regler das gefaehrlichere Szenario).
    // Deutlich groesszuegiger als MeterHubs 5s-Polling, damit normale
    // Netzwerk-/Poll-Jitter keinen Fehlalarm ausloest.
    private const FALLBACK_STALE_SECONDS = 60;

    /**
     * Liest den Leistungswert, mit Rueckfall auf eine zweite (redundante)
     * Quelle, falls die primaere fehlt ODER seit FALLBACK_STALE_SECONDS
     * nicht mehr aktualisiert wurde. $device wird per Referenz uebergeben,
     * damit 'measured'/'usingFallback' beim tatsaechlichen Umschalten
     * mitgesetzt werden koennen - nutzt damit dieselbe bestehende
     * 'measured'-Kennzeichnung (≈-Anzeige), die HeishaMons eigene grobe
     * Schaetzung bereits kennzeichnet, statt eine neue UI extra zu bauen.
     */
    private function resolvePowerValue(array &$device): ?float
    {
        $id = (int) ($device['powerID'] ?? 0);
        $value = $id > 0 ? $this->resolveVariableValue($id) : null;

        $fallbackId = (int) ($device['fallbackPowerID'] ?? 0);
        if ($fallbackId > 0 && $this->isStaleOrMissing($id, $value)) {
            $fbValue = $this->resolveVariableValue($fallbackId);
            if ($fbValue !== null) {
                $device['measured'] = $device['fallbackMeasured'] ?? true;
                $device['usingFallback'] = true;
                return $fbValue;
            }
        }
        return $value;
    }

    // Throttle fuer die Gestern-Vergleichsabfrage - buildPayload() laeuft
    // ereignisgesteuert und viel haeufiger als der Wert sich sinnvoll
    // aendern kann; eine Archivabfrage je Geraet und Aufruf waere unnoetige
    // Last auf dem Archiv-Modul.
    private const YESTERDAY_CACHE_TTL_SEC = 300;

    /**
     * Wert derselben Variable von "gestern zur gleichen Uhrzeit", fuer den
     * Geisterring am Knoten (Dietmar, 28.08.2026). Kalendertag-Differenz
     * bewusst ueber strtotime('-1 day', ...) statt $now-86400 (DST-Regel,
     * siehe CLAUDE.md) - an den zwei Umstellungstagen waere "vor 24h" sonst
     * nicht "gestern zur gleichen Uhrzeit".
     */
    private function GetYesterdayValue(int $id): ?float
    {
        if ($id <= 0 || !IPS_VariableExists($id) || !function_exists('AC_GetLoggedValues')) {
            return null;
        }
        $now = time();
        $cache = json_decode($this->ReadAttributeString('YesterdayCache'), true);
        if (!is_array($cache)) {
            $cache = [];
        }
        $entry = $cache[(string) $id] ?? null;
        if (is_array($entry) && ($now - ($entry['fetchedAt'] ?? 0)) < self::YESTERDAY_CACHE_TTL_SEC) {
            return $entry['value'] ?? null;
        }

        $arch = $this->ArchiveID();
        if ($arch <= 0) {
            return null;
        }
        $target = strtotime('-1 day', $now);
        $rows = @AC_GetLoggedValues($arch, $id, $target - 900, $target + 900, 0);
        $value = null;
        if (is_array($rows) && count($rows) > 0) {
            $best = null;
            $bestDiff = PHP_INT_MAX;
            foreach ($rows as $row) {
                $diff = abs(($row['TimeStamp'] ?? 0) - $target);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $best = $row;
                }
            }
            if ($best !== null) {
                $value = (float) ($best['Avg'] ?? $best['Value'] ?? 0);
            }
        }

        $cache[(string) $id] = ['value' => $value, 'fetchedAt' => $now];
        $this->WriteAttributeString('YesterdayCache', json_encode($cache));
        return $value;
    }

    private const PV_FORECAST_CACHE_TTL_SEC = 300;

    /**
     * PV-Prognose-Fortschrittsring (28.08.2026): Tagesertrag bisher (aus dem
     * Archiv integriert, DaySeries()) im Verhaeltnis zur Tagesprognose
     * (Statusvariable "Today" der Prognose-Instanz - bewusst NICHT
     * PVF_GetForecast(), das koennte einen Wetter-API-Abruf ausloesen,
     * siehe InverterHub/CLAUDE.md "PVF_GetForecast NICHT pollen"). Throttled
     * wie GetYesterdayValue().
     */
    private function PvForecastRing(int $pvPowerID): ?array
    {
        if ($pvPowerID <= 0) {
            return null;
        }
        $now = time();
        $cache = json_decode($this->ReadAttributeString('PvForecastCache'), true);
        if (!is_array($cache)) {
            $cache = [];
        }
        if (is_array($cache) && ($now - ($cache['fetchedAt'] ?? 0)) < self::PV_FORECAST_CACHE_TTL_SEC) {
            return $cache['ratio'] !== null ? $cache : null;
        }

        $result = ['ratio' => null, 'todayKWh' => null, 'forecastKWh' => null, 'fetchedAt' => $now];
        $pvfId = $this->PvfInstanceID();
        if ($pvfId > 0) {
            $forecastVid = $this->FindVarByIdent($pvfId, 'Today');
            $forecastKWh = $forecastVid > 0 ? (float) GetValue($forecastVid) : 0.0;
            if ($forecastKWh > 0) {
                $dayStart = strtotime('today');
                $series = $this->DaySeries($pvPowerID, $dayStart, $now);
                if (count($series) >= 2) {
                    $intervalHours = ((float) ($series[1][0] - $series[0][0])) / 3600000;
                    $todayKWh = 0.0;
                    foreach ($series as [, $w]) {
                        $todayKWh += max(0.0, $w) * $intervalHours / 1000;
                    }
                    $result['ratio'] = max(0.0, min(1.2, $todayKWh / $forecastKWh));
                    $result['todayKWh'] = $todayKWh;
                    $result['forecastKWh'] = $forecastKWh;
                }
            }
        }
        $this->WriteAttributeString('PvForecastCache', json_encode($result));
        return $result['ratio'] !== null ? $result : null;
    }

    private const PEAK_CACHE_TTL_SEC = 300;

    /** Tagesspitze (Betrag, W) fuer den Peak-Marker auf der Speiche - throttled
     *  wie GetYesterdayValue(), gleiche DaySeries()-Quelle wie der PV-Prognose-Ring. */
    private function GetPeakTodayW(int $id): ?float
    {
        if ($id <= 0) {
            return null;
        }
        $now = time();
        $cache = json_decode($this->ReadAttributeString('PeakTodayCache'), true);
        if (!is_array($cache)) {
            $cache = [];
        }
        $entry = $cache[(string) $id] ?? null;
        if (is_array($entry) && ($now - ($entry['fetchedAt'] ?? 0)) < self::PEAK_CACHE_TTL_SEC) {
            return $entry['value'] ?? null;
        }
        $series = $this->DaySeries($id, strtotime('today'), $now);
        $peak = null;
        foreach ($series as [, $w]) {
            $abs = abs($w);
            if ($peak === null || $abs > $peak) {
                $peak = $abs;
            }
        }
        $cache[(string) $id] = ['value' => $peak, 'fetchedAt' => $now];
        $this->WriteAttributeString('PeakTodayCache', json_encode($cache));
        return $peak;
    }

    private function SgwInstanceID(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(NRGDASH_GUID_STROMGEDACHT);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    private const SG_COLORS = [-1 => '#00bfa5', 1 => '#00c853', 2 => '#ffd600', 3 => '#ff6d00', 4 => '#d50000'];

    /** Netzampel-Farbwaesche im Hintergrund (28.08.2026) - liest nur die
     *  bereits vom StromGedacht-Timer aktualisierte Statusvariable, keine
     *  eigene Netzabfrage, daher ungedrosselt vertretbar. */
    private function GridAmpel(): ?array
    {
        if (!function_exists('SGW_GetState')) {
            return null;
        }
        $id = $this->SgwInstanceID();
        if ($id <= 0) {
            return null;
        }
        $state = @SGW_GetState($id);
        if (!is_array($state) || $state['state'] === null) {
            return null;
        }
        $s = (int) $state['state'];
        return ['state' => $s, 'color' => self::SG_COLORS[$s] ?? null, 'label' => $state['label'] ?? ''];
    }

    private const AUTARKY_CACHE_TTL_SEC = 300;

    /** Autarkiegrad heute (28.08.2026): 1 - Netzbezug/Hauslast, aus den
     *  bereits vorhandenen Bausteinen GridDayEnergyKWh()+DaySeries() des
     *  Haus-Knotens - throttled, gleiche Begruendung wie oben. */
    private function AutarkyRatioToday(int $housePowerID): ?float
    {
        if ($housePowerID <= 0) {
            return null;
        }
        $now = time();
        $cache = json_decode($this->ReadAttributeString('AutarkyCache'), true);
        if (!is_array($cache)) {
            $cache = [];
        }
        if (is_array($cache) && ($now - ($cache['fetchedAt'] ?? 0)) < self::AUTARKY_CACHE_TTL_SEC) {
            return $cache['ratio'] ?? null;
        }
        $dayStart = strtotime('today');
        $ratio = null;
        $grid = $this->GridDayEnergyKWh($dayStart, $now);
        $houseSeries = $this->DaySeries($housePowerID, $dayStart, $now);
        if ($grid !== null && count($houseSeries) >= 2) {
            $intervalHours = ((float) ($houseSeries[1][0] - $houseSeries[0][0])) / 3600000;
            $houseKWh = 0.0;
            foreach ($houseSeries as [, $w]) {
                $houseKWh += max(0.0, $w) * $intervalHours / 1000;
            }
            if ($houseKWh > 0.01) {
                $ratio = max(0.0, min(1.0, 1 - ($grid['importKWh'] / $houseKWh)));
            }
        }
        $this->WriteAttributeString('AutarkyCache', json_encode(['ratio' => $ratio, 'fetchedAt' => $now]));
        return $ratio;
    }

    private function isStaleOrMissing(int $id, ?float $value): bool
    {
        if ($value === null) {
            return true;
        }
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return true;
        }
        $updated = IPS_GetVariable($id)['VariableUpdated'] ?? 0;
        return (time() - $updated) > self::FALLBACK_STALE_SECONDS;
    }

    // Toleranz fuer den Live-Korrelationstest zweier vermeintlich
    // redundanter Leistungsquellen - grosszuegig genug fuer normale
    // Messabweichung zwischen zwei unabhaengigen Sensoren (Kalibrierung,
    // Zeitpunkt der Ablesung), eng genug um zwei tatsaechlich
    // UNTERSCHIEDLICHE Verbraucher sicher zu unterscheiden (bei ~200W+
    // typischer Waermepumpen-Last wuerde ein zweiter, andersartiger
    // Verbraucher i.d.R. um ein Vielfaches abweichen, nicht nur um Prozente).
    private const SAME_LOAD_TOLERANCE = 0.25;
    private const SAME_LOAD_MIN_SCALE = 50.0;

    // Zusaetzliche ABSOLUTE Toleranz fuer Werte nahe Null (Dietmar,
    // 30.07.2026, live an Netzleistung nahe dem Einspeise-/Bezugs-
    // Nulldurchgang beobachtet): InverterHub=24W, PAC2200=-9.93W - Betrag
    // der Differenz nur ~14W, aber die relative Toleranz griff wegen der
    // 50W-Mindestskala trotzdem nicht (14/50=28% > 25%). Ein rein
    // prozentualer Vergleich versagt strukturell nahe Null, wo bereits
    // kleine absolute Messabweichungen/Zeitversatz zwischen zwei Sensoren
    // relativ riesig wirken. Absolute Toleranz greift ZUSAETZLICH
    // (oder-verknuepft) - reicht die Differenz in Watt aus, gilt die Last
    // unabhaengig vom Prozentwert als identisch.
    private const SAME_LOAD_ABS_TOLERANCE_W = 30.0;

    /**
     * Echter Live-Wertevergleich, BEVOR zwei gleich kategorisierte Quellen
     * (z.B. HeishaMon + MeterHub, beide function='heatpump') als redundant
     * gemergt werden - reine Kategorie-Uebereinstimmung ist kein Beweis
     * (siehe SUITE.md "keine eigene Anlage als Norm annehmen": ein anderer
     * Haushalt kann zwei tatsaechlich getrennte Waermepumpen haben). Ohne
     * aktuell ablesbare Werte auf BEIDEN Seiten wird bewusst NICHT gemergt
     * (im Zweifel lieber zwei sichtbare Verbraucher als einen faelschlich
     * unterschlagenen).
     *
     * Vergleich auf BETRAG, nicht auf Vorzeichen (Dietmar, 30.07.2026):
     * zwei unabhaengige Quellen fuer dieselbe physische Last koennen
     * gegensaetzliche Vorzeichen-Konventionen haben (z.B. eine zaehlt
     * Bezug positiv, die andere negativ) - das macht sie nicht zu einer
     * anderen Last. Frueher wurde abs() nur fuer die Skala verwendet,
     * die eigentliche Differenz aber auf den vorzeichenbehafteten Werten
     * gebildet - dadurch waeren zwei tatsaechlich identische Lasten mit
     * gegenlaeufigem Vorzeichen faelschlich als "verschieden" erkannt
     * worden (Differenz ~2x Betrag statt ~0).
     */
    private function isSameLoad(int $idA, int $idB): bool
    {
        $a = $this->resolveVariableValue($idA);
        $b = $this->resolveVariableValue($idB);
        if ($a === null || $b === null) {
            return false;
        }
        $a = abs($a);
        $b = abs($b);
        $diff = abs($a - $b);
        if ($diff <= self::SAME_LOAD_ABS_TOLERANCE_W) {
            return true;
        }
        $scale = max($a, $b, self::SAME_LOAD_MIN_SCALE);
        return ($diff / $scale) < self::SAME_LOAD_TOLERANCE;
    }

    /**
     * Kategorien, in denen der reale Referenzpunkt typischerweise EIN
     * physischer Punkt je Haushalt ist (Netzanschluss, Hausverbrauch,
     * Batterie, eine bestimmte Waermepumpe) - ein zweiter Eintrag
     * derselben Kategorie ist dort mit hoher Wahrscheinlichkeit eine
     * redundante Zweitmessung, kein zweites echtes Geraet. BEWUSST
     * AUSGESCHLOSSEN: 'wallbox'/'vehicle'/generische 'consumer'-Eintraege -
     * dort sind mehrere Instanzen (WB1/WB2, mehrere Fahrzeuge, mehrere
     * Haushaltsgeraete) der Normalfall, ein automatischer Wertevergleich
     * koennte dort zwei echte, unterschiedliche Geraete faelschlich
     * zusammenlegen (z.B. zwei Waschmaschinen mit zufaellig aehnlicher
     * Momentanleistung).
     */
    private const REDUNDANCY_ELIGIBLE_FUNCTIONS = ['heatpump', 'grid', 'house', 'battery'];

    /**
     * Reichhaltigkeits-Punktzahl einer Quelle fuer die Auswahl als PRIMAER
     * bei einer Anzeige-/Steuerungskachel - NICHT dasselbe wie Abrechnungs-
     * Genauigkeit. Korrektur (Dietmar, 30.07.2026): der erste Wurf hat
     * 'authority'==='billing' stark belohnt (Inexogy ist Dietmars
     * Abrechnungszaehler) und damit live die falsche Quelle als primaer
     * gewaehlt - Inexogy liefert laut Dietmar "absolut am wenigsten Daten"
     * und ist "fuer Steuerungen total ungeeignet" (15-Minuten-Werte, siehe
     * MeterHub-Vertrag 'latency'==='delayed').
     *
     * 'authority' und 'latency' sind laut dem MeterHub-Vertrag (InverterHub/
     * CLAUDE.md, "Abrechnungsgenauer Netzzaehler") ausdruecklich ZWEI
     * ORTHOGONALE Achsen: 'authority' beantwortet "steht der Wert auf der
     * Rechnung?", 'latency' beantwortet "darf ein Echtzeit-Regler darauf
     * regeln?". Fuer DIESE Kachel (Live-Anzeige + potenzielle Steuer-
     * grundlage) zaehlt ausschliesslich Letzteres - 'authority' fliesst
     * hier bewusst NICHT mehr ein. Ein eigener Abrechnungs-/Kostenvergleich
     * waere ein anderer Anwendungsfall (EMS/Kostenauswertung), nicht diese
     * Verbraucherkachel.
     *
     * InverterHub-eigene Eintraege haben kein 'latency'-Feld (der Vertrag
     * sieht es dort nicht vor), gelten aber als eigener, direkter Modbus-
     * Register-Wert ohne Cloud-/Polling-Zwischenschicht implizit als
     * echtzeitfaehig - deshalb der Spezialfall unten statt einer stillen
     * Abwertung mangels Feld.
     */
    private function sourceRichnessScore(array $d): int
    {
        $score = 0;
        if (!empty($d['energyImportID'])) {
            $score += 2;
        }
        if (!empty($d['energyExportID'])) {
            $score += 2;
        }
        $latency = $d['latency'] ?? (($d['source'] ?? '') === 'inverterhub' ? 'realtime' : '');
        if ($latency === 'realtime') {
            $score += 6;
        } elseif ($latency === 'delayed') {
            $score -= 4;
        }
        if (($d['measured'] ?? true) === true) {
            $score += 1;
        }
        return $score;
    }

    /**
     * Generische Redundanz-Erkennung ueber ALLE Quellen (Dietmar,
     * 30.07.2026: "es werden alle Zähler im Auge behalten, nicht nur
     * beim ersten Scan" UND "verallgemeinere das") - laeuft bei JEDEM
     * Discover()-Lauf (5-Minuten-Timer) neu ueber den kompletten,
     * gerade frisch eingelesenen Geraetebestand, nicht nur einmalig beim
     * urspruenglichen HeishaMon/MeterHub-Waermepumpenfall.
     *
     * ZWEISTUFIG, wegen eines live beobachteten Problems (Dietmars drei
     * Netzzaehler Inexogy/PAC2200/InverterHub-eigen): ein reiner
     * Momentan-Wertevergleich (isSameLoad()) ist bei einer schnell
     * schwankenden Groesse wie Netzleistung UND einer Quelle mit
     * 'latency'==='delayed' (Inexogy, bis zu 15 Minuten alter Stand)
     * strukturell instabil - zwei aufeinanderfolgende Discover()-Laeufe
     * clusterten live unterschiedliche Zaehler-Paare, weil Inexogys
     * gecachter Wert mal zufaellig zum aktuellen Live-Wert passte und
     * mal nicht. Ein Vergleich gegen einen bekannt veralteten Wert ist
     * kein verlaesslicher Redundanz-Beweis.
     *
     * Stufe 1: NUR echtzeitfaehige Quellen (latency==='realtime' oder
     * InverterHub-eigene Eintraege ohne latency-Feld, siehe
     * sourceRichnessScore()) werden untereinander per Live-Wert
     * geclustert - hier ist ein Momentanvergleich sinnvoll, weil beide
     * Seiten tatsaechlich einen aktuellen Wert liefern.
     * Stufe 2: Verzoegerte Quellen (z.B. Inexogy) werden NICHT mehr zum
     * Aufbau eines Clusters herangezogen, sondern nur noch NACHTRAEGLICH
     * an einen bereits stufe-1-bestaetigten Cluster derselben Kategorie
     * angehaengt - und auch nur, wenn dieser Cluster noch KEINEN
     * Fallback aus Stufe 1 hat (eine echtzeitfaehige Zweitquelle ist
     * immer die bessere Wahl als eine strukturell veraltete).
     *
     * Je Cluster bleibt nur die reichhaltigste Quelle als PRIMAER
     * sichtbar, die zweitreichhaltigste wird als Fallback angehaengt
     * (resolvePowerValue()), alle weiteren werden nicht mehr separat
     * gezeigt (mehr als ein Fallback-Slot ist im Schema nicht vorgesehen).
     */
    private function mergeRedundantSources(array $devices): array
    {
        $devices = array_values($devices);
        $n = count($devices);

        $isRealtimeCandidate = function (array $d): bool {
            $latency = $d['latency'] ?? (($d['source'] ?? '') === 'inverterhub' ? 'realtime' : '');
            return $latency === 'realtime';
        };

        // Stufe 1: nur echtzeitfaehige Quellen per Live-Wert clustern.
        $clusterOf = array_fill(0, $n, -1);
        $clusters = [];
        for ($i = 0; $i < $n; $i++) {
            $fi = $devices[$i]['function'] ?? '';
            $pidI = (int) ($devices[$i]['powerID'] ?? 0);
            if (!in_array($fi, self::REDUNDANCY_ELIGIBLE_FUNCTIONS, true) || $pidI <= 0 || !$isRealtimeCandidate($devices[$i])) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                $fj = $devices[$j]['function'] ?? '';
                $pidJ = (int) ($devices[$j]['powerID'] ?? 0);
                if ($fj !== $fi || $pidJ <= 0 || !$isRealtimeCandidate($devices[$j])) {
                    continue;
                }
                if (!$this->isSameLoad($pidI, $pidJ)) {
                    continue;
                }
                $ci = $clusterOf[$i];
                $cj = $clusterOf[$j];
                if ($ci === -1 && $cj === -1) {
                    $clusters[] = [$i, $j];
                    $newIdx = count($clusters) - 1;
                    $clusterOf[$i] = $newIdx;
                    $clusterOf[$j] = $newIdx;
                } elseif ($ci !== -1 && $cj === -1) {
                    $clusters[$ci][] = $j;
                    $clusterOf[$j] = $ci;
                } elseif ($ci === -1 && $cj !== -1) {
                    $clusters[$cj][] = $i;
                    $clusterOf[$i] = $cj;
                } elseif ($ci !== $cj) {
                    foreach ($clusters[$cj] as $idx) {
                        $clusterOf[$idx] = $ci;
                        $clusters[$ci][] = $idx;
                    }
                    $clusters[$cj] = [];
                }
            }
        }

        // Einzelknoten-Cluster fuer jede redundanzfaehige, echtzeitfaehige
        // Quelle anlegen, die in Stufe 1 keinen Treffer hatte - sonst
        // haette Stufe 2 nichts zum Andocken (Cluster entstehen in Stufe 1
        // nur bei einem tatsaechlichen Treffer, nie einzeln).
        for ($i = 0; $i < $n; $i++) {
            if ($clusterOf[$i] !== -1) {
                continue;
            }
            $fi = $devices[$i]['function'] ?? '';
            $pidI = (int) ($devices[$i]['powerID'] ?? 0);
            if (in_array($fi, self::REDUNDANCY_ELIGIBLE_FUNCTIONS, true) && $pidI > 0 && $isRealtimeCandidate($devices[$i])) {
                $clusters[] = [$i];
                $clusterOf[$i] = count($clusters) - 1;
            }
        }

        // Stufe 2: verzoegerte Quellen NUR nachtraeglich an einen bereits
        // bestehenden Stufe-1-Cluster derselben Kategorie anhaengen -
        // NIE als eigenstaendiger Cluster-Beweis, NIE wenn der Cluster
        // schon eine echtzeitfaehige Zweitquelle (Fallback-Kandidat) hat.
        for ($i = 0; $i < $n; $i++) {
            if ($clusterOf[$i] !== -1) {
                continue;
            }
            $fi = $devices[$i]['function'] ?? '';
            if (!in_array($fi, self::REDUNDANCY_ELIGIBLE_FUNCTIONS, true) || $isRealtimeCandidate($devices[$i])) {
                continue;
            }
            foreach ($clusters as $ci => $members) {
                if (count($members) === 0 || count($members) >= 2) {
                    continue; // schon voll (Primaer+Fallback) oder leer
                }
                $memberFn = $devices[$members[0]]['function'] ?? '';
                if ($memberFn === $fi) {
                    $clusters[$ci][] = $i;
                    $clusterOf[$i] = $ci;
                    break;
                }
            }
        }

        $dropIndexes = [];
        foreach ($clusters as $members) {
            $members = array_values(array_unique($members));
            if (count($members) < 2) {
                continue;
            }
            usort($members, function ($a, $b) use ($devices) {
                return $this->sourceRichnessScore($devices[$b]) <=> $this->sourceRichnessScore($devices[$a]);
            });
            $primaryIdx = $members[0];
            $fallback = $devices[$members[1]];
            $devices[$primaryIdx]['fallbackPowerID']       = (int) ($fallback['powerID'] ?? 0);
            $devices[$primaryIdx]['fallbackEnergyImportID'] = (int) ($fallback['energyImportID'] ?? 0);
            $devices[$primaryIdx]['fallbackMeasured']       = (bool) ($fallback['measured'] ?? true);
            $devices[$primaryIdx]['fallbackLabel']          = (string) ($fallback['label'] ?? ($fallback['source'] ?? 'Fallback'));
            foreach ($members as $k => $idx) {
                if ($k > 0) {
                    $dropIndexes[] = $idx;
                }
            }
        }

        foreach ($dropIndexes as $idx) {
            unset($devices[$idx]);
        }
        return array_values($devices);
    }

    private function resolveVariableValue(int $id): ?float
    {
        if ($id > 0 && IPS_VariableExists($id)) {
            return (float) GetValue($id);
        }
        return null;
    }

    /**
     * Wertet die "eingesteckt"-Bedingung einer Wallbox aus (Muster:
     * InverterHubTile "Verbunden-Variable" + Bedingung + Vergleichswert).
     * Nur die beiden in unserer form.json angebotenen Bedingungen: 'truthy'
     * (ist gesetzt: wahr/≠0/nicht leer) und 'ne' (ungleich Vergleichswert -
     * z.B. go-e-Kabeltyp 0="kein Kabel"). ChargerHub-Eintraege haben kein
     * plugOp (kommen direkt aus CHUB_GetFunctions als reiner Bool-Wert) -
     * dafuer bleibt 'truthy' der Rückfall.
     */
    private function resolvePluggedCondition(array $d): bool
    {
        $id = (int) ($d['plugStateID'] ?? 0);
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return false;
        }
        $value = GetValue($id);
        $op = $d['plugOp'] ?? 'truthy';
        if ($op === 'ne') {
            return (string) $value !== (string) ($d['plugVal'] ?? '');
        }
        if ($op === 'eq') {
            return (string) $value === (string) ($d['plugVal'] ?? '');
        }
        return !empty($value);
    }

    /**
     * Frei editierbare Fahrzeugliste (Muster: InverterHubTile Vehicles-
     * Property, 1:1 uebernommen). Nur noch als Rueckfall fuer Fahrzeuge OHNE
     * Tessie (anderes Fabrikat) gedacht - siehe AllVehicles(). Zeilen ohne
     * gueltige SOC-Variable werden verworfen - ohne SOC gibt es nichts
     * anzuzeigen, das Fahrzeug waere fuer die Zuordnung nutzlos.
     */
    private function ReadVehicleRows(): array
    {
        $rows = json_decode($this->readStringProperty('Vehicles', '[]'), true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $socID = (int) ($row['SocID'] ?? 0);
            if ($socID <= 0 || !IPS_VariableExists($socID)) {
                continue;
            }
            $name = trim((string) ($row['Name'] ?? ''));
            $out[] = [
                'name' => ($name !== '' ? $name : 'Fahrzeug'),
                'socID' => $socID,
                'plugID' => (int) ($row['PlugID'] ?? 0),
                'plugOp' => (string) ($row['PlugOp'] ?? 'truthy'),
                'plugVal' => (string) ($row['PlugVal'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Tessie-Fahrzeuge VOLLAUTOMATISCH ueber den oeffentlichen Verbund-
     * Vertrag TESSIE_GetVehicleState($id) erkannt - kein manueller Eintrag
     * noetig (Dietmar, 29.07.2026: "wenn alles mit unseren Modulen
     * eingerichtet wurde, dann sollten sich alle Geraetschaften erkennen").
     * 'connected' kommt bereits fertig berechnet aus dem Vertrag (Tessies
     * eigene, robuste Logik: "Ladestatus (Detail)" != 'disconnected', mit
     * Rueckfall auf den Lade-Status - NICHT hier neu nachgebaut). Fuer die
     * Zeitkorrelation in AssignVehicles() zusaetzlich der Ident
     * 'stat_tel_DetailedChargeState' (stabiler Ident, Verbund-Konvention
     * "Idents sind API") fuer den Aenderungszeitpunkt.
     */
    private function discoverTessieVehicles(): array
    {
        $out = [];
        if (!function_exists('TESSIE_GetVehicleState')) {
            return $out;
        }
        foreach (@IPS_GetInstanceListByModuleID(NRGDASH_GUID_TESSIE) as $id) {
            $raw = @TESSIE_GetVehicleState((int) $id);
            $state = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($state) || (int) ($state['socID'] ?? 0) <= 0) {
                continue;
            }
            $changeVid = $this->FindVarByIdent((int) $id, 'stat_tel_DetailedChargeState');
            $out[] = [
                'name' => trim((string) ($state['name'] ?? '')) ?: 'Fahrzeug',
                'socID' => (int) $state['socID'],
                'connected' => (bool) ($state['connected'] ?? false),
                'changedAt' => $this->ChangedAt($changeVid),
                // Reichweite bei aktuellem Ladestand (Tessie-Vertrag 1.5,
                // 28.08.2026) - fuer die "Für mich"-Kennzahl auf der Wallbox-
                // Detailseite (im Krisenfall relevant: "komme ich damit weg?").
                // null bei aelteren Tessie-Versionen oder deaktiviertem
                // Datenpunkt - dann zeigt die Detailseite nur den SOC.
                'rangeKm' => isset($state['rangeKm']) ? (float) $state['rangeKm'] : null,
            ];
        }
        return $out;
    }

    /**
     * Automatisch erkannte Tessie-Fahrzeuge + manuell eingetragene (fuer
     * andere Fabrikate ohne Tessie) - bei Namensgleichheit (Grossschreibung
     * egal) gewinnt die automatische Erkennung, sie ist live und braucht
     * keine Pflege.
     */
    private function AllVehicles(): array
    {
        $auto = $this->discoverTessieVehicles();
        $autoNames = array_map(function ($v) {
            return strtolower($v['name']);
        }, $auto);
        $manual = array_values(array_filter($this->ReadVehicleRows(), function ($v) use ($autoNames) {
            return !in_array(strtolower($v['name']), $autoNames, true);
        }));
        return array_merge($auto, $manual);
    }

    /**
     * Allgemeine Bedingungspruefung fuer "Verbunden"-Variablen (Muster:
     * InverterHubTile CondMet() - erweitert resolvePluggedCondition() um
     * numerische Vergleiche, die AssignVehicles() fuer Wallbox UND Fahrzeug
     * gleichermassen braucht). null = Variable fehlt/ungueltig.
     */
    private function CondMet(int $varID, string $op, string $val): ?bool
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            return null;
        }
        $v = GetValue($varID);
        switch ($op) {
            case 'eq': return $this->ValEquals($v, $val);
            case 'ne': return !$this->ValEquals($v, $val);
            case 'gt': return $this->ValNum($v) > (float) $val;
            case 'ge': return $this->ValNum($v) >= (float) $val;
            case 'lt': return $this->ValNum($v) < (float) $val;
            case 'le': return $this->ValNum($v) <= (float) $val;
            default:   return $this->ValTruthy($v);
        }
    }

    private function ValEquals($v, $val): bool
    {
        if (is_bool($v)) {
            return $v === $this->ValTruthy($val);
        }
        if (is_numeric($v) && is_numeric($val)) {
            return ((float) $v) == ((float) $val);
        }
        return strcasecmp(trim((string) $v), trim((string) $val)) === 0;
    }

    private function ValNum($v): float
    {
        return is_bool($v) ? ($v ? 1.0 : 0.0) : (is_numeric($v) ? (float) $v : 0.0);
    }

    private function ValTruthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return ((float) $v) != 0.0;
        }
        $s = strtolower(trim((string) $v));
        return !($s === '' || $s === '0' || $s === 'false' || $s === 'no' || $s === 'nein');
    }

    /**
     * Zeitpunkt der letzten WERT-Aenderung (IPS' VariableChanged aendert
     * sich nur bei echtem Wertwechsel) - dient als "verbunden seit"-Zeitpunkt
     * fuer die Zeitkorrelation in AssignVehicles(), ganz ohne eigenen
     * Datenpunkt dafuer.
     */
    private function ChangedAt(int $varID): int
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            return 0;
        }
        $info = @IPS_GetVariable($varID);
        return $info ? (int) $info['VariableChanged'] : 0;
    }

    /**
     * Ordnet eingesteckte Fahrzeuge den Wallbox-Geraeten zu (1:1 aus
     * InverterHubTile::AssignVehicles() uebernommen, Verbund-Absprache
     * 29.07.2026: Wallbox und Fahrzeug melden "verbunden" jeweils fuer sich
     * unabhaengig; da beide praktisch gleichzeitig wechseln, wenn ein Auto
     * eingesteckt wird, dient IPS' VariableChanged-Zeitstempel als
     * Korrelations-Anker. Alle Wallbox-Fahrzeug-Paare innerhalb von
     * MatchToleranceSec werden gebildet, nach zeitlicher Naehe sortiert und
     * eindeutig (1:1) vergeben - so landet bei mehreren Autos/Wallboxen
     * jedes dort, wo es tatsaechlich eingesteckt wurde, ohne dass irgendwo
     * ein Datenpunkt "welches Auto steht hier" existieren muesste.
     * $rows: komplette Geraeteliste (normalizeEntry()-Form, 'function' ===
     * 'wallbox' wird intern gefiltert). Rueckgabe: [Index in $rows => Index
     * in $vehicles].
     */
    /**
     * Spiegelt das Ergebnis unserer bereits bestehenden Fahrzeug-Wallbox-
     * Zuordnung (AssignVehicles()) an die jeweilige Wallbox-Quelle, damit
     * deren eigene Instanz den Fahrzeugnamen ebenfalls sichtbar hat -
     * Anlass war eine Rueckfrage von ChargerHub/Tessie (26.08.2026), ob wir
     * bei einer Zuordnung zusaetzlich einen dummen Setter aufrufen koennen,
     * statt dass jede Wallbox-Quelle eine zweite, potenziell abweichende
     * Korrelation baut (Zustaendigkeit liegt seit dem InverterHubTile-
     * Praezedenzfall, 29.07.2026, exklusiv bei uns).
     *
     * Transport-Dispatch ueber $wallbox['transport'] (30.08.2026, OCPPHub-
     * Anfrage): CHUB_SetVehicleName() fuer ChargerHub-Wallboxen (kein
     * 'transport'-Feld -> Default), OHUBL_SetVehicleName() fuer OCPPHub-
     * Ladepunkte ('transport' === 'ocpp'). function_exists()-Guard je Zweig,
     * damit ein fehlendes/aelteres Partnermodul nicht zum Fatal Error fuehrt.
     *
     * $timeCorrelated (31.08.2026, OCPPHub-Autocharge-Feature): gibt weiter,
     * ob AssignVehicles() eine ECHTE Zeitkorrelation gefunden hat oder nur
     * einen der beiden Sonderfaelle (Ausschlussverfahren ohne geprueften
     * Zeitstempel, siehe deren Docblock) - OCPPHub autorisiert automatisch
     * nur bei true. PFLICHTPARAMETER bei OHUB_SetVehicleName() (Symcons
     * generierte Instanzfunktion ignoriert PHP-Standardwerte, derselbe
     * Fund wie beim OHUB_RemoteStart()-ArgumentCountError vom 31.08.2026) -
     * deshalb hier KEIN Default, CHUB_SetVehicleName() bleibt unveraendert
     * bei 2 Argumenten, da ChargerHub das Feld (noch) nicht angefragt hat.
     */
    private function PublishVehicleNameToChargerHub(array $wallbox, string $vehicleName, bool $timeCorrelated): void
    {
        $instanceID = (int) ($wallbox['instanceID'] ?? 0);
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return;
        }
        if (($wallbox['transport'] ?? '') === 'ocpp') {
            if (function_exists('OHUBL_SetVehicleName')) {
                @OHUBL_SetVehicleName($instanceID, $vehicleName, $timeCorrelated);
            }
            return;
        }
        if (function_exists('CHUB_SetVehicleName')) {
            @CHUB_SetVehicleName($instanceID, $vehicleName);
        }
    }

    private function AssignVehicles(array $rows, array $vehicles): array
    {
        $tol = max(0, $this->readIntProperty('MatchToleranceSec', self::DEF_MATCH_TOLERANCE));

        $wbConnected = [];
        $wbAllIdx = [];
        foreach ($rows as $i => $row) {
            // normalizeDeviceCategory() statt eines rohen Vergleichs auf
            // 'wallbox' - ChargerHub liefert die Kategorie roh als 'charger'
            // (Verbund-Vertrag CHUB_GetFunctions), nicht als 'wallbox'. Ein
            // wörtlicher Vergleich hat die beiden echten ChargerHub-Instanzen
            // (WB1/WB2, Dietmars Anlage) komplett übersehen - real gefundener
            // Fehler, 29.07.2026, module.html hatte für die Icon-Anzeige
            // längst eine eigene Übersetzung (CONSUMER_TYPE_MAP), nur diese
            // Stelle hier nicht.
            if ($this->normalizeDeviceCategory($row['function'] ?? '') !== 'wallbox') {
                continue;
            }
            $wbAllIdx[] = $i;
            $plugID = (int) ($row['plugStateID'] ?? 0);
            $op = (string) ($row['plugOp'] ?? 'truthy');
            $val = (string) ($row['plugVal'] ?? '');
            if ($this->CondMet($plugID, $op, $val) === true) {
                $wbConnected[$i] = $this->ChangedAt($plugID);
            }
        }

        // Zwei Fahrzeug-Formen: automatisch erkannte Tessie-Fahrzeuge tragen
        // 'connected' bereits fertig berechnet (Verbund-Vertrag
        // TESSIE_GetVehicleState) + 'changedAt' fuer die Zeitkorrelation;
        // manuell eingetragene (Fahrzeuge ohne Tessie) haben stattdessen
        // plugID/plugOp/plugVal und werden ueber CondMet() ausgewertet.
        $vConnected = [];
        foreach ($vehicles as $j => $v) {
            if (array_key_exists('connected', $v)) {
                if ($v['connected'] === true) {
                    $vConnected[$j] = (int) ($v['changedAt'] ?? 0);
                }
                continue;
            }
            if ($this->CondMet((int) $v['plugID'], (string) $v['plugOp'], (string) $v['plugVal']) === true) {
                $vConnected[$j] = $this->ChangedAt((int) $v['plugID']);
            }
        }

        $pairs = [];
        foreach ($wbConnected as $i => $tw) {
            foreach ($vConnected as $j => $tv) {
                $d = abs($tw - $tv);
                if ($tol > 0 && $d > $tol) {
                    continue;
                }
                $pairs[] = ['d' => $d, 'w' => $i, 'v' => $j];
            }
        }
        usort($pairs, function ($a, $b) {
            return $a['d'] <=> $b['d'];
        });

        // Werte sind ['v' => Fahrzeug-Index, 'correlated' => bool] - Letzteres
        // seit 31.08.2026 fuer OCPPHubs OHUBL_SetVehicleName()-Konfidenzflag
        // (Autocharge-Feature): true nur bei echter Zeitkorrelation ueber
        // $pairs unten, false bei den beiden Sonderfaellen weiter unten
        // (reines Ausschlussverfahren, keine geprueften Zeitstempel).
        $map = [];
        $usedV = [];
        foreach ($pairs as $p) {
            if (isset($map[$p['w']]) || isset($usedV[$p['v']])) {
                continue;
            }
            $map[$p['w']] = ['v' => $p['v'], 'correlated' => true];
            $usedV[$p['v']] = true;
        }

        // Physische Eindeutigkeit ueber das Label, nicht die Zeilenzahl -
        // Regression 31.08.2026 (Dietmar: "diesen Fall ... hatten wir doch
        // schon einmal"): bei bewusster Mehrfach-Discovery derselben
        // Wallbox (Testbetrieb ueber ChargerHub UND OCPPHub gleichzeitig,
        // 30.08.2026) zaehlten die beiden Sonderfaelle unten "1 verbundene
        // Wallbox" faelschlich als 2 Zeilen - der Sonderfall griff nicht
        // mehr, Fahrzeugname/SOC verschwanden trotz eindeutiger Lage
        // (derselbe Zaehl-Fehler wie a3a3f19, nur eine Ebene hoeher). Beide
        // Sonderfaelle gruppieren deshalb erst nach Label und zaehlen
        // GRUPPEN statt Zeilen; bei Treffer werden ALLE Zeilen der Gruppe
        // zugeordnet, nicht nur die erste.
        //
        // NACHTRAG selber Tag: die reine trim()-Normalisierung reichte
        // NICHT - Live-Check per php_eval zeigte ChargerHub liefert "WB 2"
        // (mit Leerzeichen), OCPPHub "WB2" (ohne) fuer dieselbe physische
        // Wallbox. Alle Leerraeume raus, nicht nur an den Raendern -
        // beide Schreibweisen sonst weiterhin zwei verschiedene Gruppen.
        $labelOf = function (int $i) use ($rows): string {
            return preg_replace('/\s+/', '', strtolower((string) ($rows[$i]['label'] ?? '')));
        };

        // Sonderfall genau eine VERBUNDENE Wallbox / genau ein VERBUNDENES
        // Fahrzeug (nach Abzug der bereits zeitkorrelierten Paare): auch
        // ohne zeitliche Naehe eindeutig, wer zu wem gehoert - Dietmar,
        // 05.08.2026, real aufgetretener Fall: Auto steckt schon Stunden an
        // der Wallbox, Ladevorgang startet erst spaeter zeitversetzt
        // (geplantes/preisgesteuertes Laden) - der Wallbox-Verbunden-
        // Zeitstempel liegt dann weit ausserhalb von MatchToleranceSec,
        // obwohl die Zuordnung nach Ausschlussverfahren trotzdem eindeutig
        // ist. Ergaenzt den bestehenden Sonderfall unten (dort: insgesamt
        // nur 1 Wallbox/1 Fahrzeug konfiguriert), dieser hier greift auch
        // bei mehreren registrierten Wallboxen/Fahrzeugen, solange gerade
        // nur je eine(r) davon ueberhaupt verbunden ist.
        $wbRemaining = array_diff_key($wbConnected, $map);
        $vRemaining = array_diff_key($vConnected, $usedV);
        $wbRemainingGroups = [];
        foreach (array_keys($wbRemaining) as $i) {
            $wbRemainingGroups[$labelOf($i)][] = $i;
        }
        if (count($wbRemainingGroups) === 1 && count($vRemaining) === 1) {
            $vIdx = array_key_first($vRemaining);
            foreach (reset($wbRemainingGroups) as $i) {
                $map[$i] = ['v' => $vIdx, 'correlated' => false];
            }
        }

        // Sonderfall genau eine Wallbox / genau ein Fahrzeug: die Lage ist
        // auch ohne Zeitkorrelation eindeutig - hier darf die Verbunden-
        // Bedingung des Fahrzeugs sogar fehlen.
        $wbGroups = [];
        foreach ($wbAllIdx as $i) {
            $wbGroups[$labelOf($i)][] = $i;
        }
        if (count($map) === 0 && count($wbGroups) === 1 && count($vehicles) === 1) {
            $groupIdxs = reset($wbGroups);
            $wbState = false;
            foreach ($groupIdxs as $i) {
                $row = $rows[$i];
                $s = $this->CondMet(
                    (int) ($row['plugStateID'] ?? 0),
                    (string) ($row['plugOp'] ?? 'truthy'),
                    (string) ($row['plugVal'] ?? '')
                );
                // null (kein plugStateID konfiguriert) laesst durch wie
                // bisher, true gewinnt sofort - false von EINER Quelle darf
                // eine andere Quelle derselben physischen Wallbox nicht
                // blockieren.
                if ($s === true) {
                    $wbState = true;
                    break;
                }
                if ($s === null) {
                    $wbState = null;
                }
            }
            $vState = array_key_exists('connected', $vehicles[0])
                ? $vehicles[0]['connected']
                : $this->CondMet((int) $vehicles[0]['plugID'], (string) $vehicles[0]['plugOp'], (string) $vehicles[0]['plugVal']);
            if ($wbState !== false && $vState !== false) {
                foreach ($groupIdxs as $i) {
                    $map[$i] = ['v' => 0, 'correlated' => false];
                }
            }
        }

        return $map;
    }

    /**
     * Zuletzt bekannter Discovery-Stand, ohne erneut abzufragen - für den
     * Konsum durch die HTML-Kachel (Phase 2).
     */
    public function GetDevices(): array
    {
        // ReadAttributeString() kann in seltenen Kernel-Uebergangszustaenden
        // (z.B. waehrend eines Modul-Reloads, wenn ein MessageSink()-Event
        // die Instanz erreicht, bevor sie wieder vollstaendig angebunden ist)
        // 'false' statt eines Strings liefern - json_decode() wirft dann in
        // PHP 8 einen TypeError statt nur eine Warnung. is_string()-Wache
        // davor, statt das nur nach dem Decode abzufangen (real aufgetreten,
        // 30.07.2026, ausgeloest durch einen Modul-Reload waehrend eines
        // MeterHub-Wertupdates).
        $json = $this->ReadAttributeString('DeviceCache');
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) ? $data : [];
    }

    public function GetDiagnostics(): array
    {
        $json = $this->ReadAttributeString('DiagnosticsCache');
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) ? $data : [];
    }

    /**
     * Diagnose-Verträge sind bewusst NICHT ins gemeinsame Geräte-Schema
     * gemischt (normalizeEntry()/functionCategory()) - sie tragen keinen
     * function-Wert und gehören fachlich zu einer eigenen Instanz statt
     * einem Fluss-Icon. Ursprünglicher Anbieter: IHUBMON_GetDiagnostics
     * (InverterHubMonitor) - bleibt als Fallback bestehen, falls doch mal
     * installiert. Seit Dietmar diese Instanz gelöscht hat ("möchte nicht so
     * viele Instanzen verwalten", gleicher Grund wie beim Sankey/MPPT-Fix,
     * 28./29.07.2026), berechnen wir alle drei Diagnose-Typen selbst
     * (computeOwnDiagnostics()) direkt über die InverterHub-KERNINSTANZ.
     * Jeder Eintrag bleibt generisch (type/label/level/threshold/reason +
     * Referenzen/Werte je Typ), das Rendering in module.html iteriert
     * type-neutral, unveraendert.
     */
    private function discoverDiagnostics(): array
    {
        $results = [];
        if (function_exists('IHUBMON_GetDiagnostics')) {
            foreach (IPS_GetInstanceListByModuleID(NRGDASH_GUID_INVERTERHUBMON) as $id) {
                $data = IHUBMON_GetDiagnostics($id);
                if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
                    continue;
                }
                foreach ($data['entries'] as $entry) {
                    if (!is_array($entry) || !isset($entry['type'])) {
                        continue;
                    }
                    $entry['source']     = 'inverterhubmonitor';
                    $entry['instanceID'] = $data['instanceID'] ?? $id;
                    $results[] = $entry;
                }
            }
        }
        if (count($results) > 0) {
            return $results;
        }
        foreach ($this->computeOwnDiagnostics() as $entry) {
            $entry['source'] = 'nrgdashboard';
            $results[] = $entry;
        }
        return $results;
    }

    /**
     * Eigene Gesundheits-/Diagnose-Berechnung, unabhaengig von
     * InverterHubMonitor - 1:1 dieselbe Bewertungslogik wie deren
     * GetDiagnostics() (Schwellen/Formeln unveraendert uebernommen, mit der
     * InverterHub-Sitzung abgestimmt, 29.07.2026), nur die Datenherkunft ist
     * jetzt die InverterHub-KERNINSTANZ statt eines Diagnose-Zwischenmoduls.
     * Nur bei genau einer InverterHub-Instanz (sonst waere die Zuordnung von
     * Einstrahlung/PVF-Instanz/Riso-Schwelle zu "welchem" WR nicht eindeutig
     * - dieselbe Automatik-Konvention wie ueberall sonst im Verbund).
     */
    private function computeOwnDiagnostics(): array
    {
        $entries = [];
        $ihub = $this->singleInverterHubCoreID();
        if ($ihub <= 0 || !function_exists('IHUB_GetFunctions')) {
            return $entries;
        }
        $data = @IHUB_GetFunctions($ihub);
        if (!is_array($data)) {
            return $entries;
        }

        // 1) Ertrag vs. PV-Prognose - GEMESSENE Einstrahlung x
        // Generatorparameter, NIE PVF_GetForecast (Wetterfehler wuerde sonst
        // als Anlagenfehler ausgewiesen - Verbund-Konvention, siehe
        // InverterHub/CLAUDE.md "Diagnose = GEMESSENE Einstrahlung ...").
        $irr = $this->resolveIrradianceID();
        $pvVid = (int) ($data['pvPowerID'] ?? 0);
        if ($irr > 0 && IPS_VariableExists($irr) && $pvVid > 0) {
            $pvf = $this->PvfModel();
            if ($pvf !== null) {
                $measuredW = (float) GetValue($pvVid);
                $expectedW = (float) GetValue($irr) * $pvf['totalKwp'] * $pvf['pr'];
                if ($expectedW > 200.0) {
                    $ratio = $measuredW / $expectedW;
                    if ($ratio < 0.5) {
                        $level = 'kritisch';
                        $reason = 'Gemessener Ertrag liegt unter 50 % der Erwartung — Verschmutzung oder Defekt möglich.';
                    } elseif ($ratio < 0.8) {
                        $level = 'auffaellig';
                        $reason = 'Gemessener Ertrag liegt unter 80 % der Erwartung.';
                    } else {
                        $level = 'normal';
                        $reason = 'Ertrag im erwarteten Bereich.';
                    }
                } else {
                    $level = null;
                    $reason = 'Erwartete Leistung zu gering für eine Bewertung (Dämmerung/stark bewölkt).';
                }
                $entries[] = [
                    'type' => 'yield_vs_forecast',
                    'label' => 'Ertrag vs. Prognose',
                    'measuredPowerID' => $pvVid,
                    'expected' => round($expectedW, 0),
                    'unit' => 'W',
                    'level' => $level,
                    'threshold' => 0.8,
                    'reason' => $reason,
                ];
            }
        }

        // 2) MPPT-Strangvergleich - nur ab mindestens 2 Straengen sinnvoll.
        $stringIDs = [];
        foreach ([1, 2, 3, 4] as $n) {
            $vid = $this->FindVarByIdent($ihub, 'mppt' . $n . '_power');
            if ($vid > 0) {
                $stringIDs[$n] = $vid;
            }
        }
        if (count($stringIDs) >= 2) {
            $vals = [];
            foreach ($stringIDs as $n => $vid) {
                $vals[$n] = (float) GetValue($vid);
            }
            $max = max($vals);
            if ($max > 100.0) {
                $level = 'normal';
                $reason = 'Alle Stränge im erwarteten Verhältnis zueinander.';
                foreach ($vals as $n => $v) {
                    if ($v < 0.5 * $max) {
                        $level = 'auffaellig';
                        $reason = 'MPPT ' . $n . ' liegt deutlich unter den übrigen Strängen — Verschattung oder Defekt möglich.';
                        break;
                    }
                }
            } else {
                $level = null;
                $reason = 'Erzeugung zu gering für eine Bewertung.';
            }
            $entries[] = [
                'type' => 'mppt_string_compare',
                'label' => 'MPPT-Strangvergleich',
                'stringPowerIDs' => $stringIDs,
                'unit' => 'W',
                'level' => $level,
                'threshold' => 0.5,
                'reason' => $reason,
            ];
        }

        // 3) Isolationswiderstand (Riso) - Bewertung NUR mit vom Nutzer
        // gesetzter Schwelle (kein Herstellerdefault ohne Bestaetigung).
        $risoVid = $this->FindVarByIdent($ihub, 'riso');
        if ($risoVid > 0) {
            $warn = $this->readIntProperty('RisoWarnKOhm', 0);
            $val = (float) GetValue($risoVid);
            if ($warn > 0) {
                $level = ($val < $warn) ? 'kritisch' : 'normal';
                $reason = ($val < $warn)
                    ? 'Isolationswiderstand liegt unter der konfigurierten Schwelle (' . $warn . ' kΩ).'
                    : 'Isolationswiderstand über der konfigurierten Schwelle.';
            } else {
                $level = null;
                $reason = 'Keine Schwelle konfiguriert (Instanzeinstellungen) — Bewertung nicht möglich.';
            }
            $entries[] = [
                'type' => 'riso',
                'label' => 'Isolationswiderstand',
                'measuredID' => $risoVid,
                'unit' => 'kΩ',
                'level' => $level,
                'threshold' => $warn ?: null,
                'reason' => $reason,
            ];
        }

        return $entries;
    }

    /**
     * Eigene Property gewinnt; sonst - bei genau einer NRGDashboardPVMonitor-
     * Instanz - deren "IrradianceID" mitlesen (Dietmar, 29.07.2026: "möchte
     * Einstrahlungswerte nicht mehr als einmal irgendwo eintragen"). Direktes
     * Lesen einer Schwester-Instanz-Property ist hier bewusst in Ordnung -
     * beide Kacheln leben im selben Repo/derselben Suite, das ist keine
     * Kopplung an ein fremdes Modul, die einen *_GetFunctions()-Vertrag
     * bräuchte.
     */
    private function resolveIrradianceID(): int
    {
        $own = $this->readIntProperty('IrradianceID', 0);
        if ($own > 0 && IPS_VariableExists($own)) {
            return $own;
        }
        $ids = @IPS_GetInstanceListByModuleID(NRGDASH_GUID_MONITOR);
        if (is_array($ids) && count($ids) === 1) {
            $vid = (int) @IPS_GetProperty((int) $ids[0], 'IrradianceID');
            if ($vid > 0 && IPS_VariableExists($vid)) {
                return $vid;
            }
        }
        return 0;
    }

    /** Eigene Property gewinnt; sonst automatisch bei genau einer
     *  installierten TibberGridReward-Instanz (analog resolveIrradianceID()). */
    // Zwischen zwei erfolglosen HTTP-Versuchen mindestens 1 Tag warten
    // (Seite down/kein Internet) - der taegliche Timer wuerde sonst bei
    // dauerhaftem Fehler jeden Tag erneut probieren, was fuer diese
    // Quartalsdaten voellig ausreicht.
    private const BDEW_RETRY_SECONDS = 24 * 60 * 60;
    // Ein gespeicherter Wert gilt nach 100 Tagen als faellig fuer eine
    // Auffrischung (BDEW veroeffentlicht ca. alle 90 Tage/vierteljaehrlich,
    // etwas Puffer falls die Seite mal spaeter aktualisiert wird).
    private const BDEW_REFRESH_SECONDS = 100 * 24 * 60 * 60;

    /**
     * Vom Timer taeglich aufgerufen (oeffentlich, siehe RegisterTimer in
     * Create()). Holt einen neuen BDEW-Wert nur, wenn der letzte gespeicherte
     * Eintrag faellig ist UND der letzte Versuch lang genug her ist -
     * dieselbe Drossel-Idee wie TibberGridReward::GetPriceCurve() fuers
     * eigene Preis-Nachladen (kein Dauerfeuer bei anhaltendem Fehlschlag).
     */
    public function CheckBdewPrice(): void
    {
        $history = json_decode($this->ReadAttributeString('BdewPriceHistory'), true);
        $history = is_array($history) ? $history : [];
        $latest = end($history);
        $latestAge = $latest ? (time() - (int) $latest['fetchedAt']) : PHP_INT_MAX;
        if ($latestAge < self::BDEW_REFRESH_SECONDS) {
            return;
        }
        $lastTry = $this->ReadAttributeInteger('BdewLastTry');
        if (time() - $lastTry < self::BDEW_RETRY_SECONDS) {
            return;
        }
        $this->WriteAttributeInteger('BdewLastTry', time());
        $price = $this->FetchBdewPrice();
        if ($price === null) {
            $this->SendDebug(__FUNCTION__, 'BDEW-Abruf fehlgeschlagen, naechster Versuch in ' . self::BDEW_RETRY_SECONDS . 's', 0);
            return;
        }
        $history[] = ['fetchedAt' => time(), 'priceCtPerKWh' => $price];
        // Nie mehr als 40 Eintraege behalten (~10 Jahre Quartalshistorie) -
        // reine Vorsicht gegen unbegrenztes Wachstum, kein aktueller Bedarf.
        if (count($history) > 40) {
            $history = array_slice($history, -40);
        }
        $this->WriteAttributeString('BdewPriceHistory', json_encode($history));
        $this->SendDebug(__FUNCTION__, 'Neuer BDEW-Durchschnittspreis gespeichert: ' . $price . ' ct/kWh', 0);
    }

    /**
     * Liest den aktuellen Strompreis-Durchschnitt fuer Haushaltskunden von
     * der BDEW-Uebersichtsseite (bdew.de) - bewusst NICHT das quartalsweise
     * veroeffentlichte PDF (kein verlaesslicher PHP-PDF-Textextraktor ohne
     * Zusatzbibliothek), sondern die HTML-Seite selbst, die die aktuelle
     * Kennzahl schon als Klartext-Satz enthaelt: "Der durchschnittliche
     * Strompreis fuer Haushalte ... betraegt ... durchschnittlich XX,X
     * ct/kWh" (Stand 28.08.2026 lebend geprueft). Liefert null bei jedem
     * Fehler (Netzwerk, HTTP-Fehler, Satz nicht gefunden - z.B. nach einem
     * Wortlaut-Wechsel der Seite) - dann bleibt der zuletzt gespeicherte
     * Wert einfach stehen, es wird nichts erfunden.
     */
    private function FetchBdewPrice(): ?float
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://www.bdew.de/service/daten-und-grafiken/bdew-strompreisanalyse/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (IP-Symcon NRGDashboard)');
        $html = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($html === false || $code >= 400) {
            $this->SendDebug(__FUNCTION__, 'HTTP ' . $code . ' ' . $err, 0);
            return null;
        }
        // Zahl vor "ct/kWh" im Satz mit "durchschnittlich" - deutsches
        // Dezimalkomma. Bewusst kein starres Vollsatz-Match (Wortlaut kann
        // leicht variieren), nur die fuer uns entscheidende Zahl-Einheit-
        // Kombination in Satznaehe von "Haushalt"+"durchschnittlich".
        if (preg_match('/Haushalt[^.]{0,200}?durchschnittlich\s+(\d+[,.]\d+)\s*ct\/?kWh/us', $html, $m)
            || preg_match('/durchschnittlich\s+(\d+[,.]\d+)\s*ct\/?kWh[^.]{0,200}?Haushalt/us', $html, $m)) {
            $price = (float) str_replace(',', '.', $m[1]);
            // Grobe Plausibilitaetsgrenze (20-80 ct/kWh) - ein Wert weit
            // ausserhalb deutet auf einen falsch getroffenen Satz hin (z.B.
            // Industriepreis oder ein Datum), lieber ablehnen als falsch
            // uebernehmen.
            if ($price >= 20 && $price <= 80) {
                return $price;
            }
            $this->SendDebug(__FUNCTION__, 'Gefundener Wert unplausibel: ' . $price, 0);
        }
        return null;
    }

    /** Zuletzt gespeicherter BDEW-Wert + Alter in Tagen, oder null ohne
     *  jeden Eintrag (noch nie erfolgreich abgerufen). */
    private function CurrentBdewPrice(): ?array
    {
        $history = json_decode($this->ReadAttributeString('BdewPriceHistory'), true);
        $latest = is_array($history) ? end($history) : false;
        if (!$latest) {
            return null;
        }
        return [
            'priceCtPerKWh' => (float) $latest['priceCtPerKWh'],
            'ageDays' => (int) floor((time() - (int) $latest['fetchedAt']) / 86400),
        ];
    }

    private function TibberInstanceID(): int
    {
        $own = $this->readIntProperty('TibberInstance', 0);
        if ($own > 0 && @IPS_InstanceExists($own)) {
            return $own;
        }
        $ids = @IPS_GetInstanceListByModuleID(NRGDASH_GUID_TIBBERGRIDREWARD);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    /**
     * Grid-Reward-Erloes heute (EUR) - liest die beiden dafuer vorgesehenen
     * Statusvariablen der TibberGridReward-Instanz direkt (Idents
     * 'GridRewardEnergyToday' [kWh] und 'GridRewardEffectiveRate' [ct/kWh],
     * beide fest in deren MaintainVariable()-Aufrufen benannt). KEIN
     * dokumentierter *_Get*-Vertrag dafuer vorhanden (anders als
     * GetPriceCurve()/GetActiveControls()) - bewusst trotzdem gelesen, weil
     * beide Variablen offensichtlich fuer genau diesen Zweck (Anzeige des
     * Grid-Reward-Ertrags) gedacht sind, aber als roher Ident-Zugriff
     * FRAGILER als ein formaler Vertrag: aendert TibberGridReward die
     * Idents, faellt das hier still auf null zurueck (kein Fehler, nur
     * fehlende Kennzahl) statt zu brechen.
     */
    private function GridRewardEarningsTodayEUR(): ?float
    {
        $id = $this->TibberInstanceID();
        if ($id <= 0) {
            return null;
        }
        $energyVid = $this->FindVarByIdent($id, 'GridRewardEnergyToday');
        $rateVid = $this->FindVarByIdent($id, 'GridRewardEffectiveRate');
        if ($energyVid <= 0 || $rateVid <= 0) {
            return null;
        }
        $energyKWh = (float) @GetValue($energyVid);
        $rateCt = (float) @GetValue($rateVid);
        if ($energyKWh <= 0) {
            return null;
        }
        return $energyKWh * $rateCt / 100;
    }

    /**
     * Preis-Slots (ct/kWh brutto) fuer den ausgewaehlten Tag - duennes
     * Kompatibilitaets-Wrapper um PriceSlotsForRange() (31.08.2026, siehe
     * dort), bestehende Aufrufer unveraendert.
     */
    private function PriceSlotsForDay(int $dayStart, int $dayEnd): array
    {
        return $this->PriceSlotsForRange($dayStart, $dayEnd);
    }

    /**
     * Preis-Slots (ct/kWh brutto) fuer einen BELIEBIGEN Zeitraum - Tag,
     * Monat, Jahr, Lebenszeit (31.08.2026, MeterHub-Anfrage: Kostenauswertung
     * ueber Zeit fuer Sammelkategorien wie "Beleuchtung"). function_exists()-
     * Wache: das Modul darf ohne TibberGridReward voll funktionsfaehig
     * bleiben (Verbund-Konvention, kein Partnermodul vorausgesetzt).
     *
     * Zwei Quellen KOMBINIERT statt alles-oder-nichts: Tibber liefert nur
     * echte Slots fuer heute/nahe Zukunft (kein Preis-Archiv der Vergangenheit
     * bei uns) - genau dieser Ausschnitt wird bevorzugt uebernommen. Fuer
     * jeden Zeitabschnitt, den Tibber NICHT abdeckt (z.B. der Rest eines
     * angefragten Monats/Jahres), fuellt BdewHistorySlots() aus der bei uns
     * gespeicherten BDEW-Preishistorie auf - echte, zeitlich zutreffende
     * Quartalswerte statt eines einzigen flachen Naeherungswerts fuer den
     * gesamten Zeitraum (Verbesserung auch fuer den bisherigen Tagesfall:
     * ein Monatswechsel des BDEW-Werts mitten am Tag wurde vorher ignoriert).
     */
    private function PriceSlotsForRange(int $from, int $to): array
    {
        $slots = [];
        $id = function_exists('TIBBERGR_GetPriceCurve') ? $this->TibberInstanceID() : 0;
        if ($id > 0) {
            // try/catch (31.08.2026, Anlass: Tibber-eigener Fatal Error in
            // GetPriceApiToken()) - @ faengt keinen Fatal Error/uncaught
            // Throwable aus der aufgerufenen Funktion selbst ab.
            try {
                $raw = @TIBBERGR_GetPriceCurve($id);
            } catch (\Throwable $e) {
                $raw = null;
            }
            if (is_array($raw)) {
                foreach ($raw as $s) {
                    if (is_array($s) && (int) ($s['end'] ?? 0) > $from && (int) ($s['start'] ?? PHP_INT_MAX) < $to) {
                        $slots[] = $s;
                    }
                }
            }
        }
        $covered = $slots;
        usort($covered, function ($a, $b) { return $a['start'] <=> $b['start']; });
        $cursor = $from;
        foreach ($covered as $s) {
            $sStart = max($from, (int) $s['start']);
            if ($sStart > $cursor) {
                foreach ($this->BdewHistorySlots($cursor, $sStart) as $bs) {
                    $slots[] = $bs;
                }
            }
            $cursor = max($cursor, min($to, (int) $s['end']));
        }
        if ($cursor < $to) {
            foreach ($this->BdewHistorySlots($cursor, $to) as $bs) {
                $slots[] = $bs;
            }
        }
        return $slots;
    }

    /**
     * Rekonstruiert Preis-Slots aus der eigenen BDEW-Preishistorie
     * (BdewPriceHistory-Attribut, siehe CheckBdewPrice()): jeder gespeicherte
     * Wert gilt ab seinem Abrufzeitpunkt bis zum naechsten Eintrag (bzw. bis
     * "jetzt" beim letzten) - eine grobe, aber echte Zeitreihe statt eines
     * einzigen flachen Naeherungswerts. [] ohne jeden gespeicherten Wert.
     */
    private function BdewHistorySlots(int $from, int $to): array
    {
        $history = json_decode($this->ReadAttributeString('BdewPriceHistory'), true);
        if (!is_array($history) || count($history) === 0) {
            return [];
        }
        usort($history, function ($a, $b) { return ((int) $a['fetchedAt']) <=> ((int) $b['fetchedAt']); });
        $slots = [];
        $n = count($history);
        for ($i = 0; $i < $n; $i++) {
            $segStart = (int) $history[$i]['fetchedAt'];
            $segEnd = ($i + 1 < $n) ? (int) $history[$i + 1]['fetchedAt'] : time();
            if ($segEnd <= $from || $segStart >= $to) {
                continue;
            }
            $slots[] = [
                'start' => max($from, $segStart),
                'end' => min($to, $segEnd),
                'price' => (float) $history[$i]['priceCtPerKWh'],
                'approx' => true,
                'ageDays' => (int) floor((time() - $segStart) / 86400),
            ];
        }
        return $slots;
    }

    /**
     * Vertrag fuer andere Verbund-Module (31.08.2026, MeterHub-Anfrage):
     * Preis (ct/kWh) zu einem einzelnen Zeitpunkt, echte Tibber-Slots wo
     * verfuegbar, sonst BDEW-Historie. null, wenn fuer diesen Zeitpunkt gar
     * keine Preisquelle vorliegt (kein Wert erfinden).
     */
    public function GetPriceAt(int $Timestamp): ?float
    {
        return $this->PriceAt($this->PriceSlotsForRange($Timestamp, $Timestamp + 1), $Timestamp);
    }

    /**
     * Vertrag fuer andere Verbund-Module (31.08.2026, MeterHub-Anfrage:
     * "Kriterienabrechnung" - Kostenauswertung ueber Zeit fuer eigene
     * Sammelkategorien): Preis-Slots fuer einen beliebigen Zeitraum, damit
     * ein Konsument seine eigene Energie-Zeitreihe damit gewichten kann,
     * ohne BDEW-Abruf/Tibber-Kopplung selbst nachzubauen (Kernprinzip
     * "Bewertung/Datenerhebung nicht doppelt bauen" - hier sind WIR der
     * Anbieter der Preisdaten). Format je Slot: {start, end (Unix-Sekunden),
     * price (ct/kWh), approx (bool - true bei BDEW-Naeherung statt echtem
     * Tibber-Slot), ageDays (nur bei approx)}.
     */
    public function GetPriceSeries(int $From, int $To): array
    {
        return $this->PriceSlotsForRange($From, $To);
    }

    /** Preis (ct/kWh) des Slots, der $ts enthaelt, oder null. */
    private function PriceAt(array $slots, int $ts): ?float
    {
        foreach ($slots as $s) {
            if ($ts >= (int) ($s['start'] ?? 0) && $ts < (int) ($s['end'] ?? 0)) {
                return (float) $s['price'];
            }
        }
        return null;
    }

    /**
     * Kosten (EUR) einer Leistungsreihe ueber den ausgewaehlten Tag, mit
     * dem je Zeitpunkt tatsaechlich gueltigen Slot-Preis gewichtet (nicht
     * nur dem aktuellen Preis - Tibber-Preise schwanken stuendlich). $sign
     * waehlt, welche Vorzeichenhaelfte der Leistung zaehlt: 1 = nur
     * Verbrauch/positive Werte (Wallbox, Waermepumpe), -1 = nur Bezug/
     * negative Werte (Netz, "+"=Einspeisung in unserer Konvention). null,
     * falls fuer keinen Zeitpunkt ein Preis vorlag (z.B. Luecke in der
     * Preiskurve) - dann lieber keine (ggf. falsche) Zahl zeigen.
     */
    private function DayCostEUR(array $powerSeries, array $priceSlots, int $sign): ?float
    {
        if (count($powerSeries) < 2 || count($priceSlots) === 0) {
            return null;
        }
        $intervalHours = ((float) ($powerSeries[1][0] - $powerSeries[0][0])) / 3600000;
        $costCt = 0.0;
        $any = false;
        foreach ($powerSeries as [$tsMs, $w]) {
            $w = (float) $w;
            if (($sign > 0 && $w <= 0) || ($sign < 0 && $w >= 0)) {
                continue;
            }
            $price = $this->PriceAt($priceSlots, intdiv((int) $tsMs, 1000));
            if ($price === null) {
                continue;
            }
            $any = true;
            $kwh = abs($w) * $intervalHours / 1000;
            $costCt += $kwh * $price;
        }
        return $any ? ($costCt / 100) : null;
    }

    private function singleInverterHubCoreID(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(NRGDASH_GUID_INVERTERHUB);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    /**
     * Rekursive Ident-Suche (Muster: NRGDashboardPVMonitor::FindVarByIdent(),
     * 1:1 uebernommen) - IPS_GetObjectIDByIdent findet nur DIREKTE Kinder,
     * InverterHubs Treiber verschieben ihre Variablen aber sofort nach
     * Anlage in fachliche Unterkategorien (z.B. "PV / MPPT").
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

    private function PvfInstanceID(): int
    {
        $explicit = $this->readIntProperty('PvfInstance', 0);
        if ($explicit > 0 && IPS_InstanceExists($explicit)) {
            return $explicit;
        }
        $ids = @IPS_GetInstanceListByModuleID(NRGDASH_GUID_PVPROGNOSE);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    /**
     * Generatorparameter der PV-Prognose (Muster: InverterHubMonitor::
     * PvfModel(), reduziert auf das, was die Diagnose braucht: Gesamt-kWp +
     * Performance-Ratio - keine Temperaturkorrektur, die betrifft nur die
     * Erwartungskurve im Monitor-Tab, nicht diese Schwellenbewertung).
     */
    private function PvfModel(): ?array
    {
        $id = $this->PvfInstanceID();
        if ($id <= 0 || !function_exists('PVF_GetGenerators')) {
            return null;
        }
        $r = @PVF_GetGenerators($id);
        if (!is_array($r) || !isset($r['generators']) || !is_array($r['generators'])) {
            return null;
        }
        $pr = (float) ($r['pr'] ?? 0);
        if ($pr <= 0.0) {
            $pr = 0.85;
        }
        $total = 0.0;
        foreach ($r['generators'] as $g) {
            $total += (float) ($g['kwp'] ?? 0);
        }
        if ($total <= 0.0) {
            return null;
        }
        return ['pr' => $pr, 'totalKwp' => $total];
    }

    /**
     * InverterHub folgt NICHT dem MHUB_GetFunctions-Listenmuster: IHUB_GetFunctions
     * liefert pro physischer Instanz ein OBJEKT (contractVersion/instanceID/
     * manufacturer/measured/pvPowerID/acPowerID/batPowerID/gridPowerID/socID/...),
     * keine Liste von {function,label,powerID}-Einträgen (siehe InverterHub/
     * CLAUDE.md, Abschnitt Vertragsversionierung). Ein anfänglicher Versuch, das
     * über discoverListContract() mit den anderen Hubs zu behandeln, hat PV/
     * Batterie/Netz stillschweigend verworfen (kein 'function'-Feld vorhanden) -
     * deshalb eine eigene Uebersetzung: jede vorhandene *PowerID wird zu einem
     * eigenen Geräte-Eintrag (pv/battery/grid), analog zu einer Zuordnung bei
     * MeterHub. Batterie bekommt zusätzlich socID fürs Ladestands-Icon.
     */
    /**
     * Meldet sichtbar (Log), wenn ein installiertes Partnermodul ZWAR
     * Instanzen hat, aber keinen einzigen auswertbaren Geraete-Eintrag
     * geliefert hat. Zwei legitime Ursachen, deshalb bewusst neutral
     * formuliert statt vorschnell "Fehler" zu behaupten: (1) die Instanzen
     * sind schlicht noch nicht konfiguriert (z.B. MeterHub ohne
     * Funktionszuordnung - live beobachteter Normalfall, kein Bug), oder
     * (2) der Datenvertrag hat sich in Form/Feldern geaendert (unser
     * eigener discoverInverterHub-Fehler vom 27.07.2026 waere so sofort im
     * Log aufgefallen, statt erst durch eine manuelle Live-Pruefung). Kein
     * Fehler, wenn schlicht keine Instanz installiert ist - das ist der
     * normale, unterstuetzte Fall.
     */
    private function checkSourceCoverage(string $label, string $moduleGUID, int $foundCount): void
    {
        $instanceCount = count(IPS_GetInstanceListByModuleID($moduleGUID));
        if ($instanceCount > 0 && $foundCount === 0) {
            $this->LogMessage(
                sprintf(
                    'ℹ️ %s ist installiert (%d Instanz(en)), liefert aber keine auswertbaren Geräte - ' .
                    'entweder ist dort noch keine Funktionszuordnung konfiguriert, oder der Datenvertrag ' .
                    'hat sich geändert.',
                    $label,
                    $instanceCount
                ),
                KL_WARNING
            );
        }
    }

    private function discoverInverterHub(): array
    {
        $results = [];
        if (!function_exists('IHUB_GetFunctions')) {
            return $results;
        }
        foreach (IPS_GetInstanceListByModuleID(NRGDASH_GUID_INVERTERHUB) as $id) {
            $data = IHUB_GetFunctions($id);
            if (!is_array($data)) {
                continue;
            }
            $measured = $data['measured'] ?? true;
            // Feste Node-Labels wie in InverterHubTile (NODE_DEFS_LEAD/TAIL:
            // 'Solar'/'Batterie'/'Netz') - NICHT der Instanzname. Vorheriger
            // Fehler: IPS_GetName($id) fuer alle drei verwendet, wodurch jeder
            // Knoten denselben Instanznamen ("InverterHub WR1 (GoodWe)") trug
            // statt seiner Rolle.
            //
            // Workaround vom 27.07.2026 wieder ENTFERNT (nicht nur zurueckgesetzt -
            // siehe Git-Historie fuer den Code): InverterHub hat den eigentlichen
            // Bug gefunden (Commit 96349f1) - MeterInvert wird bereits beim
            // SCHREIBEN von gridPowerID angewendet (kanonisch, wie urspruenglich
            // zugesichert); ihre eigene Kachel hatte einen Doppel-Invert-Bug
            // (Property zusaetzlich beim Lesen nochmal angewendet), der sich
            // durch die zweite Inversion "zufaellig" richtig anfuehlte. Unser
            // MeterInvert-Workaround haette auf dem jetzt korrigierten Stand
            // GENAU DENSELBEN Doppel-Invert-Fehler bei uns reproduziert - eine
            // zweite Korrektur auf einem bereits korrigierten Wert. Bleibt eine
            // Diskrepanz an Dietmars konkreter Instanz (#52838): dort steht
            // MeterInvert laut InverterHub inhaltlich falsch (Konfigurationsfehler,
            // kein Vertragsbruch) - das faellt in deren Zustaendigkeit, nicht
            // unsere; wir lesen gridPowerID ab jetzt wieder unveraendert.
            $map = [
                'pv'      => ['Solar',    $data['pvPowerID']   ?? 0],
                'battery' => ['Batterie', $data['batPowerID']  ?? 0],
                'grid'    => ['Netz',     $data['gridPowerID'] ?? 0],
            ];
            foreach ($map as $function => [$label, $powerID]) {
                if (!$powerID) {
                    continue;
                }
                $entry = [
                    'function' => $function,
                    'label'    => $label,
                    'powerID'  => $powerID,
                    'measured' => $measured,
                ];
                if ($function === 'battery' && !empty($data['socID'])) {
                    $entry['socID'] = $data['socID'];
                }
                // Batterie-Block-Details (InverterHub-Vertrag 1.1, 28.08.2026) -
                // fuer die Krisen-/Notfall-Kennzahl "Restlaufzeit bei
                // Stromausfall" (siehe BuildDetailPayload()) sowie generisch
                // ueber DetailValues() als Temperatur/SOC/SOH je Block. Additiv
                // pruefen, aeltere InverterHub-Versionen liefern diese Felder
                // schlicht nicht mit (kein Fehler, nur fehlende Werte).
                if ($function === 'battery') {
                    if (!empty($data['batteryTempIDs'])) {
                        $entry['batteryTempIDs'] = $data['batteryTempIDs'];
                    }
                    if (!empty($data['batterySocIDs'])) {
                        $entry['batterySocIDs'] = $data['batterySocIDs'];
                    }
                    if (!empty($data['batterySohIDs'])) {
                        $entry['batterySohIDs'] = $data['batterySohIDs'];
                    }
                    if (!empty($data['batteryCapacityID'])) {
                        $entry['batteryCapacityID'] = $data['batteryCapacityID'];
                    }
                }
                // MPPT-Strangdetails (InverterHub-Vertrag 1.2, 28.08.2026) -
                // fuer die Stromwerte-Tabelle je Strang auf der Solar-
                // Detailseite (DetailValues() gruppiert *IDs-Arrays generisch
                // per Index zu einer Tabelle, siehe detail.html splitGroups()).
                if ($function === 'pv') {
                    if (!empty($data['mpptPowerIDs'])) {
                        $entry['mpptPowerIDs'] = $data['mpptPowerIDs'];
                    }
                    if (!empty($data['mpptCurrentIDs'])) {
                        $entry['mpptCurrentIDs'] = $data['mpptCurrentIDs'];
                    }
                    if (!empty($data['mpptVoltageIDs'])) {
                        $entry['mpptVoltageIDs'] = $data['mpptVoltageIDs'];
                    }
                }
                $results[] = $this->normalizeEntry($entry, 'inverterhub', $id);
            }
        }
        return $results;
    }

    /**
     * IHUBTILE_GetConsumers($id) (InverterHubTile, ab Commit 0f09445) liefert
     * die gemischte Verbraucherliste, die deren eigene Kachel rendert: manuell
     * in der Consumers-Property eingetragene Geraete + MeterHub + HeishaMon,
     * bereits auf ein Consumer-Type-Schluesselvokabular normalisiert
     * ('type' entspricht 1:1 unseren CONSUMER_TYPES-Schluesseln in
     * module.html, keine weitere Uebersetzung noetig). Feldname der
     * Variablen-ID ist bewusst 'id', nicht 'powerID' - hier auf unser
     * Schema uebersetzt.
     */
    private function discoverInverterHubTileConsumers(): array
    {
        $results = [];
        if (!function_exists('IHUBTILE_GetConsumers')) {
            return $results;
        }
        foreach (IPS_GetInstanceListByModuleID(NRGDASH_GUID_INVERTERHUBTILE) as $id) {
            // Verteidigung in der Tiefe (27.07.2026): IHUBTILE_GetConsumers()
            // hat zwischenzeitlich live die Signatur gewechselt (2 statt 1
            // Parameter verlangt) und liess Discover() dadurch komplett mit
            // einem Fatal Error abbrechen - function_exists() allein schuetzt
            // NICHT vor einer falschen Parameterzahl. Try/catch begrenzt den
            // Schaden auf diese eine Quelle, statt die gesamte Discovery zu
            // verlieren (Verbund-Grundregel: kein Modul setzt ein anderes
            // ungeprueft voraus).
            try {
                $entries = IHUBTILE_GetConsumers($id);
            } catch (\Throwable $e) {
                $this->LogMessage(
                    '⚠️ IHUBTILE_GetConsumers($id) ist fehlgeschlagen (' . $e->getMessage() . ') - Verbraucherliste von InverterHubTile wird übersprungen.',
                    KL_WARNING
                );
                continue;
            }
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry) || empty($entry['type']) || empty($entry['id'])) {
                    continue;
                }
                $results[] = $this->normalizeEntry([
                    'function' => $entry['type'],
                    'label'    => $entry['label'] ?? $entry['type'],
                    'powerID'  => $entry['id'],
                    'measured' => true,
                ], 'inverterhubtile', $id);
            }
        }
        return $results;
    }

    /**
     * IHUBTILE_GetHouseLoad($id) (InverterHubTile, ab Commit cf33250):
     * liefert houseLoadID > 0, wenn die Kachel einen ECHTEN Hauslast-Zaehler
     * bevorzugt (eigene Prioritaetskette: HouseLoadID-Property > Quell-
     * instanz HouseLoadMeterID/ManualHouseID > MeterHub-Kernwert), sonst 0
     * (dann rechnet auch InverterHubTile selbst nur die Bilanz). Nur bei
     * houseLoadID > 0 ein 'house'-Geraet einhaengen - module.html bevorzugt
     * ein vorhandenes 'house'-Geraet ohnehin schon vor der eigenen
     * pv-grid+bat-Naeherung (siehe handleMessage()).
     */
    private function discoverInverterHubTileHouseLoad(): array
    {
        $results = [];
        if (!function_exists('IHUBTILE_GetHouseLoad')) {
            return $results;
        }
        foreach (IPS_GetInstanceListByModuleID(NRGDASH_GUID_INVERTERHUBTILE) as $id) {
            try {
                $data = IHUBTILE_GetHouseLoad($id);
            } catch (\Throwable $e) {
                $this->LogMessage(
                    '⚠️ IHUBTILE_GetHouseLoad($id) ist fehlgeschlagen (' . $e->getMessage() . ') - echte Hauslast-Quelle wird übersprungen, Näherung (pv-grid+bat) greift stattdessen.',
                    KL_WARNING
                );
                continue;
            }
            if (!is_array($data) || empty($data['houseLoadID'])) {
                continue;
            }
            $results[] = $this->normalizeEntry([
                'function' => 'house',
                'label'    => 'Haus',
                'powerID'  => $data['houseLoadID'],
                'measured' => true,
            ], 'inverterhubtile', $id);
        }
        return $results;
    }

    /**
     * Manuelle Kernwerte (Muster: InverterHubTile "Manuelle Datenpunkte") -
     * fuer Haushalte ganz ohne InverterHub-Instanz. Jedes Feld ist optional;
     * nur belegte IDs werden zu einem Geraet. Invert-Schalter analog
     * InverterHubTile: Netz +=Einspeisung/-=Bezug, Batterie +=Entladen/
     * -=Laden - stimmt die Richtung nicht, hier umschalten.
     *
     * Bewusst NICHT uebernommen (Umfang begrenzt fuer diese Runde): die
     * Einheit-Auswahl (Automatisch/W/kW/MW) je Feld - Werte werden bei uns
     * unveraendert in der gelieferten Einheit (Watt) erwartet. Bei Bedarf
     * nachruestbar, sobald es einen konkreten Anwendungsfall gibt.
     */
    private function discoverManualCore(): array
    {
        $results = [];
        $pv = $this->readIntProperty('ManualPvID', 0);
        if ($pv > 0) {
            $results[] = $this->normalizeEntry([
                'function' => 'pv', 'label' => 'Solar', 'powerID' => $pv, 'measured' => true,
            ], 'manual', 0);
        }
        $grid = $this->readIntProperty('ManualGridID', 0);
        if ($grid > 0) {
            $entry = [
                'function' => 'grid', 'label' => 'Netz', 'powerID' => $grid, 'measured' => true,
                'invert'   => $this->readBoolProperty('ManualGridInvert'),
            ];
            $results[] = $this->normalizeEntry($entry, 'manual', 0);
        }
        $bat = $this->readIntProperty('ManualBatID', 0);
        if ($bat > 0) {
            $entry = [
                'function' => 'battery', 'label' => 'Batterie', 'powerID' => $bat, 'measured' => true,
                'invert'   => $this->readBoolProperty('ManualBatInvert'),
            ];
            $soc = $this->readIntProperty('ManualSocID', 0);
            if ($soc > 0) {
                $entry['socID'] = $soc;
            }
            $results[] = $this->normalizeEntry($entry, 'manual', 0);
        }
        $house = $this->readIntProperty('ManualHouseID', 0);
        if ($house > 0) {
            $results[] = $this->normalizeEntry([
                'function' => 'house', 'label' => 'Haus', 'powerID' => $house, 'measured' => true,
            ], 'manual', 0);
        }
        return $results;
    }

    /**
     * Frei editierbare Verbraucherliste (Muster: InverterHubTile "Weitere
     * Verbraucher") - unabhaengig von jedem Hub-Modul, weil nicht jeder
     * Haushalt dieselben Geraete hat (Dietmar, 27.07.2026). Jede Zeile:
     * Type (Schluessel aus CONSUMER_TYPES in module.html), Name, VariableID,
     * optional PlugID/PlugOp/PlugVal fuer den "eingesteckt"-Sonderfall
     * (siehe resolvePluggedCondition()).
     */
    private function discoverManualConsumers(): array
    {
        $results = [];
        $rows = json_decode($this->readStringProperty('Consumers', '[]'), true);
        if (!is_array($rows)) {
            return $results;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['Type']) || empty($row['VariableID'])) {
                continue;
            }
            $entry = [
                'function' => $row['Type'],
                'label'    => $row['Name'] ?? $row['Type'],
                'powerID'  => (int) $row['VariableID'],
                'measured' => true,
            ];
            if (!empty($row['PlugID'])) {
                $entry['plugStateID'] = (int) $row['PlugID'];
                $entry['plugOp']      = $row['PlugOp']  ?? 'truthy';
                $entry['plugVal']     = $row['PlugVal'] ?? '';
            }
            // Optionales SocID (kein eigenes Formularfeld, JSON-Zeile reicht -
            // z.B. fuer eine manuell eingetragene Wallbox mit Fahrzeug ohne
            // eigenes Partnermodul): buildPayload() loest 'soc' daraus auf,
            // exakt wie bei automatisch erkannten Geraeten (Zeile 1038).
            if (!empty($row['SocID'])) {
                $entry['socID'] = (int) $row['SocID'];
            }
            // Beliebige weitere *ID-/*IDs-Felder generisch durchreichen
            // (03.09.2026, Dietmar: "es fehlen Werte, Werte und nochmals
            // Werte") - DetailValues() zeigt ohnehin JEDES *ID-/*IDs-Feld
            // automatisch in der "Aktuelle Werte"-Tabelle an (type-neutral,
            // Kernprinzip 2), das gilt jetzt auch fuer manuell eingetragene
            // Verbraucher, nicht nur fuer Hub-Vertraege. Feldname direkt wie
            // vom Aufrufer (JSON-Zeile) vorgegeben uebernehmen, z.B.
            // "chargeEnableID"/"currentLimitID" fuer eine Demo-Wallbox mit
            // funktionierenden Steuer-Buttons, oder "vinID"/"rangeKmID" fuer
            // zusaetzliche Fahrzeug-Kennwerte. Bereits behandelte Felder
            // (Type/Name/VariableID/PlugID/PlugOp/PlugVal/SocID) ausnehmen.
            $handled = ['Type', 'Name', 'VariableID', 'PlugID', 'PlugOp', 'PlugVal', 'SocID'];
            foreach ($row as $field => $val) {
                if (in_array($field, $handled, true)) {
                    continue;
                }
                if (preg_match('/IDs$/', $field) && is_array($val)) {
                    $entry[$field] = array_map('intval', $val);
                } elseif (preg_match('/ID$/', $field) && is_numeric($val)) {
                    $entry[$field] = (int) $val;
                }
            }
            // Verschachtelte Mitglieder (03.09.2026, Aufschachteln): eine manuell
            // eingetragene Zeile darf ein 'Members'-Array tragen (gleiche
            // Feldnamen wie eine Zeile: Type/Name/VariableID/Factor/..., beliebig
            // tief). Gedacht fuer Haushalte ohne MeterHub UND fuer die
            // Vorstellungs-Instanz - die echte Hierarchie kommt sonst aus
            // MeterHubVirtual (Vertrag 1.3 'members'), siehe attachMembers().
            if (!empty($row['Members']) && is_array($row['Members'])) {
                $entry['members'] = $this->normalizeManualMembers($row['Members']);
            }
            $results[] = $this->normalizeEntry($entry, 'manual', 0);
        }
        return $results;
    }

    /**
     * Gemeinsamer Pfad für alle Partner, die dem MHUB_GetFunctions-Muster
     * folgen (Liste von Einträgen mit function/label/powerID/...): Instanzen
     * suchen, Vertrag abrufen, jeden Eintrag um Quelle und Kategorie
     * ergänzen. Ohne installiertes Partnermodul bleibt die Liste leer -
     * Verbund-Grundregel, kein Modul setzt ein anderes voraus.
     */
    private function discoverListContract(string $moduleGUID, string $function, string $source): array
    {
        $results = [];
        if (!function_exists($function)) {
            return $results;
        }
        foreach (IPS_GetInstanceListByModuleID($moduleGUID) as $id) {
            $entries = call_user_func($function, $id);
            // Real gefundener Bug (27.07.2026): MHUB_GetFunctions() ist als
            // `: string` deklariert und liefert ein JSON-kodiertes Array,
            // waehrend z.B. CHUB_GetFunctions() `: array` direkt zurueckgibt.
            // Der reine is_array()-Check hat dadurch JEDES MeterHub-Ergebnis
            // stillschweigend verworfen - 5 echte Zaehler (4x Shelly Pro 3EM,
            // 1x Siemens PAC2200) fielen komplett aus der Discovery, obwohl
            // der Vertragsaufruf selbst einwandfrei funktionierte.
            if (is_string($entries)) {
                $entries = json_decode($entries, true);
            }
            if (!is_array($entries)) {
                continue;
            }
            // Zweiter Struktur-Unterschied, direkt danach live entdeckt:
            // MHUB_GetFunctions ist ein OBJEKT-Vertrag (Instanz-Metadaten wie
            // 'meter'/'measureMode' plus ein 'assignments'-Array mit den
            // eigentlichen Eintraegen), waehrend z.B. CHUB_GetFunctions eine
            // FLACHE Liste direkt zurueckgibt. Ein reiner is_array()-Check
            // reicht nicht - ohne diesen Zweig waeren alle 'meter'/'measureMode'-
            // Metadatenfelder faelschlich als Eintraege durchgereicht worden
            // (und htten kein 'function'-Feld, wie unten geprueft).
            if (isset($entries['assignments']) && is_array($entries['assignments'])) {
                $entries = $entries['assignments'];
            }
            foreach ($entries as $entry) {
                if (!is_array($entry) || !isset($entry['function'])) {
                    continue;
                }
                $results[] = $this->normalizeEntry($entry, $source, $id);
            }
        }
        return $results;
    }

    /**
     * HeishaMon weicht bewusst vom function/label-Vokabular ab
     * (Type/Caption/PowerID/EnergyID/Measured, vor der Verbund-Konvention
     * veröffentlicht - ein publizierter Vertrag wird nicht umbenannt). Die
     * Uebersetzung liegt auf der Konsumentenseite, hier.
     */
    private function discoverHeishaMon(): array
    {
        $results = [];
        if (!function_exists('HEISHA_GetFunctions')) {
            return $results;
        }
        foreach (IPS_GetInstanceListByModuleID(NRGDASH_GUID_HEISHAMON) as $id) {
            $entries = HEISHA_GetFunctions($id);
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry) || !isset($entry['Type'])) {
                    continue;
                }
                $results[] = $this->normalizeEntry([
                    'function'       => $entry['Type'],
                    'label'          => $entry['Caption'] ?? IPS_GetName($id),
                    'powerID'        => $entry['PowerID'] ?? 0,
                    'energyImportID' => $entry['EnergyID'] ?? 0,
                    'measured'       => $entry['Measured'] ?? true,
                ], 'heishamon', $id);
            }
        }
        return $results;
    }

    /**
     * Tessie liefert ein Objekt-Vertrag (kein GetFunctions), daher eigene
     * Uebersetzung: ein Fahrzeug wird als Gerät vom Typ 'vehicle' geführt.
     */
    private function discoverTessie(): array
    {
        $results = [];
        if (!function_exists('TESSIE_GetVehicleState')) {
            return $results;
        }
        foreach (IPS_GetInstanceListByModuleID(NRGDASH_GUID_TESSIE) as $id) {
            $state = TESSIE_GetVehicleState($id);
            if (!is_array($state)) {
                continue;
            }
            $results[] = $this->normalizeEntry([
                'function' => 'vehicle',
                'label'    => IPS_GetName($id),
                'socID'    => $state['socID'] ?? 0,
            ], 'tessie', $id);
        }
        return $results;
    }

    /**
     * Ein Eintrag im gemeinsamen Geräte-Schema der Kachel: function/label
     * wie im Verbund üblich, dazu Herkunft (Quellmodul) und instanceID
     * (welche Partnerinstanz das geliefert hat - für Nachverfolgung bei
     * mehreren gleichartigen Instanzen) sowie die vorläufige Kategorie
     * für die Anordnung in Phase 2.
     */
    private function normalizeEntry(array $entry, string $source, int $instanceID): array
    {
        $entry['source'] = $source;
        // Bevorzugt die vom Vertrag selbst gelieferte instanceID (Fall
        // OCPPHub, 30.08.2026: der Splitter sammelt ueber OHUB_GetFunctions()
        // die Eintraege ALLER eigenen Ladepunkt-Kinder ein - die Splitter-ID
        // selbst waere fuer Steuerungsaufrufe wie OHUBL_ManualStart() falsch,
        // jeder Eintrag braucht seine EIGENE Instanz-ID). Faellt sonst wie
        // bisher auf die Instanz zurueck, auf der die Vertragsfunktion
        // aufgerufen wurde (1:1-Faelle wie ChargerHub/MeterHub).
        $entry['instanceID'] = $entry['instanceID'] ?? $instanceID;
        $entry['category']   = $this->functionCategory((string) $entry['function']);
        return $entry;
    }

    /**
     * Ordnet einen function-Wert des Verbund-Vokabulars einer der vier
     * Anzeigekategorien zu (Erzeugung -> Speicher -> Verteilung ->
     * Verbraucher, siehe SUITE.md-Formular-Konvention). Unbekannte Werte
     * fallen auf 'verbraucher' zurück, statt zu verschwinden - lieber
     * falsch einsortiert als unsichtbar.
     */
    private function functionCategory(string $function): string
    {
        $map = [
            'pv'      => 'erzeugung',
            'battery' => 'speicher',
            'grid'    => 'verteilung',
            'house'   => 'verbraucher',
            'charger' => 'verbraucher',
            'heatpump' => 'verbraucher',
            'vehicle'  => 'verbraucher',
        ];
        if (isset($map[$function])) {
            return $map[$function];
        }
        // Wallbox-Kanäle aus MeterHub (wallbox1..5) und ähnlich
        // präfixierte Verbraucherkanäle fallen ebenfalls auf Verbraucher.
        return 'verbraucher';
    }

    // ------------------------------------------------------------------
    // Geraete-Detailansicht (Klick auf einen Knoten, 28.08.2026)
    // ------------------------------------------------------------------

    /**
     * Findet ein Geraet ueber seinen discovery-stabilen deviceKey() -
     * derselbe Schluessel, den buildPayload() als detailKey mitgibt.
     */
    /* ==================================================================
     * Aufschachteln (03.09.2026, Dietmar): Sammelknoten (virtuelle Zaehler
     * mit Unterzaehlern) lassen sich in der Kachel per Klick in die naechste
     * Ebene oeffnen. Zwei Quellen, EINE Normalform je Mitglied:
     *   ['key','label','function','factor','powerID','energyImportID',
     *    'energyExportID','instanceID','hasMembers']
     * - MeterHubVirtual, Vertrag 1.3: 'members' je Zuordnung (Ebene 1) und
     *   auf INSTANZEBENE (jede weitere Ebene - Zwischenknoten einer
     *   Verkettung haben typischerweise keine Dashboard-Funktion, dort ist
     *   'assignments' leer; MeterHub-Hinweis 03.09.2026).
     * - Manuelle Verbraucher: verschachteltes 'Members' in der Zeile.
     * Rekursion: ist der Parent einer Mitglieds-powerID selbst eine
     * MeterHubVirtual-Instanz, liefert deren GetFunctions() die naechste
     * Ebene - MeterHub selbst gibt nur die eigene Ebene aus (abgestimmt).
     * Mitglieds-Schluessel = <Geraete-Key>'>'<Index>['>'<Index>...] - damit
     * FindDeviceByKey() ein Mitglied jeder Tiefe als synthetisches Geraet
     * aufloesen kann (Detailseite ueber dieselbe DetailValues()-Mechanik).
     * ================================================================== */

    private const MEMBER_MAX_DEPTH = 6;

    /** Normalisiert die Mitglieder eines Top-Level-Geraets (nur EINE Ebene). */
    private function attachMembers(array $d): array
    {
        $raw = $d['members'] ?? null;
        if (!is_array($raw) || count($raw) === 0) {
            // MHUBV-Eintrag ohne eigene Zuordnungs-Mitglieder (z.B. aeltere
            // Vertragsversion) - Instanz-Feld als Rueckfall versuchen.
            $inst = (int) ($d['instanceID'] ?? 0);
            if (($d['source'] ?? '') === 'meterhub' && $inst > 0 && $this->isMhubvInstance($inst)) {
                $raw = $this->mhubvMembersOf($inst);
            }
        }
        if (!is_array($raw) || count($raw) === 0) {
            unset($d['members']);
            $d['hasMembers'] = false;
            $d['memberCount'] = 0;
            return $d;
        }
        $d['members'] = $this->normalizeMemberList($raw, $this->deviceKey($d), (string) ($d['function'] ?? 'other'), 1);
        $d['hasMembers'] = count($d['members']) > 0;
        $d['memberCount'] = count($d['members']);
        return $d;
    }

    /** Manuelle 'Members'-Zeilen (Type/Name/VariableID/Factor/...) -> Vertragsform. */
    private function normalizeManualMembers(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $vid = (int) ($row['VariableID'] ?? $row['powerID'] ?? 0);
            if ($vid <= 0) { continue; }
            $m = [
                'name'           => (string) ($row['Name'] ?? $row['name'] ?? ''),
                'function'       => (string) ($row['Type'] ?? $row['function'] ?? 'other'),
                'factor'         => (float) ($row['Factor'] ?? $row['factor'] ?? 100),
                'powerID'        => $vid,
                'energyImportID' => (int) ($row['EnergyImportID'] ?? $row['energyImportID'] ?? 0),
                'energyExportID' => (int) ($row['EnergyExportID'] ?? $row['energyExportID'] ?? 0),
            ];
            if (!empty($row['SocID'])) { $m['socID'] = (int) $row['SocID']; }
            if (!empty($row['SwitchID'])) { $m['switchID'] = (int) $row['SwitchID']; }
            if (!empty($row['SwitchStateID'])) { $m['switchStateID'] = (int) $row['SwitchStateID']; }
            if (!empty($row['Members']) && is_array($row['Members'])) {
                $m['members'] = $this->normalizeManualMembers($row['Members']);
            }
            $out[] = $m;
        }
        return $out;
    }

    /**
     * Eine Mitgliederliste (Vertragsform) auf die Kachel-Normalform bringen.
     * $parentFunction: Rueckfall fuer Mitglieder ohne eigene 'function'
     * (MeterHub liefert keine - ein Mitglied ist dort nur "ein Term").
     */
    private function normalizeMemberList(array $raw, string $parentKey, string $parentFunction, int $depth): array
    {
        $out = [];
        foreach (array_values($raw) as $i => $m) {
            if (!is_array($m)) { continue; }
            $pid = (int) ($m['powerID'] ?? 0);
            $srcInst = ($pid > 0 && IPS_VariableExists($pid)) ? (int) IPS_GetParent($pid) : 0;
            $nested = $m['members'] ?? null; // manuelle Verschachtelung
            $hasKids = is_array($nested) && count($nested) > 0;
            if (!$hasKids && $depth < self::MEMBER_MAX_DEPTH && $srcInst > 0 && $this->isMhubvInstance($srcInst)) {
                $hasKids = count($this->mhubvMembersOf($srcInst)) > 0;
            }
            $out[] = [
                'key'            => $parentKey . '>' . $i,
                'label'          => trim((string) ($m['name'] ?? $m['label'] ?? '')) ?: ('Mitglied ' . ($i + 1)),
                'function'       => (string) ($m['function'] ?? $parentFunction),
                'factor'         => (float) ($m['factor'] ?? 100),
                'powerID'        => $pid,
                'energyImportID' => (int) ($m['energyImportID'] ?? 0),
                'energyExportID' => (int) ($m['energyExportID'] ?? 0),
                'socID'          => (int) ($m['socID'] ?? 0),
                'instanceID'     => $srcInst,
                'hasMembers'     => $hasKids,
                // Schaltgruppen (MeterHub-Vertrag 1.4, 03.09.2026): switchID
                // (Bool, per EnableAction() steuerbar wie chargeEnableID) je
                // Mitglied - auch bei abgezogenen Zeilen (einzeln bleibt ein
                // Mitglied schaltbar, auch wenn es nicht zur Gruppensumme
                // zaehlt). switchStateID (0 aus/1 teilweise/2 an) nur bei
                // Mitgliedern, die selbst wieder eine Gruppe sind.
                'switchID'       => (int) ($m['switchID'] ?? 0),
                'switchStateID'  => (int) ($m['switchStateID'] ?? 0),
            ];
        }
        return $out;
    }

    private function isMhubvInstance(int $inst): bool
    {
        if ($inst <= 0 || !IPS_InstanceExists($inst)) { return false; }
        return (IPS_GetInstance($inst)['ModuleInfo']['ModuleID'] ?? '') === NRGDASH_GUID_METERHUBV;
    }

    /** Instanz-Feld 'members' einer MeterHubVirtual-Instanz (Vertrag 1.3), leer wenn nicht vorhanden. */
    private function mhubvMembersOf(int $inst): array
    {
        if (!function_exists('MHUBV_GetFunctions') || !$this->isMhubvInstance($inst)) { return []; }
        try {
            $raw = @MHUBV_GetFunctions($inst);
        } catch (\Throwable $e) {
            return [];
        }
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($data)) { return []; }
        // Instanz-Feld bevorzugen (immer da, auch bei Zwischenknoten ohne
        // Funktion), Zuordnungs-Feld als Rueckfall.
        if (!empty($data['members']) && is_array($data['members'])) { return $data['members']; }
        foreach (($data['assignments'] ?? []) as $a) {
            if (is_array($a) && !empty($a['members']) && is_array($a['members'])) { return $a['members']; }
        }
        return [];
    }

    /**
     * Mitglieder eines beliebigen Schluessels (Top-Level-Geraet ODER Mitglied
     * jeder Tiefe) - fuer den ?members=-Hook. Liefert die Kachel-Normalform
     * inkl. aufgeloestem Momentanwert ('value') und SOC.
     */
    private function MembersForKey(string $key): array
    {
        $parent = $this->FindDeviceByKey($key);
        if ($parent === null) { return []; }
        $parentFunction = (string) ($parent['function'] ?? 'other');
        $depth = substr_count($key, '>') + 1;
        $list = null;
        if (!empty($parent['members']) && is_array($parent['members'])) {
            // Top-Level (schon normalisiert) oder manuell verschachtelt (roh)
            $first = reset($parent['members']);
            $list = (is_array($first) && isset($first['key']))
                ? $parent['members']
                : $this->normalizeMemberList($parent['members'], $key, $parentFunction, $depth);
        } elseif (($parent['instanceID'] ?? 0) > 0 && $this->isMhubvInstance((int) $parent['instanceID'])) {
            $list = $this->normalizeMemberList($this->mhubvMembersOf((int) $parent['instanceID']), $key, $parentFunction, $depth);
        }
        if (!is_array($list)) { return []; }
        foreach ($list as &$m) {
            $m['value'] = ($m['powerID'] > 0) ? $this->resolveVariableValue((int) $m['powerID']) : null;
            $m['soc'] = (!empty($m['socID']) && IPS_VariableExists((int) $m['socID'])) ? $this->resolveVariableValue((int) $m['socID']) : null;
            $m['switchable'] = (!empty($m['switchID']) && IPS_VariableExists((int) $m['switchID']));
            $m['switchOn'] = $m['switchable'] ? (bool) GetValueBoolean((int) $m['switchID']) : null;
            $m['switchState'] = (!empty($m['switchStateID']) && IPS_VariableExists((int) $m['switchStateID'])) ? (int) GetValue((int) $m['switchStateID']) : null;
            $m['detailKey'] = $m['key'];
        }
        unset($m);
        return $list;
    }

    /** Loest einen Mitglieds-Schluessel (<Key>'>'<i>...) zu einem synthetischen Geraet auf. */
    private function resolveMemberChain(string $key): ?array
    {
        $parts = explode('>', $key);
        $rootKey = array_shift($parts);
        $node = null;
        foreach ($this->GetDevices() as $d) {
            if ($this->deviceKey($d) === $rootKey) { $node = $d; break; }
        }
        if ($node === null) { return null; }
        $curKey = $rootKey;
        foreach ($parts as $idxStr) {
            if (!ctype_digit((string) $idxStr)) { return null; }
            $idx = (int) $idxStr;
            $depth = substr_count($curKey, '>') + 1;
            $parentFunction = (string) ($node['function'] ?? 'other');
            $list = null;
            if (!empty($node['members']) && is_array($node['members'])) {
                $first = reset($node['members']);
                $list = (is_array($first) && isset($first['key']))
                    ? $node['members']
                    : $this->normalizeMemberList($node['members'], $curKey, $parentFunction, $depth);
                $rawList = $node['members'];
            } elseif (($node['instanceID'] ?? 0) > 0 && $this->isMhubvInstance((int) $node['instanceID'])) {
                $rawList = $this->mhubvMembersOf((int) $node['instanceID']);
                $list = $this->normalizeMemberList($rawList, $curKey, $parentFunction, $depth);
            } else {
                return null;
            }
            if (!isset($list[$idx])) { return null; }
            $m = $list[$idx];
            // Manuell verschachtelte Roh-Mitglieder mitfuehren, damit die
            // naechste Runde sie wiederfindet.
            $rawChild = (isset($rawList) && is_array($rawList)) ? (array_values($rawList)[$idx] ?? []) : [];
            $node = $this->synthesizeMemberDevice($m, isset($rawChild['members']) ? $rawChild['members'] : null);
            $curKey = $m['key'];
        }
        return $node;
    }

    /** Ein Mitglied als vollwertiges Geraet (fuer BuildDetailPayload/DetailValues). */
    private function synthesizeMemberDevice(array $m, ?array $rawNested): array
    {
        $d = [
            'function'       => (string) ($m['function'] ?? 'other'),
            'label'          => (string) ($m['label'] ?? 'Mitglied'),
            'powerID'        => (int) ($m['powerID'] ?? 0),
            'energyImportID' => (int) ($m['energyImportID'] ?? 0),
            'energyExportID' => (int) ($m['energyExportID'] ?? 0),
            'measured'       => true,
            'source'         => 'member',
            'instanceID'     => (int) ($m['instanceID'] ?? 0),
            'category'       => 'verbraucher',
            'factor'         => (float) ($m['factor'] ?? 100),
            'detailKey'      => (string) ($m['key'] ?? ''),
            'hasMembers'     => (bool) ($m['hasMembers'] ?? false),
        ];
        if (!empty($m['socID'])) { $d['socID'] = (int) $m['socID']; }
        if (!empty($m['switchID'])) { $d['switchID'] = (int) $m['switchID']; }
        if (!empty($m['switchStateID'])) { $d['switchStateID'] = (int) $m['switchStateID']; }
        if (is_array($rawNested) && count($rawNested) > 0) { $d['members'] = $rawNested; }
        return $d;
    }

    private function FindDeviceByKey(string $key): ?array
    {
        // Mitglieds-Schluessel (Aufschachteln): <Geraete-Key>'>'<Index>...
        if (strpos($key, '>') !== false) {
            return $this->resolveMemberChain($key);
        }
        foreach ($this->GetDevices() as $d) {
            if ($this->deviceKey($d) === $key) {
                return $d;
            }
        }
        return null;
    }

    /**
     * Baut die Nutzlast der Detailseite. Kernprinzip 2 (CLAUDE.md) gilt
     * auch hier: es wird KEIN Geraetetyp hart verdrahtet - stattdessen
     * werden generisch ALLE Vertragsfelder mit `ID`/`IDs`-Suffix des
     * Geraets aufgeloest (Name der Variable + formatierter Wert). Dadurch
     * zeigen Zaehler automatisch ihre Stromeigenschaften (Spannung/Strom/
     * cos φ/Frequenz je Phase, sofern MeterHub sie liefert) und Fahrzeuge/
     * Wallboxen ihre eigenen Felder (SOC, Reichweite, Steckerstatus, ...)
     * - der Anbieter entscheidet ueber seine GetFunctions-Felder, was
     * erscheint, ohne dass hier je eine Zeile angepasst werden muss.
     */
    private function BuildDetailPayload(string $key, string $dayStr): array
    {
        $d = $this->FindDeviceByKey($key);
        if ($d === null) {
            return ['ok' => false, 'error' => 'Gerät nicht gefunden - bitte die Kachel neu öffnen (die Geräteliste hat sich geändert).'];
        }
        // Nutzer-Umbenennung (Formular) auch auf der Detailseite anzeigen.
        $overrides = $this->deviceOverrideMap();
        $o = $overrides[$key] ?? null;
        $label = !empty($o['name']) ? $o['name'] : ($d['label'] ?? ($d['function'] ?? 'Gerät'));

        // MPPT-Strangvergleich + Isolationswiderstand fuer den Solar-Knoten
        // (Dietmar, 28.08.2026: "wuerden mich die ganzen String- bzw.
        // MPPT-Daten interessieren, Isolationswiderstand ist auch wichtig").
        // Diese Werte leben NICHT im Geraete-Vertrag (GetDevices()), sondern
        // im separaten Diagnose-Vertrag (GetDiagnostics()/resolveDiagnostics(),
        // von InverterHubMonitor abgeleitet) - deshalb tauchten sie bislang
        // nicht generisch in DetailValues() auf. Zuordnung bleibt type-
        // neutral: nicht per Geraete-Instanz verknuepft, sondern ueber den
        // eigenen 'type'-String der Diagnose-Eintraege ("mppt"/"riso" enthalten)
        // erkannt - unabhaengig vom Wechselrichter-Hersteller. Die gefundenen
        // *ID(s)-Felder werden einfach in $d gemischt, DetailValues() rendert
        // sie danach automatisch mit, ohne eigene Sonderbehandlung.
        if (($d['function'] ?? '') === 'pv') {
            foreach ($this->resolveDiagnostics() as $diag) {
                $type = (string) ($diag['type'] ?? '');
                // Fallback nur, wenn InverterHub-Vertrag 1.2 (mpptPowerIDs)
                // noch fehlt (aeltere Version oder anderer WR-Hersteller) -
                // sonst gaebe es die Strangleistung doppelt (einmal aus dem
                // Geraete-Vertrag, einmal aus der Diagnose).
                if (empty($d['mpptPowerIDs']) && stripos($type, 'mppt') !== false && !empty($diag['stringPowerIDs']) && is_array($diag['stringPowerIDs'])) {
                    $d['mpptStringIDs'] = $diag['stringPowerIDs'];
                }
                if (stripos($type, 'riso') !== false && !empty($diag['measuredID'])) {
                    $d['insulationResistanceID'] = $diag['measuredID'];
                }
            }
        }

        // Tagesauswahl (YYYY-MM-DD); DST-Regel: Kalendertag-Grenzen NIE
        // ueber feste Sekundenzahlen, deshalb strtotime auf dem Datum.
        $dayStart = strtotime('today');
        if ($dayStr !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayStr)) {
            $parsed = strtotime($dayStr . ' 00:00:00');
            if ($parsed !== false) {
                $dayStart = $parsed;
            }
        }
        $dayEnd = min(time(), strtotime('+1 day', $dayStart));

        $instName = '';
        $modName = '';
        $iid = (int) ($d['instanceID'] ?? 0);
        if ($iid > 0 && IPS_InstanceExists($iid)) {
            $instName = IPS_GetName($iid);
            $inst = IPS_GetInstance($iid);
            $modName = $inst['ModuleInfo']['ModuleName'] ?? '';
        }

        // resolvePowerValue() modifiziert $d per Referenz (setzt 'usingFallback',
        // falls die primaere powerID gerade veraltet/leer ist und stattdessen
        // die redundante fallbackPowerID greift, siehe AssignRedundancy()).
        // MUSS vor der ID-Wahl fuer die Archiv-Diagramme laufen - sonst fragen
        // "Leistung" und "Energie je Tag" weiterhin die (ggf. tote/unarchivierte)
        // primaere Quelle ab, obwohl die Kachel laengst auf die Fallback-Quelle
        // umgeschaltet hat (Fund 28.08.2026: leeres Leistungsdiagramm bei einer
        // Wallbox, deren primaere powerID nicht mehr aktualisiert wurde).
        $powerNow = $this->resolvePowerValue($d);
        $powerID = (int) (!empty($d['usingFallback']) ? ($d['fallbackPowerID'] ?? 0) : ($d['powerID'] ?? 0));
        $archivingJustEnabled = $this->EnsureArchiving($powerID);
        $powerSeries = $this->DaySeries($powerID, $dayStart, $dayEnd);
        $energy = $this->DailyEnergyBars($d, $dayStart);
        $isToday = date('Y-m-d', $dayStart) === date('Y-m-d');
        return [
            'ok'        => true,
            'key'       => $key,
            'label'     => $label,
            'function'  => (string) ($d['function'] ?? ''),
            'source'    => trim($instName . ($modName !== '' ? ' (' . $modName . ')' : '')),
            'powerNow'  => $powerNow,
            'values'    => $this->DetailValues($d),
            'day'       => date('Y-m-d', $dayStart),
            'dayLabel'  => date('d.m.Y', $dayStart),
            'isToday'   => $isToday,
            'power'     => $powerSeries,
            'archivingJustEnabled' => $archivingJustEnabled,
            'energy'    => $energy,
            // "Für mich"-Kennzahlen (Dietmar, 28.08.2026: "was einen als
            // Hausbesitzer interessieren könnte ... auch mit Blick auf
            // Krisen-/Katastrophenfälle") - siehe BuildHighlights().
            'highlights' => $this->BuildHighlights($d, $powerSeries, $energy, $isToday, $dayStart, $dayEnd),
            // Kaskadierte Unterzaehler (Dietmar, 28.08.2026: "wenn es
            // hinter den Knotenpunkten weitere Unterzaehler geben wuerde ...
            // man koennte diese Erweiterung auch im Overlay fortfuehren").
            // Generisches Feld 'subMeters' = [{label, powerID}, ...] auf dem
            // Geraete-Eintrag - AKTUELL liefert das noch KEIN Partnermodul
            // (MeterHub hat bislang keine Eltern/Kind-Beziehung zwischen
            // Zaehlpunkten im Vertrag, siehe Dietmars Klarstellung: die
            // Kaskadierung muss von MeterHub selbst kommen, protokoll-
            // neutral - explizit NICHT an Zigbee2MQTT o.ae. gebunden). Diese
            // Zeile ist reine Anzeige-Vorbereitung: erscheint ein solches
            // Feld kuenftig in einem Geraete-Eintrag (gleich welcher
            // Quelle), zeigt die Detailseite die Unterzaehler automatisch -
            // heute ist die Liste in jedem echten Aufruf leer.
            'subMeters' => $this->ResolveSubMeters($d),
            'renderedAt' => time(),
            'bg'        => $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND)),
            'font'      => $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT)),
            // Wallbox-Steuerung (30.08.2026, Dietmar: "na bau mal", nach
            // Abstimmung mit OCPPHub) - Start/Stop/Tages-Override direkt von
            // der Detailseite aus. Bewusst NUR bei OCPP-Ladepunkten (aktuell
            // einziger Transport mit diesen Funktionen; ChargerHub/Modbus
            // hat sie (noch) nicht) und NICHT, wenn eine andere Instanz die
            // Regelhoheit haelt (`externallyManaged`, z.B. EMS/Tibber/§14a) -
            // ein manueller Eingriff daneben waere genau die "zwei Regler auf
            // derselben Batterie"-Situation, die dieser Verbund vermeidet.
            'control'   => $this->ChargerControlInfo($d),
        ];
    }

    /**
     * Liefert null, wenn dieses Geraet keine Wallbox-Steuerung anbietet
     * (kein Ladepunkt, oder eine andere Instanz haelt bereits die
     * Regelhoheit). $HOOK_PATH-Aktionen siehe ProcessHookData()
     * ('wallboxAction'), Zielinstanz ist IMMER die im Vertrag selbst
     * mitgelieferte instanceID des Ladepunkts (normalizeEntry()).
     *
     * Zwei Ebenen (30.08.2026, Dietmar: "die ChargerHub-Wallboxen genauso,
     * und eigentlich gäbe es noch mehr sinnvolle Steuerbefehle"):
     * 1) TRANSPORTNEUTRAL ueber den gemeinsamen Verbund-Vertrag (CHUB_
     *    GetFunctions 1.2, den OCPPHub feldgleich implementiert):
     *    'chargeEnableID'/'currentLimitID' sind normale, per EnableAction()
     *    RequestAction-gebundene IPS-Variablen - IPS_RequestAction() wirkt
     *    darauf unabhaengig vom Hersteller/Transport, OHNE dass wir
     *    irgendeine modulspezifische Funktion kennen muessen (CLAUDE.md
     *    Kernprinzip 2: type-neutral, kein Gerätetyp hart verdrahtet).
     *    Deckt ChargerHub UND OCPPHub gleichermassen ab.
     * 2) OCPP-SPEZIFISCH (echte RemoteStart/Stop-Transaktion, Tages-
     *    Override) - hat keine Entsprechung bei den Modbus-Wallboxen von
     *    ChargerHub (die kennen keine "Transaktion", nur den Dauerzustand
     *    ctl_enable) und bleibt deshalb an OHUBL_ManualStart() & Co.
     *    gebunden, nur wenn $d['transport'] === 'ocpp'.
     */
    private function ChargerControlInfo(array $d): ?array
    {
        // Demomodus (01.09.2026) - siehe Property-Docblock in Create():
        // steuert die Wallbox NICHT nur aus, sondern liefert direkt kein
        // 'control'-Feld, damit die Detailseite gar nicht erst versucht,
        // Schalter/Regler zu zeichnen - kein Sonderfall im Frontend noetig.
        if ($this->readBoolProperty('DemoMode', false)) {
            return null;
        }
        // normalizeDeviceCategory() statt eines woertlichen Vergleichs auf
        // 'charger' (03.09.2026, Fund: eine manuell eingetragene Wallbox
        // ohne Hub-Modul traegt 'function'=>'wallbox', nicht 'charger' -
        // beide meinen dieselbe Geraeteart, siehe AssignVehicles()-
        // Kommentar vom 29.07.2026 zum selben Muster).
        if ($this->normalizeDeviceCategory($d['function'] ?? '') !== 'wallbox') {
            return null;
        }
        if (!empty($d['externallyManaged'])) {
            return null;
        }
        $instanceID = (int) ($d['instanceID'] ?? 0);
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return null;
        }

        // OCPPHub-eigener Vorfuehrmodus (01.09.2026, OHUB_IsDemoMode() -
        // Splitter-Property, lehnt echte OCPP-Befehle serverseitig ab).
        // Auch wenn UNSER DemoMode aus ist: zeigt OCPPHub selbst Buttons an,
        // die dort ohnehin nur abgelehnt wuerden, waere das verwirrend
        // ("Start geklickt, nichts passiert") statt einer klaren Aussage.
        // Defense-in-depth analog unserem eigenen zweistufigen Schutz.
        if (($d['transport'] ?? '') === 'ocpp' && function_exists('OHUB_IsDemoMode')) {
            $splitterId = (int) @IPS_GetProperty($instanceID, 'SplitterID');
            if ($splitterId > 0 && @OHUB_IsDemoMode($splitterId)) {
                return null;
            }
        }

        $info = ['instanceID' => $instanceID];

        $enableID = (int) ($d['chargeEnableID'] ?? 0);
        if ($enableID > 0 && IPS_VariableExists($enableID) && (IPS_GetVariable($enableID)['VariableAction'] ?? 0) > 0) {
            $info['enableID'] = $enableID;
            $info['enableValue'] = (bool) GetValueBoolean($enableID);
        }

        $limitID = (int) ($d['currentLimitID'] ?? 0);
        if ($limitID > 0 && IPS_VariableExists($limitID) && (IPS_GetVariable($limitID)['VariableAction'] ?? 0) > 0) {
            $info['limitID'] = $limitID;
            $info['limitValue'] = (int) round(GetValue($limitID));
            $info['minCurrent'] = (int) ($d['minCurrent'] ?? 6);
            $info['maxCurrent'] = (int) ($d['maxCurrent'] ?? 32);
        }

        if (($d['transport'] ?? '') === 'ocpp' && function_exists('OHUBL_ManualStart')) {
            // Kein aktueller Override-Status verfuegbar - DailyOverride ist
            // bei OCPPHub ein internes Attribut, kein oeffentlicher Getter
            // (Stand 30.08.2026). Die Schaltflaeche wirkt trotzdem (Ein/Aus),
            // zeigt aber keinen "ist gerade aktiv"-Zustand an.
            $info['ocppActions'] = true;
        }

        // blockReasonID (31.08.2026, contractVersion 1.2, additiv) - generischer,
        // vom Anbieter interpretierter Klartext, WARUM eine Wallbox einen
        // Ladebefehl gerade ablehnt/blockiert (z.B. OCPP-Reject-Grund). Bereits
        // interpretierter Wert (kein ID-Suffix im Ergebnis), da direkt in der
        // Detailseite angezeigt - jedes Partnermodul (ChargerHub genauso wie
        // OCPPHub) kann das Feld optional befuellen, ohne dass wir hier
        // OCPP-Spezifika kennen muessen (CLAUDE.md Kernprinzip 2).
        $reasonID = (int) ($d['blockReasonID'] ?? 0);
        if ($reasonID > 0 && IPS_VariableExists($reasonID)) {
            $reason = trim((string) GetValue($reasonID));
            if ($reason !== '') {
                $info['blockReason'] = $reason;
            }
        }

        // Nichts Steuerbares gefunden - dann lieber gar kein Panel zeigen
        // als eines ohne Inhalt.
        if (!isset($info['enableID']) && !isset($info['limitID']) && empty($info['ocppActions']) && !isset($info['blockReason'])) {
            return null;
        }
        return $info;
    }

    /**
     * Uebersetzt technische Rohwerte in "was bedeutet das fuer mich"-
     * Kennzahlen (Dietmar, 28.08.2026). Zwei Schichten:
     * 1) GENERISCH, fuer JEDES Geraet mit einer Leistungsreihe gleich
     *    berechnet (Spitzenleistung/Betriebsstunden/Energie des Tages) -
     *    kein Geraetetyp hart verdrahtet, CLAUDE.md Kernprinzip 2.
     * 2) Gezielt je Funktion, wo eine einzelne Zahl im Alltag oder im
     *    Krisenfall (Stromausfall, Evakuierung) tatsaechlich relevant ist:
     *    Batterie-Restlaufzeit, Netzbezug/-einspeisung getrennt, Fahrzeug-
     *    Reichweite. Nur mit tatsaechlich vorhandenen Daten - keine Werte
     *    erfinden/schaetzen, wo die Datengrundlage fehlt (z.B. keine
     *    Kostenanzeige ohne echten Strompreis-Vertrag).
     */
    /**
     * Loest ein generisches 'subMeters'-Feld ([{label,powerID}, ...]) am
     * Geraete-Eintrag zu Live-Werten auf, falls vorhanden - siehe
     * Kommentar bei BuildDetailPayload(). Robust gegen jede Form (fehlendes
     * Feld, kein Array, einzelne kaputte Eintraege) statt einen Fehler zu
     * werfen, da noch KEIN Partnermodul dieses Feld liefert und ein
     * kuenftiges Format daher nicht final feststeht.
     */
    private function ResolveSubMeters(array $d): array
    {
        $raw = $d['subMeters'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $vid = (int) ($entry['powerID'] ?? 0);
            if ($vid <= 0 || !IPS_VariableExists($vid)) {
                continue;
            }
            $out[] = [
                'label' => (string) ($entry['label'] ?? IPS_GetName($vid)),
                'value' => GetValueFormatted($vid),
                'powerW' => (float) GetValue($vid),
            ];
        }
        return $out;
    }

    private function BuildHighlights(array $d, array $powerSeries, array $energy, bool $isToday, int $dayStart, int $dayEnd): array
    {
        $out = [];
        $fn = (string) ($d['function'] ?? '');
        $dayWord = $isToday ? 'heute' : 'an diesem Tag';
        // Strompreis-Kurve fuer den Tag: bevorzugt TibberGridReward (echte
        // stuendliche Preise), sonst automatisch der BDEW-Haushalts-
        // durchschnitt als grober Ersatzwert (PriceSlotsForDay(), Dietmar
        // 28.08.2026 - "das sollte auch das ausgelieferte Modul ganz ohne
        // Dich koennen"). [] nur, wenn WIRKLICH keine Quelle verfuegbar ist
        // (auch kein je erfolgreich abgerufener BDEW-Wert) - dann bleiben
        // alle Kosten-Kennzahlen einfach weg statt eine falsche Zahl zu
        // zeigen.
        $priceSlots = $this->PriceSlotsForDay($dayStart, $dayEnd);
        $priceApprox = count($priceSlots) > 0 && !empty($priceSlots[0]['approx']);
        $priceSuffix = $priceApprox ? ' (Ø Haushalt, BDEW)' : '';
        if (count($priceSlots) > 0) {
            $nowPrice = $this->PriceAt($priceSlots, time());
            if ($nowPrice !== null && in_array($fn, ['grid', 'battery', 'wallbox', 'heatpump'], true)) {
                $label = $priceApprox ? 'Strompreis' : 'Strompreis gerade jetzt';
                $hint = $priceApprox ? ('Bundesweiter BDEW-Haushaltsdurchschnitt, vor ' . $priceSlots[0]['ageDays'] . ' Tagen abgerufen - keine echte Momentanquelle (z.B. Tibber) konfiguriert.') : null;
                $item = ['label' => $label . $priceSuffix, 'value' => number_format($nowPrice, 1, ',', '.') . ' ct/kWh'];
                if ($hint !== null) {
                    $item['hint'] = $hint;
                }
                $out[] = $item;
            }
        }

        // --- 1) Generisch aus der Leistungsreihe -----------------------
        if (count($powerSeries) >= 2) {
            $peak = 0.0;
            $activeSlots = 0;
            foreach ($powerSeries as [$ts, $w]) {
                $peak = max($peak, abs((float) $w));
                if (abs((float) $w) > 20) {
                    $activeSlots++;
                }
            }
            $intervalHours = ((float) ($powerSeries[1][0] - $powerSeries[0][0])) / 3600000;
            if ($intervalHours > 0) {
                $out[] = ['label' => 'Spitzenleistung ' . $dayWord, 'value' => $this->FmtWWithUnit($peak)];
                $out[] = ['label' => 'In Betrieb ' . $dayWord, 'value' => $this->FmtHours($activeSlots * $intervalHours)];
            }
        }
        // Energie des ausgewaehlten Tages aus den bereits berechneten
        // 14-Tage-Balken herauspicken (kein zweiter Archiv-Zugriff noetig).
        $dayKey = date('Y-m-d', $dayStart);
        foreach (($energy['bars'] ?? []) as [$bd, $bv]) {
            if ($bd === $dayKey) {
                $unit = $energy['unit'] ?: 'kWh';
                $approxMark = !empty($energy['approx']) ? '≈ ' : '';
                $out[] = ['label' => 'Energie ' . $dayWord, 'value' => $approxMark . number_format((float) $bv, 1, ',', '.') . ' ' . $unit];
                break;
            }
        }

        // --- 2) Funktionsspezifisch -------------------------------------
        if ($fn === 'battery') {
            $capId = (int) ($d['batteryCapacityID'] ?? 0);
            $socId = (int) ($d['socID'] ?? 0);
            $capacityKWh = ($capId > 0) ? $this->resolveVariableValue($capId) : null;
            $socPercent = ($socId > 0) ? $this->resolveVariableValue($socId) : null;
            $houseLoadW = $this->EstimateHouseLoadW();
            if ($capacityKWh !== null && $socPercent !== null && $houseLoadW !== null) {
                $usableKWh = $capacityKWh * max(0, min(100, $socPercent)) / 100;
                // Sehr geringe/negative Hauslast (z.B. gerade PV-Ueberschuss)
                // wuerde eine absurd hohe/negative Laufzeit ergeben - unterhalb
                // 50 W gilt "reicht praktisch beliebig lange" statt einer
                // konkreten (falschen) Praezisionszahl.
                if ($houseLoadW < 50) {
                    $out[] = ['label' => 'Reicht bei Stromausfall', 'value' => '> 24 Std.', 'hint' => 'Bei aktuell sehr geringer Hauslast'];
                } else {
                    $hours = $usableKWh / ($houseLoadW / 1000);
                    $out[] = [
                        'label' => 'Reicht bei Stromausfall',
                        'value' => $this->FmtHours($hours),
                        'hint' => 'Schätzung bei aktueller Hauslast von ' . $this->FmtWWithUnit($houseLoadW) . ' - im echten Inselbetrieb versorgt die Batterie meist nur die Notstromkreise, nicht das ganze Haus.',
                    ];
                }
            }
        } elseif ($fn === 'grid' && count($powerSeries) >= 2) {
            $importKWh = 0.0;
            $exportKWh = 0.0;
            $intervalHours = ((float) ($powerSeries[1][0] - $powerSeries[0][0])) / 3600000;
            foreach ($powerSeries as [$ts, $w]) {
                if ($w < 0) {
                    $importKWh += abs($w) * $intervalHours / 1000;
                } else {
                    $exportKWh += $w * $intervalHours / 1000;
                }
            }
            $out[] = ['label' => 'Netzbezug ' . $dayWord, 'value' => number_format($importKWh, 1, ',', '.') . ' kWh'];
            $out[] = ['label' => 'Einspeisung ' . $dayWord, 'value' => number_format($exportKWh, 1, ',', '.') . ' kWh'];
            // Bezugskosten mit dem je Zeitpunkt tatsaechlich gueltigen
            // Tibber-Preis gewichtet (nicht dem aktuellen) - Preise
            // schwanken stuendlich, ein Tagesdurchschnitt waere ungenau.
            $importCost = $this->DayCostEUR($powerSeries, $priceSlots, -1);
            if ($importCost !== null) {
                $out[] = ['label' => 'Netzbezug-Kosten ' . $dayWord . $priceSuffix, 'value' => number_format($importCost, 2, ',', '.') . ' €'];
            }
            // "Ersparnis durch dynamischen Tarif/zeitvariable Netzentgelte"
            // (Dietmar, 28.08.2026) - NICHT die PV/Batterie-Ersparnis (die
            // steht separat beim Solar-Knoten), sondern: fuer EXAKT dieselbe
            // bezogene Menge Netzstrom, was kostet sie mit dem echten
            // Tibber-Tarif (inkl. zeitvariabler §14a-Netzentgelte, siehe
            // TIBBERGR_GetPriceCurve()-Vertrag) gegenueber einem deutschen
            // Standardtarif (BDEW-Haushaltsdurchschnitt, FLACH über den
            // ganzen Tag)? Nur sinnvoll/anzeigbar, wenn Tibber TATSAECHLICH
            // aktiv ist (sonst waere "echter Tarif" = "Standardtarif" und
            // die Differenz truegerisch 0) UND ein BDEW-Vergleichswert
            // vorliegt.
            if (!$priceApprox && $importCost !== null) {
                $bdewRef = $this->CurrentBdewPrice();
                if ($bdewRef !== null) {
                    $standardCost = $importKWh * $bdewRef['priceCtPerKWh'] / 100;
                    $tariffSaving = $standardCost - $importCost;
                    $out[] = [
                        'label' => 'Ersparnis durch dynamischen Tarif ' . $dayWord,
                        'value' => number_format($tariffSaving, 2, ',', '.') . ' €',
                        'hint' => 'Dieselbe Netzbezugsmenge (' . number_format($importKWh, 1, ',', '.') . ' kWh) zum dynamischen Tibber-Tarif inkl. zeitvariabler Netzentgelte statt zum deutschen Standardtarif (BDEW-Haushaltsdurchschnitt, ' . number_format($bdewRef['priceCtPerKWh'], 1, ',', '.') . ' ct/kWh).',
                    ];
                }
            }
            // Grid-Reward-Erloes (bezahlte Flexibilitaet, z.B. Batterie/
            // Fahrzeug wird von Tibber ferngesteuert entladen/geladen) -
            // eine echte Einnahme, kein Kostenvorteil, deshalb als eigene
            // Zeile statt in die Ersparnis eingerechnet.
            $rewardEUR = $this->GridRewardEarningsTodayEUR();
            if ($rewardEUR !== null) {
                $out[] = [
                    'label' => 'Grid-Reward-Erlös ' . $dayWord,
                    'value' => number_format($rewardEUR, 2, ',', '.') . ' €',
                    'hint' => 'Vergütung von Tibber Grid Rewards für ferngesteuerte Flexibilität (z. B. Batterie-/Fahrzeug-Entladung).',
                ];
            }
        }
        if ($fn === 'pv') {
            // "Was hat die Anlage heute gespart" (Dietmar, 28.08.2026) -
            // bewusst nur der Direktverbrauchs-Anteil (PV-Erzeugung minus
            // Einspeisung) mal Strompreis, NICHT zusaetzlich Batterie-
            // Entladung: die Batterie laedt selbst ueberwiegend aus PV-
            // Ueberschuss, eine separate Batterie-Ersparnis wuerde denselben
            // Solarstrom ein zweites Mal gutschreiben (Doppelzaehlung).
            $dayKey = date('Y-m-d', $dayStart);
            $pvKWhToday = null;
            foreach (($energy['bars'] ?? []) as [$bd, $bv]) {
                if ($bd === $dayKey) {
                    $pvKWhToday = (float) $bv;
                    break;
                }
            }
            $grid = $this->GridDayEnergyKWh($dayStart, $dayEnd);
            if ($pvKWhToday !== null && $grid !== null && count($priceSlots) > 0) {
                $selfUsedKWh = max(0, $pvKWhToday - $grid['exportKWh']);
                // Durchschnittspreis des Tages aus den Slots (fuer eine
                // einzelne Tages-Summe reicht das - im Gegensatz zur
                // zeitpunktgenauen Kostenberechnung oben, wo es auf die
                // Stunde ankommt).
                $avgPrice = array_sum(array_column($priceSlots, 'price')) / count($priceSlots);
                $out[] = [
                    'label' => 'Ersparnis ' . $dayWord . $priceSuffix,
                    'value' => number_format($selfUsedKWh * $avgPrice / 100, 2, ',', '.') . ' €',
                    'hint' => 'Direkt selbst verbrauchter Solarstrom (' . number_format($selfUsedKWh, 1, ',', '.') . ' kWh) zum ' . ($priceApprox ? 'BDEW-Haushaltsdurchschnitt' : 'Tagesmittel der Strompreise') . ' - ohne zusätzliche Batterie-Ersparnis (sonst würde derselbe Solarstrom doppelt gutgeschrieben).',
                ];
            }
        }
        if ($fn === 'wallbox' || $fn === 'heatpump') {
            $cost = $this->DayCostEUR($powerSeries, $priceSlots, 1);
            if ($cost !== null) {
                $hint = $priceApprox
                    ? 'Näherung mit dem bundesweiten BDEW-Haushaltsdurchschnitt statt einem echten stündlichen Preis.'
                    : 'Auf Basis des jeweils gültigen Strompreises.';
                $out[] = ['label' => 'Kosten ' . $dayWord . $priceSuffix, 'value' => number_format($cost, 2, ',', '.') . ' €', 'hint' => $hint];
            }
        }
        if ($fn === 'wallbox' && !empty($d['vehicleRangeKm'])) {
            $out[] = [
                'label' => 'Geschätzte Reichweite',
                'value' => round((float) $d['vehicleRangeKm']) . ' km',
                'hint' => 'Bei aktuellem Ladestand des Fahrzeugs',
            ];
        }

        return $out;
    }

    /** Netzbezug/-einspeisung des Tages in kWh (fuer die PV-"Ersparnis"-
     *  Kennzahl) - findet das Netz-Geraet selbst und fragt dessen eigene
     *  Leistungsreihe ab, damit die PV-Detailseite nicht auf Werte
     *  angewiesen ist, die nur beim Aufruf der Netz-Detailseite berechnet
     *  werden. null, wenn kein Netz-Geraet aufgeloest werden kann. */
    private function GridDayEnergyKWh(int $dayStart, int $dayEnd): ?array
    {
        foreach ($this->GetDevices() as $dev) {
            if (($dev['function'] ?? '') !== 'grid') {
                continue;
            }
            $this->resolvePowerValue($dev);
            $powerID = (int) (!empty($dev['usingFallback']) ? ($dev['fallbackPowerID'] ?? 0) : ($dev['powerID'] ?? 0));
            $series = $this->DaySeries($powerID, $dayStart, $dayEnd);
            if (count($series) < 2) {
                return null;
            }
            $intervalHours = ((float) ($series[1][0] - $series[0][0])) / 3600000;
            $importKWh = 0.0;
            $exportKWh = 0.0;
            foreach ($series as [$ts, $w]) {
                if ($w < 0) {
                    $importKWh += abs($w) * $intervalHours / 1000;
                } else {
                    $exportKWh += $w * $intervalHours / 1000;
                }
            }
            return ['importKWh' => $importKWh, 'exportKWh' => $exportKWh];
        }
        return null;
    }

    /** Aktuelle Netto-Hauslast in Watt, dieselbe Formel wie module.html
     *  (houseW = pv - grid + bat, "+" bei grid/bat = Einspeisung/Entladen) -
     *  null, wenn keins der beteiligten Geraete aufgeloest werden kann. */
    private function EstimateHouseLoadW(): ?float
    {
        $devices = $this->GetDevices();
        $pv = null; $grid = null; $bat = null; $house = null;
        foreach ($devices as $dev) {
            $val = $this->resolvePowerValue($dev);
            if ($val === null) {
                continue;
            }
            switch ($dev['function'] ?? '') {
                case 'house':    $house = $val; break;
                case 'pv':       $pv = $val; break;
                case 'grid':     $grid = $val; break;
                case 'battery':  $bat = $val; break;
            }
        }
        if ($house !== null) {
            return $house;
        }
        if ($pv !== null && $grid !== null) {
            return $pv - $grid + ($bat ?? 0);
        }
        return null;
    }

    private function FmtWWithUnit(float $w): string
    {
        $a = abs($w);
        return $a >= 10000
            ? number_format($w / 1000, 1, ',', '.') . ' kW'
            : number_format($w, 0, ',', '.') . ' W';
    }

    private function FmtHours(float $hours): string
    {
        if ($hours >= 24) {
            return '> 24 Std.';
        }
        $h = floor($hours);
        $m = round(($hours - $h) * 60);
        if ($m === 60.0) {
            $h++; $m = 0;
        }
        return $h > 0 ? ($h . ' Std. ' . $m . ' Min.') : ($m . ' Min.');
    }

    /**
     * Loest generisch alle `ID`-/`IDs`-Vertragsfelder eines Geraets in
     * Anzeigezeilen auf: Variablenname (vergibt der Anbieter, deshalb
     * type-neutral verwendbar), formatierter Wert (Profil des Anbieters)
     * und Aktualitaets-Zeitstempel. Die L1-L3-Gruppierung passiert im
     * Frontend rein anhand des Namens.
     */
    private function DetailValues(array $d): array
    {
        $rows = [];
        $add = function ($vid, string $field) use (&$rows) {
            $vid = (int) $vid;
            if ($vid <= 0 || !IPS_VariableExists($vid)) {
                return;
            }
            $v = IPS_GetVariable($vid);
            $rows[] = [
                'field' => $field,
                'name'  => IPS_GetName($vid),
                'value' => GetValueFormatted($vid),
                'ts'    => (int) $v['VariableUpdated'],
            ];
        };
        // Felder, die ChargerControlInfo() bereits prominent im Steuer-Panel
        // zeigt (Freigabe/Limit/Ablehnungsgrund) - hier ausschliessen, sonst
        // stehen dieselben Werte doppelt und ohne den erklaerenden Kontext
        // des Steuer-Panels da (Dietmar 31.08.2026: "Beschriftung und
        // Aussage gehoeren zusammen", genau das ist die Aufgabe des
        // Steuer-Panels, nicht dieser generischen Tabelle).
        $controlFields = ['chargeEnableID', 'currentLimitID', 'blockReasonID'];
        foreach ($d as $field => $val) {
            if ($field === 'instanceID' || str_starts_with($field, '_') || in_array($field, $controlFields, true)) {
                continue;
            }
            if (preg_match('/IDs$/', $field) && is_array($val)) {
                foreach ($val as $vid) {
                    $add($vid, $field);
                }
            } elseif (preg_match('/ID$/', $field) && is_numeric($val)) {
                $add($val, $field);
            }
        }
        return $rows;
    }

    /** Erste Archiv-Instanz (Standard-Setup: genau eine). */
    private function ArchiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return count($ids) > 0 ? (int) $ids[0] : 0;
    }

    /**
     * Aktiviert die Archivierung fuer eine Variable, falls noch nicht aktiv
     * - Dietmar 31.08.2026: "wenn Du schon eine Auswertung anbietest, dann
     * solltest Du auch alle betreffenden Datenpunkte archivieren", statt nur
     * "keine Archivdaten" anzuzeigen und es dabei zu belassen. Wirkt nur
     * lazy beim tatsaechlichen Aufruf der Leistungsgrafik (nicht pauschal
     * fuer jedes discovered Geraet), damit nicht ungefragt Dutzende fremde
     * Variablen archiviert werden, die nie jemand ansieht. Rueckgabe true,
     * wenn die Archivierung GERADE erst aktiviert wurde (fuer die Detail-
     * seite: dann liegen naturgemaess noch keine historischen Daten vor,
     * das ist kein Fehler mehr, sondern nur eine Frage der Zeit).
     */
    private function EnsureArchiving(int $vid): bool
    {
        $arch = $this->ArchiveID();
        if ($arch <= 0 || $vid <= 0 || !IPS_VariableExists($vid) || AC_GetLoggingStatus($arch, $vid)) {
            return false;
        }
        AC_SetLoggingStatus($arch, $vid, true);
        IPS_ApplyChanges($arch);
        return true;
    }

    /**
     * 5-Minuten-Verlauf einer Variablen fuer einen Tag ([ms, wert]-Paare,
     * chronologisch). Leer, wenn Variable fehlt oder nicht archiviert wird
     * - die Detailseite zeigt dann einen Hinweis statt eines leeren Charts.
     */
    // Sanity-Obergrenze fuer archivierte Leistungswerte (01.09.2026, Dietmar:
    // "2204,91 kWh von einer 9,18 kWp PV Anlage" - ein einzelner defekter
    // Messwert von 261.554.185 W nachts in InverterHubs eigenem Archiv hat
    // den kompletten Tagesbalken auf einen absurden Wert gezogen). Bewusst
    // KEIN anlagenspezifischer Wert (waere hart verdrahtet, CLAUDE.md
    // Kernprinzip 2) - 1 MW ist fuer jede denkbare Heim-/Kleingewerbe-Anlage
    // (Solar, Wallbox, Waermepumpe, Hausanschluss) implausibel, unabhaengig
    // von Geraetetyp/Hersteller. Der eigentliche Defekt (woher der
    // Fantasiewert kommt) liegt beim archivierenden Partnermodul - hier nur
    // Schutz davor, dass EIN kaputter Messwert die Darstellung sprengt.
    private const IMPLAUSIBLE_POWER_W = 1_000_000.0;

    /**
     * NACHTRAG 01.09.2026 (Dietmar: "Passt aber immer noch nicht die
     * Anzeige!" - bei PVMonitor/WPMonitor pruefte der erste Fix nur 'Avg',
     * das verduennt einen einzelnen Ausreisser ueber Tag/Monat so stark,
     * dass er unauffaellig unter der Implausibilitaetsgrenze bleibt. Hier
     * bei der 5-Minuten-Reihe ist die Verduennung zwar kleiner, aber
     * 'Max'/'Min' bleiben trotzdem die korrektere, konsistente Pruefstelle
     * (zeigen den Rohwert unverduennt, unabhaengig von der Aggregationsstufe).
     */
    private function RowHasImplausiblePower(array $row): bool
    {
        $max = isset($row['Max']) ? abs((float) $row['Max']) : 0.0;
        $min = isset($row['Min']) ? abs((float) $row['Min']) : 0.0;
        return max($max, $min) > self::IMPLAUSIBLE_POWER_W;
    }

    private function DaySeries(int $vid, int $from, int $to): array
    {
        $arch = $this->ArchiveID();
        if ($arch <= 0 || $vid <= 0 || !IPS_VariableExists($vid) || !AC_GetLoggingStatus($arch, $vid)) {
            return [];
        }
        $agg = @AC_GetAggregatedValues($arch, $vid, 5, $from, $to, 0);
        if (!is_array($agg)) {
            return [];
        }
        $out = [];
        foreach ($agg as $row) {
            $w = (float) $row['Avg'];
            if ($this->RowHasImplausiblePower($row)) {
                $this->SendDebug(
                    __FUNCTION__,
                    sprintf('Unplausibler Archivwert verworfen: Variable #%d, %s, Max=%.0f W', $vid, date('Y-m-d H:i', (int) $row['TimeStamp']), (float) ($row['Max'] ?? 0)),
                    0
                );
                continue;
            }
            $out[] = [((int) $row['TimeStamp']) * 1000, round($w, 1)];
        }
        usort($out, function ($a, $b) { return $a[0] <=> $b[0]; });
        return $out;
    }

    /**
     * Tages-Energiebalken der letzten 14 Tage bis einschliesslich des
     * gewaehlten Tages. Quelle type-neutral: bevorzugt wird die ERSTE
     * archivierte Zaehler-Variable (Aggregationstyp 1) unter allen
     * `ID`-Feldern des Geraets - bei Countern liefert AC_GetAggregatedValues
     * je Periode direkt den Verbrauch (Avg-Feld, IPS-Konvention). Gibt es
     * keinen Zaehler, wird die Leistung integriert (Tages-Mittel x 24 h) -
     * eine Naeherung, die die Seite auch so kennzeichnet.
     */
    private function DailyEnergyBars(array $d, int $dayStart): array
    {
        $arch = $this->ArchiveID();
        if ($arch <= 0) {
            return ['bars' => [], 'unit' => '', 'approx' => false];
        }
        $from = strtotime('-13 day', $dayStart);
        $to = min(time(), strtotime('+1 day', $dayStart));

        // Zaehler suchen (type-neutral ueber alle ID-Felder, powerID zuletzt).
        $counterID = 0;
        foreach ($d as $field => $val) {
            if ($field === 'instanceID' || $field === 'powerID' || !preg_match('/ID$/', $field) || !is_numeric($val)) {
                continue;
            }
            $vid = (int) $val;
            if ($vid > 0 && IPS_VariableExists($vid) && AC_GetLoggingStatus($arch, $vid)
                && (int) AC_GetAggregationType($arch, $vid) === 1) {
                $counterID = $vid;
                break;
            }
        }

        $bars = [];
        if ($counterID > 0) {
            $agg = @AC_GetAggregatedValues($arch, $counterID, 1, $from, $to, 0);
            if (is_array($agg)) {
                foreach ($agg as $row) {
                    $bars[] = [date('Y-m-d', (int) $row['TimeStamp']), round((float) $row['Avg'], 2)];
                }
            }
            // Einheit vom Profil des Zaehlers uebernehmen (Anbieter-Hoheit).
            $unit = trim($this->VariableSuffix($counterID));
            usort($bars, function ($a, $b) { return strcmp($a[0], $b[0]); });
            return ['bars' => $bars, 'unit' => ($unit !== '' ? $unit : 'kWh'), 'approx' => false, 'name' => IPS_GetName($counterID)];
        }

        // Gleiche Fallback-Logik wie in BuildDetailPayload() - siehe Kommentar
        // dort (Fund 28.08.2026). Integration UEBER die bereits sanitisierte
        // DaySeries() (5-Minuten-Punkte, siehe IMPLAUSIBLE_POWER_W dort) statt
        // einer einzelnen Tages-Durchschnitts-Abfrage - eine Tages-Aggregation
        // mit period=1 gewichtet jeden archivierten Rohwert gleich stark,
        // ein einzelner defekter Ausreisser (Fund 01.09.2026: 261.554.185 W
        // nachts) zieht dadurch den GESAMTEN Tageswert absurd hoch. Ueber die
        // 5-Minuten-Reihe integriert bleibt ein bereits verworfener Ausreisser
        // (dort behandelt) tatsaechlich draussen, statt nur verduennt zu sein.
        $powerID = (int) (!empty($d['usingFallback']) ? ($d['fallbackPowerID'] ?? 0) : ($d['powerID'] ?? 0));
        if ($powerID > 0 && IPS_VariableExists($powerID) && AC_GetLoggingStatus($arch, $powerID)) {
            for ($ts = $from; $ts < $to; $ts = strtotime('+1 day', $ts)) {
                $dayEnd = min($to, strtotime('+1 day', $ts));
                $series = $this->DaySeries($powerID, $ts, $dayEnd);
                if (count($series) < 2) {
                    continue;
                }
                $intervalHours = ((float) ($series[1][0] - $series[0][0])) / 3600000;
                $kwh = 0.0;
                foreach ($series as [, $w]) {
                    $kwh += abs((float) $w) * $intervalHours / 1000;
                }
                $bars[] = [date('Y-m-d', $ts), round($kwh, 2)];
            }
            usort($bars, function ($a, $b) { return strcmp($a[0], $b[0]); });
            return ['bars' => $bars, 'unit' => 'kWh', 'approx' => true];
        }
        return ['bars' => [], 'unit' => '', 'approx' => false];
    }

    /** Profil-Suffix einer Variablen (z.B. " kWh"), leer wenn keins. */
    private function VariableSuffix(int $vid): string
    {
        $v = IPS_GetVariable($vid);
        $profile = $v['VariableCustomProfile'] !== '' ? $v['VariableCustomProfile'] : $v['VariableProfile'];
        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            return (string) IPS_GetVariableProfile($profile)['Suffix'];
        }
        return '';
    }
}
