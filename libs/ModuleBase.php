<?php

declare(strict_types=1);

/**
 * Gemeinsamer Modulrahmen der sieben Dashboard-Module (0.13.0, Umbau 1 der Code-Bestandsaufnahme vom 18.11.2026): WebHook-Eintrag
 * im WebHook-Control, Lizenzhinweis, "Was ist Neu"-/Wozu-Bestaetigung samt geteiltem Ausblenden ueber Geschwister-Instanzen.
 * Die Funktionen sind WOERTLICH aus den Modulen hierher verschoben (Pruefung: .tools/rahmen-trait.php). Modulspezifisch ist nur,
 * was die Klasse als Konstante liefert:
 *   HOOK_PATH           Pfad ohne Instanznummer, z. B. '/hook/nrgdashtopology'
 *   FN_PREFIX           Praefix der von Symcon erzeugten Modulfunktionen, z. B. 'NRGDASHTOPO_'
 *   SELF_MODULE_GUID    GUID des eigenen Moduls (Geschwister-Suche)
 *   NEWS_VERSIONS       "Was ist Neu"-Zeilen je Version
 *   ATTR_REVIEW_HINT_GONE, LICENSE_URL, PAYPAL_URL
 * Oeffentliche Funktionen dieses Traits exportiert Symcon je Modul mit dem Modulpraefix (Timer und Formularknoepfe rufen sie so auf).
 * Eine Klassenmethode gleichen Namens im Modul ersetzt die Trait-Methode - so bleiben abweichende Varianten (siehe CHANGELOG 0.13.0)
 * bis zu einer bewussten Entscheidung unveraendert.
 */
trait NRGDashModuleBase
{
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

    /** Hook je nach Einstellung "Standalone-Webseite" registrieren oder entfernen. */
    private function SyncHook(string $WebHook): void
    {
        if ($this->ReadPropertyBoolean('StandaloneEnabled')) {
            $this->RegisterHook($WebHook);
        } else {
            $this->UnregisterHook($WebHook);
        }
    }

    /** Beim Loeschen der Instanz den eigenen WebHook-Eintrag mit aufraeumen (kein Verwaisen im WebHook-Control). */
    public function Destroy()
    {
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->UnregisterHook(self::HOOK_PATH . $this->InstanceID);
        }
        parent::Destroy();
    }

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

    public function AckPurposeIntro(): void
    {
        $this->AckPurposeIntroApply();
        $this->propagateDismiss('AckPurposeIntroApply');
    }

    public function AckPurposeIntroApply(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    /** Store-Review-Hinweis wegklicken (Button) - siehe AckNews(). */
    public function DismissReviewHint(): void
    {
        $this->DismissReviewHintApply();
        $this->propagateDismiss('DismissReviewHintApply');
    }

    /** "Uebernehmen": siehe AckNewsApply() - nur lokaler Zustand, keine Propagation. */
    public function DismissReviewHintApply(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
    }

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
            $fn = self::FN_PREFIX . $applyMethod;
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
}
