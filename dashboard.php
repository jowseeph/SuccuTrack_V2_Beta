<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
require 'config.php'; // Sets Asia/Manila timezone globally

$uid = (int)$_SESSION['user_id'];

// All plants for this user
$stmt = $pdo->prepare("SELECT * FROM plants WHERE user_id = ? ORDER BY plant_id ASC");
$stmt->execute([$uid]);
$plants = $stmt->fetchAll();

// Per-plant data: latest reading, stats, chart history
$plantData = [];
foreach ($plants as $plant) {
    $pid = (int)$plant['plant_id'];

    $q = $pdo->prepare("
        SELECT h.humidity_percent, h.status, h.recorded_at
        FROM humidity h
        JOIN user_logs ul ON h.humidity_id = ul.humidity_id
        WHERE h.plant_id = ? AND ul.user_id = ?
        ORDER BY h.recorded_at DESC LIMIT 1
    ");
    $q->execute([$pid, $uid]);
    $latest = $q->fetch();

    $s = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=?");
    $s->execute([$uid, $pid]); $total = (int)$s->fetchColumn();

    $s2 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Dry'");
    $s2->execute([$uid, $pid]); $dry = (int)$s2->fetchColumn();

    $s3 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Ideal'");
    $s3->execute([$uid, $pid]); $ideal = (int)$s3->fetchColumn();

    $s4 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Humid'");
    $s4->execute([$uid, $pid]); $humid = (int)$s4->fetchColumn();

    // Last 50 readings oldest-first for the chart.
    // FIX: recorded_at is now stored in Asia/Manila; date() also uses Asia/Manila
    // so labels are always in local PH time — no UTC/local mismatch.
    $chartQ = $pdo->prepare("
        SELECT h.humidity_percent, h.status, h.recorded_at
        FROM humidity h
        JOIN user_logs ul ON h.humidity_id = ul.humidity_id
        WHERE h.plant_id = ? AND ul.user_id = ?
        ORDER BY h.recorded_at DESC LIMIT 50
    ");
    $chartQ->execute([$pid, $uid]);
    $chartRows = array_reverse($chartQ->fetchAll());

    // FIX: Store UNIX timestamp as the sort key so the JS can sort mixed-plant
    // labels correctly in true chronological order (string "M d H:i" sorts
    // lexicographically wrong when months wrap, e.g. "Dec 31" < "Jan 01").
    $chartPoints = array_map(fn($r) => [
        'ts'      => strtotime($r['recorded_at']),                 // UNIX int – sort key
        'label'   => date('M d H:i', strtotime($r['recorded_at'])), // display label
        'value'   => (float)$r['humidity_percent'],
        'status'  => $r['status'],
    ], $chartRows);

    $plantData[] = [
        'plant'       => $plant,
        'latest'      => $latest,
        'stats'       => compact('total','dry','ideal','humid'),
        'chartPoints' => $chartPoints,
    ];
}

// Combined log table (all plants)
$logs = $pdo->prepare("
    SELECT p.plant_name, h.humidity_percent, h.status, h.recorded_at
    FROM humidity h
    JOIN user_logs ul ON h.humidity_id = ul.humidity_id
    JOIN plants p ON h.plant_id = p.plant_id
    WHERE ul.user_id = ?
    ORDER BY h.recorded_at DESC LIMIT 100
");
$logs->execute([$uid]);
$allLogs = $logs->fetchAll();

$tips = [
    'Dry'   => ['💧 Water immediately — give a thorough soak and let it drain fully.',
                '☀️ Too much direct sun speeds up drying — consider partial shade.',
                '🪴 Check if soil is pulling away from the pot edges.'],
    'Ideal' => ['✅ Perfect conditions! Maintain your current watering schedule.',
                '🌿 A diluted succulent fertiliser will boost growth right now.',
                '🔄 Rotate the pot a quarter turn weekly for even sun exposure.'],
    'Humid' => ['🚫 Stop watering until the top 2 cm of soil is completely dry.',
                '💨 Move to a well-ventilated area to reduce excess moisture.',
                '🪨 Ensure the pot has drainage holes to prevent root rot.'],
];

// FIX: Build the fixed plant→colour mapping in PHP so the combined chart
// and the per-plant cards share the same palette index.
// The palette order matches PALETTE[] in JS below.
$PALETTE_LINES = ['#4a7c59','#3a6fa8','#b85c2a','#7a4fa8','#a85c7a','#2a8a8a','#8a6200'];
$plantColorMap = []; // plant_id => hex colour (for JS dataset index lookup)
foreach ($plantData as $i => $pd) {
    $plantColorMap[$pd['plant']['plant_id']] = $PALETTE_LINES[$i % count($PALETTE_LINES)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard – SuccuTrack</title>
<link rel="stylesheet" href="style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>
<body>
<nav class="navbar">
  <div class="nav-brand">🌵 SuccuTrack</div>
  <div class="nav-links">
    <span>Hi, <?= htmlspecialchars($_SESSION['username']) ?></span>
    <a href="logout.php" class="btn btn-sm">Logout</a>
  </div>
</nav>

<div class="container">

<?php if (empty($plants)): ?>
  <div class="card"><p class="empty-msg">No plants assigned to your account. Contact your admin.</p></div>
<?php else: ?>

  <?php
    $sumTotal = array_sum(array_column(array_column($plantData, 'stats'), 'total'));
    $sumDry   = array_sum(array_column(array_column($plantData, 'stats'), 'dry'));
    $sumIdeal = array_sum(array_column(array_column($plantData, 'stats'), 'ideal'));
    $sumHumid = array_sum(array_column(array_column($plantData, 'stats'), 'humid'));
  ?>
  <div class="stats-row" style="grid-template-columns:repeat(5,1fr);">
    <div class="stat-card stat-plants"><div class="stat-num"><?= count($plants) ?></div><div class="stat-label">🪴 My Plants</div></div>
    <div class="stat-card stat-total"><div class="stat-num"><?= $sumTotal ?></div><div class="stat-label">📊 Total Readings</div></div>
    <div class="stat-card stat-dry"><div class="stat-num"><?= $sumDry ?></div><div class="stat-label">🏜️ Dry</div></div>
    <div class="stat-card stat-ideal"><div class="stat-num"><?= $sumIdeal ?></div><div class="stat-label">✅ Ideal</div></div>
    <div class="stat-card stat-humid"><div class="stat-num"><?= $sumHumid ?></div><div class="stat-label">💧 Humid</div></div>
  </div>

  <?php foreach ($plantData as $idx => $pd):
    $plant       = $pd['plant'];
    $latest      = $pd['latest'];
    $stats       = $pd['stats'];
    $pid         = (int)$plant['plant_id'];
    $statusClass = $latest ? strtolower($latest['status']) : 'none';
    $cardId      = 'card-' . $pid;
    // FIX: assign plant line colour from the server-side palette map so it
    // always matches the combined chart dataset colour.
    $plantLineColor = $plantColorMap[$pid];
  ?>
  <div class="plant-card plant-border-<?= $statusClass ?>" id="<?= $cardId ?>">

    <div class="plant-header">
      <div>
        <div class="plant-name">🪴 <?= htmlspecialchars($plant['plant_name']) ?></div>
        <div class="plant-city">📍 <?= htmlspecialchars($plant['city']) ?> &nbsp;|&nbsp; IoT Device #<?= $pid ?></div>
      </div>
      <div style="text-align:right;" id="header-reading-<?= $pid ?>">
        <?php if ($latest): ?>
        <div class="reading-value reading-<?= $statusClass ?>">
          <?= $latest['humidity_percent'] ?><span class="reading-unit">%</span>
        </div>
        <span class="badge badge-<?= $statusClass ?> badge-lg"><?= $latest['status'] ?></span>
        <?php else: ?>
        <div class="reading-empty">No reading yet</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="plant-mini-stats">
      <div class="mini-stat"><span class="mini-val" id="stat-total-<?= $pid ?>"><?= $stats['total'] ?></span><span class="mini-lbl">Total</span></div>
      <div class="mini-stat mini-dry"><span class="mini-val" id="stat-dry-<?= $pid ?>"><?= $stats['dry'] ?></span><span class="mini-lbl">Dry</span></div>
      <div class="mini-stat mini-ideal"><span class="mini-val" id="stat-ideal-<?= $pid ?>"><?= $stats['ideal'] ?></span><span class="mini-lbl">Ideal</span></div>
      <div class="mini-stat mini-humid"><span class="mini-val" id="stat-humid-<?= $pid ?>"><?= $stats['humid'] ?></span><span class="mini-lbl">Humid</span></div>
    </div>

    <div id="reading-info-<?= $pid ?>">
      <?php if ($latest): ?>
      <div class="weather-info">
        <span>📍 <?= htmlspecialchars($plant['city']) ?></span>
        <!-- FIX: date() uses Asia/Manila so timestamp shown matches stored record -->
        <span>🕐 <?= date('M d, Y H:i:s', strtotime($latest['recorded_at'])) ?> (PHT)</span>
      </div>
      <?php endif; ?>
    </div>

    <div style="margin-top:16px;">
      <button class="btn btn-primary btn-sim" id="fetchBtn-<?= $pid ?>"
              onclick="fetchReading(<?= $pid ?>)">
        🌐 Fetch Live Reading
      </button>
      <div class="sim-status-text" id="fetchStatus-<?= $pid ?>"></div>
    </div>

    <div id="tips-<?= $pid ?>">
      <?php if ($latest && isset($tips[$latest['status']])): ?>
      <div class="plant-tips plant-tips-<?= $statusClass ?>">
        <?php foreach ($tips[$latest['status']] as $tip): ?>
          <div class="plant-tip"><?= $tip ?></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- end plant-card -->
  <?php endforeach; ?>

  <!-- ── Combined Humidity Trend Chart ──────────────────────────────────── -->
  <?php
    $hasAnyData = array_reduce($plantData, fn($c, $pd) => $c || !empty($pd['chartPoints']), false);
  ?>
  <div class="card">
    <div class="chart-header" style="margin-bottom:14px;">
      <h2 style="margin:0;">📊 Humidity Trend — All Plants</h2>
      <span class="chart-count">Last 50 readings per plant · Asia/Manila time (PHT)</span>
    </div>
    <?php if (!$hasAnyData): ?>
      <p class="empty-msg">No readings yet. Click Fetch Live Reading on any plant above.</p>
    <?php else: ?>
      <div style="position:relative;height:260px;">
        <canvas id="combined-chart"></canvas>
      </div>
      <!-- FIX: Legend is built from PHP palette map so colour is guaranteed
           to match each plant's dataset in the chart. -->
      <div class="chart-legend" style="margin-top:12px;">
        <?php foreach ($plantData as $i => $pd): ?>
        <span class="legend-item">
          <span class="legend-dot" style="background:<?= $plantColorMap[$pd['plant']['plant_id']] ?>"></span>
          <?= htmlspecialchars($pd['plant']['plant_name']) ?>
        </span>
        <?php endforeach; ?>
        <span class="legend-item" style="margin-left:14px;font-style:italic;color:var(--text-3);font-size:.72rem;">
          ● point colour = status (🟤 Dry / 🟢 Ideal / 🔵 Humid)
        </span>
      </div>
    <?php endif; ?>
  </div>

  <script>
  // ── Combined trend chart ────────────────────────────────────────────────────
  (function() {
    // Data passed from PHP — one entry per plant.
    // Each chartPoints item: { ts (unix int), label (string), value, status }
    const plantDatasets = <?= json_encode(array_map(fn($pd) => [
        'pid'    => $pd['plant']['plant_id'],
        'name'   => $pd['plant']['plant_name'],
        'points' => $pd['chartPoints'],
    ], $plantData)) ?>;

    if (!plantDatasets.length) return;

    // FIX: Palette defined once in PHP and mirrored here — indexes are identical.
    const PALETTE = [
      { line: '#4a7c59', bg: 'rgba(74,124,89,0.07)'  },
      { line: '#3a6fa8', bg: 'rgba(58,111,168,0.07)' },
      { line: '#b85c2a', bg: 'rgba(184,92,42,0.07)'  },
      { line: '#7a4fa8', bg: 'rgba(122,79,168,0.07)' },
      { line: '#a85c7a', bg: 'rgba(168,92,122,0.07)' },
      { line: '#2a8a8a', bg: 'rgba(42,138,138,0.07)' },
      { line: '#8a6200', bg: 'rgba(138,98,0,0.07)'   },
    ];

    // Status dot colours (per-point, so the viewer can see status at a glance)
    const STATUS_PT = { Dry:'#b85c2a', Ideal:'#4a7c59', Humid:'#3a6fa8' };

    // FIX: Collect all unique timestamps (UNIX int) and sort numerically so
    // the X-axis is always in true chronological order regardless of which
    // plant produced which reading and in what order.
    const tsSet = new Set();
    plantDatasets.forEach(pd => pd.points.forEach(p => tsSet.add(p.ts)));
    const allTs     = Array.from(tsSet).sort((a, b) => a - b);  // numeric sort
    const allLabels = allTs.map(ts => {
      // FIX: Format using a fixed locale so the label string is stable and
      // matches what PHP produced (both Asia/Manila).
      const d = new Date(ts * 1000);
      const mo = d.toLocaleString('en-PH', {month:'short', timeZone:'Asia/Manila'});
      const dy = String(d.toLocaleString('en-PH', {day:'2-digit',  timeZone:'Asia/Manila'}));
      const hr = String(d.toLocaleString('en-PH', {hour:'2-digit', hour12:false, timeZone:'Asia/Manila'})).padStart(2,'0');
      const mn = String(d.getMinutes()).padStart(2,'0');
      return `${mo} ${dy} ${hr}:${mn}`;
    });

    // Build one dataset per plant — null for timestamps where no reading exists
    const datasets = plantDatasets.map((pd, i) => {
      const col     = PALETTE[i % PALETTE.length];
      const tsMap   = {};
      pd.points.forEach(p => { tsMap[p.ts] = p; });

      const data     = allTs.map(ts => tsMap[ts]?.value ?? null);
      const ptColors = allTs.map(ts => STATUS_PT[tsMap[ts]?.status] ?? col.line);

      return {
        label: pd.name,
        data,
        borderColor:          col.line,
        backgroundColor:      col.bg,
        borderWidth:          2,
        fill:                 false,
        tension:              0.35,
        spanGaps:             true,
        pointRadius:          allTs.length > 40 ? 2 : 4,
        pointHoverRadius:     6,
        pointBackgroundColor: ptColors,
        pointBorderColor:     '#fff',
        pointBorderWidth:     1.5,
        // Store pid so fetchReading() can find this dataset by plant ID
        _pid: pd.pid,
      };
    });

    // Soft zone bands (background fill only — no legend entries)
    const zoneDry = {
      label: '_dry', data: allTs.map(() => 20),
      fill: { target: 'origin', above: 'rgba(184,92,42,0.04)' },
      borderWidth: 0.8, borderColor: 'rgba(184,92,42,0.18)',
      borderDash: [4,4], pointRadius: 0, tension: 0,
    };
    const zoneHumid = {
      label: '_humid', data: allTs.map(() => 100),
      fill: { target: { value: 60 }, above: 'rgba(58,111,168,0.04)' },
      borderWidth: 0.8, borderColor: 'rgba(58,111,168,0.18)',
      borderDash: [4,4], pointRadius: 0, tension: 0,
    };

    const ctx = document.getElementById('combined-chart');
    if (!ctx) return;

    // Store ts array for pushToCombinedChart()
    window._combinedTs = allTs;

    window._combinedChart = new Chart(ctx, {
      type: 'line',
      data: { labels: allLabels, datasets: [zoneDry, zoneHumid, ...datasets] },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
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
          x: {
            ticks: { font:{size:10}, color:'#96aea0', maxRotation:40, maxTicksLimit:10, autoSkip:true },
            grid:  { color:'rgba(150,174,160,0.1)' },
          },
          y: {
            min:0, max:100,
            ticks: { font:{size:10}, color:'#96aea0', callback: v => v+'%', stepSize:20 },
            grid:  { color:'rgba(150,174,160,0.1)' },
          },
        },
      },
    });
  })();
  </script>

  <!-- ── Detection Records Table ─────────────────────────────────────────── -->
  <div class="card">
    <h2>📋 All Detection Records <span class="user-count"><?= count($allLogs) ?></span></h2>
    <p class="subtitle">Combined humidity readings from all your IoT devices · timestamps in PHT (UTC+8)</p>
    <?php if (empty($allLogs)): ?>
      <p class="empty-msg">No records yet. Click Fetch Live Reading on any plant above.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="det-table">
        <thead>
          <tr><th>#</th><th>Plant</th><th>Humidity %</th><th>Status</th><th>Date &amp; Time (PHT)</th></tr>
        </thead>
        <tbody>
          <?php foreach ($allLogs as $i => $log): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td>🪴 <?= htmlspecialchars($log['plant_name']) ?></td>
            <td><strong><?= $log['humidity_percent'] ?>%</strong></td>
            <td><span class="badge badge-<?= strtolower($log['status']) ?>"><?= $log['status'] ?></span></td>
            <!-- FIX: date() is in Asia/Manila (set by config.php) -->
            <td><?= date('M d, Y H:i:s', strtotime($log['recorded_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

<?php endif; ?>
</div>

<script>
const tipsData = {
  'Dry':   ['💧 Water immediately — give a thorough soak and let it drain fully.',
             '☀️ Too much direct sun speeds up drying — consider partial shade.',
             '🪴 Check if soil is pulling away from the pot edges.'],
  'Ideal': ['✅ Perfect conditions! Maintain your current watering schedule.',
             '🌿 A diluted succulent fertiliser will boost growth right now.',
             '🔄 Rotate the pot a quarter turn weekly for even sun exposure.'],
  'Humid': ['🚫 Stop watering until the top 2 cm of soil is completely dry.',
             '💨 Move to a well-ventilated area to reduce excess moisture.',
             '🪨 Ensure the pot has drainage holes to prevent root rot.'],
};

const STATUS_PT = { Dry:'#b85c2a', Ideal:'#4a7c59', Humid:'#3a6fa8' };

// FIX: plant→dataset index map uses the same PALETTE order as the chart block.
// Offset by 2 because datasets[0]=zoneDry, datasets[1]=zoneHumid.
const plantDatasetIndex = {};
<?php foreach ($plantData as $i => $pd): ?>
plantDatasetIndex[<?= $pd['plant']['plant_id'] ?>] = <?= $i + 2 ?>;
<?php endforeach; ?>

// Push a new live reading into the combined chart
function pushToCombinedChart(pid, ts, label, value, status) {
  const chart = window._combinedChart;
  if (!chart) return;
  const dsIdx = plantDatasetIndex[pid];
  if (dsIdx === undefined) return;

  const tsArr = window._combinedTs;

  // Check whether this exact ts already exists
  const existIdx = tsArr.indexOf(ts);

  if (existIdx === -1) {
    // New timestamp — insert in sorted position
    let insertAt = tsArr.length;
    for (let i = 0; i < tsArr.length; i++) {
      if (tsArr[i] > ts) { insertAt = i; break; }
    }
    tsArr.splice(insertAt, 0, ts);
    chart.data.labels.splice(insertAt, 0, label);

    // Extend zone bands
    chart.data.datasets[0].data.splice(insertAt, 0, 20);
    chart.data.datasets[1].data.splice(insertAt, 0, 100);

    // Extend all plant datasets with null at this position
    chart.data.datasets.forEach((ds, i) => {
      if (i >= 2) ds.data.splice(insertAt, 0, null);
    });
  }

  const labelIdx = tsArr.indexOf(ts);
  const ds = chart.data.datasets[dsIdx];
  ds.data[labelIdx] = value;
  if (Array.isArray(ds.pointBackgroundColor)) {
    ds.pointBackgroundColor[labelIdx] = STATUS_PT[status] || '#96aea0';
  }

  chart.update();
}

function fetchReading(pid) {
  const btn    = document.getElementById('fetchBtn-' + pid);
  const status = document.getElementById('fetchStatus-' + pid);
  btn.disabled    = true;
  btn.textContent = '⏳ Fetching from API...';
  status.textContent = '';

  const form = new FormData();
  form.append('plant_id', pid);

  fetch('simulate.php', { method: 'POST', body: form })
    .then(r => r.json())
    .then(data => {
      btn.disabled    = false;
      btn.textContent = '🌐 Fetch Live Reading';

      if (!data.success) {
        status.textContent = '⚠️ ' + data.error;
        status.style.color = 'var(--dry)';
        return;
      }

      // FIX: status text already carries PHT (set by simulate.php using
      // Asia/Manila date_default_timezone_set in config.php).
      status.textContent = '✅ Reading saved — ' + data.detected_at + ' (PHT)';
      status.style.color = 'var(--ideal)';

      const s = data.status.toLowerCase();

      // Update card border
      const card = document.getElementById('card-' + pid);
      card.className = card.className.replace(/plant-border-\w+/, 'plant-border-' + s);

      // Update header reading
      document.getElementById('header-reading-' + pid).innerHTML = `
        <div class="reading-value reading-${s}">${data.humidity}<span class="reading-unit">%</span></div>
        <span class="badge badge-${s} badge-lg">${data.status}</span>
      `;

      // Update reading info
      document.getElementById('reading-info-' + pid).innerHTML = `
        <div class="weather-info">
          <span>📍 ${data.city}</span>
          <span>🌤️ ${data.description}</span>
          <span>🕐 ${data.detected_at} (PHT)</span>
        </div>
      `;

      // Update mini stats
      document.getElementById('stat-total-' + pid).textContent = data.total;
      document.getElementById('stat-dry-'   + pid).textContent = data.dry;
      document.getElementById('stat-ideal-' + pid).textContent = data.ideal;
      document.getElementById('stat-humid-' + pid).textContent = data.humid;

      // FIX: Parse detected_at as a UNIX timestamp so pushToCombinedChart
      // can insert it in the correct chronological position.
      const ts    = Math.floor(new Date(data.detected_at.replace(' ', 'T') + '+08:00').getTime() / 1000);
      const label = data.detected_at.slice(5, 16); // "MM-DD HH:mm"
      pushToCombinedChart(pid, ts, label, data.humidity, data.status);

      // Update care tips
      document.getElementById('tips-' + pid).innerHTML =
        `<div class="plant-tips plant-tips-${s}">` +
        (tipsData[data.status] || []).map(t => `<div class="plant-tip">${t}</div>`).join('') +
        `</div>`;

      // Pulse animation
      const ri = document.getElementById('reading-info-' + pid);
      ri.classList.add('pulse');
      setTimeout(() => ri.classList.remove('pulse'), 600);
    })
    .catch(() => {
      btn.disabled    = false;
      btn.textContent = '🌐 Fetch Live Reading';
      status.textContent = '⚠️ Server error. Try again.';
      status.style.color = 'var(--dry)';
    });
}
</script>
</body>
</html>
