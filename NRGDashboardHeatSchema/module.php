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
 * (elektrische Leistung, sofern gemessen), kein Rohrschema.
 *
 * Optionale Felder (contractVersion 1.3, mit HeishaMon abgestimmt, additiv
 * versioniert): pumpFlowID (l/min), pumpSpeedID (U/min), pumpDutyID
 * (Rohwert), threeWayValveStateID (0=Room/1=DHW), twoWayValveStateID
 * (bool), mainInletTempID/mainOutletTempID/z1WaterTempID/z2WaterTempID/
 * dhwTempID/bufferTempID/dischargeTempID (°C), compressorFreqID (Hz),
 * defrostingStateID (bool). Fehlt ein Feld (=0), wird es im Schema
 * ausgeblendet statt einer Nullanzeige.
 */
class NRGDashboardHeatSchema extends IPSModule
{
    private const HEISHA_GUID = '{1919151A-3C0F-4C09-B906-291638EC1469}';
    // WPHub-Vertrag (contractVersion 1.3, mit WPHub am 13.08.2026 bestaetigt):
    // liefert NIE die 14 Pumpen-/Ventilfelder (Cloud-API kennt sie nicht,
    // Schluessel fehlen komplett statt 0) - deshalb ueberall defensiv mit
    // ?? 0 statt fester Schluesselmenge gelesen (siehe DiscoverHeatpumps()).
    private const WPHUB_GUID = '{5BE429EA-3AAD-4A8B-85DE-5778CCA2E6BC}';
    private const METERHUB_GUID = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
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
            'Zone1ShowRadiator' => 'Heizkreis 1: Heizkörper',
            'Zone1ShowFloor'    => 'Heizkreis 1: Fußbodenheizung',
            'Zone2ShowRadiator' => 'Heizkreis 2: Heizkörper',
            'Zone2ShowFloor'    => 'Heizkreis 2: Fußbodenheizung',
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
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowStyle', 0, 'Organische Welle', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowStyle', 1, 'Gleichmäßige Punkte', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowStyle', 2, 'Fließende Striche', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowStyle', 3, 'Flüssigkeitsglanz', '', -1);
        $flowIsNew = @IPS_GetObjectIDByIdent('FlowStyle', $this->InstanceID) === false;
        $this->RegisterVariableInteger('FlowStyle', 'Wasserfluss-Darstellung', 'NRGDASHHEAT.FlowStyle', 50);
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
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowMotion', 0, 'Atmend', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowMotion', 1, 'Konstant', '', -1);
        $flowMotionIsNew = @IPS_GetObjectIDByIdent('FlowMotion', $this->InstanceID) === false;
        $this->RegisterVariableInteger('FlowMotion', 'Wasserfluss-Bewegungsart', 'NRGDASHHEAT.FlowMotion', 51);
        $this->EnableAction('FlowMotion');
        if ($flowMotionIsNew) {
            $this->SetValue('FlowMotion', 0);
        }

        if (!IPS_VariableProfileExists('NRGDASHHEAT.FlowSpeed')) {
            IPS_CreateVariableProfile('NRGDASHHEAT.FlowSpeed', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowSpeed', 0, 'Langsam', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowSpeed', 1, 'Normal', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.FlowSpeed', 2, 'Schnell', '', -1);
        $flowSpeedIsNew = @IPS_GetObjectIDByIdent('FlowSpeed', $this->InstanceID) === false;
        $this->RegisterVariableInteger('FlowSpeed', 'Wasserfluss-Geschwindigkeit', 'NRGDASHHEAT.FlowSpeed', 52);
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
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.Bauart', 0, 'Split (mit Innengerät)', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.Bauart', 1, 'Monoblock (nur Außengerät)', '', -1);
        $bauartIsNew = @IPS_GetObjectIDByIdent('Bauart', $this->InstanceID) === false;
        $this->RegisterVariableInteger('Bauart', 'Bauart', 'NRGDASHHEAT.Bauart', 60);
        $this->EnableAction('Bauart');
        if ($bauartIsNew) {
            $this->SetValue('Bauart', 0);
        }

        $componentToggles = [
            'HasBuffer'  => ['Pufferspeicher vorhanden', 61, true],
            'HasDhwTank' => ['Warmwasser-Tank vorhanden', 63, true],
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
            'BufferLiters' => ['Pufferspeicher: Volumen (l)', 62, 100],
            'DhwLiters'    => ['Warmwasser-Tank: Volumen (l)', 64, 185],
        ];
        foreach ($literToggles as $ident => $spec) {
            $isNew = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->RegisterVariableInteger($ident, $spec[0], '', $spec[1]);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, $spec[2]);
            }
        }

        // Simulation (Dietmar, 17.08.2026: "die Buttons fuer die
        // Simulation genauso [hinter den Doppelpfeil]") - dieselben
        // Betriebsmodus-Szenarien wie im lokalen Pruefstand
        // (.tools/test-scenarios.html), aber direkt in der echten
        // Kachel/aufgezogenen Ansicht schaltbar: ersetzt bei aktivierter
        // Simulation die echten Sensordaten durch feste Beispielwerte
        // (siehe buildSimulatedUnit()), ohne die Instanz neu
        // konfigurieren zu muessen.
        if (!IPS_VariableProfileExists('NRGDASHHEAT.SimulationMode')) {
            IPS_CreateVariableProfile('NRGDASHHEAT.SimulationMode', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.SimulationMode', 0, 'Aus (echte Daten)', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.SimulationMode', 1, 'Heizbetrieb', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.SimulationMode', 2, 'Kühlbetrieb', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.SimulationMode', 3, 'Warmwasserbetrieb', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.SimulationMode', 4, 'Standby', '', -1);
        IPS_SetVariableProfileAssociation('NRGDASHHEAT.SimulationMode', 5, 'Abtaubetrieb', '', -1);
        $simIsNew = @IPS_GetObjectIDByIdent('SimulationMode', $this->InstanceID) === false;
        $this->RegisterVariableInteger('SimulationMode', 'Simulation', 'NRGDASHHEAT.SimulationMode', 70);
        $this->EnableAction('SimulationMode');
        if ($simIsNew) {
            $this->SetValue('SimulationMode', 0);
        }

        $this->RegisterTimer('Refresh', 0, 'NRGDASHHEAT_Render($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);
        $this->SetTimerInterval('Refresh', 5 * 60 * 1000);
        $this->Render();
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
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
        if (in_array($Ident, ['Zone1ShowRadiator', 'Zone1ShowFloor', 'Zone2ShowRadiator', 'Zone2ShowFloor'], true)) {
            $this->SetValue($Ident, (bool) $Value);
            $this->Render();
            return;
        }
        if (in_array($Ident, ['FlowStyle', 'FlowMotion', 'FlowSpeed', 'Bauart', 'BufferLiters', 'DhwLiters', 'SimulationMode'], true)) {
            $this->SetValue($Ident, (int) $Value);
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
     * authority='billing') gemerged - Muster: NRGDashboardMonitor::
     * GridPowerID(), MeterHubs eigener Vorschlag vom 13.08.2026 (Merge beim
     * Konsumenten statt Kopplung zwischen den Erzeuger-Modulen).
     */
    private function DiscoverHeatpumps(): array
    {
        $entries = [];
        foreach ([self::HEISHA_GUID => 'HEISHA_GetFunctions', self::WPHUB_GUID => 'WPHUB_GetFunctions'] as $guid => $fn) {
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
                $list = (isset($data['Type']) || isset($data['type'])) ? [$data] : $data;
                foreach ($list as $e) {
                    if (!is_array($e) || (($e['Type'] ?? $e['type'] ?? '') !== 'heatpump')) {
                        continue;
                    }
                    $e['_instanceID'] = (int) $id;
                    $entries[] = $e;
                }
            }
        }
        return $entries;
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
            return $this->buildBasePayload([$this->buildSimulatedUnit($simMode)]);
        }

        $heatpumps = $this->DiscoverHeatpumps();
        if (count($heatpumps) === 0) {
            return [
                'ok'    => false,
                'error' => 'Keine Wärmepumpe gefunden - HeishaMon oder WPHub installieren und konfigurieren.',
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

            $hasPipeSchema = (int) ($e['pumpFlowID'] ?? 0) > 0
                || (int) ($e['pumpSpeedID'] ?? 0) > 0
                || (int) ($e['mainInletTempID'] ?? 0) > 0
                || (int) ($e['mainOutletTempID'] ?? 0) > 0;

            $units[] = [
                'id'              => (int) $e['_instanceID'],
                'label'           => (string) ($e['Caption'] ?? $e['caption'] ?? IPS_GetName((int) $e['_instanceID'])),
                'hasPipeSchema'   => $hasPipeSchema,
                'power'           => $this->num($powerID),
                'pumpFlow'        => $this->num((int) ($e['pumpFlowID'] ?? 0)),
                'pumpSpeed'       => $this->num((int) ($e['pumpSpeedID'] ?? 0)),
                'pumpDuty'        => $this->num((int) ($e['pumpDutyID'] ?? 0)),
                'threeWayValve'   => $this->num((int) ($e['threeWayValveStateID'] ?? 0)),
                'twoWayValve'     => $this->boolVal((int) ($e['twoWayValveStateID'] ?? 0)),
                'mainInletTemp'   => $this->numTemp((int) ($e['mainInletTempID'] ?? 0)),
                'mainOutletTemp'  => $this->numTemp((int) ($e['mainOutletTempID'] ?? 0)),
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
                // Sauggas-/Kaltgastemperatur (Gegenstueck zum Heissgas):
                // additives Feld, bei HeishaMon angefragt (14.08.2026) -
                // bis der Vertrag es liefert, bleibt das Feld null.
                'suctionTemp'     => $this->numTemp((int) ($e['suctionTempID'] ?? 0)),
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
            'bauart'      => ((int) $this->GetValue('Bauart') === 1) ? 'monoblock' : 'split',
            'hasBuffer'   => (bool) $this->GetValue('HasBuffer'),
            'bufferLiters' => (int) $this->GetValue('BufferLiters'),
            'hasDhwTank'  => (bool) $this->GetValue('HasDhwTank'),
            'dhwLiters'   => (int) $this->GetValue('DhwLiters'),
            'renderedAt'  => time(),
            'units'       => $units,
        ];
    }

    /**
     * Baut eine synthetische Einheit fuer die Simulation (Dietmar,
     * 17.08.2026) - dieselben Betriebsmodus-Szenarien wie im lokalen
     * Pruefstand .tools/test-scenarios.html, hier aber als PHP-Gegenstueck
     * fuer die echte Kachel.
     */
    private function buildSimulatedUnit(int $mode): array
    {
        $u = [
            'id' => $this->InstanceID, 'label' => 'Wärmepumpe (Simulation)',
            'hasPipeSchema' => true, 'power' => 1500,
            'pumpFlow' => 15.0, 'pumpSpeed' => 1450.0, 'pumpDuty' => null,
            'threeWayValve' => 0, 'twoWayValve' => true,
            'mainInletTemp' => 36.0, 'mainOutletTemp' => 42.0,
            'z1WaterTemp' => 38.5, 'z2WaterTemp' => 33.0,
            'dhwTemp' => 44.0, 'bufferTemp' => 40.0,
            'compressorFreq' => 34.0, 'dischargeTemp' => 82.0,
            'defrosting' => false, 'fanSpeed' => 400.0, 'suctionTemp' => 17.0,
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
                break;
            case 4: // Standby: kein Durchfluss, Verdichter aus
                $u['compressorFreq'] = 0.0;
                $u['pumpFlow'] = 0.0;
                $u['operatingModeNorm'] = 0;
                break;
            case 5: // Abtaubetrieb
                $u['defrosting'] = true;
                break;
            case 1: // Heizbetrieb - Default oben bereits gesetzt
            default:
                break;
        }
        return $u;
    }
}
