<?php

declare(strict_types=1);

// GUID    : {6B2F8C41-9E3A-4D6B-8F1C-5A7D9E2B4C61}
// Verbund : NRG-Stack (DG65) - siehe https://github.com/DG65/EMS/blob/main/SUITE.md
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
define('NRGDASH_GUID_HEISHAMON',      '{1919151A-3C0F-4C09-B906-291638EC1469}');
define('NRGDASH_GUID_TESSIE',         '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}');
define('NRGDASH_GUID_TIBBERGRIDREWARD', '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}');
define('NRGDASH_GUID_STROMGEDACHT',   '{D5A8C3A1-2222-4A55-8888-123456789003}');
define('NRGDASH_GUID_PVPROGNOSE',     '{257DD4E8-9705-462E-89FC-56D0A1038353}');
define('NRGDASH_GUID_LASTPROGNOSE',   '{DC5AD508-507F-40EA-8630-0959AED83050}');

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
    private const NEWS_VERSION = '0.2.0';
    private const NEWS_ITEMS = [
        'Ereignisgesteuerte Aktualisierung (sofortiger Push bei jeder Wertänderung) statt reinem 5-Minuten-Takt.',
        'Echte Hauslast (IHUBTILE_GetHouseLoad) bevorzugt vor der berechneten Näherung, sofern konfiguriert.',
        'Manuelle Datenpunkte und frei editierbare Verbraucherliste - die Kachel läuft jetzt auch ganz ohne installiertes Partnermodul.',
        'Vollständige Darstellungs-Einstellungen (Hintergrundfarbe, Schriftart, Übergangszeit, Fluss-Tempo) wie InverterHubTile.',
        'Einzelne automatisch gefundene Geräte lassen sich jetzt ein-/ausblenden, ohne sie manuell neu einzutragen.',
    ];
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';
    private const GITHUB_URL = 'https://github.com/DG65/NRGDashboard';

    public function Create()
    {
        parent::Create();

        $this->RegisterAttributeString('DeviceCache', '[]');
        $this->RegisterAttributeString('DiagnosticsCache', '[]');
        $this->RegisterAttributeInteger('LastDiscoveryTs', 0);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);
        $this->RegisterPropertyInteger('TransitionMs', self::DEF_TRANSITION);
        $this->RegisterPropertyInteger('FlowRefW', self::DEF_FLOWREF);
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
        $this->SetVisualizationType(1);
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
    }

    /**
     * Fuegt das "Was ist Neu"-Panel (versionsscharf dismissible) und den
     * Forum/GitHub-Hinweis (einmalig dismissible) um die statische form.json
     * herum ein, traegt die Versionsnummer ins Doku-Panel ein - exakte
     * Struktur wie InverterHubTile (Muster fuer den ganzen Verbund).
     */
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }

        $this->injectVersionIntoDocPanel($form);
        $this->injectDeviceToggleValues($form);

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

        // Jede Quelle einzeln einsammeln UND sofort auf stille Vertragsbrueche
        // pruefen (checkSourceCoverage) - Verbund-Zielbild "Zuverlaessigkeit
        // ohne KI-Krücke" (SUITE.md, 27.07.2026): genau das haette den realen
        // Fall vom 27.07.2026 (IHUB_GetFunctions liefert ein Objekt statt der
        // erwarteten Liste, PV/Batterie/Netz fielen dadurch still raus)
        // automatisch im Log gemeldet, statt erst durch eine Live-Sitzung beim
        // Nutzer aufzufallen. Ein Endnutzer hat keine Sitzung, die das nachtraeglich
        // repariert.
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
        if (!$hasManualHouse) {
            $devices = array_merge($devices, $this->discoverInverterHubTileHouseLoad());
        }

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
        $this->UpdateFormField(
            'DiscoveryResult',
            'caption',
            count($devices) > 0
                ? sprintf('✅ %d Geräte gefunden (zuletzt %s Uhr).', count($devices), date('H:i:s'))
                : '⚠️ Keine Geräte gefunden - sind Partnermodule installiert und konfiguriert?'
        );

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
        if ($message === VM_UPDATE) {
            $this->UpdateVisualizationValue(json_encode($this->buildPayload()));
        }
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($this->buildPayload()) . ');</script>';
        return $html;
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
            // Wallbox-Sonderfall (InverterHub, 27.07.2026): "eingesteckt aber
            // nicht ladend" (0 W) gilt trotzdem als aktiv/volle Farbe, nicht
            // ausgegraut. plugStateID kommt entweder unveraendert aus
            // CHUB_GetFunctions durch (normalizeEntry() entfernt keine Felder)
            // oder aus der manuellen Verbraucherliste samt plugOp/plugVal.
            if (!empty($d['plugStateID'])) {
                $d['plugged'] = $this->resolvePluggedCondition($d);
            }
            return $d;
        }, $this->GetDevices());

        // Fahrzeug-Zuordnung fuer Wallboxen (Dietmar, 29.07.2026: bei
        // eingestecktem Auto sollen subText/SOC-Ring das ERKANNTE Fahrzeug
        // zeigen, nicht nur "Wallbox aktiv") - muss VOR dem Ausblenden/
        // Reindizieren unten laufen, AssignVehicles() liefert Indizes auf
        // dieses $devices-Array.
        $vehicles = $this->ReadVehicleRows();
        if (count($vehicles) > 0) {
            $assign = $this->AssignVehicles($devices, $vehicles);
            foreach ($assign as $wbIdx => $vIdx) {
                $v = $vehicles[$vIdx];
                $devices[$wbIdx]['socHave'] = true;
                $devices[$wbIdx]['soc'] = round((float) GetValue($v['socID']));
                $devices[$wbIdx]['sub'] = $v['name'];
            }
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
            'diagnostics' => $this->resolveDiagnostics(),
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
    private function resolvePowerValue(array $device): ?float
    {
        $id = $device['powerID'] ?? 0;
        return $id > 0 ? $this->resolveVariableValue($id) : null;
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
     * Property, 1:1 uebernommen). Zeilen ohne gueltige SOC-Variable werden
     * verworfen - ohne SOC gibt es nichts anzuzeigen, das Fahrzeug waere
     * fuer die Zuordnung nutzlos.
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
    private function AssignVehicles(array $rows, array $vehicles): array
    {
        $tol = max(0, $this->readIntProperty('MatchToleranceSec', self::DEF_MATCH_TOLERANCE));

        $wbConnected = [];
        $wbAllIdx = [];
        foreach ($rows as $i => $row) {
            if (($row['function'] ?? '') !== 'wallbox') {
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

        $vConnected = [];
        foreach ($vehicles as $j => $v) {
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

        $map = [];
        $usedV = [];
        foreach ($pairs as $p) {
            if (isset($map[$p['w']]) || isset($usedV[$p['v']])) {
                continue;
            }
            $map[$p['w']] = $p['v'];
            $usedV[$p['v']] = true;
        }

        // Sonderfall genau eine Wallbox / genau ein Fahrzeug: die Lage ist
        // auch ohne Zeitkorrelation eindeutig - hier darf die Verbunden-
        // Bedingung des Fahrzeugs sogar fehlen.
        if (count($map) === 0 && count($wbAllIdx) === 1 && count($vehicles) === 1) {
            $i = $wbAllIdx[0];
            $row = $rows[$i];
            $wbState = $this->CondMet(
                (int) ($row['plugStateID'] ?? 0),
                (string) ($row['plugOp'] ?? 'truthy'),
                (string) ($row['plugVal'] ?? '')
            );
            $vState = $this->CondMet((int) $vehicles[0]['plugID'], (string) $vehicles[0]['plugOp'], (string) $vehicles[0]['plugVal']);
            if ($wbState !== false && $vState !== false) {
                $map[$i] = 0;
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
        $json = $this->ReadAttributeString('DeviceCache');
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    public function GetDiagnostics(): array
    {
        $json = $this->ReadAttributeString('DiagnosticsCache');
        $data = json_decode($json, true);
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
        $irr = $this->readIntProperty('IrradianceID', 0);
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

    private function singleInverterHubCoreID(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(NRGDASH_GUID_INVERTERHUB);
        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : 0;
    }

    /**
     * Rekursive Ident-Suche (Muster: NRGDashboardMonitor::FindVarByIdent(),
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
        $entry['source']     = $source;
        $entry['instanceID'] = $instanceID;
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
}
