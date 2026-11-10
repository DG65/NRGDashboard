<?php

declare(strict_types=1);

/**
 * NRGDashboardTopology - Verbund-Uebersicht als Netzwerk-Karte (drittes
 * Modul neben NRGDashboardTile/Monitor). Auf Vorschlag von EMS (28.07.2026):
 * Stern-Topologie um die EMS-Instanz herum, Knoten = von EMS gefundene
 * Partnermodule, Farbe = Verbindungsstatus aus EMS_GetFederationHealth().
 *
 * Bewusst begrenzter Scope (v1, mit EMS abgestimmt): EMS kennt nur SEINE
 * eigenen gefundenen Partner, nicht das vollstaendige Beziehungsgeflecht
 * des gesamten Verbunds (z.B. dass NRGDashboard selbst direkt mit
 * InverterHub spricht). Ein vollstaendiger Multi-Modul-Graph braeuchte
 * einen neuen, noch nicht existierenden Vertrag (z.B. <Modul>_GetConnections()),
 * den der Verbund erst gemeinsam abstimmen muesste - das wird hier bewusst
 * NICHT vorweggenommen.
 */
require_once __DIR__ . '/../libs/FormStatus.php';
require_once __DIR__ . '/../libs/FormLayout.php';
require_once __DIR__ . '/../libs/NRGDASH_Text.php';

class NRGDashboardTopology extends IPSModule
{
    use NRGDashFormStatus;
    use NRGDashFormLayout;

