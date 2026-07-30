<?php

declare(strict_types=1);

/**
 * NRG-Stack Dashboard Map - raeumliche 3D-Karte des Verbunds.
 *
 * Layout (30.07.2026, nach Dietmars Vorbild "wie Topology, nur in 3D"):
 * zweistufiges Radial-Cluster-Layout wie NRGDashboardTopology, hier um den
 * Wechselrichter als physischen Mittelpunkt statt um EMS. EIN Slot je
 * Geraete-Kategorie auf dem Hauptring (PV, Batterie, Netz, Hausverbrauch,
 * Verbraucher, Wallbox, Fahrzeug); Kategorien mit mehreren Instanzen
 * (mehrere Netzzaehler, mehrere Wallboxen, ...) bekommen einen Cluster-Hub
 * auf dem Hauptring, ihre Mitglieder faechern lokal weiter aussen auf -
 * genau das Muster, das bei Topology das Ueberfuellen bei vielen
 * MeterHub-Instanzen geloest hat.
 */
class NRGDashboardMap extends IPSModule
{
    private const IHUB_GUID = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';
    private const MHUB_GUID = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
    private const CHUB_GUID = '{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}';
    private const HEISHA_GUID = '{1919151A-3C0F-4C09-B906-291638EC1469}';
    private const TESSIE_GUID = '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}';

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';

    // Verbund-Formularkonvention (EMS/SUITE.md "Einheitliche Formular-Optik",
    // Muster NRGDashboardTile/Topology): "Was ist Neu" (versionsscharf
    // dismissible, Version IN der Caption - siehe Klaerung EMS/InverterHub
    // 30.07.2026) + Doku-Panel mit dauerhafter Versionszeile + Forum-Hinweis
    // (einmalig dismissible). NEWS_VERSION bei jeder nutzersichtbaren
    // Aenderung erhoehen.
    private const NEWS_VERSION = '0.7.0';
    private const NEWS_ITEMS = [
        'Neu: Geraete werden jetzt nach Kategorie geclustert dargestellt (wie die Verbund-Gesundheit-Kachel) - deutlich uebersichtlicher bei mehreren Netzzaehlern/Wallboxen/Fahrzeugen.',
        'Neu: alle MeterHub-Funktionen erscheinen jetzt als eigener Knoten (vorher nur Netz/Hausverbrauch, andere Verbraucher fielen still raus).',
        'Neu: ruhigere 3D-Navigation mit Traegheit statt direktem 1:1-Mitziehen.',
    ];
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';
    private const GITHUB_URL = 'https://github.com/DG65/NRGDashboard';

