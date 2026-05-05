<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header("Location: index.php"); exit;
}
require 'config.php'; // Sets Asia/Manila timezone

$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_plant_id'])) {
    $pid  = intval($_POST['edit_plant_id']);
    $name = trim($_POST['plant_name']);
    $city = trim($_POST['plant_city']);
    if ($name && $city) {
        $pdo->prepare("UPDATE plants SET plant_name = ?, city = ? WHERE plant_id = ?")
            ->execute([$name, $city, $pid]);
        $msg = "Plant updated successfully.";
    }
}

$users = $pdo->query("
    SELECT u.user_id, u.username, u.email, u.created_at,
           COUNT(p.plant_id) AS plant_count
    FROM users u
    LEFT JOIN plants p ON u.user_id = p.user_id
    WHERE u.role = 'user'
    GROUP BY u.user_id
    ORDER BY u.username ASC
")->fetchAll();

$plants = $pdo->query("
    SELECT p.plant_id, p.plant_name, p.city, p.latitude, p.longitude, p.created_at,
           u.username, u.user_id,
           h.humidity_percent, h.status, h.recorded_at AS last_reading
    FROM plants p
    JOIN users u ON p.user_id = u.user_id
    LEFT JOIN humidity h ON h.humidity_id = (
        SELECT h2.humidity_id FROM humidity h2
        WHERE h2.plant_id = p.plant_id
        ORDER BY h2.recorded_at DESC LIMIT 1
    )
    ORDER BY u.username, p.plant_id
")->fetchAll();

$counts = $pdo->query("SELECT status, COUNT(*) as total FROM humidity GROUP BY status")->fetchAll();
$stats  = array_column($counts, 'total', 'status');
$total  = array_sum(array_column($counts, 'total'));

// FIX: Fetch the last 100 readings with plant_id so the analytics trend chart
// can colour each point by plant (not just by status).
$readings = $pdo->query("
    SELECT h.humidity_id, h.plant_id, p.plant_name, u.username,
           h.humidity_percent, h.status, h.recorded_at
    FROM humidity h
    LEFT JOIN plants p ON h.plant_id = p.plant_id
    LEFT JOIN users u  ON p.user_id  = u.user_id
    ORDER BY h.recorded_at DESC LIMIT 100
")->fetchAll();

// Build per-plant reading counts for the bar chart (avoid N+1 in JS)
$plantReadingCounts = [];
foreach ($plants as $p) {
    $c = $pdo->prepare("SELECT COUNT(*) FROM humidity WHERE plant_id = ?");
    $c->execute([$p['plant_id']]);
    $plantReadingCounts[$p['plant_id']] = (int)$c->fetchColumn();
}

// Server-side palette map — same indexes as PALETTE[] in JS so every chart,
// legend dot, bar and popup uses the same colour for the same plant.
$PALETTE_LINES = ['#4a7c59','#3a6fa8','#b85c2a','#7a4fa8','#a85c7a','#2a8a8a','#8a6200'];
$plantColorMap  = [];
$plantChartData = [];   // per-plant time-series for the multi-line trend chart

foreach ($plants as $i => $p) {
    $pid = (int)$p['plant_id'];
    $plantColorMap[$pid] = $PALETTE_LINES[$i % count($PALETTE_LINES)];

    // Fetch last 50 readings for this plant, oldest-first
    $cq = $pdo->prepare("
        SELECT humidity_percent, status,
               UNIX_TIMESTAMP(recorded_at) AS ts,
               recorded_at
        FROM humidity
        WHERE plant_id = ?
        ORDER BY recorded_at DESC LIMIT 50
    ");
    $cq->execute([$pid]);
    $rows = array_reverse($cq->fetchAll());

    $plantChartData[] = [
        'pid'    => $pid,
        'name'   => $p['plant_name'],
        'points' => array_map(fn($r) => [
            'ts'    => (int)$r['ts'],
            'label' => date('M d H:i', (int)$r['ts']),
            'value' => (float)$r['humidity_percent'],
            'status'=> $r['status'],
        ], $rows),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manager – SuccuTrack</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
#manager-map { height:480px; border-radius:var(--radius-sm); border:1px solid var(--border); overflow:hidden; z-index:1; position:relative; }
.badge-manager { background:#f0eaff; color:#6b3ec8; border:1px solid #d4baff; }
.section-tabs { display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap; }
.stab { padding:8px 18px; font-size:.82rem; font-weight:500; border:1px solid var(--border); border-radius:var(--radius-sm); background:var(--white); color:var(--text-2); cursor:pointer; font-family:'DM Sans',sans-serif; transition:.15s; }
.stab.active { background:var(--green); color:#fff; border-color:var(--green); }
.stab:hover:not(.active) { background:var(--green-lt); color:var(--green); border-color:var(--green-md); }
.spanel { display:none; } .spanel.active { display:block; }
.edit-form { display:grid; grid-template-columns:1fr 1fr auto; gap:8px; align-items:end; margin-top:10px; }
.edit-form input { padding:7px 10px; border:1.5px solid var(--border); border-radius:var(--radius-sm); font-size:.82rem; font-family:inherit; background:var(--white); color:var(--text); }
.edit-form input:focus { outline:none; border-color:var(--green); }
.map-legend { display:flex; gap:16px; flex-wrap:wrap; margin-top:10px; font-size:.76rem; }
.legend-dot { width:12px; height:12px; border-radius:50%; display:inline-block; margin-right:4px; vertical-align:middle; }
.coverage-info { background:var(--green-lt); border:1px solid var(--green-md); border-radius:var(--radius-sm); padding:10px 14px; font-size:.8rem; color:var(--text-2); margin-bottom:12px; }
.tz-note { font-size:.72rem; color:var(--text-3); font-style:italic; margin-bottom:4px; }
</style>
</head>
<body>

<nav class="navbar">
  <div class="nav-brand">🌵 SuccuTrack <span class="admin-badge" style="background:#6b3ec8;">Manager</span></div>
  <div class="nav-links">
    <span class="nav-user">Hi, <?= htmlspecialchars($_SESSION['username']) ?></span>
    <a href="logout.php" class="btn btn-sm">Logout</a>
  </div>
</nav>

<div class="container">

  <?php if ($msg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <div class="stats-row">
    <div class="stat-card stat-total"><div class="stat-num"><?= $total ?></div><div class="stat-label">📊 Total Readings</div></div>
    <div class="stat-card stat-dry"><div class="stat-num"><?= $stats['Dry'] ?? 0 ?></div><div class="stat-label">🏜️ Dry</div></div>
    <div class="stat-card stat-ideal"><div class="stat-num"><?= $stats['Ideal'] ?? 0 ?></div><div class="stat-label">✅ Ideal</div></div>
    <div class="stat-card stat-humid"><div class="stat-num"><?= $stats['Humid'] ?? 0 ?></div><div class="stat-label">💧 Humid</div></div>
    <div class="stat-card stat-plants"><div class="stat-num"><?= count($plants) ?></div><div class="stat-label">🪴 Plants</div></div>
  </div>

  <div class="card">
    <h2>🗺️ Coverage Map</h2>
    <p class="subtitle">Plant locations with polygon coverage areas.</p>
    <div class="coverage-info">
      📍 Currently monitoring <strong><?= count($plants) ?> plants</strong>
      across <strong><?= count(array_unique(array_column($plants, 'city'))) ?> area(s)</strong>.
    </div>
    <div id="manager-map"></div>
    <div class="map-legend">
      <span><span class="legend-dot" style="background:#b85c2a"></span>Dry</span>
      <span><span class="legend-dot" style="background:#4a7c59"></span>Ideal</span>
      <span><span class="legend-dot" style="background:#3a6fa8"></span>Humid</span>
      <span><span class="legend-dot" style="background:#96aea0"></span>No data</span>
    </div>
  </div>

  <div class="card">
    <h2>📋 Data Management</h2>
    <p class="subtitle">View and manage plants, users, humidity data, and analytics</p>
    <p class="tz-note">🕐 All timestamps in <strong>Asia/Manila (PHT, UTC+8)</strong></p>

    <div class="section-tabs">
      <button class="stab active" onclick="showPanel('panel-plants',   this)">🪴 All Plants</button>
      <button class="stab"        onclick="showPanel('panel-users',    this)">👤 Users Overview</button>
      <button class="stab"        onclick="showPanel('panel-humidity', this)">💧 Humidity Records</button>
      <button class="stab"        onclick="showPanel('panel-analytics',this)">📊 Analytics</button>
    </div>

    <!-- Plants panel -->
    <div class="spanel active" id="panel-plants">
      <?php if (empty($plants)): ?>
        <p class="empty-msg">No plants registered yet.</p>
      <?php else: ?>
      <div class="table-wrap">
        <table class="det-table">
          <thead><tr><th>#</th><th>Plant Name</th><th>Owner</th><th>City</th><th>Last Humidity</th><th>Status</th><th>Last Reading (PHT)</th><th>Edit</th></tr></thead>
          <tbody>
            <?php foreach ($plants as $p): ?>
            <tr>
              <td><?= $p['plant_id'] ?></td>
              <td><strong>🪴 <?= htmlspecialchars($p['plant_name']) ?></strong></td>
              <td><?= htmlspecialchars($p['username']) ?></td>
              <td><?= htmlspecialchars($p['city']) ?></td>
              <td><?= $p['humidity_percent'] ? $p['humidity_percent'].'%' : '—' ?></td>
              <td><?php if ($p['status']): ?><span class="badge badge-<?= strtolower($p['status']) ?>"><?= $p['status'] ?></span><?php else: ?>—<?php endif; ?></td>
              <td><?= $p['last_reading'] ? date('M d, Y H:i', strtotime($p['last_reading'])) : '—' ?></td>
              <td><button class="btn btn-sm" onclick="toggleEdit(<?= $p['plant_id'] ?>, '<?= addslashes(htmlspecialchars($p['plant_name'])) ?>', '<?= addslashes(htmlspecialchars($p['city'])) ?>')">✏️ Edit</button></td>
            </tr>
            <tr id="edit-row-<?= $p['plant_id'] ?>" style="display:none;background:var(--green-lt);">
              <td colspan="8" style="padding:12px 13px;">
                <form method="POST" class="edit-form">
                  <input type="hidden" name="edit_plant_id" value="<?= $p['plant_id'] ?>">
                  <div>
                    <label style="font-size:.7rem;font-weight:600;color:var(--text-2);text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px;">Plant Name</label>
                    <input type="text" name="plant_name" id="edit-name-<?= $p['plant_id'] ?>" required>
                  </div>
                  <div>
                    <label style="font-size:.7rem;font-weight:600;color:var(--text-2);text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px;">City</label>
                    <input type="text" name="plant_city" id="edit-city-<?= $p['plant_id'] ?>" required>
                  </div>
                  <div style="display:flex;gap:6px;">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-sm" onclick="toggleEdit(<?= $p['plant_id'] ?>)">Cancel</button>
                  </div>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Users panel -->
    <div class="spanel" id="panel-users">
      <div class="table-wrap">
        <table class="det-table">
          <thead><tr><th>#</th><th>Username</th><th>Email</th><th>Plants</th><th>Joined (PHT)</th></tr></thead>
          <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
              <td><?= $u['user_id'] ?></td>
              <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
              <td><?= htmlspecialchars($u['email']) ?></td>
              <td><?= $u['plant_count'] ?> plant<?= $u['plant_count'] != 1 ? 's' : '' ?></td>
              <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Humidity records panel -->
    <div class="spanel" id="panel-humidity">
      <?php if (empty($readings)): ?>
        <p class="empty-msg">No humidity records yet.</p>
      <?php else: ?>
      <div class="table-wrap">
        <table class="det-table">
          <thead><tr><th>#</th><th>Plant</th><th>Owner</th><th>Humidity %</th><th>Status</th><th>Recorded At (PHT)</th></tr></thead>
          <tbody>
            <?php foreach ($readings as $i => $r): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td>🪴 <?= htmlspecialchars($r['plant_name'] ?? '—') ?></td>
              <td><?= htmlspecialchars($r['username'] ?? '—') ?></td>
              <td><strong><?= $r['humidity_percent'] ?>%</strong></td>
              <td><span class="badge badge-<?= strtolower($r['status']) ?>"><?= $r['status'] ?></span></td>
              <td><?= date('M d, Y H:i', strtotime($r['recorded_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Analytics panel -->
    <div class="spanel" id="panel-analytics">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
        <div>
          <p style="font-size:.8rem;font-weight:600;color:var(--text-2);margin-bottom:10px;">Status Distribution</p>
          <div style="position:relative;height:220px;"><canvas id="donut-chart"></canvas></div>
        </div>
        <div>
          <p style="font-size:.8rem;font-weight:600;color:var(--text-2);margin-bottom:10px;">Readings per Plant</p>
          <div style="position:relative;height:220px;"><canvas id="bar-chart"></canvas></div>
        </div>
      </div>
      <div style="margin-top:22px;padding-top:18px;border-top:1px solid var(--border);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
          <p style="font-size:.8rem;font-weight:600;color:var(--text-2);margin:0;">
            📈 Humidity Trend — Per Plant (Last 50 readings each) · PHT
          </p>
          <span style="font-size:.72rem;color:var(--text-3);">Each plant = its own line &amp; colour</span>
        </div>
        <!-- Legend: one dot per plant, colour-matched to its chart line -->
        <div class="chart-legend" style="margin-bottom:12px;">
          <?php foreach ($plantChartData as $pd): ?>
          <?php if (!empty($pd['points'])): ?>
          <span class="legend-item">
            <span class="legend-dot" style="background:<?= $plantColorMap[$pd['pid']] ?>"></span>
            <?= htmlspecialchars($pd['name']) ?>
          </span>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <div style="position:relative;height:260px;"><canvas id="trend-chart"></canvas></div>
      </div>
    </div>
  </div>

</div><!-- /.container -->

<script>
// ── Leaflet Map ──────────────────────────────────────────────────────────────
const map = L.map('manager-map');
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '© OpenStreetMap contributors', maxZoom: 18
}).addTo(map);

const manoloFortichPolygon = [
  [8.4450,124.7820],[8.4600,124.8050],[8.4720,124.8280],[8.4700,124.8520],
  [8.4580,124.8760],[8.4380,124.9000],[8.4150,124.9200],[8.3900,124.9350],
  [8.3650,124.9400],[8.3380,124.9300],[8.3100,124.9150],[8.2880,124.8950],
  [8.2600,124.8750],[8.2215,124.8490],[8.2380,124.8200],[8.2620,124.7980],
  [8.2880,124.7780],[8.3150,124.7600],[8.3420,124.7480],[8.3700,124.7520],
  [8.3980,124.7680],[8.4250,124.7750],[8.4450,124.7820],
];
const poly = L.polygon(manoloFortichPolygon, {
  color:'#4a7c59', weight:2.5, opacity:0.85,
  fillColor:'#4a7c59', fillOpacity:0.08, dashArray:'8, 5'
}).addTo(map).bindTooltip('Manolo Fortich, Bukidnon', { direction:'center' });
map.fitBounds(poly.getBounds(), { padding:[20,20] });

const plants       = <?= json_encode($plants) ?>;
const statusColors = { dry:'#b85c2a', ideal:'#4a7c59', humid:'#3a6fa8', '':'#96aea0' };
const sleep        = ms => new Promise(r => setTimeout(r, ms));

async function geocodeCity(city) {
  try {
    const res  = await fetch(
      `https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(city + ', Philippines')}&format=json&limit=1`,
      { headers: { 'Accept-Language': 'en' } }
    );
    const data = await res.json();
    if (data && data[0]) return { lat: parseFloat(data[0].lat), lng: parseFloat(data[0].lon) };
  } catch(e) {}
  return null;
}
function jitter(seed, range) {
  const x = Math.sin(seed * 127.1) * 43758.5453;
  return (x - Math.floor(x) - 0.5) * range;
}
async function plotPlants() {
  const cityCache = {};
  for (let i = 0; i < plants.length; i++) {
    const p      = plants[i];
    const status = (p.status || '').toLowerCase();
    const color  = statusColors[status] || statusColors[''];
    const city   = p.city;
    let lat, lng;
    if (p.latitude && p.longitude) {
      lat = parseFloat(p.latitude); lng = parseFloat(p.longitude);
    } else {
      if (!cityCache[city]) {
        if (Object.keys(cityCache).length > 0) await sleep(1200);
        const coords = await geocodeCity(city);
        cityCache[city] = coords || { lat:8.3677, lng:124.8653 };
      }
      const base = cityCache[city];
      lat = base.lat + jitter(p.plant_id * 3, 0.005);
      lng = base.lng + jitter(p.plant_id * 7, 0.005);
    }
    const r = 0.002;
    const hexCoords = Array.from({length:6}, (_, k) => {
      const angle = (Math.PI/3)*k - Math.PI/6;
      return [lat + r*Math.cos(angle), lng + r*Math.sin(angle)*1.2];
    });
    L.polygon(hexCoords, { color, weight:1.5, opacity:0.75, fillColor:color, fillOpacity:0.15, dashArray:'4, 3' }).addTo(map);
    const pinIcon = L.divIcon({
      className: '',
      html: `<div style="background:${color};width:30px;height:30px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.25);"></div>`,
      iconSize:[30,30], iconAnchor:[15,30]
    });
    // FIX: Use locale 'en-PH' + Asia/Manila timezone so the popup timestamp
    // shows local PH time, not the browser's local time.
    const humidity = p.humidity_percent ? p.humidity_percent + '%' : 'No data';
    const time     = p.last_reading
      ? new Date(p.last_reading.replace(' ','T') + '+08:00')
          .toLocaleString('en-PH', { timeZone:'Asia/Manila', dateStyle:'medium', timeStyle:'short' })
      : '—';
    const pinned   = p.latitude && p.longitude ? '📍 Exact pin' : '🔍 City estimate';
    L.marker([lat,lng], { icon:pinIcon }).addTo(map).bindPopup(`
      <div style="font-family:'DM Sans',sans-serif;min-width:170px;padding:4px 0;">
        <div style="font-weight:600;font-size:.9rem;margin-bottom:4px;">🪴 ${p.plant_name}</div>
        <div style="font-size:.78rem;color:#506358;margin-bottom:2px;">👤 ${p.username}</div>
        <div style="font-size:.78rem;color:#506358;margin-bottom:6px;">📍 ${city}</div>
        <div style="font-size:1.1rem;font-weight:700;color:${color};">${humidity}</div>
        <span style="display:inline-block;padding:2px 9px;border-radius:12px;font-size:.68rem;font-weight:600;text-transform:uppercase;background:${color}22;color:${color};border:1px solid ${color}44;margin-top:4px;">${p.status || 'No data'}</span>
        <div style="font-size:.72rem;color:#96aea0;margin-top:6px;">🕐 ${time} (PHT)</div>
        <div style="font-size:.68rem;color:#96aea0;margin-top:2px;">${pinned}</div>
      </div>
    `);
  }
}
plotPlants();

function showPanel(id, btn) {
  document.querySelectorAll('.spanel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.stab').forEach(b => b.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  btn.classList.add('active');
  if (id === 'panel-analytics') initCharts();
}

function toggleEdit(pid, name, city) {
  const row = document.getElementById('edit-row-' + pid);
  if (!row.style.display || row.style.display === 'none') {
    row.style.display = 'table-row';
    document.getElementById('edit-name-' + pid).value = name || '';
    document.getElementById('edit-city-' + pid).value = city || '';
  } else {
    row.style.display = 'none';
  }
}

// ── Analytics Charts ─────────────────────────────────────────────────────────
// plantColorMap: plant_id (int key) → hex colour, built server-side so every
// chart, legend dot, bar and popup always agrees on which colour = which plant.
const plantColorMap = <?= json_encode($plantColorMap) ?>;

// Full per-plant time-series (last 50 readings each, oldest-first).
// Structure: [{ pid, name, points:[{ts,label,value,status}] }, …]
const plantChartData = <?= json_encode($plantChartData) ?>;

let chartsInited = false;
function initCharts() {
  if (chartsInited) return;
  chartsInited = true;

  // ── Donut ─ status distribution ──────────────────────────────────────────
  const dry   = <?= $stats['Dry']   ?? 0 ?>;
  const ideal = <?= $stats['Ideal'] ?? 0 ?>;
  const humid = <?= $stats['Humid'] ?? 0 ?>;

  new Chart(document.getElementById('donut-chart'), {
    type: 'doughnut',
    data: {
      labels: ['Dry','Ideal','Humid'],
      datasets: [{ data:[dry,ideal,humid], backgroundColor:['#b85c2a','#4a7c59','#3a6fa8'], borderColor:'#fff', borderWidth:3 }]
    },
    options: { responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ position:'bottom', labels:{ font:{size:11,family:'DM Sans'}, padding:12 } } } }
  });

  // ── Bar ─ readings per plant ──────────────────────────────────────────────
  const plantNames  = <?= json_encode(array_column($plants, 'plant_name')) ?>;
  const plantIds    = <?= json_encode(array_column($plants, 'plant_id'))   ?>;
  const plantCounts = <?= json_encode(array_values($plantReadingCounts))   ?>;
  const barColors   = plantIds.map(id => (plantColorMap[id] || '#4a7c59') + 'b3');
  const barBorders  = plantIds.map(id =>  plantColorMap[id] || '#4a7c59');

  new Chart(document.getElementById('bar-chart'), {
    type: 'bar',
    data: {
      labels: plantNames,
      datasets: [{ label:'Readings', data:plantCounts,
        backgroundColor:barColors, borderColor:barBorders, borderWidth:1.5, borderRadius:6 }]
    },
    options: { responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ display:false } },
      scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1, font:{size:10} } },
               x:{ ticks:{ font:{size:10} } } } }
  });

  // ── Multi-line Trend ─ one dataset per plant ──────────────────────────────
  // KEY FIX: Instead of one dataset with mixed readings from all plants
  // (which Chart.js connects as a single line, leaving gaps for plants with
  // fewer readings), we build ONE dataset PER PLANT and fill missing
  // timestamps with null. spanGaps:true then draws a clean connected line
  // for each plant independently — exactly like dashboard.php does it.

  // Step 1: collect every unique UNIX timestamp across all plants, sorted.
  const tsSet = new Set();
  plantChartData.forEach(pd => pd.points.forEach(p => tsSet.add(p.ts)));
  const allTs = Array.from(tsSet).sort((a, b) => a - b);

  // Step 2: format display labels from the sorted timestamps.
  const allLabels = allTs.map(ts => {
    const d  = new Date(ts * 1000);
    const mo = d.toLocaleString('en-PH', { month:'short',   timeZone:'Asia/Manila' });
    const dy = d.toLocaleString('en-PH', { day:'2-digit',   timeZone:'Asia/Manila' });
    const hr = d.toLocaleString('en-PH', { hour:'2-digit',  hour12:false, timeZone:'Asia/Manila' }).padStart(2,'0');
    const mn = String(d.getMinutes()).padStart(2,'0');
    return `${mo} ${dy} ${hr}:${mn}`;
  });

  // Step 3: build one dataset per plant — null where no reading exists.
  const STATUS_PT = { Dry:'#b85c2a', Ideal:'#4a7c59', Humid:'#3a6fa8' };

  const plantDatasets = plantChartData
    .filter(pd => pd.points.length > 0)   // skip plants with zero data
    .map(pd => {
      const col    = plantColorMap[pd.pid] || '#96aea0';
      const tsMap  = {};
      pd.points.forEach(p => { tsMap[p.ts] = p; });

      const data     = allTs.map(ts => tsMap[ts]?.value  ?? null);
      const ptColors = allTs.map(ts => STATUS_PT[tsMap[ts]?.status] ?? col);

      return {
        label:                pd.name,
        data,
        borderColor:          col,
        backgroundColor:      col + '12',   // very light fill
        borderWidth:          2,
        fill:                 false,
        tension:              0.35,
        spanGaps:             true,          // connect across nulls
        pointRadius:          allTs.length > 60 ? 2 : 4,
        pointHoverRadius:     6,
        pointBackgroundColor: ptColors,      // dot colour = status
        pointBorderColor:     '#fff',
        pointBorderWidth:     1.5,
        _pid:                 pd.pid,
      };
    });

  // Zone bands (soft background — excluded from legend & tooltip)
  const zoneDry = {
    label:'_dry', data: allTs.map(() => 20),
    fill:{ target:'origin', above:'rgba(184,92,42,0.04)' },
    borderWidth:0.8, borderColor:'rgba(184,92,42,0.18)',
    borderDash:[4,4], pointRadius:0, tension:0,
  };
  const zoneHumid = {
    label:'_humid', data: allTs.map(() => 100),
    fill:{ target:{ value:60 }, above:'rgba(58,111,168,0.04)' },
    borderWidth:0.8, borderColor:'rgba(58,111,168,0.18)',
    borderDash:[4,4], pointRadius:0, tension:0,
  };

  new Chart(document.getElementById('trend-chart'), {
    type: 'line',
    data: { labels: allLabels, datasets: [zoneDry, zoneHumid, ...plantDatasets] },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode:'index', intersect:false },
      plugins: {
        legend: { display: false },
        tooltip: {
          filter: item => !item.dataset.label.startsWith('_'),
          callbacks: {
            title: ctx => ctx[0]?.label || '',
            label: ctx => {
              if (ctx.parsed.y === null) return null;
              const v = ctx.parsed.y;
              const s = v < 20 ? 'Dry' : v <= 60 ? 'Ideal' : 'Humid';
              return ` ${ctx.dataset.label}: ${v}% (${s})`;
            },
          },
        },
      },
      scales: {
        x: { ticks:{ font:{size:9}, maxTicksLimit:10, maxRotation:35, autoSkip:true },
             grid:{ color:'rgba(150,174,160,0.1)' } },
        y: { min:0, max:100,
             ticks:{ font:{size:10}, callback: v => v+'%', stepSize:20 },
             grid:{ color:'rgba(150,174,160,0.1)' } },
      },
    },
  });
}
</script>
</body>
</html>