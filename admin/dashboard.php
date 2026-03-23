<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    redirect_to("auth/login.php"); exit;
}
require_once __DIR__ . '/../config/config.php';

$msg = $error = "";

// ── Admin reject a recommended user ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_reject_uid'])) {
    $ruid = intval($_POST['admin_reject_uid']);
    $note = trim($_POST['admin_reject_note'] ?? '');
    $stmt = $pdo->prepare("SELECT user_id, username, status FROM users WHERE user_id=? AND role='user'");
    $stmt->execute([$ruid]);
    $rrow = $stmt->fetch();
    if ($rrow && in_array($rrow['status'], ['pending','recommended'], true)) {
        $pdo->prepare("UPDATE users SET status='rejected' WHERE user_id=?")->execute([$ruid]);
        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE for_role='admin' AND ref_user_id=?")->execute([$ruid]);
        notify_manager_rejected($pdo, $ruid, $rrow['username'], $_SESSION['username']);
        log_status_change($pdo, $ruid, (int)$_SESSION['user_id'], $rrow['status'], 'rejected', $note);
        $msg = "❌ @{$rrow['username']} has been rejected.";
    } else {
        $error = "User not found or cannot be rejected at this stage.";
    }
}

// ── Unread notification badge ─────────────────────────────────────────────────
$_adminUnread = get_unread_count($pdo, 'admin');

// ── Deletion of humidity log entries ─────────────────────────────────────────
if (($_GET['action'] ?? '') === 'delete_log') {
    $log_id      = intval($_GET['log_id']      ?? 0);
    $humidity_id = intval($_GET['humidity_id'] ?? 0);
    if ($humidity_id) {
        if ($log_id) {
            $pdo->prepare("DELETE FROM user_logs WHERE log_id=?")->execute([$log_id]);
        } else {
            $pdo->prepare("DELETE FROM user_logs WHERE humidity_id=?")->execute([$humidity_id]);
        }
        $pdo->prepare("DELETE FROM humidity WHERE humidity_id=?")->execute([$humidity_id]);
    }
    redirect_to("admin/dashboard.php?deleted=1"); exit;
}
if (isset($_GET['activated'])) $msg = "Plants assigned and user account activated.";
if (isset($_GET['deleted']))   $msg = "Record deleted successfully.";