    // Anzeigename je Geraete-Kategorie - fuer Cluster-Hub-Beschriftung
    // ("Netz, 2 Instanzen") analog MODULE_LABELS in NRGDashboardTopology.
    private const CATEGORY_LABELS = [
        'pv'       => 'PV-Strang',
        'battery'  => 'Batterie',
        'grid'     => 'Netz',
        'house'    => 'Hausverbrauch',
        'consumer' => 'Verbraucher',
        'wallbox'  => 'Wallbox',
        'vehicle'  => 'Fahrzeug',
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('InverterInstance', 0);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);

        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);

        $this->RegisterVariableString('MapHTML', 'NRG-Stack Map', '~HTMLBox');
        $this->RegisterTimer('NRGDASHMAP_Discover', 0, 'NRGDASHMAP_Discover($_IPS[\'TARGET\']);');
        $this->SetStatus(102);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('NRGDASHMAP_Discover', 10 * 60 * 1000);
        $this->Discover();
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

    private function ColorOrEmpty(int $v): string
    {
        return $v < 0 ? '' : sprintf('#%06X', $v);
    }

    private function FontStack(string $v): string
    {
        return ($v === '' || $v === self::DEF_FONT) ? '' : $v;
    }

    /**
     * InverterHub-Instanz - explizite Wahl gewinnt, sonst Automatik bei
     * genau einer Instanz (Muster: EmsInstanceID() in NRGDashboardTopology).
     * Mehr-WR-Anlagen (InverterHubVirtual) sind noch nicht gebaut (siehe
     * InverterHub/CLAUDE.md, "InverterHubVirtual - Designstand") - bis
     * dahin bleibt die Einschraenkung auf eine Instanz bestehen und wird
     * im Formular klar benannt statt stillschweigend geraten.
     */
    private function InverterInstanceID(): int
    {
        $cfg = $this->readIntProperty('InverterInstance', 0);
        if ($cfg > 0 && IPS_InstanceExists($cfg)
            && IPS_GetInstance($cfg)['ModuleInfo']['ModuleID'] === self::IHUB_GUID) {
            return $cfg;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::IHUB_GUID);
        if (!is_array($ids) || count($ids) === 0) {
            return 0;
        }
        if (count($ids) === 1) {
            return (int) $ids[0];
        }
        foreach ($ids as $id) {
            if (IPS_GetInstance((int) $id)['InstanceStatus'] === 102) {
                return (int) $id;
            }
        }
        return (int) $ids[0];
    }

    public function ResetStyle(): void
    {
        $this->UpdateFormField('ColorBackground', 'value', self::DEF_BACKGROUND);
        $this->UpdateFormField('FontFamily', 'value', self::DEF_FONT);
    }

    /**
     * Fuegt "Was ist Neu" (versionsscharf dismissible) und den Forum-Hinweis
     * (einmalig dismissible) um die statische form.json herum ein, traegt
     * die Versionsnummer ins Doku-Panel ein - exakte Struktur wie
     * NRGDashboardTopology/Tile (Muster fuer den ganzen Verbund).
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
                    ['type' => 'Label', 'caption' => '🧪 NRG-Stack Dashboard ist Beta — Rückmeldungen sind willkommen:'],
                    ['type' => 'Label', 'link' => true, 'caption' => self::GITHUB_URL],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'NRGDASHMAP_DismissReviewHint($id);'],
                ],
            ];
        }

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

    private function newsBanner(): ?array
    {
        if (@$this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 Neu in diesem Modul — bitte kurz ansehen und ggf. die Einstellungen prüfen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'NRGDASHMAP_AckNews($id);'];
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
     * Discovery: sammelt ALLE Geraete mit Kategorie (fuer die Client-
     * seitige Cluster-Gruppierung) statt wie zuvor nur Netz/Hausverbrauch
     * aus MeterHub zu zeigen. Jede MeterHub-Zuordnung wird zu einem
     * eigenen Knoten - auch Waermepumpe, Herd, Carport-Verbraucher etc.,
     * die vorher still herausfielen. Eindeutige IDs je Zuordnung
     * (powerID/instanceID+slot) statt fixer 'grid'/'house'-Strings, damit
     * mehrere Netzzaehler (z.B. Inexogy + PAC2200) nicht kollidieren.
     */
    public function Discover(): array
    {
        $nodes = [];
        $edges = [];

        $ihub = $this->InverterInstanceID();
        $ihubData = ($ihub > 0 && function_exists('IHUB_GetFunctions')) ? @IHUB_GetFunctions($ihub) : null;
        if (is_string($ihubData)) {
            $ihubData = json_decode($ihubData, true);
        }

        $centerId = 'inverter';
        if ($ihub > 0 && is_array($ihubData)) {
            $nodes[] = ['id' => $centerId, 'label' => IPS_GetName($ihub), 'category' => 'inverter', 'center' => true];

            for ($i = 1; $i <= 4; $i++) {
                $vid = $this->FindVarByIdent($ihub, 'mppt' . $i . '_power');
                if ($vid > 0) {
                    $nodes[] = ['id' => 'pv_' . $i, 'label' => 'PV-Strang ' . $i, 'category' => 'pv'];
                    $edges[] = ['source' => $centerId, 'target' => 'pv_' . $i];
                }
            }

            if ((int) ($ihubData['batPowerID'] ?? 0) > 0) {
                $nodes[] = ['id' => 'battery', 'label' => 'Batterie', 'category' => 'battery'];
                $edges[] = ['source' => $centerId, 'target' => 'battery'];
            }
        } else {
            // Kein/kein eindeutiger Wechselrichter - Verbund bleibt als
            // Mittelpunkt bestehen, nur ohne PV/Batterie-Zweig (Muster:
            // Topology zeigt bei fehlender EMS-Instanz eine Fehlermeldung
            // statt stillschweigend eine leere Karte).
            $nodes[] = ['id' => $centerId, 'label' => 'NRG-Stack Verbund', 'category' => 'inverter', 'center' => true];
        }

        // MeterHub: JEDE Zuordnung wird ein eigener Knoten. 'grid'/'house'
        // haengen direkt am Zentrum bzw. am Netz, alle anderen Funktionen
        // (Waermepumpe, Herd, Carport-Verbraucher, ...) werden als
        // Kategorie 'consumer' unter Hausverbrauch geclustert.
        $gridId = null;
        $houseId = null;
        foreach ($this->MeterHubAssignments() as $a) {
            $fn = (string) ($a['function'] ?? '');
            $label = (string) ($a['label'] ?? $fn);
            $key = 'meter_' . (int) ($a['powerID'] ?? 0) . '_' . preg_replace('/[^a-z0-9]/i', '', $fn);
            if ($fn === 'grid') {
                $nodes[] = ['id' => $key, 'label' => $label, 'category' => 'grid'];
                $edges[] = ['source' => $centerId, 'target' => $key];
                $gridId = $gridId ?? $key;
            } elseif ($fn === 'house') {
                $nodes[] = ['id' => $key, 'label' => $label, 'category' => 'house'];
                $edges[] = ['source' => $gridId ?? $centerId, 'target' => $key];
                $houseId = $key;
            } elseif ($fn !== '' && $fn !== 'pv' && $fn !== 'battery') {
                $nodes[] = ['id' => $key, 'label' => $label, 'category' => 'consumer'];
                $edges[] = ['source' => $houseId ?? ($gridId ?? $centerId), 'target' => $key];
            }
        }

        // HeishaMon (Waermepumpe) - eigene Instanz ohne MeterHub-Vertrag,
        // gleiche Kategorie 'consumer' wie die MeterHub-Verbraucher oben.
        foreach (@IPS_GetInstanceListByModuleID(self::HEISHA_GUID) as $id) {
            $id = (int) $id;
            $nodes[] = ['id' => 'heisha_' . $id, 'label' => IPS_GetName($id), 'category' => 'consumer'];
            $edges[] = ['source' => $houseId ?? ($gridId ?? $centerId), 'target' => 'heisha_' . $id];
        }

        // Wallboxen (ChargerHub)
        $wbIds = [];
        foreach (@IPS_GetInstanceListByModuleID(self::CHUB_GUID) as $id) {
            $id = (int) $id;
            $entries = @CHUB_GetFunctions($id);
            if (is_string($entries)) {
                $entries = json_decode($entries, true);
            }
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $e) {
                $key = 'wb_' . $id;
                $nodes[] = ['id' => $key, 'label' => (string) ($e['label'] ?? 'Wallbox'), 'category' => 'wallbox'];
                $edges[] = ['source' => $houseId ?? ($gridId ?? $centerId), 'target' => $key];
                $wbIds[] = $key;
            }
        }

        // Fahrzeuge (Tessie) - haengen physisch an der Wallbox, mangels
        // Zeitkorrelations-Zuordnung (wie in NRGDashboardTile) hier am
        // ersten gefundenen Wallbox-Cluster, sonst am Hausverbrauch.
        foreach (@IPS_GetInstanceListByModuleID(self::TESSIE_GUID) as $id) {
            $id = (int) $id;
            $state = @TESSIE_GetVehicleState($id);
            if (is_string($state)) {
                $state = json_decode($state, true);
            }
            if (!is_array($state)) {
                continue;
            }
            $key = 'vehicle_' . $id;
            $nodes[] = ['id' => $key, 'label' => (string) ($state['name'] ?? 'Fahrzeug'), 'category' => 'vehicle'];
            $edges[] = ['source' => $wbIds[0] ?? ($houseId ?? $centerId), 'target' => $key];
        }

        $result = ['nodes' => $nodes, 'edges' => $edges];
        $html = $this->GenerateHTML($result);
        SetValue($this->GetIDForIdent('MapHTML'), $html);
        $this->updateDiscoveryResultLabel($result);
        return $result;
    }

    /**
     * Ergebnis-Label im Formular (Muster NRGDashboardTile/Topology): zeigt
     * die gefundenen Geraete gruppiert nach Kategorie, nicht nur die
     * Gesamtzahl - No-Op, wenn gerade kein Formular offen ist.
     */
    private function updateDiscoveryResultLabel(array $result): void
    {
        $nodes = $result['nodes'] ?? [];
        $byCat = [];
        foreach ($nodes as $n) {
            $cat = $n['category'] ?? '?';
            if ($cat === 'inverter') {
                continue;
            }
            $byCat[$cat] = ($byCat[$cat] ?? 0) + 1;
        }
        if (empty($byCat)) {
            $this->UpdateFormField('DiscoveryResult', 'caption', '⚠️ Keine Geräte gefunden - sind Partnermodule installiert und konfiguriert?');
            return;
        }
        $parts = [];
        foreach ($byCat as $cat => $count) {
            $parts[] = $count . 'x ' . (self::CATEGORY_LABELS[$cat] ?? $cat);
        }
        $this->UpdateFormField(
            'DiscoveryResult',
            'caption',
            sprintf('✅ %d Geräte gefunden: %s (zuletzt %s Uhr).', count($nodes) - 1, implode(', ', $parts), date('H:i:s'))
        );
    }

    private function GenerateHTML(array $data): string
    {
        $three = @file_get_contents(__DIR__ . '/three.min.js') ?: '';
        $nodes = json_encode($data['nodes']);
        $edges = json_encode($data['edges']);
        $bg = $this->ColorOrEmpty($this->readIntProperty('ColorBackground', self::DEF_BACKGROUND));
        $font = $this->FontStack($this->readStringProperty('FontFamily', self::DEF_FONT));
        $bgCss = $bg !== '' ? $bg : '#1a1a1a';
        $fontCss = $font !== '' ? $font : 'system-ui';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  html, body { margin: 0; height: 100%; background: {$bgCss}; overflow: hidden; font-family: {$fontCss}, system-ui; }
  #wrap { width: 100%; height: 100%; cursor: grab; }
  #wrap.dragging { cursor: grabbing; }
  canvas { display: block; }
  #labels { position: absolute; inset: 0; pointer-events: none; }

  /* "Tahoe"-artige Frosted-Glass-Pills statt harter Text-Schatten -
     durchscheinender, weich beleuchteter Chip statt Klartext auf
     transparentem Hintergrund. */
  .label {
    position: absolute; transform: translate(-50%, -50%);
    font-size: 12px; font-weight: 600; color: #eef2f6; white-space: nowrap;
    padding: 4px 11px; border-radius: 999px;
    background: rgba(38, 42, 50, 0.55);
    backdrop-filter: blur(14px) saturate(160%);
    -webkit-backdrop-filter: blur(14px) saturate(160%);
    border: 1px solid rgba(255,255,255,0.14);
    box-shadow: 0 4px 16px rgba(0,0,0,0.28), inset 0 1px 0 rgba(255,255,255,0.10);
  }
  .label.small { font-size: 10px; font-weight: 500; padding: 2px 8px; opacity: .92; }
  .label.hub-count { font-size: 9px; padding: 1px 7px; opacity: .8; background: rgba(38,42,50,0.4); box-shadow: none; }

  #info, #tooltip {
    position: absolute; color: #dfe6ee; font-size: 11.5px; font-weight: 500;
    background: rgba(38, 42, 50, 0.55);
    backdrop-filter: blur(14px) saturate(160%);
    -webkit-backdrop-filter: blur(14px) saturate(160%);
    border: 1px solid rgba(255,255,255,0.14);
    box-shadow: 0 4px 16px rgba(0,0,0,0.28), inset 0 1px 0 rgba(255,255,255,0.10);
    padding: 5px 13px; border-radius: 999px;
  }
  #info { bottom: 10px; left: 10px; }
  #tooltip {
    display: none; z-index: 5; pointer-events: none; line-height: 1.45;
    border-radius: 16px; padding: 8px 14px;
  }
  #tooltip b { font-weight: 700; }
  #tooltip .tt-sub { opacity: .75; font-size: 10.5px; }
