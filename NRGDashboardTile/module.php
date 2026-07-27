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

        // InverterHubTile fuehrt selbst schon eine gemischte Verbraucherliste
        // (manuell in ihrer eigenen Consumers-Property eingetragene Geraete +
        // MeterHub + HeishaMon, siehe IHUBTILE_GetConsumers - mit InverterHub
        // abgestimmt am 27.07.2026, nachdem uns eine manuell zugeordnete
        // "Klimaanlage" fehlte, die in keinem Hub-Vertrag auftaucht). Ist eine
        // Instanz davon vorhanden, ist ihre Liste die vollstaendigere und wird
        // bevorzugt - eigene MeterHub/HeishaMon-Direktabfrage entfaellt dann,
        // sonst gaebe es Dubletten. ChargerHub und Tessie sind NICHT in
        // IHUBTILE_GetConsumers enthalten (laut InverterHub) und bleiben
        // deshalb immer eigenstaendige Quellen.
        $tileConsumers = $this->discoverInverterHubTileConsumers();
        if (count($tileConsumers) > 0) {
            $devices = array_merge($devices, $tileConsumers);
        } else {
            $meterHub = $this->discoverListContract(NRGDASH_GUID_METERHUB, 'MHUB_GetFunctions', 'meterhub');
            $this->checkSourceCoverage('MeterHub', NRGDASH_GUID_METERHUB, count($meterHub));
            $devices = array_merge($devices, $meterHub);

            $devices = array_merge($devices, $this->discoverListContract(
                NRGDASH_GUID_METERHUBV, 'MHUBV_GetFunctions', 'meterhub'
            ));

            $heishaMon = $this->discoverHeishaMon();
            $this->checkSourceCoverage('HeishaMon', NRGDASH_GUID_HEISHAMON, count($heishaMon));
            $devices = array_merge($devices, $heishaMon);
        }

        // Reale Lücke, live entdeckt (27.07.2026): InverterHub sagt, ChargerHub
        // sei NIE Teil von IHUBTILE_GetConsumers - trotzdem hat Dietmar dieselben
        // physischen Wallboxen zusaetzlich manuell in die Consumers-Property der
        // Kachel eingetragen (andere Variablen-ID als der ChargerHub-eigene
        // Leistungswert). Ohne Entdopplung erschien "WB 1"/"WB 2" zweimal als
        // getrennter Knoten. Es gibt keinen gemeinsamen Schluessel (unterschiedliche
        // powerIDs) - Label-Abgleich ist der einzige verfuegbare Anhaltspunkt.
        // Bevorzugt wird der InverterHubTile-Eintrag (das ist die bereits von
        // Dietmar sichtgeprüfte Referenzkachel).
        $chargerHub = $this->discoverListContract(NRGDASH_GUID_CHARGERHUB, 'CHUB_GetFunctions', 'chargerhub');
        $this->checkSourceCoverage('ChargerHub', NRGDASH_GUID_CHARGERHUB, count($chargerHub));
        $tileLabels = array_map(function (array $d) {
            return mb_strtolower(trim($d['label'] ?? ''));
        }, array_filter($devices, function (array $d) {
            return ($d['source'] ?? '') === 'inverterhubtile';
        }));
        foreach ($chargerHub as $entry) {
            if (!in_array(mb_strtolower(trim($entry['label'] ?? '')), $tileLabels, true)) {
                $devices[] = $entry;
            }
        }

        $devices = array_merge($devices, $this->discoverTessie());

        // Echter Hauslast-Zaehler (IHUBTILE_GetHouseLoad, InverterHub-Fund
        // 27.07.2026: unsere houseW-Bilanz pv-grid+bat ist nur eine Naeherung;
        // InverterHubTile bevorzugt einen tatsaechlich konfigurierten Zaehler
        // (eigene HouseLoadID-Kette, kachelspezifisch, kein Feld in
        // IHUB_GetFunctions). Als 'house'-Geraet eingehaengt - module.html
        // bevorzugt ein 'house'-Geraet ohnehin schon vor der Bilanzformel.
        $devices = array_merge($devices, $this->discoverInverterHubTileHouseLoad());

        // Manuelle Konfiguration IMMER zusaetzlich auswerten (kein Hub-Modul
        // vorausgesetzt) - fuer Haushalte ganz ohne InverterHub/MeterHub/etc.
        $devices = array_merge($devices, $this->discoverManualCore());
        $devices = array_merge($devices, $this->discoverManualConsumers());

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
        $devices = array_map(function (array $d) {
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
        ];
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
     * einem Fluss-Icon. Erster Anbieter: IHUBMON_GetDiagnostics
     * (InverterHubMonitor, ab 0.74.0-beta.1). Jeder Eintrag ist bereits
     * generisch (type/label/level/threshold/reason + Referenzen/Werte je
     * Typ) - das Rendering in module.html iteriert type-neutral, damit ein
     * künftiger zweiter Anbieter (MeterHub, HeishaMon, ...) ohne
     * Aenderungen an dieser Stelle reinpasst, solange er demselben
     * Grundschema folgt (siehe README, Abschnitt "Diagnostik-Vertrag").
     */
    private function discoverDiagnostics(): array
    {
        $results = [];
        if (!function_exists('IHUBMON_GetDiagnostics')) {
            return $results;
        }
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
        return $results;
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
            if (!is_array($entries)) {
                continue;
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