// ── Onboarding users (pending OR recommended) ─────────────────────────────────
$onboardingUsers = $pdo->query("
    SELECT user_id, username, email, status, created_at,
           (SELECT COUNT(*) FROM plants WHERE user_id = users.user_id) AS plant_count
    FROM users
    WHERE role='user' AND status IN ('pending','recommended')
    ORDER BY FIELD(status,'recommended','pending'), created_at ASC
")->fetchAll();
$onboardingCount = count($onboardingUsers);

// ── Core stats ────────────────────────────────────────────────────────────────
$users  = $pdo->query("SELECT user_id, username, email, role, COALESCE(status,'active') AS status, created_at FROM users ORDER BY created_at DESC")->fetchAll();
$counts = $pdo->query("SELECT status, COUNT(*) as total FROM humidity GROUP BY status")->fetchAll();
$stats  = array_column($counts, 'total', 'status');
$total  = array_sum(array_column($counts, 'total'));

$plants = $pdo->query("
    SELECT p.plant_id, p.plant_name, p.city, p.created_at,
           u.username,
           (SELECT COUNT(*) FROM humidity h WHERE h.plant_id = p.plant_id) AS reading_count,
           (SELECT h2.humidity_percent FROM humidity h2 WHERE h2.plant_id = p.plant_id ORDER BY h2.recorded_at DESC LIMIT 1) AS last_humidity,
           (SELECT h3.status FROM humidity h3 WHERE h3.plant_id = p.plant_id ORDER BY h3.recorded_at DESC LIMIT 1) AS last_status
    FROM plants p JOIN users u ON p.user_id = u.user_id
    ORDER BY p.plant_id ASC
")->fetchAll();

$humidity = $pdo->query("
    SELECT h.humidity_id, p.plant_name, u.username, h.humidity_percent, h.status, h.recorded_at
    FROM humidity h
    LEFT JOIN plants p ON h.plant_id = p.plant_id
    LEFT JOIN users u  ON p.user_id  = u.user_id
    ORDER BY h.recorded_at DESC LIMIT 200
")->fetchAll();

$logs = $pdo->query("
    SELECT ul.log_id, ul.humidity_id, u.username,
           p.plant_name, h.humidity_percent, h.status, h.recorded_at
    FROM user_logs ul
    JOIN users u    ON ul.user_id     = u.user_id
    JOIN humidity h ON ul.humidity_id = h.humidity_id
    LEFT JOIN plants p ON h.plant_id  = p.plant_id
    ORDER BY h.recorded_at DESC LIMIT 200
")->fetchAll();

// Onboarding audit log
$onboardLog = $pdo->query("
    SELECT ol.*, u.username AS subject, a.username AS actor
    FROM onboarding_log ol
    JOIN users u ON ol.user_id  = u.user_id
    JOIN users a ON ol.actor_id = a.user_id
    ORDER BY ol.created_at DESC LIMIT 50
")->fetchAll();

// ── Chart data ────────────────────────────────────────────────────────────────
$globalHumidity = $pdo->query("
    SELECT DATE(recorded_at) AS day,
           ROUND(AVG(humidity_percent),1) AS avg_pct,
           COUNT(*) AS cnt
    FROM humidity
    WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(recorded_at)
    ORDER BY day ASC
")->fetchAll();

$PALETTE = ['#4f63d8','#0d7c6b','#c0430e','#8b5cf6','#d97706','#059669','#db2777'];
$plantHumidityData = [];
foreach ($plants as $i => $p) {
    $pid = (int)$p['plant_id'];
    $rows = $pdo->prepare("SELECT humidity_percent, status, UNIX_TIMESTAMP(recorded_at) AS ts, recorded_at FROM humidity WHERE plant_id=? ORDER BY recorded_at ASC LIMIT 50");
    $rows->execute([$pid]);
    $plantHumidityData[] = [
        'pid'    => $pid,
        'name'   => $p['plant_name'],
        'color'  => $PALETTE[$i % count($PALETTE)],
        'total'  => (int)$p['reading_count'],
        'latest' => $p['last_humidity'],
        'status' => $p['last_status'],
        'rows'   => $rows->fetchAll(),
    ];
}

$activePage = 'admin_dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard – SuccuTrack</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/css/jquery.dataTables.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/js/jquery.dataTables.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
.pill-rejected{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;display:flex;align-items:center;justify-content:center;padding:16px;}
.modal-box{background:var(--surface,#fff);border-radius:12px;padding:26px 28px;width:100%;max-width:440px;box-shadow:0 8px 32px rgba(0,0,0,.18);}
.modal-box h3{margin:0 0 6px;font-size:1rem;color:var(--text,#0f172a);}
.modal-box p{font-size:.8rem;color:var(--text-3,#64748b);margin:0 0 16px;}
.modal-box textarea{width:100%;border:1px solid var(--border,#e2e8f0);border-radius:8px;padding:10px 12px;font-size:.83rem;resize:vertical;min-height:80px;box-sizing:border-box;font-family:inherit;}
.modal-actions{display:flex;gap:10px;margin-top:16px;justify-content:flex-end;}
.onboard-info-admin{background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:11px 14px;font-size:.8rem;color:#1e40af;margin-bottom:14px;display:flex;align-items:flex-start;gap:8px;}
.timeline{list-style:none;padding:0;margin:0;}
.timeline li{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--border,#e2e8f0);}
.timeline li:last-child{border-bottom:none;}
.tl-dot{width:10px;height:10px;border-radius:50%;margin-top:5px;flex-shrink:0;}
.tl-dot.approved{background:#1a6e3c;}.tl-dot.rejected{background:#dc2626;}
.tl-dot.activated{background:#1656a3;}.tl-dot.pending{background:#f59e0b;}
.tl-body{flex:1;min-width:0;}
.tl-title{font-size:.8rem;font-weight:600;color:var(--text,#0f172a);}
.tl-meta{font-size:.72rem;color:var(--text-3,#94a3b8);margin-top:2px;}
.tl-note{font-size:.75rem;color:var(--text-2,#64748b);margin-top:4px;font-style:italic;}
</style>
</head>
<body class="role-admin">
<div class="app-layout">
  <?php include __DIR__ . '/../components/sidebar.php'; ?>

  <div class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <button class="sb-toggle" onclick="openSidebar()">☰</button>
        <div class="topbar-title">Admin <span>Dashboard</span></div>
      </div>
      <div class="topbar-right">
        <?php if ($_adminUnread > 0): ?>
        <a href="dashboard.php?jumptab=tab-onboarding"
           style="display:inline-flex;align-items:center;gap:5px;background:#eff6ff;border:1px solid #93c5fd;border-radius:20px;padding:3px 11px;font-size:.69rem;font-weight:700;color:#1e40af;text-decoration:none;">
          🔔 <?= $_adminUnread ?> notification<?= $_adminUnread > 1 ? 's' : '' ?>
        </a>
        <?php endif; ?>
        <div class="live-indicator"><span class="dot dot-on"></span> Live</div>
        <span style="font-size:.73rem;color:var(--text-3);">PHT (UTC+8)</span>
      </div>
    </header>

    <div class="page-body">
      <?php if ($msg):   ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

      <div class="pg-header">
        <h1>System Overview</h1>
        <p>Monitor all plants, users, humidity readings, and system logs</p>
      </div>

      <!-- Stats -->
      <div class="stats-grid">
        <div class="stat-card stat-total"><div class="stat-num"><?= $total ?></div><div class="stat-label">📊 Total Readings</div></div>
        <div class="stat-card stat-dry"><div class="stat-num"><?= $stats['Dry'] ?? 0 ?></div><div class="stat-label">🏜️ Dry</div></div>
        <div class="stat-card stat-ideal"><div class="stat-num"><?= $stats['Ideal'] ?? 0 ?></div><div class="stat-label">✅ Ideal</div></div>
        <div class="stat-card stat-humid"><div class="stat-num"><?= $stats['Humid'] ?? 0 ?></div><div class="stat-label">💧 Humid</div></div>
        <div class="stat-card stat-plants"><div class="stat-num"><?= count($plants) ?></div><div class="stat-label">🪴 Plants</div></div>
        <div class="stat-card stat-users"><div class="stat-num"><?= count($users) ?></div><div class="stat-label">👤 Users</div></div>
        <?php if ($onboardingCount > 0): ?>
        <div class="stat-card" style="border-bottom:2px solid #f59e0b;">
          <div class="stat-num" style="color:#b45309;"><?= $onboardingCount ?></div>
          <div class="stat-label">🔔 Needs Action</div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Charts -->
      <div class="two-col" style="margin-bottom:20px;">
        <div class="card" style="margin-bottom:0;">
          <div class="card-header"><div><div class="card-title">📈 Global Humidity Trend</div><div class="card-subtitle">Average % per day · last 30 days</div></div></div>
          <div style="height:210px;"><canvas id="globalHumidityChart"></canvas></div>
        </div>
        <div class="card" style="margin-bottom:0;">
          <div class="card-header"><div><div class="card-title">🍩 Status Distribution</div><div class="card-subtitle">All-time readings</div></div></div>
          <div style="height:210px;"><canvas id="statusDonut"></canvas></div>
        </div>
      </div>

      <!-- Data tables -->
      <div class="card">
        <div class="card-header"><div><div class="card-title">📋 System Data</div><div class="card-subtitle">Browse and manage all records · PHT (UTC+8)</div></div></div>
        <div class="tab-nav">
          <button class="tab-btn" onclick="switchTab('tab-onboarding',event)" style="position:relative;" id="tab-onboarding-btn">
            🔔 Onboarding
            <?php if ($onboardingCount > 0): ?>
            <span style="position:absolute;top:-5px;right:-5px;min-width:16px;height:16px;padding:0 4px;border-radius:10px;background:#f59e0b;color:#fff;font-size:.58rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;"><?= $onboardingCount ?></span>
            <?php endif; ?>
          </button>
          <button class="tab-btn"        onclick="switchTab('tab-log',event)">📜 Audit Log</button>
          <button class="tab-btn active" onclick="switchTab('tab-users',event)">👤 Users (<?= count($users) ?>)</button>
          <button class="tab-btn"        onclick="switchTab('tab-plants',event)">🪴 Plants (<?= count($plants) ?>)</button>
          <button class="tab-btn"        onclick="switchTab('tab-humidity',event)">💧 Readings (<?= count($humidity) ?>)</button>
          <button class="tab-btn"        onclick="switchTab('tab-logs',event)">📋 Logs (<?= count($logs) ?>)</button>
        </div>

        <!-- ── ONBOARDING tab ─────────────────────────────────────────────── -->
        <div class="tab-panel" id="tab-onboarding">
          <?php if (empty($onboardingUsers)): ?>
          <div style="text-align:center;padding:28px 0;">
            <div style="font-size:2rem;margin-bottom:8px;">✅</div>
            <div style="font-size:.86rem;font-weight:600;color:var(--text);margin-bottom:4px;">No pending actions</div>
            <p style="font-size:.74rem;color:var(--text-3);">All users have been processed and plants assigned.</p>
          </div>
          <?php else: ?>
          <div class="onboard-info-admin">
            📋 <strong><?= $onboardingCount ?> user<?= $onboardingCount > 1 ? 's' : '' ?></strong> need your attention.
            Users marked <strong>Awaiting Plants</strong> were approved by a Manager — assign plants to activate them.
            You may also reject any user at this stage.
          </div>
          <div class="table-wrap">
            <table class="det-table">
              <thead>
                <tr><th>#</th><th>Username</th><th>Email</th><th>Registered (PHT)</th><th>Status</th><th>Plants</th><th>Actions</th></tr>
              </thead>
              <tbody>
                <?php foreach ($onboardingUsers as $ou):
                  $si = user_status_label($ou['status']); ?>
                <tr>
                  <td><?= $ou['user_id'] ?></td>
                  <td><strong>@<?= htmlspecialchars($ou['username']) ?></strong></td>
                  <td><?= htmlspecialchars($ou['email']) ?></td>
                  <td><?= date('M d, Y H:i', strtotime($ou['created_at'])) ?></td>
                  <td><span class="status-pill <?= $si['pill'] ?>"><?= $si['label'] ?></span></td>
                  <td><?= $ou['plant_count'] ?> assigned</td>
                  <td>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                      <!-- Assign plants (only for recommended users) -->
                      <?php if ($ou['status'] === 'recommended'): ?>
                      <a href="manage_plants.php?user_id=<?= $ou['user_id'] ?>&username=<?= urlencode($ou['username']) ?>"
                         class="btn btn-primary" style="font-size:.7rem;padding:4px 10px;">
                        🪴 Assign Plants
                      </a>
                      <?php endif; ?>
                      <!-- Reject -->
                      <button type="button" class="btn btn-danger" style="font-size:.7rem;padding:4px 10px;"
                              onclick="openAdminRejectModal(<?= $ou['user_id'] ?>,'<?= htmlspecialchars($ou['username'],ENT_QUOTES) ?>')">
                        ❌ Reject
                      </button>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

        <!-- ── AUDIT LOG tab ──────────────────────────────────────────────── -->
        <div class="tab-panel" id="tab-log">
          <?php if (empty($onboardLog)): ?>
          <div style="text-align:center;padding:24px 0;font-size:.8rem;color:var(--text-3);">No onboarding activity yet.</div>
          <?php else: ?>
          <ul class="timeline" style="padding:12px 16px;">
            <?php foreach ($onboardLog as $entry):
              $dotClass = match($entry['to_status']) {
                'recommended' => 'approved',
                'rejected'    => 'rejected',
                'active'      => 'activated',
                default       => 'pending',
              };
              $actionLabel = match($entry['to_status']) {
                'recommended' => 'approved',
                'rejected'    => 'rejected',
                'active'      => 'activated',
                default       => $entry['to_status'],
              };
            ?>
            <li>
              <div class="tl-dot <?= $dotClass ?>"></div>
              <div class="tl-body">
                <div class="tl-title">
                  @<?= htmlspecialchars($entry['actor']) ?> <?= $actionLabel ?>
                  @<?= htmlspecialchars($entry['subject']) ?>
                </div>
                <div class="tl-meta">
                  <?= date('M d, Y H:i', strtotime($entry['created_at'])) ?> PHT &middot;
                  <?= htmlspecialchars($entry['from_status']) ?> → <?= htmlspecialchars($entry['to_status']) ?>
                </div>
                <?php if ($entry['note']): ?>
                <div class="tl-note">"<?= htmlspecialchars($entry['note']) ?>"</div>
                <?php endif; ?>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>

        <!-- Users tab -->
        <div class="tab-panel active" id="tab-users">
          <div class="table-wrap">
            <table id="dt-users" class="det-table" style="width:100%">
              <thead><tr><th>#</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Joined (PHT)</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($users as $u):
                  $si = user_status_label($u['status'] ?? 'active'); ?>
                <tr>
                  <td><?= $u['user_id'] ?></td>
                  <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                  <td><?= htmlspecialchars($u['email']) ?></td>
                  <td><span class="badge badge-<?= $u['role'] ?>"><?= $u['role'] ?></span></td>
                  <td><span class="status-pill <?= $si['pill'] ?>"><?= $si['label'] ?></span></td>
                  <td data-order="<?= $u['created_at'] ?>"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                  <td>
                    <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
                    <a href="../api/delete_user.php?id=<?= $u['user_id'] ?>" class="btn btn-danger"
                       onclick="return confirm('Delete <?= htmlspecialchars($u['username'],ENT_QUOTES) ?>?')">Delete</a>
                    <?php else: ?><span class="you-label">You</span><?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Plants tab -->
        <div class="tab-panel" id="tab-plants">
          <div class="table-wrap">
            <table id="dt-plants" class="det-table" style="width:100%">
              <thead><tr><th>#</th><th>Plant</th><th>Owner</th><th>City</th><th>Readings</th><th>Last %</th><th>Status</th><th>Added</th></tr></thead>
              <tbody>
                <?php foreach ($plants as $p): ?>
                <tr>
                  <td><?= $p['plant_id'] ?></td>
                  <td><strong>🪴 <?= htmlspecialchars($p['plant_name']) ?></strong></td>
                  <td><?= htmlspecialchars($p['username']) ?></td>
                  <td><?= htmlspecialchars($p['city']) ?></td>
                  <td><?= $p['reading_count'] ?></td>
                  <td><?= $p['last_humidity'] ? $p['last_humidity'] . '%' : '—' ?></td>
                  <td><?php if ($p['last_status']): ?><span class="badge badge-<?= strtolower($p['last_status']) ?>"><?= $p['last_status'] ?></span><?php else: ?>—<?php endif; ?></td>
                  <td data-order="<?= $p['created_at'] ?>"><?= date('M d, Y', strtotime($p['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Humidity tab -->
        <div class="tab-panel" id="tab-humidity">
          <div class="table-wrap">
            <table id="dt-humidity" class="det-table" style="width:100%">
              <thead><tr><th>#</th><th>Plant</th><th>Owner</th><th>Humidity %</th><th>Status</th><th>Recorded At (PHT)</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($humidity as $h): ?>
                <tr>
                  <td><?= $h['humidity_id'] ?></td>
                  <td><?= htmlspecialchars($h['plant_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($h['username']   ?? '—') ?></td>
                  <td><strong><?= $h['humidity_percent'] ?>%</strong></td>
                  <td><span class="badge badge-<?= strtolower($h['status']) ?>"><?= $h['status'] ?></span></td>
                  <td data-order="<?= $h['recorded_at'] ?>"><?= date('M d, Y H:i', strtotime($h['recorded_at'])) ?></td>
                  <td><a href="dashboard.php?action=delete_log&log_id=0&humidity_id=<?= $h['humidity_id'] ?>" class="btn btn-danger" onclick="return confirm('Delete this reading?')">Delete</a></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Logs tab -->
        <div class="tab-panel" id="tab-logs">
          <div class="table-wrap">
            <table id="dt-logs" class="det-table" style="width:100%">
              <thead><tr><th>Log #</th><th>User</th><th>Plant</th><th>Humidity %</th><th>Status</th><th>Recorded At (PHT)</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($logs as $r): ?>
                <tr>
                  <td><?= $r['log_id'] ?></td>
                  <td><?= htmlspecialchars($r['username']) ?></td>
                  <td><?= htmlspecialchars($r['plant_name'] ?? '—') ?></td>
                  <td><strong><?= $r['humidity_percent'] ?>%</strong></td>
                  <td><span class="badge badge-<?= strtolower($r['status']) ?>"><?= $r['status'] ?></span></td>
                  <td data-order="<?= $r['recorded_at'] ?>"><?= date('M d, Y H:i', strtotime($r['recorded_at'])) ?></td>
                  <td><a href="dashboard.php?action=delete_log&log_id=<?= $r['log_id'] ?>&humidity_id=<?= $r['humidity_id'] ?>" class="btn btn-danger" onclick="return confirm('Delete this record?')">Delete</a></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div><!-- /page-body -->
  </div><!-- /main-content -->
</div><!-- /app-layout -->

<!-- ── Admin reject modal ────────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="admin-reject-modal" style="display:none;" onclick="if(event.target===this)closeAdminRejectModal()">
  <div class="modal-box">
    <h3>❌ Reject user</h3>
    <p>Optionally provide a reason. The manager who recommended this user will be notified.</p>
    <form method="POST" id="admin-reject-form">
      <input type="hidden" name="admin_reject_uid"  id="admin-reject-uid-input">
      <textarea name="admin_reject_note" id="admin-reject-note-input" placeholder="Reason for rejection (optional)…"></textarea>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeAdminRejectModal()">Cancel</button>
        <button type="submit" class="btn btn-danger">Confirm Reject</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Reject modal ──────────────────────────────────────────────────────────────
function openAdminRejectModal(uid, username) {
  document.getElementById('admin-reject-uid-input').value  = uid;
  document.getElementById('admin-reject-note-input').value = '';
  document.getElementById('admin-reject-modal').style.display = 'flex';
}
function closeAdminRejectModal() {
  document.getElementById('admin-reject-modal').style.display = 'none';
}

// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(id, ev) {
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  ev.currentTarget.classList.add('active');
  const map = {'tab-users':'#dt-users','tab-plants':'#dt-plants','tab-humidity':'#dt-humidity','tab-logs':'#dt-logs'};
  if (map[id]) $(map[id]).DataTable().columns.adjust().draw(false);
  // Mark admin notifications as read when onboarding tab is opened
  if (id === 'tab-onboarding') {
    fetch('../api/mark_notifications_read.php?role=admin', {method:'POST'}).catch(()=>{});
  }
}

// Auto-jump to onboarding tab if ?jumptab=tab-onboarding
(function(){
  const p = new URLSearchParams(location.search).get('jumptab');
  if (p) {
    const btn = document.getElementById(p + '-btn') ||
                document.querySelector(`[onclick*="'${p}'"]`);
    if (btn) btn.click();
  }
})();

// ── DataTables ────────────────────────────────────────────────────────────────
$(document).ready(function () {
  const opts = {
    pageLength: 10, lengthMenu: [5,10,25,50],
    language: { search:'Search:', lengthMenu:'Show _MENU_ entries',
      info:'Showing _START_–_END_ of _TOTAL_', paginate:{previous:'‹',next:'›'} }
  };
  $('#dt-users').DataTable({ ...opts, order:[[4,'desc']], columnDefs:[{targets:6,orderable:false}] });
  $('#dt-plants').DataTable({ ...opts, order:[[7,'desc']] });
  $('#dt-humidity').DataTable({ ...opts, order:[[5,'desc']], columnDefs:[{targets:6,orderable:false}] });
  $('#dt-logs').DataTable({ ...opts, order:[[5,'desc']], columnDefs:[{targets:6,orderable:false}] });
});

// ── Charts ────────────────────────────────────────────────────────────────────
const FONT = 'inherit';
const gridColor = 'rgba(0,0,0,.05)';
const gDays = <?= json_encode(array_column($globalHumidity, 'day')) ?>;
const gAvg  = <?= json_encode(array_map(fn($r)=>(float)$r['avg_pct'], $globalHumidity)) ?>;
new Chart(document.getElementById('globalHumidityChart'), {
  type:'line',
  data:{labels:gDays,datasets:[{label:'Avg Humidity %',data:gAvg,borderColor:'#4f63d8',backgroundColor:'rgba(79,99,216,.07)',borderWidth:2.5,fill:true,tension:0.4,pointRadius:4,pointBackgroundColor:'#4f63d8',pointBorderColor:'#fff',pointBorderWidth:2}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>' '+c.parsed.y+'%'}}},scales:{x:{ticks:{font:{size:9},maxTicksLimit:9,maxRotation:30},grid:{color:gridColor}},y:{min:0,max:100,ticks:{font:{size:9},callback:v=>v+'%',stepSize:20},grid:{color:gridColor}}}}
});
new Chart(document.getElementById('statusDonut'), {
  type:'doughnut',
  data:{labels:['Dry','Ideal','Humid'],datasets:[{data:[<?= (int)($stats['Dry']??0) ?>,<?= (int)($stats['Ideal']??0) ?>,<?= (int)($stats['Humid']??0) ?>],backgroundColor:['#c0430e','#1a6e3c','#1656a3'],borderColor:'#fff',borderWidth:3,hoverOffset:6}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{font:{size:11},padding:14,usePointStyle:true}}}}
});
</script>
</body>
</html>