</style>
</head>
<body>
<div id="wrap"></div>
<div id="labels"></div>
<div id="info"></div>
<div id="tooltip"></div>

<script>
$three

// Kategorie-Farben - eine Farbe je physischer Geraeteart, unabhaengig
// davon, ob Einzelknoten oder Cluster-Hub.
var CAT_COLOR = {
  inverter: 0xE8823C, pv: 0xF2C230, battery: 0x5FCB6B, grid: 0x4AA3E0,
  house: 0xE8A23C, consumer: 0x90A4AE, wallbox: 0x9575CD, vehicle: 0x4DD0E1
};
var CAT_LABEL = {
  pv: 'PV-Strang', battery: 'Batterie', grid: 'Netz', house: 'Hausverbrauch',
  consumer: 'Verbraucher', wallbox: 'Wallbox', vehicle: 'Fahrzeug'
};

var nodesData = $nodes;
var edgesData = $edges;

var R_TYPE = 42;    // Hauptring: Einzelinstanz oder Cluster-Hub
var R_MEMBER = 68;  // Faecher-Ring: Mitglieder innerhalb eines Clusters
var NODE_SIZE = 8;
var MEMBER_SIZE = 5.5;

var scene, camera, renderer, group;
var nodeMeshes = {};

// Traegheits-Navigation statt direktem 1:1-Mitziehen (Dietmars Kritik an
// der bisherigen Steuerung): beim Ziehen wird eine Geschwindigkeit
// aufgebaut, die nach Loslassen sanft ausklingt (Muster: OrbitControls-
// Damping). Zoom ebenfalls sanft interpoliert statt sprunghaft.
var rotY = 0.6, tiltX = -0.45, dist = 190, targetDist = 190;
var rotVelY = 0, tiltVel = 0;
var DAMPING = 0.90;
var dragging = false, lastX = 0, lastY = 0;

