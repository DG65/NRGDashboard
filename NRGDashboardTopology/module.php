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
class NRGDashboardTopology extends IPSModule
{
    private const EMS_GUID = '{31C61A7B-28C4-4F97-9651-1A64B3469E3C}';

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';

    // Verbund-Formularkonvention (Muster NRGDashboardTile): "Was ist Neu"
    // (versionsscharf dismissible) + Forum-Hinweis (einmalig dismissible) +
    // Versionszeile im Doku-Panel. NEWS_VERSION bei jeder nutzersichtbaren
    // Aenderung an diesem Modul erhoehen.
    private const NEWS_VERSION = '0.6.5';
    private const NEWS_ITEMS = [
        'Feedback-Panel jetzt 1:1 wie bei MeterHub: eigener "Zum Forums-Thread"-Knopf statt eines reinen Link-Textes.',
        '💬 Der Symcon-Forum-Thread ist jetzt live - der bisherige GitHub-Hinweis im Feedback-Panel verweist ab sofort dorthin.',
        '🧡 Neu: "Über dieses Modul" (Lizenz/Spenden-Hinweis) ganz unten im Formular, der Forum/GitHub-Hinweis ist jetzt ein eigenes, dismissibles Panel statt einer schlichten Zeile.',
        '👋 Neu: ein "Wozu dieses Modul?"-Panel ganz oben im Formular erklärt kurz, was diese Kachel tut und welchen Nutzen sie stiftet - gedacht für den ersten Kontakt, einmalig wegklickbar.',
        'Neuer "?"-Knopf oben rechts zeigt die Einführungs-Tour jederzeit erneut - unabhängig davon, ob sie schon einmal bestätigt wurde. Gedacht für gemeinsam genutzte Instanzen (z. B. eine Demo-/Vorstellungs-Instanz mit einem geteilten Zugang), wo jeder Besucher die Tour selbst starten können soll.',
        'Neu: Verbund-Gesundheit als Stern-Topologie um die EMS-Instanz - Partnermodule farbig nach Verbindungsstatus.',
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

        $this->RegisterPropertyInteger('EmsInstance', 0);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
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
            $this->RegisterHook('/hook/nrgdashtopology' . $this->InstanceID);
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
     * Popup oder jeden Browser). Aufruf: /hook/nrgdashtopology<InstanzID>.
     * Mit ?json=1 werden nur die Daten geliefert (fuer die Auto-Aktualisierung).
     */
    public function ProcessHookData()
    {
        // Einfuehrungs-Tour bestaetigt (29.08.2026) - vom Tour-Overlay in
        // module.html per fetch() aufgerufen, siehe Create().
        if (isset($_GET['dismissTour'])) {
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
        $html = file_get_contents(__DIR__ . '/module.html');
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
        'PVF'         => 'PV-Prognose',
    ];

    public function GetVisualizationTile()
    {
        $payload = $this->buildPayload();
        $this->updateInstanceStatus($payload);
        $html = file_get_contents(__DIR__ . '/module.html');
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
            $this->UpdateFormField('DiscoveryResult', 'caption', '⚠️ ' . ($payload['error'] ?? 'Keine Verbindungsdaten verfügbar.'));
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
            return 'ℹ️ Noch nicht gesucht — Kachel öffnen oder Formular übernehmen.';
        }
        $names = json_decode((string) $this->ReadAttributeString('PartnerNamesCache'), true) ?: [];
        $count = count($names);
        $icon = $count > 0 ? '✅' : '⚠️';
        return sprintf('%s %d Partner gefunden (zuletzt %s Uhr).', $icon, $count, date('H:i:s', $ts));
    }

    private function formatPartnerNames(array $names, string $emsLabel): string
    {
        if (empty($names)) {
            return sprintf('EMS-Instanz „%s“ gefunden, aber keine Partnermodule gemeldet.', $emsLabel);
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
                $el['caption'] = empty($names) ? 'Noch keine Details - erst suchen.' : implode(', ', $names);
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
        return '✅ Tour wird beim nächsten Öffnen der Kachel wieder angezeigt.';
    }

    /**
     * Fuegt "Was ist Neu" (versionsscharf dismissible) und den Forum-Hinweis
     * (einmalig dismissible) um die statische form.json herum ein, traegt die
     * Versionsnummer ins Doku-Panel ein - exakte Struktur wie
     * NRGDashboardTile (Muster fuer den ganzen Verbund).
     */
    public function GetConfigurationForm()
    {
        $raw = str_replace('%%HOOK%%', '/hook/nrgdashtopology' . $this->InstanceID, file_get_contents(__DIR__ . '/form.json'));
        $form = json_decode($raw, true);
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }

        $this->injectVersionIntoDocPanel($form);
        $this->injectDiscoveryResultLabel($form);

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
                    ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'NRGDASHTOPO_DismissReviewHint($id);'],
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
                ['type' => 'Label', 'caption' => 'Zeigt den Gesundheitszustand aller installierten NRG-Stack-Module auf einen Blick, radial um das EMS gruppiert - je Instanz oder Modul-Cluster eine Statusfarbe.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen: sofort erkennen, ob irgendein Partnermodul nicht antwortet oder fehlt, statt jede Instanz einzeln durchzuklicken.'],
                ['type' => 'Label', 'caption' => 'Der Status kommt vom EMS (bei genau einer installierten Instanz automatisch erkannt). NRGDashboardTile/Map zeigen ergänzend den eigentlichen Energiefluss statt nur den Gesundheitszustand.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'NRGDASHTOPO_AckPurposeIntro($id);'],
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
    private function newsBanner(): ?array
    {
        if (@$this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 Neu in diesem Modul — bitte kurz ansehen und ggf. die Einstellungen prüfen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'NRGDASHTOPO_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
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
                'error' => 'Keine EMS-Instanz gefunden - bitte im Formular auswählen oder EMS installieren.',
            ];
        }

        $health = @EMS_GetFederationHealth($emsID);
        if (!is_array($health) || !isset($health['entries']) || !is_array($health['entries'])) {
            return [
                'ok'    => false,
                'error' => 'EMS_GetFederationHealth() lieferte keine auswertbaren Daten.',
            ];
        }

        $nodes = [];
        foreach ($health['entries'] as $entry) {
            $module = (string) ($entry['module'] ?? '');
            $status = (int) ($entry['status'] ?? 0);
            $healthy = (bool) ($entry['healthy'] ?? false);
            $nodes[] = [
                'key'    => $module . '_' . (int) ($entry['instanceID'] ?? 0),
                'label'  => (string) ($entry['label'] ?? (self::MODULE_LABELS[$module] ?? $module)),
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
                'label'  => (string) ($m['label'] ?? (self::MODULE_LABELS[$module] ?? $module)),
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