    /**
     * Wählbare Fachpanels für „Formular: Aufbau“: Panel-name aus form.json => stabiler Schlüssel (SUITE.md 3a).
     * Gefunden werden die Panels über ihren Namen, nie über die (übersetzte) Caption; die Schlüssel stehen so in der Property FormLayout.
     */
    private const FORM_LAYOUT_PANELS = ['PanelSource' => 'source', 'PanelDetails' => 'details', 'PanelStyle' => 'style'];

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
        'Computes the best fit first',
        'Before this map is drawn, it automatically tries all rotation angles and picks the one that fills the tile area best - every installed partner module appears as its own node, without any manual linking.',
        'Frosted-glass look',
        'Labels and areas use a soft glass look (inspired by current operating system design languages) instead of flat boxes - and they live in the same coordinate space as the nodes, so they scale along automatically, without any pixel conversion of their own.',
        'Nodes always stay the same size',
        'Unlike what you might expect, the nodes do not shrink as the number of modules grows - the spacing between them grows instead. The map stays equally readable with 3 as with 15 partners.',
        'Readable labels, however long',
        'Short names like “WB 1” stay compact, long ones like “InverterHub WR1 (GoodWe)” widen the label automatically or wrap onto two lines - instead of simply being cut off, as is usual elsewhere.',
        'Sector tinting',
        'Each module category gets a subtle, transparent pie-slice area in the background - the grouping stays recognizable at a glance even without connecting lines.',
        'Details on hover',
        'Move the mouse over a node to show more information about it.',
        'healthy',
        'disrupted',
        'deleted',
        'no response',
        'Status: %s',
        '%d instances',
        '%d instances · status %s',
        'Federation coordinator',
        'PV forecast',
        'No data available.',
        '%d/%d partner instances healthy',
    ];

    private const EMS_GUID = '{31C61A7B-28C4-4F97-9651-1A64B3469E3C}';

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';

    // Verbund-Formularkonvention (Muster NRGDashboardTile): "Was ist Neu"
    // (versionsscharf dismissible) + Forum-Hinweis (einmalig dismissible) +
    // Versionszeile im Doku-Panel. NEWS_VERSION bei jeder nutzersichtbaren
    // Aenderung an diesem Modul erhoehen.
    // Versionsweise statt EINER flachen Liste (Dietmar, 23.09.2026: "nur die
    // Neuerungen zwischen der zuletzt verwendeten und der nun installierten
    // Version anzeigen") - newsBanner() zeigt jeden Schluessel-Eintrag, dessen
    // Version NEUER ist als das zuletzt bestaetigte Attribut SeenNews (siehe
    // dort), gruppiert nach Version. Die alte, gewachsene Liste bleibt als EIN
    // Eintrag unter ihrer letzten Vergabe ('0.6.5') erhalten statt sie
    // nachtraeglich (und unzuverlaessig) pro Zeile einer Version zuzuordnen -
    // neue Eintraege ab jetzt bekommen ihre EIGENE Versionsnummer als Schluessel.
    private const NEWS_VERSIONS = [
        '0.6.6' => [
            '🪟 New: “Form: layout” at the very bottom of the form - choose for each section whether it is expanded, collapsed or hidden when opened, and at which position it appears (applies after “Apply changes” the next time the form is opened; hidden sections keep working).'
        ],
        '0.6.5' => [
            'Feedback panel now exactly as in MeterHub: a dedicated "To the forum thread" button instead of a plain link text.',
            '💬 The Symcon forum thread is now live - the previous GitHub note in the feedback panel now points there.',
            '🧡 New: "About this module" (license/donation note) at the very bottom of the form; the forum/GitHub note is now a panel of its own that can be dismissed, instead of a plain line.',
            '👋 New: a "What is this module for?" panel at the very top of the form briefly explains what this tile does and what it is good for - meant for first contact, can be dismissed once.',
            'New "?" button at the top right shows the introduction tour again at any time - regardless of whether it has been confirmed before. Intended for shared instances (e.g. a demo/showcase instance with a shared login) where every visitor should be able to start the tour themselves.',
            'New: federation health as a star topology around the EMS instance - partner modules colored by connection status.',
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
        NRGDASH_Text::setzen(fn (string $text): string => $this->Translate($text));

        $this->RegisterPropertyInteger('EmsInstance', 0);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        // Wählbarer Formular-Aufbau (SUITE.md 3a): Liste {Key, Panel, Opened, Closed, Hidden, Pos}, leer = Standard
        // Standalone-Webseite (Hook) abschaltbar (09.11.2026, Forum doctee #232):
        // Standard an, damit bestehende Installationen unveraendert laufen.
        $this->RegisterPropertyBoolean('StandaloneEnabled', true);
        $this->RegisterPropertyString('FormLayout', '[]');
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);

        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        // "Wozu dieses Modul?" (SUITE.md "Einheitliche Formular-Optik" Punkt 0,
        // 14.09.2026) - einmalig dismissible, nicht versioniert.
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeInteger('LastDiscoveryTs', 0);
        $this->RegisterAttributeString('PartnerNamesCache', '[]');

        // Einfuehrungs-Tour bei erster Benutzung (29.08.2026, Dietmar:
        // "eine Tour die bei der ersten Benutzung eingeblendet und nur per
        // Haken ausgeblendet werden kann") - je Instanz einmalig, WebFront-
        // seitig (nicht nur Konsole, da die Kartenfeinheiten gerade dort
        // erlebt werden). Bestaetigung kommt ueber den WebHook zurueck
        // (ProcessHookData(), ?dismissTour=1) - die Kachel selbst hat als
        // sandboxed HTML-SDK-Tile keinen anderen Rueckkanal in die Instanz.
        $this->RegisterAttributeBoolean('TourSeen', false);

        $this->RegisterTimer('Refresh', 0, 'NRGDASHTOPO_Render($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        NRGDASH_Text::setzen(fn (string $text): string => $this->Translate($text));
        $this->SetVisualizationType(1);
        // Verbindungsstatus kann sich haeufiger aendern als Energiewerte -
        // 60s-Takt statt der 5-Minuten der anderen beiden Module, plus ein
        // sofortiger Lauf bei ApplyChanges() (gleiche Lehre wie bei
        // NRGDashboardTile: sonst bleibt eine offene Kachel bis zum ersten
        // Timer-Tick auf einem veralteten Stand).
        $this->SetTimerInterval('Refresh', 60 * 1000);
        // Standalone-Webseite fuer IPSView/Browser (WebView/Popup) - Muster
        // Prognoses Energiebilanz-Modul (Dietmar, 27.08.2026: "alle Kacheln
        // ... auch so vorbereiten, dass man sie in IPSView einbinden kann").
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->SyncHook('/hook/nrgdashtopology' . $this->InstanceID);
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

    /**
     * Einfuehrungs-Tour bestaetigt: die Kachel meldet das ueber requestAction()
     * zurueck, nicht ueber den offenen WebHook (der kann abgeschaltet sein).
     */
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'dismissTour') {
            $this->WriteAttributeBoolean('TourSeen', true);
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
     * Popup oder jeden Browser). Aufruf: /hook/nrgdashtopology<InstanzID>.
     * Mit ?json=1 werden nur die Daten geliefert (fuer die Auto-Aktualisierung).
     */
    public function ProcessHookData()
    {
        // Einfuehrungs-Tour bestaetigt (29.08.2026) - vom Tour-Overlay in
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

    /**
     * Instanzstatus spiegelt jetzt den tatsaechlichen Zustand wider (Muster:
     * Tile/Monitor) statt immer auf 102 zu stehen - buildPayload() liefert
     * das Fehlerbild ohnehin schon, hier nur zusaetzlich auf die
     * IPS-Statusanzeige (Konsole/Objektbaum) gespiegelt.
     */
    private function updateInstanceStatus(array $payload): void
    {
        $this->SetStatus(($payload['ok'] ?? false) ? 102 : 104);
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

    /**
     * EMS-Instanz - explizite Wahl gewinnt, sonst Automatik: bei mehreren
     * Instanzen die erste mit Status 102 (gesund), sonst die erste Instanz.
     * Muster: z.B. Mehrinstanz-Testszenarios mit alten/neuen EMS-Kopien.
     */
    private function EmsInstanceID(): int
    {
        $cfg = $this->readIntProperty('EmsInstance', 0);
        if ($cfg > 0 && IPS_InstanceExists($cfg)
            && IPS_GetInstance($cfg)['ModuleInfo']['ModuleID'] === self::EMS_GUID) {
            return $cfg;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::EMS_GUID);
        if (!is_array($ids) || count($ids) === 0) {
            return 0;
        }
        // Bei genau einer: nimm sie
        if (count($ids) === 1) {
            return (int) $ids[0];
        }
        // Bei mehreren: bevorzuge Status 102 (gesund)
        foreach ($ids as $id) {
            if (IPS_GetInstance((int) $id)['InstanceStatus'] === 102) {
                return (int) $id;
            }
        }
        // Fallback: erste Instanz
        return (int) $ids[0];
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
     * Anzeigename je Partner-Modulschluessel (aus EMS_GetFederationHealth()
     * 'module'-Feld) - nur fuer die Beschriftung, kein neuer Vertrag.
     */
    private const MODULE_LABELS = [
        'goodweet'    => 'GoodWe ET',
        'inverterhub' => 'InverterHub',
        'meterhub'    => 'MeterHub',
        'chargerhub'  => 'ChargerHub',
        'heishamon'   => 'HeishaMon',
        'tessie'      => 'Tessie',
        'tibber'      => 'Tibber Grid Rewards',
        // EMS 0.16.0: reiner Anzeige-Eintrag (kein Steuer-/Situations-
        // Vertrag, nicht Teil von PartnerCache/Discover()) - Casing 'PVF'
        // exakt wie von EMS_GetFederationHealth() geliefert.
        'PVF'         => 'PV forecast',
    ];

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
        $this->updateDiscoveryResultLabel($payload);
    }

    /**
     * Ergebnis-Kopfzeile im Formular (SUITE.md "Einheitliche Verbund-
     * Status-Kopfzeile", 20.08.2026, Referenz EMS::getDiscoverySummaryLine()):
     * EINE Zeile Icon+Zahl+Zeitstempel, kein Aufzaehlungssatz mehr - die
     * Partnernamen (genau die, die auch die Kachel je Knoten zeigt) wandern
     * in ein eingeklapptes Unter-Panel (siehe formatPartnerNames()). Der
     * Sonderfall "keine EMS-Instanz erreichbar" bleibt eine eigene Meldung -
     * dafuer gibt es noch keine Zahl zum Zaehlen. No-Op, wenn gerade kein
     * Formular offen ist (UpdateFormField).
     */
    private function updateDiscoveryResultLabel(array $payload): void
    {
        if (!($payload['ok'] ?? false)) {
            $this->UpdateFormField('DiscoveryResult', 'caption', '⚠️ ' . ($payload['error'] ?? NRGDASH_Text::t('No connection data available.')));
            return;
        }
        $names = array_map(static fn (array $n) => $n['label'], $payload['nodes'] ?? []);
        $this->WriteAttributeInteger('LastDiscoveryTs', time());
        $this->WriteAttributeString('PartnerNamesCache', json_encode($names));
        $this->UpdateFormField('DiscoveryResult', 'caption', $this->getDiscoverySummaryLine());
        $this->UpdateFormField('DiscoveryDetails', 'caption', $this->formatPartnerNames($names, $payload['emsLabel'] ?? '?'));
    }

    private function getDiscoverySummaryLine(): string
    {
        $ts = (int) $this->ReadAttributeInteger('LastDiscoveryTs');
        if ($ts === 0) {
            return NRGDASH_Text::t('ℹ️ Not searched yet — open the tile or apply the form.');
        }
        $names = json_decode((string) $this->ReadAttributeString('PartnerNamesCache'), true) ?: [];
        $count = count($names);
        $icon = $count > 0 ? '✅' : '⚠️';
        // Einzahl/Mehrzahl als zwei vollständige Sätze (deutsch gleich)
        if ($count === 1) {
            return NRGDASH_Text::t('%s %d partner found (last checked at %s).', $icon, $count, date('H:i:s', $ts));
        }
        return NRGDASH_Text::t('%s %d partners found (last checked at %s).', $icon, $count, date('H:i:s', $ts));
    }

    private function formatPartnerNames(array $names, string $emsLabel): string
    {
        if (empty($names)) {
            return NRGDASH_Text::t('EMS instance “%s” found, but no partner modules reported.', $emsLabel);
        }
        return implode(', ', $names);
    }

    private function injectDiscoveryResultLabel(array &$form): void
    {
        foreach ($form['elements'] as &$el) {
            if (($el['name'] ?? '') === 'DiscoveryResult') {
                $el['caption'] = $this->getDiscoverySummaryLine();
            }
            if (($el['name'] ?? '') === 'DiscoveryDetails') {
                $names = json_decode((string) $this->ReadAttributeString('PartnerNamesCache'), true) ?: [];
                $el['caption'] = empty($names) ? NRGDASH_Text::t('No details yet - search first.') : implode(', ', $names);
            }
        }
        unset($el);
    }

    public function ResetStyle(): void
    {
        $this->UpdateFormField('ColorBackground', 'value', self::DEF_BACKGROUND);
        $this->UpdateFormField('FontFamily', 'value', self::DEF_FONT);
    }

    /** Konsolen-Gegenstueck zur WebFront-Dismiss-Tour - fuer den Fall, dass
     *  ein Nutzer sich die Feinheiten nochmal zeigen lassen will. */
    public function ResetTour(): string
    {
        $this->WriteAttributeBoolean('TourSeen', false);
        // Store-Checkliste Punkt 13 (13.09.2026): der Button gab bisher keine
        // sichtbare Rueckmeldung in der Konsole - die Wirkung zeigte sich nur
        // beim naechsten Oeffnen der WebFront-Kachel.
        return NRGDASH_Text::t('✅ The tour will be shown again the next time the tile is opened.');
    }

    /**
     * Fuegt "Was ist Neu" (versionsscharf dismissible) und den Forum-Hinweis
     * (einmalig dismissible) um die statische form.json herum ein, traegt die
     * Versionsnummer ins Doku-Panel ein - exakte Struktur wie
     * NRGDashboardTile (Muster fuer den ganzen Verbund).
     */
    /** Verbindungsstatus zur EMS-Instanz (SUITE.md "Verbund-Verbindungen sichtbar machen"). */
    private function emsStatusLine(array &$elements): string
    {
        $ids = @IPS_GetInstanceListByModuleID(self::EMS_GUID);
        $ids = is_array($ids) ? $ids : [];
        if (count($ids) === 0) {
            return NRGDASH_Text::t('ℹ️ No EMS instance found - the topology shows the other installed NRG-Stack modules without EMS as the center.');
        }
        $cfg = $this->readIntProperty('EmsInstance', 0);
        $explicit = $cfg > 0 && IPS_InstanceExists($cfg) && IPS_GetInstance($cfg)['ModuleInfo']['ModuleID'] === self::EMS_GUID;
        $id = $this->EmsInstanceID();
        $head = $this->formInstanceLabel($id) . ' (' . $this->formInstanceState($id) . ')';
        if (!$explicit && count($ids) > 1) {
            return NRGDASH_Text::t('⚠️ %d EMS instances found, none selected - %s is used (a healthy one is preferred). Please choose one below, e.g. when running test and production EMS side by side.', count($ids), $head);
        }
        // Logik nie am Anzeigetext: aktiv oder nicht, sagt formInstanceActive()
        if (!$this->formInstanceActive($id)) {
            return $explicit
                ? NRGDASH_Text::t('⚠️ EMS %s selected, but it is not active - the topology shows the state of the modules, the center delivers no live values.', $head)
                : NRGDASH_Text::t('⚠️ EMS %s detected automatically, but it is not active - the topology shows the state of the modules, the center delivers no live values.', $head);
        }
        if (!$explicit) {
            $this->hideFormField($elements, 'EmsInstance');
        }
        return $explicit
            ? NRGDASH_Text::t('✏️ EMS: %s (own entry) - center of the topology, the health and connections of all modules are grouped from there.', $head)
            : NRGDASH_Text::t('🔗 EMS: %s (detected automatically) - center of the topology, the health and connections of all modules are grouped from there.', $head);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }
        // Das Label mit dem Hook-Pfad enthält einen eingesetzten Wert: die Konsole fände den Text so nicht mehr, deshalb selbst übersetzen.
        $this->setFormCaption($form['elements'], 'HookInfo', NRGDASH_Text::t('IPSView/popup: the tile also runs as a standalone web page at http://<Symcon-IP>:3777%s (auto-refresh every 30 s). In IPSView, place a WebView element on a popup page and enter this URL. Note: WebHooks can be reached on the local network without logging in.', '/hook/nrgdashtopology' . $this->InstanceID));

        $this->injectVersionIntoDocPanel($form);
        $this->setFormStatusLine($form['elements'], 'EmsStatus', $this->emsStatusLine($form['elements']));
        $this->injectDiscoveryResultLabel($form);

        // Fachpanels nach der Auswahl in „Formular: Aufbau“ ordnen (SUITE.md 3a); Zweck, Neu und Doku bleiben oben, Forum und Lizenz unten
        $form['elements'] = $this->formLayoutApply($form['elements'], self::FORM_LAYOUT_PANELS);

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
                    ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHTOPO_DismissReviewHint($id);'],
                ],
            ];
        }
        $form['elements'][] = $this->LicenseHint();

        return json_encode($form);
    }

    /** Knopf „Auf Standard zurücksetzen“ im Panel „Formular: Aufbau“ (setzt nur die Liste im offenen Formular; gespeichert wird per „Übernehmen“). */
    public function ResetFormLayout(): string
    {
        return $this->formLayoutReset(__DIR__ . '/form.json', self::FORM_LAYOUT_PANELS);
    }

    private function formLayoutResetFunction(): string
    {
        return 'NRGDASHTOPO_ResetFormLayout';
    }

    private function injectVersionIntoDocPanel(array &$form): void
    {
        $lib = @IPS_GetLibrary('{8D4E7A2C-1F6B-4C93-A5D8-3E9F1B6C7D02}');
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? NRGDASH_Text::t('ℹ️ NRG-Stack Dashboard version %s (build %s)', $lib['Version'], $lib['Build'] ?? '?')
            : 'ℹ️ NRG-Stack Dashboard';
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
                ['type' => 'Label', 'caption' => 'Shows the health of all installed NRG-Stack modules at a glance, grouped radially around EMS - one status color per instance or module cluster.'],
                ['type' => 'Label', 'caption' => 'The benefit: see immediately whether any partner module is not responding or missing, instead of clicking through every instance one by one.'],
                ['type' => 'Label', 'caption' => 'The status comes from EMS (detected automatically when exactly one instance is installed). NRGDashboardTile/Map additionally show the actual energy flow instead of just the health.'],
                ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHTOPO_AckPurposeIntro($id);'],
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

    /**
     * "Was ist Neu"-Panel, versionsscharf dismissible: erscheint erneut,
     * sobald sich NEWS_VERSION erhoeht, auch wenn eine fruehere Version
     * schon bestaetigt wurde. Version STEHT in der Caption (Klaerung EMS/
     * InverterHub, 30.07.2026: SUITE.md-Text "keine Versionsnummer im
     * Panel" war veraltet/falsch, nicht die Praxis - Dismiss blendet das
     * GESAMTE Panel aus, kein Zwischenzustand moeglich. SUITE.md in
     * Commit da42f8c korrigiert.)
     */
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
        $items[] = ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'NRGDASHTOPO_AckNews($id);'];
        $latest = array_key_last($pending);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => NRGDASH_Text::t('🆕 New up to version %s', $latest), 'expanded' => true, 'items' => $items];
    }

    // Eigene Modul-GUID (14.09.2026, "geteiltes Ausblenden ueber
    // Geschwister-Instanzen") - fuer die Geschwistersuche unten, NICHT
    // fuer die Discovery anderer Verbund-Module (dafuer stehen eigene
    // GUID-Konstanten je Partnermodul).
    private const SELF_MODULE_GUID = '{28EEBB3F-A735-4CE6-B879-6408A0EC4A68}';

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
            $fn = 'NRGDASHTOPO_' . $applyMethod;
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

    /**
     * Stern-Topologie: EMS-Instanz in der Mitte, ein Knoten je Eintrag aus
     * EMS_GetFederationHealth()['entries']. Status je Knoten:
     *   healthy    - status===102 (laeuft)
     *   unhealthy  - Instanz existiert, aber status!==102 (Fehler/Inaktiv)
     *   missing    - Instanz wurde geloescht (status===0 laut EMS-Vertrag)
     *   noresponse - Instanz installiert, aber Vertragsfunktion antwortet
     *                nicht (EMS 0.11.0, additive Felder 'missing'/
     *                'missingCount' - eigener Fall, NICHT dasselbe wie
     *                der obige geloescht-Fall trotz gleichem Wortlaut bei
     *                EMS. Higher-Alarm-Stufe: noch installiert, aber
     *                stumm - genau das, was Dietmar als Alarmfall wollte.
     */
    private function buildPayload(): array
    {
        $emsID = $this->EmsInstanceID();
        if ($emsID <= 0 || !function_exists('EMS_GetFederationHealth')) {
            return [
                'ok'    => false,
                'error' => NRGDASH_Text::t('No EMS instance found - please choose one in the form or install EMS.'),
            ];
        }

        $health = @EMS_GetFederationHealth($emsID);
        if (!is_array($health) || !isset($health['entries']) || !is_array($health['entries'])) {
            return [
                'ok'    => false,
                'error' => NRGDASH_Text::t('EMS_GetFederationHealth() returned no usable data.'),
            ];
        }

        $nodes = [];
        foreach ($health['entries'] as $entry) {
            $module = (string) ($entry['module'] ?? '');
            $status = (int) ($entry['status'] ?? 0);
            $healthy = (bool) ($entry['healthy'] ?? false);
            $nodes[] = [
                'key'    => $module . '_' . (int) ($entry['instanceID'] ?? 0),
                'label'  => (string) ($entry['label'] ?? NRGDASH_Text::t(self::MODULE_LABELS[$module] ?? $module)),
                'module' => $module,
                'status' => $status === 0 ? 'missing' : ($healthy ? 'healthy' : 'unhealthy'),
            ];
        }

        // EMS 0.11.0: additive Liste "installiert, aber ohne Antwort" -
        // eigener Status noresponse, damit der Verbund diesen Fall optisch
        // von "abgeschaltet/Fehler" (unhealthy) und "komplett geloescht"
        // (missing) unterscheiden kann. Fehlt das Feld (aeltere EMS-Version),
        // bleibt die Liste leer - rein additiv, kein Bruch.
        foreach ((array) ($health['missing'] ?? []) as $m) {
            $module = (string) ($m['module'] ?? '');
            $nodes[] = [
                'key'    => $module . '_' . (int) ($m['instanceID'] ?? 0) . '_noresponse',
                'label'  => (string) ($m['label'] ?? NRGDASH_Text::t(self::MODULE_LABELS[$module] ?? $module)),
                'module' => $module,
                'status' => 'noresponse',
            ];
        }

        return [
            'ok'           => true,
            'emsLabel'     => IPS_GetName($emsID),
            'summary'      => (string) ($health['summary'] ?? ''),
            'total'        => (int) ($health['total'] ?? count($nodes)),
            'healthy'      => (int) ($health['healthyCount'] ?? 0),
            'missingCount' => (int) ($health['missingCount'] ?? 0),
            'nodes'        => $nodes,
            'bg'        => $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND)),
            'font'      => $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT)),
            'hookPath'  => '/hook/nrgdashtopology' . $this->InstanceID,
            // Einfuehrungs-Tour bei erster Benutzung (29.08.2026).
            'showTour'  => !(bool) $this->ReadAttributeBoolean('TourSeen'),
        ];
    }
}