function initScene() {
  var wrap = document.getElementById('wrap');
  scene = new THREE.Scene();
  camera = new THREE.PerspectiveCamera(45, wrap.clientWidth / wrap.clientHeight, 0.1, 2000);
  renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.setSize(wrap.clientWidth, wrap.clientHeight);
  wrap.appendChild(renderer.domElement);

  scene.add(new THREE.HemisphereLight(0xffffff, 0x30363d, 1.15));
  var dir = new THREE.DirectionalLight(0xffffff, 0.55);
  dir.position.set(60, 120, 90);
  scene.add(dir);

  group = new THREE.Group();
  scene.add(group);

  wrap.addEventListener('mousedown', function (e) {
    dragging = true; lastX = e.clientX; lastY = e.clientY; wrap.classList.add('dragging');
  });
  window.addEventListener('mouseup', function () { dragging = false; wrap.classList.remove('dragging'); });
  window.addEventListener('mousemove', function (e) {
    if (!dragging) return;
    var dx = e.clientX - lastX, dy = e.clientY - lastY;
    rotVelY = dx * 0.0012;
    tiltVel = dy * 0.0009;
    lastX = e.clientX; lastY = e.clientY;
  });
  wrap.addEventListener('wheel', function (e) {
    targetDist = Math.max(60, Math.min(500, targetDist + e.deltaY * 0.2));
    e.preventDefault();
  }, { passive: false });

  window.addEventListener('resize', function () {
    camera.aspect = wrap.clientWidth / wrap.clientHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(wrap.clientWidth, wrap.clientHeight);
  });

  initTooltip(wrap);
  renderMap();
  animate();
}

