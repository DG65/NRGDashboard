<?php

declare(strict_types=1);

class NRGDashboardMap extends IPSModule
{
    private const IHUB_GUID = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';
    private const MHUB_GUID = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
    private const CHUB_GUID = '{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}';
    private const HEISHA_GUID = '{1919151A-3C0F-4C09-B906-291638EC1469}';
    private const TESSIE_GUID = '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}';

    public function Create()
    {
        parent::Create();
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

    public function Discover(): array
    {
        $nodes = [];
        $edges = [];

        $ihub = $this->singleInstance(self::IHUB_GUID);
        $ihubData = ($ihub > 0 && function_exists('IHUB_GetFunctions')) ? @IHUB_GetFunctions($ihub) : null;

        if ($ihub > 0 && is_array($ihubData)) {
            // Layer 0: PV-Strings
            for ($i = 1; $i <= 4; $i++) {
                $vid = $this->FindVarByIdent($ihub, 'mppt' . $i . '_power');
                if ($vid > 0) {
                    $nodes[] = ['id' => 'pv_' . $i, 'label' => 'PV-Strang ' . $i, 'category' => 'pv'];
                    $edges[] = ['source' => 'pv_' . $i, 'target' => 'inverter'];
                }
            }
            $nodes[] = ['id' => 'inverter', 'label' => IPS_GetName($ihub), 'category' => 'inverter'];

            // Layer 2: Battery
            if ((int)($ihubData['batPowerID'] ?? 0) > 0) {
                $nodes[] = ['id' => 'battery', 'label' => 'Batterie', 'category' => 'battery'];
                $edges[] = ['source' => 'inverter', 'target' => 'battery'];
            }
        }

        // Layer 3: Grid/House/Consumers
        foreach ($this->MeterHubAssignments() as $a) {
            $fn = $a['function'] ?? '';
            $label = (string)($a['label'] ?? $fn);
            if ($fn === 'grid') {
                $nodes[] = ['id' => 'grid', 'label' => $label, 'category' => 'grid'];
                $edges[] = ['source' => 'inverter', 'target' => 'grid'];
            } elseif ($fn === 'house') {
                $nodes[] = ['id' => 'house', 'label' => $label, 'category' => 'house'];
                $edges[] = ['source' => 'grid', 'target' => 'house'];
            }
        }

        // HeishaMon
        foreach (@IPS_GetInstanceListByModuleID(self::HEISHA_GUID) as $id) {
            $id = (int)$id;
            $nodes[] = ['id' => 'heisha_' . $id, 'label' => IPS_GetName($id), 'category' => 'consumer'];
            $edges[] = ['source' => 'house', 'target' => 'heisha_' . $id];
        }

        // Layer 4: Wallboxes
        foreach (@IPS_GetInstanceListByModuleID(self::CHUB_GUID) as $id) {
            $id = (int)$id;
            $entries = @CHUB_GetFunctions($id);
            if (is_string($entries)) $entries = json_decode($entries, true);
            if (!is_array($entries)) continue;
            foreach ($entries as $e) {
                $key = 'wb_' . $id;
                $nodes[] = ['id' => $key, 'label' => (string)($e['label'] ?? 'Wallbox'), 'category' => 'wallbox'];
                $edges[] = ['source' => 'house', 'target' => $key];
            }
        }

        // Layer 5: Vehicles
        foreach (@IPS_GetInstanceListByModuleID(self::TESSIE_GUID) as $id) {
            $id = (int)$id;
            $state = @TESSIE_GetVehicleState($id);
            if (is_string($state)) $state = json_decode($state, true);
            if (!is_array($state)) continue;
            $nodes[] = ['id' => 'vehicle_' . $id, 'label' => (string)($state['name'] ?? 'Fahrzeug'), 'category' => 'vehicle'];
        }

        $result = ['nodes' => $nodes, 'edges' => $edges];
        $html = $this->GenerateHTML($result);
        SetValue($this->GetIDForIdent('MapHTML'), $html);
        return $result;
    }

    private function GenerateHTML(array $data): string
    {
        $three = @file_get_contents(__DIR__ . '/three.min.js') ?: '';
        $nodes = json_encode($data['nodes']);
        $edges = json_encode($data['edges']);
        $nodeCount = count($data['nodes']);
        $edgeCount = count($data['edges']);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  html, body { margin: 0; height: 100%; background: #1a1a1a; overflow: hidden; font-family: system-ui; }
  #wrap { width: 100%; height: 100%; }
  canvas { display: block; }
  #labels { position: absolute; inset: 0; pointer-events: none; }
  .label { position: absolute; font-size: 12px; color: #ccc; text-shadow: 0 1px 2px rgba(0,0,0,.8); transform: translate(-50%, -50%); white-space: nowrap; }
  #info { position: absolute; bottom: 10px; left: 10px; color: #888; font-size: 11px; }
</style>
</head>
<body>
<div id="wrap"></div>
<div id="labels"></div>
<div id="info">Nodes: {$nodeCount} | Edges: {$edgeCount}</div>

<script>
$three

var CAT_COLOR = {
  pv: 0xF2C230, inverter: 0xE8823C, battery: 0x5FCB6B, grid: 0x4AA3E0,
  house: 0xE8A23C, consumer: 0x90A4AE, wallbox: 0x9575CD, vehicle: 0x4DD0E1
};

var nodes = $nodes;
var edges = $edges;

var scene, camera, renderer, group;
var nodeMeshes = {};
var rotY = 0.5, tiltX = -0.35, dist = 120;

function initScene() {
  var wrap = document.getElementById('wrap');
  scene = new THREE.Scene();
  camera = new THREE.PerspectiveCamera(45, wrap.clientWidth / wrap.clientHeight, 0.1, 2000);
  renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
  renderer.setSize(wrap.clientWidth, wrap.clientHeight);
  wrap.appendChild(renderer.domElement);

  var hemi = new THREE.HemisphereLight(0xffffff, 0x30363d, 1.1);
  scene.add(hemi);
  var dir = new THREE.DirectionalLight(0xffffff, 0.6);
  dir.position.set(40, 80, 60);
  scene.add(dir);

  group = new THREE.Group();
  scene.add(group);

  // Maus-Steuerung
  wrap.addEventListener('mousedown', function(e) {
    var lastX = e.clientX, lastY = e.clientY;
    function move(e) {
      rotY += (e.clientX - lastX) * 0.008;
      tiltX = Math.max(-1.2, Math.min(0.1, tiltX + (e.clientY - lastY) * 0.006));
      lastX = e.clientX; lastY = e.clientY;
    }
    window.addEventListener('mousemove', move);
    window.addEventListener('mouseup', function() { window.removeEventListener('mousemove', move); });
  });

  wrap.addEventListener('wheel', function(e) {
    dist = Math.max(40, Math.min(400, dist + e.deltaY * 0.15));
    e.preventDefault();
  }, { passive: false });

  window.addEventListener('resize', function() {
    camera.aspect = wrap.clientWidth / wrap.clientHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(wrap.clientWidth, wrap.clientHeight);
  });

  renderMap();
  animate();
}

function renderMap() {
  while (group.children.length) group.remove(group.children[0]);
  nodeMeshes = {};
  document.getElementById('labels').innerHTML = '';

  if (nodes.length === 0) {
    document.getElementById('info').textContent = '❌ Keine Geräte gefunden';
    return;
  }

  var positions = {};
  var layers = {};
  nodes.forEach(function(n) {
    if (!layers[n.category]) layers[n.category] = [];
    layers[n.category].push(n);
  });

  var layerIdx = 0;
  var layerHeight = 30;
  Object.keys(layers).forEach(function(cat) {
    var layer = layers[cat];
    var nodeSpacing = 25;
    layer.forEach(function(n, i) {
      var x = (i - (layer.length - 1) / 2) * nodeSpacing;
      var y = layerIdx * layerHeight;
      positions[n.id] = {x: x, y: y};
    });
    layerIdx++;
  });

  nodes.forEach(function(n) {
    var color = CAT_COLOR[n.category] || 0x888888;
    var geo = new THREE.BoxGeometry(8, 8, 8);
    var mat = new THREE.MeshStandardMaterial({ color: color, roughness: 0.5, metalness: 0.1 });
    var mesh = new THREE.Mesh(geo, mat);
    var p = positions[n.id];
    mesh.position.set(p.x, p.y, 0);
    group.add(mesh);
    nodeMeshes[n.id] = mesh;

    var label = document.createElement('div');
    label.className = 'label';
    label.textContent = n.label;
    label.id = 'label_' + n.id;
    document.getElementById('labels').appendChild(label);
  });

  edges.forEach(function(e) {
    var a = positions[e.source];
    var b = positions[e.target];
    if (!a || !b) return;
    var pts = [new THREE.Vector3(a.x, a.y, 0), new THREE.Vector3(b.x, b.y, 0)];
    var geo = new THREE.BufferGeometry().setFromPoints(pts);
    var mat = new THREE.LineBasicMaterial({ color: 0x666666 });
    var line = new THREE.Line(geo, mat);
    group.add(line);
  });
}

function animate() {
  requestAnimationFrame(animate);
  var cx = dist * Math.sin(rotY) * Math.cos(tiltX);
  var cy = dist * Math.sin(-tiltX) + 30;
  var cz = dist * Math.cos(rotY) * Math.cos(tiltX);
  camera.position.set(cx, cy, cz);
  camera.lookAt(0, 20, 0);
  renderer.render(scene, camera);

  var wrap = document.getElementById('wrap');
  var w = wrap.clientWidth, h = wrap.clientHeight;
  Object.keys(nodeMeshes).forEach(function(id) {
    var mesh = nodeMeshes[id];
    var label = document.getElementById('label_' + id);
    if (!label) return;
    var v = mesh.position.clone().project(camera);
    var x = (v.x * 0.5 + 0.5) * w;
    var y = (-v.y * 0.5 + 0.5) * h;
    label.style.left = x + 'px';
    label.style.top = y + 'px';
    label.style.display = v.z > 1 ? 'none' : '';
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
        if (!is_array($children)) return 0;
        foreach ($children as $cid) {
            $obj = @IPS_GetObject($cid);
            if (!is_array($obj)) continue;
            if (($obj['ObjectIdent'] ?? '') === $ident) return $cid;
            if ($obj['HasChildren'] ?? false) {
                $found = $this->FindVarByIdent($cid, $ident);
                if ($found > 0) return $found;
            }
        }
        return 0;
    }

    private function MeterHubAssignments(): array
    {
        $ids = @IPS_GetInstanceListByModuleID(self::MHUB_GUID);
        if (!is_array($ids) || !function_exists('MHUB_GetFunctions')) return [];
        $out = [];
        foreach ($ids as $id) {
            $f = @MHUB_GetFunctions((int)$id);
            if (is_string($f)) $f = json_decode($f, true);
            if (!is_array($f) || !isset($f['assignments'])) continue;
            $out = array_merge($out, $f['assignments']);
        }
        return $out;
    }

    private function singleInstance(string $guid): int
    {
        $ids = @IPS_GetInstanceListByModuleID($guid);
        return (is_array($ids) && count($ids) === 1) ? (int)$ids[0] : 0;
    }
}
?>
