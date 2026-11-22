<?php

declare(strict_types=1);

/**
 * Allgemeine Suche nach Modulen anderer Autoren (Fremdanbindung, SUITE.md "Fremdanbindung", Regeln 2/3/8; Vorbild EMS/Partners.php
 * discoverForeign; 0.14.1 Wallboxen, 0.14.2 Waermepumpen). IPS_GetModuleList -> Geraetemodul (ModuleType 3) -> Praefix ->
 * <PRAEFIX>_GetFunctions. Je EINTRAG wird nach Vertragstyp gefiltert (contractType, sonst Type/type, sonst function), nie die ganze
 * Liste: ein Modul kann mehrere Geraetearten in einer Liste liefern (WPISM8: heatpump und ventilation). Vertrags-Hauptversion 1.
 * Eigene Praefixe sind ausgenommen (sie kommen ueber ihren bekannten Weg), deaktivierte Instanzen (104) werden uebersprungen,
 * jeder Aufruf ist gekapselt (ArgumentCountError: gleichnamige Funktion mit anderer Signatur, Modul wird uebersprungen), Zeitbudget 3 s.
 * Nur Lesen und Anzeigen: Eintraege tragen source 'ext:<PRAEFIX>', _instanceID und _extPrefix.
 */
trait NRGDashForeignDiscovery
{
    /** Praefixe der eigenen Verbund-Module (ohne Unterstrich), die die Fremdsuche nie beruecksichtigt. */
    private function foreignOwnPrefixes(): array
    {
        return ['CHUB', 'OHUB', 'CHEEB', 'MHUB', 'MHUBV', 'MHUBE', 'IHUB', 'IHUBTILE', 'HEISHA', 'HMON', 'WPHUB', 'WPMBHUB', 'WPMBGW',
            'SAMEHS', 'WPBSBL', 'WPEEB', 'EMS', 'TIBBERGR', 'SPOT', 'SGW', 'PVF', 'LFC', 'NRGDASH', 'TESSIE'];
    }

    /**
     * @param string $type Vertragstyp, z. B. 'charger' oder 'heatpump'
     * @return array<int,array> rohe Eintraege mit source 'ext:<PRAEFIX>', _instanceID, _extPrefix
     */
    private function discoverForeignByType(string $type): array
    {
        $own = $this->foreignOwnPrefixes();
        $out = [];
        $t0 = microtime(true);
        foreach (IPS_GetModuleList() as $guid) {
            if (microtime(true) - $t0 > 3.0) {
                $this->SendDebug('discoverForeignByType', 'time budget exhausted', 0);
                break;
            }
            $mod = @IPS_GetModule($guid);
            if (!is_array($mod) || (int) ($mod['ModuleType'] ?? 0) !== 3) {
                continue;
            }
            $prefix = (string) ($mod['Prefix'] ?? '');
            $fn = $prefix . '_GetFunctions';
            if ($prefix === '' || in_array(strtoupper($prefix), $own, true) || !function_exists($fn)) {
                continue;
            }
            foreach (IPS_GetInstanceListByModuleID($guid) as $id) {
                if ((int) (IPS_GetInstance($id)['InstanceStatus'] ?? 0) === 104) {
                    continue; // vom Nutzer deaktiviert
                }
                try {
                    $data = call_user_func($fn, $id);
                } catch (\ArgumentCountError $e) {
                    continue 2; // gleichnamige Funktion mit anderer Signatur: kein Vertragspartner
                } catch (\Throwable $e) {
                    $this->SendDebug('discoverForeignByType', sprintf('%s #%d: %s', $prefix, $id, $e->getMessage()), 0);
                    continue;
                }
                if (is_string($data)) {
                    $data = json_decode($data, true);
                }
                if (!is_array($data) || $data === []) {
                    continue;
                }
                $entries = (array_keys($data) === range(0, count($data) - 1)) ? $data : [$data];
                foreach ($entries as $e) {
                    if (!is_array($e)) {
                        continue;
                    }
                    $t = (string) ($e['contractType'] ?? ($e['Type'] ?? ($e['type'] ?? ($e['function'] ?? ''))));
                    if ($t !== $type) {
                        continue;
                    }
                    if ((int) explode('.', (string) ($e['contractVersion'] ?? '0'))[0] !== 1) {
                        continue;
                    }
                    $e['source'] = 'ext:' . $prefix;
                    $e['_instanceID'] = (int) $id;
                    $e['_extPrefix'] = $prefix;
                    $out[] = $e;
                }
            }
        }
        return $out;
    }

    /**
     * Haengt Waermepumpen anderer Autoren an die Eintraege der festen Quellen (WPMonitor, HeatSchema). Eine schon bekannte Leistungsvariable
     * (powerID oder PowerID) wird nie doppelt gezeigt, ebensowenig ein als Dublette markierter Eintrag (duplicateOf).
     */
    private function mergeForeignHeatpumps(array $entries): array
    {
        $havePower = [];
        foreach ($entries as $known) {
            $kp = (int) ($known['powerID'] ?? $known['PowerID'] ?? 0);
            if ($kp > 0) {
                $havePower[$kp] = true;
            }
        }
        foreach ($this->discoverForeignByType('heatpump') as $ext) {
            $ep = (int) ($ext['powerID'] ?? $ext['PowerID'] ?? 0);
            if (isset($havePower[$ep]) || !empty($ext['duplicateOf'])) {
                continue;
            }
            if ($ep > 0) {
                $havePower[$ep] = true;
            }
            $entries[] = $ext;
        }
        return $entries;
    }
}