// Hover-Tooltip (Apple-artige "Liquid Glass"-Optik, auf Dietmars Wunsch
// ausprobiert): Raycast auf die Knoten-Meshes, zeigt Bezeichnung + Kategorie
// + technische ID/Instanzzahl. Nur aktiv, wenn NICHT gerade gedreht wird,
// damit Drehen und Hover sich nicht in die Quere kommen.
var raycaster = new THREE.Raycaster();
var mouseNDC = new THREE.Vector2(-10, -10);
var tooltipEl;

function initTooltip(wrap) {
  tooltipEl = document.getElementById('tooltip');
  wrap.addEventListener('mousemove', function (e) {
    if (dragging) { tooltipEl.style.display = 'none'; return; }
    var rect = wrap.getBoundingClientRect();
    mouseNDC.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
    mouseNDC.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;
    raycaster.setFromCamera(mouseNDC, camera);
    var hits = raycaster.intersectObjects(Object.values(nodeMeshes));
    if (hits.length && hits[0].object.userData.label) {
      var meta = hits[0].object.userData;
      tooltipEl.innerHTML = '<b>' + meta.label + '</b><br><span class="tt-sub">' + meta.sub + '</span>';
      tooltipEl.style.left = (e.clientX + 16) + 'px';
      tooltipEl.style.top = (e.clientY + 16) + 'px';
      tooltipEl.style.display = 'block';
    } else {
      tooltipEl.style.display = 'none';
    }
  });
  wrap.addEventListener('mouseleave', function () { tooltipEl.style.display = 'none'; });
}

function wrapLabel(el, text, maxLen) {
  el.textContent = text.length > maxLen ? (text.slice(0, maxLen - 1) + '…') : text;
}

/**
 * Weiche, "glasige" Kugel statt harter Box (Dietmars Wunsch, Apples
 * aktuelle "Liquid Glass"-Formensprache als Vorbild): niedrige Rauheit +
 * Clearcoat fuer einen sanften Glanzlicht-Reflex, dazu ein zartes
 * Eigenleuchten in der Kategorie-Farbe statt reiner Objektfarbe.
 * Cluster-Hubs bekommen zusaetzlich eine groessere, sehr transparente
 * Halo-Kugel dahinter - optisch von einzelnen Instanzen unterscheidbar,
 * ohne die Form selbst aendern zu muessen.
 */
function makeNodeGroup(color, size, isHub) {
  var g = new THREE.Group();
  var geo = new THREE.SphereGeometry(size, 28, 20);
  var mat = new THREE.MeshPhysicalMaterial({
    color: color, roughness: 0.28, metalness: 0.05,
    clearcoat: 1, clearcoatRoughness: 0.18,
    emissive: color, emissiveIntensity: 0.12
  });
  var mesh = new THREE.Mesh(geo, mat);
  g.add(mesh);
  if (isHub) {
    var haloGeo = new THREE.SphereGeometry(size * 1.55, 20, 14);
    var haloMat = new THREE.MeshBasicMaterial({ color: color, transparent: true, opacity: 0.12, depthWrite: false });
    g.add(new THREE.Mesh(haloGeo, haloMat));
  }
  return { group: g, mesh: mesh };
}

function addLabel(id, text, small) {
  var el = document.createElement('div');
  el.className = small ? 'label small' : 'label';
  wrapLabel(el, text, small ? 14 : 16);
  el.id = 'label_' + id;
  document.getElementById('labels').appendChild(el);
  return el;
}

/**
 * Zweistufiges Radial-Cluster-Layout wie NRGDashboardTopology, hier in der
 * XZ-Ebene (Y=0) - Zentrum ist der Wechselrichter statt EMS. EIN Slot je
 * Geraete-Kategorie auf dem Hauptring; Kategorien mit mehreren Mitgliedern
 * (mehrere Netzzaehler/Wallboxen/Fahrzeuge) bekommen einen Cluster-Hub,
 * dessen Mitglieder lokal weiter aussen in ihrem eigenen Sektor faechern -
 * verdraengt keine anderen Kategorien, egal wie viele MeterHub-Zaehler
 * dazukommen.
 */
/**
 * Dezente Sektor-Einfaerbung am Boden (Dietmars eigener Vorschlag,
 * aufgegriffen): eine flache, sehr transparente Tortenstueck-Flaeche je
 * Kategorie-Slot, damit die Gruppierung auch ohne Verbindungslinien /
 * beim Drehen sofort erkennbar bleibt.
 */
function addSectorTint(angle, halfWidth, color, outerR) {
  var shape = new THREE.Shape();
  var innerR = 10;
  shape.moveTo(innerR * Math.cos(angle - halfWidth), innerR * Math.sin(angle - halfWidth));
  var steps = 12;
  for (var s = 0; s <= steps; s++) {
    var a = angle - halfWidth + (2 * halfWidth) * (s / steps);
    shape.lineTo(outerR * Math.cos(a), outerR * Math.sin(a));
  }
  for (var s2 = steps; s2 >= 0; s2--) {
    var a2 = angle - halfWidth + (2 * halfWidth) * (s2 / steps);
    shape.lineTo(innerR * Math.cos(a2), innerR * Math.sin(a2));
  }
  var geo = new THREE.ShapeGeometry(shape);
  var mat = new THREE.MeshBasicMaterial({ color: color, transparent: true, opacity: 0.07, side: THREE.DoubleSide, depthWrite: false });
  var mesh = new THREE.Mesh(geo, mat);
  mesh.rotation.x = -Math.PI / 2;
  mesh.position.y = -1.2;
  group.add(mesh);
}

function renderMap() {
  while (group.children.length) group.remove(group.children[0]);
  nodeMeshes = {};
  document.getElementById('labels').innerHTML = '';

  var info = document.getElementById('info');
  if (nodesData.length === 0) {
    info.textContent = '❌ Keine Geräte gefunden - InverterHub/MeterHub/ChargerHub/Tessie installieren oder auf den nächsten Suchlauf warten.';
    return;
  }
  info.textContent = 'Geräte: ' + (nodesData.length - 1);

  var positions = {};
  var center = nodesData.find(function (n) { return n.center; }) || nodesData[0];
  positions[center.id] = { x: 0, y: 0, z: 0 };

  var centerNG = makeNodeGroup(CAT_COLOR.inverter, 12, false);
  group.add(centerNG.group);
  centerNG.mesh.userData = { label: center.label, sub: 'Zentrum · ' + center.id };
  centerNG.mesh.userData.baseY = 0; centerNG.mesh.userData.phase = Math.random() * Math.PI * 2;
  nodeMeshes[center.id] = centerNG.mesh;
  addLabel(center.id, center.label, false);

  // Restliche Knoten nach Kategorie gruppieren (Cluster-Kandidaten)
  var groups = []; var byCat = {};
  nodesData.forEach(function (n) {
    if (n.id === center.id) return;
    if (!byCat[n.category]) { byCat[n.category] = { category: n.category, members: [] }; groups.push(byCat[n.category]); }
    byCat[n.category].members.push(n);
  });

  var g = groups.length;
  groups.forEach(function (grp, i) {
    var angle = (g > 0) ? (2 * Math.PI * i / g) - Math.PI / 2 : 0;
    var hx = R_TYPE * Math.cos(angle);
    var hz = R_TYPE * Math.sin(angle);
    var isCluster = grp.members.length > 1;
    var color = CAT_COLOR[grp.category] || 0x888888;

    addSectorTint(angle, (2 * Math.PI / g) * 0.42, color, isCluster ? R_MEMBER + 14 : R_TYPE + 12);

    positions[isCluster ? ('__hub_' + grp.category) : grp.members[0].id] = { x: hx, y: 0, z: hz };

    var hubNG = makeNodeGroup(color, NODE_SIZE, isCluster);
    hubNG.group.position.set(hx, 0, hz);
    group.add(hubNG.group);

    var hubId = isCluster ? ('__hub_' + grp.category) : grp.members[0].id;
    var hubLabelText = isCluster ? (CAT_LABEL[grp.category] || grp.category) : grp.members[0].label;
    hubNG.mesh.userData = {
      label: hubLabelText,
      sub: isCluster ? (grp.members.length + ' Instanzen · ' + (CAT_LABEL[grp.category] || grp.category)) : ('Einzelinstanz · ' + grp.members[0].id)
    };
    hubNG.mesh.userData.baseY = 0; hubNG.mesh.userData.phase = Math.random() * Math.PI * 2;
    nodeMeshes[hubId] = hubNG.mesh;
    addLabel(hubId, hubLabelText, false);
    if (isCluster) {
      var countEl = document.createElement('div');
      countEl.className = 'label small hub-count';
      countEl.textContent = grp.members.length + ' Instanzen';
      countEl.id = 'label_' + hubId + '_count';
      document.getElementById('labels').appendChild(countEl);
    }

    if (isCluster) {
      var sectorWidth = (2 * Math.PI / g) * 0.7;
      var n = grp.members.length;
      grp.members.forEach(function (member, j) {
        var mAngle = (n > 1) ? (angle - sectorWidth / 2 + sectorWidth * (j / (n - 1))) : angle;
        var mx = R_MEMBER * Math.cos(mAngle);
        var mz = R_MEMBER * Math.sin(mAngle);
        positions[member.id] = { x: mx, y: 0, z: mz };

        var mNG = makeNodeGroup(color, MEMBER_SIZE, false);
        mNG.group.position.set(mx, 0, mz);
        group.add(mNG.group);
        mNG.mesh.userData = { label: member.label, sub: (CAT_LABEL[grp.category] || grp.category) + ' · ' + member.id };
        mNG.mesh.userData.baseY = 0; mNG.mesh.userData.phase = Math.random() * Math.PI * 2;
        nodeMeshes[member.id] = mNG.mesh;
        addLabel(member.id, member.label, true);
      });
    }
  });

  // Kanten: EMS/Zentrum->Hub, Hub->Mitglied, sowie alle uebrigen Kanten
  // aus edgesData, soweit beide Enden eine Position haben (Kanten zu
  // geclusterten IDs werden auf den jeweiligen Hub umgebogen).
  function resolveId(id) {
    if (positions[id]) return id;
    var hub = '__hub_' + (nodesData.find(function (n) { return n.id === id; }) || {}).category;
    return positions[hub] ? hub : null;
  }
  edgesData.forEach(function (e) {
    var srcId = resolveId(e.source), tgtId = resolveId(e.target);
    if (!srcId || !tgtId || srcId === tgtId) return;
    var a = positions[srcId], b = positions[tgtId];
    var geo = new THREE.BufferGeometry().setFromPoints([
      new THREE.Vector3(a.x, a.y, a.z), new THREE.Vector3(b.x, b.y, b.z)
    ]);
    var line = new THREE.Line(geo, new THREE.LineBasicMaterial({ color: 0x666666, transparent: true, opacity: 0.6 }));
    group.add(line);
  });
}

var _worldPos = new THREE.Vector3();

function animate() {
  requestAnimationFrame(animate);

  if (!dragging) {
    rotVelY *= DAMPING;
    tiltVel *= DAMPING;
  }
  rotY += rotVelY;
  tiltX = Math.max(-1.3, Math.min(0.15, tiltX + tiltVel));
  dist += (targetDist - dist) * 0.12;

  var cx = dist * Math.sin(rotY) * Math.cos(tiltX);
  var cy = dist * Math.sin(-tiltX) + 50;
  var cz = dist * Math.cos(rotY) * Math.cos(tiltX);
  camera.position.set(cx, cy, cz);
  camera.lookAt(0, 0, 0);

  // Sehr sanftes Schweben je Knoten (eigene Phase je Instanz) - kleine
  // "lebendige" Bewegung im Stil von Apples aktueller Bewegungssprache,
  // Amplitude bewusst klein gehalten (~1 Einheit) um nicht abzulenken.
  var t = performance.now() * 0.001;
  Object.keys(nodeMeshes).forEach(function (id) {
    var mesh = nodeMeshes[id];
    var ud = mesh.userData;
    if (ud && ud.phase !== undefined) {
      mesh.position.y = Math.sin(t * 0.6 + ud.phase) * 1.1;
    }
  });

  renderer.render(scene, camera);

  var wrap = document.getElementById('wrap');
  var w = wrap.clientWidth, h = wrap.clientHeight;
  Object.keys(nodeMeshes).forEach(function (id) {
    var mesh = nodeMeshes[id];
    var label = document.getElementById('label_' + id);
    if (!label) return;
    mesh.getWorldPosition(_worldPos);
    var v = _worldPos.clone().project(camera);
    var x = (v.x * 0.5 + 0.5) * w;
    var y = (-v.y * 0.5 + 0.5) * h;
    label.style.left = x + 'px';
    label.style.top = (y + 14) + 'px';
    label.style.display = v.z > 1 ? 'none' : '';
    var countLabel = document.getElementById('label_' + id + '_count');
    if (countLabel) {
      countLabel.style.left = x + 'px';
      countLabel.style.top = (y + 27) + 'px';
      countLabel.style.display = v.z > 1 ? 'none' : '';
    }
  });
}

if (typeof THREE !== 'undefined') {
  initScene();
} else {
  document.getElementById('info').textContent = '❌ Three.js nicht geladen';
}
</script>
</body>
</html>
HTML;
    }

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

    private function MeterHubAssignments(): array
    {
        $ids = @IPS_GetInstanceListByModuleID(self::MHUB_GUID);
        if (!is_array($ids) || !function_exists('MHUB_GetFunctions')) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            $f = @MHUB_GetFunctions((int) $id);
            if (is_string($f)) {
                $f = json_decode($f, true);
            }
            if (!is_array($f) || !isset($f['assignments']) || !is_array($f['assignments'])) {
                continue;
            }
            $out = array_merge($out, $f['assignments']);
        }
        return $out;
    }
}
?>
