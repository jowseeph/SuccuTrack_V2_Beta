<?php
/**
 * config/config.php
 */

define('APP_ROOT', dirname(__DIR__));

if (!defined('APP_BASE')) {
    $docRoot  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appRoot  = str_replace('\\', '/', APP_ROOT);
    $relative = ltrim(str_replace($docRoot, '', $appRoot), '/');
    define('APP_BASE', '/' . $relative);
}

function url_to(string $path): string {
    return rtrim(APP_BASE, '/') . '/' . ltrim($path, '/');
}
function redirect_to(string $path, int $code = 302): void {
    http_response_code($code);
    header('Location: ' . url_to($path));
    exit;
}

define('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);

// ── Database ──────────────────────────────────────────────────────────────────
$host = 'localhost';
$db   = 'u442411629_succulent';
$user = 'u442411629_dev_succulent';
$pass = '%oV0p(24rNz7';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    error_log("DB Connection failed: " . $e->getMessage());
    die("Database unavailable. Please try again later.");
}

// ── Schema migrations ─────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email      VARCHAR(255) NOT NULL,
        username   VARCHAR(80)  NOT NULL,
        password   VARCHAR(255) NOT NULL,
        otp_code   CHAR(6)      NOT NULL,
        attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email   (email),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // NEW: extended status ENUM with full onboarding pipeline states
    $pdo->exec("ALTER TABLE users
        ADD COLUMN IF NOT EXISTS is_verified TINYINT(1) NOT NULL DEFAULT 1");
    $pdo->exec("ALTER TABLE users MODIFY COLUMN status
        ENUM('pending','recommended','rejected','active') NOT NULL DEFAULT 'pending'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        notif_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        for_role    ENUM('admin','manager') NOT NULL,
        type        VARCHAR(40)  NOT NULL,
        title       VARCHAR(160) NOT NULL,
        body        TEXT NOT NULL,
        ref_user_id INT UNSIGNED NULL,
        is_read     TINYINT(1) NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_role_unread (for_role, is_read),
        INDEX idx_ref (ref_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // NEW: audit trail for all status changes
    $pdo->exec("CREATE TABLE IF NOT EXISTS onboarding_log (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED NOT NULL,
        actor_id    INT UNSIGNED NOT NULL,
        from_status VARCHAR(30) NOT NULL,
        to_status   VARCHAR(30) NOT NULL,
        note        TEXT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

} catch (PDOException $e) {
    // Non-fatal
}

// ── OTP constants ─────────────────────────────────────────────────────────────
define('OTP_LENGTH',          6);
define('OTP_EXPIRY_MINUTES', 10);
define('OTP_MAX_ATTEMPTS',    5);

// ── SMTP ──────────────────────────────────────────────────────────────────────
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'succutrack@gmail.com');
define('SMTP_PASS', 'oypvvdxsrpzcrwqk');
define('SMTP_FROM', 'succutrack@gmail.com');
define('SMTP_NAME', 'SuccuTrack');
define('OTP_DEV_MODE', false);

// ── Status helper: human-readable labels + pill CSS class ────────────────────
function user_status_label(string $status): array {
    return match($status) {
        'pending'     => ['label' => '⏳ Pending Review',       'pill' => 'pill-pending'],
        'recommended' => ['label' => '📋 Awaiting Plants',      'pill' => 'pill-recommended'],
        'rejected'    => ['label' => '❌ Rejected',             'pill' => 'pill-rejected'],
        'active'      => ['label' => '✅ Active',               'pill' => 'pill-active'],
        default       => ['label' => ucfirst($status),          'pill' => 'pill-active'],
    };
}

// ── Onboarding log writer ─────────────────────────────────────────────────────
function log_status_change(PDO $pdo, int $userId, int $actorId, string $from, string $to, string $note = ''): void {
    $pdo->prepare(
        "INSERT INTO onboarding_log (user_id, actor_id, from_status, to_status, note)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$userId, $actorId, $from, $to, $note]);
}

// ── Notification helpers ──────────────────────────────────────────────────────
function notify_managers_new_user(PDO $pdo, int $newUserId, string $username): void {
    $pdo->prepare(
        "INSERT INTO notifications (for_role, type, title, body, ref_user_id)
         VALUES ('manager','new_user',?,?,?)"
    )->execute([
        "New user registered: @{$username}",
        "User @{$username} has verified their email and is awaiting your review.",
        $newUserId,
    ]);
}

function notify_admins_recommended(PDO $pdo, int $userId, string $username, string $managerName): void {
    $pdo->prepare(
        "INSERT INTO notifications (for_role, type, title, body, ref_user_id)
         VALUES ('admin','recommended',?,?,?)"
    )->execute([
        "User approved — assign plants: @{$username}",
        "Manager @{$managerName} approved @{$username}. Please assign plants to activate their account.",
        $userId,
    ]);
}

// NEW: notify manager when admin rejects a user they recommended
function notify_manager_rejected(PDO $pdo, int $userId, string $username, string $adminName): void {
    $pdo->prepare(
        "INSERT INTO notifications (for_role, type, title, body, ref_user_id)
         VALUES ('manager','rejected',?,?,?)"
    )->execute([
        "User rejected by Admin: @{$username}",
        "Admin @{$adminName} rejected @{$username} after your recommendation.",
        $userId,
    ]);
}

// NEW: notify user's manager when admin activates a user
function notify_manager_activated(PDO $pdo, int $userId, string $username): void {
    $pdo->prepare(
        "INSERT INTO notifications (for_role, type, title, body, ref_user_id)
         VALUES ('manager','activated',?,?,?)"
    )->execute([
        "User activated: @{$username}",
        "Admin has assigned plants to @{$username}. Their account is now active.",
        $userId,
    ]);
}

function get_unread_count(PDO $pdo, string $role): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE for_role=? AND is_read=0");
    $s->execute([$role]);
    return (int) $s->fetchColumn();
}

function count_pending_users(PDO $pdo): int {
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='user' AND status='pending'")->fetchColumn();
}

function count_actionable_users(PDO $pdo): int {
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='user' AND status IN('pending','recommended')")->fetchColumn();
}

// ── smtp_send() ───────────────────────────────────────────────────────────────
function smtp_send(string $to, string $toName, string $subject, string $htmlBody, string $textBody): array {
    $host = SMTP_HOST; $port = SMTP_PORT; $user = SMTP_USER;
    $pass = SMTP_PASS; $from = SMTP_FROM; $name = SMTP_NAME;
    $timeout = 15;
    if (!$user || !$pass) return ['ok' => false, 'error' => 'smtp_not_configured'];
    try {
        $ctx  = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $sock = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) return ['ok' => false, 'error' => "Cannot connect: {$errstr}"];
        stream_set_timeout($sock, $timeout);
        $cmd = function(string $c) use ($sock): string {
            if ($c !== '') fwrite($sock, $c . "\r\n");
            $r = '';
            while ($line = fgets($sock, 512)) { $r .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
            return $r;
        };
        $expect = function(string $r, string $code): void {
            if (substr(trim($r), 0, 3) !== $code)
                throw new RuntimeException("SMTP error (expected {$code}): " . trim($r));
        };
        $expect($cmd(''), '220');
        $expect($cmd("EHLO " . gethostname()), '250');
        $expect($cmd("STARTTLS"), '220');
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT))
            throw new RuntimeException("TLS handshake failed");
        $expect($cmd("EHLO " . gethostname()), '250');
        $expect($cmd("AUTH LOGIN"), '334');
        $expect($cmd(base64_encode($user)), '334');
        $expect($cmd(base64_encode($pass)), '235');
        $expect($cmd("MAIL FROM:<{$from}>"), '250');
        $expect($cmd("RCPT TO:<{$to}>"), '250');
        $b = '=_' . md5(uniqid('', true));
        $h  = "Date: " . date('r') . "\r\nMessage-ID: <" . uniqid('st', true) . "@succutrack.app>\r\n";
        $h .= "From: {$name} <{$from}>\r\nTo: {$toName} <{$to}>\r\nSubject: {$subject}\r\n";
        $h .= "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$b}\"\r\nX-Mailer: SuccuTrack/PHP\r\n";
        $body  = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($textBody) . "\r\n--{$b}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($htmlBody) . "\r\n--{$b}--\r\n";
        $expect($cmd("DATA"), '354');
        $msg = str_replace("\n.", "\n..", $h . "\r\n" . $body);
        $expect($cmd($msg . "\r\n."), '250');
        $cmd("QUIT"); fclose($sock);
        return ['ok' => true];
    } catch (RuntimeException $e) {
        if (isset($sock) && is_resource($sock)) fclose($sock);
        error_log("[SuccuTrack SMTP] " . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ── send_otp_email() ──────────────────────────────────────────────────────────
function send_otp_email(PDO $pdo, string $email, string $username, string $hashed_password): array {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        return ['success' => false, 'error' => 'invalid_email'];

    $pdo->prepare("DELETE FROM email_verifications WHERE email=? OR expires_at < NOW()")->execute([$email]);
    $otp = '';
    for ($i = 0; $i < OTP_LENGTH; $i++) $otp .= (string) random_int(0, 9);
    $expires = date('Y-m-d H:i:s', time() + OTP_EXPIRY_MINUTES * 60);
    $pdo->prepare("INSERT INTO email_verifications (email,username,password,otp_code,attempts,expires_at) VALUES(?,?,?,?,0,?)")
        ->execute([$email, $username, $hashed_password, $otp, $expires]);

    if (OTP_DEV_MODE) {
        error_log("[SuccuTrack DEV] OTP for {$email}: {$otp}");
        return ['success' => true, 'otp' => $otp, 'dev_mode' => true];
    }

    $year = date('Y'); $expMin = OTP_EXPIRY_MINUTES;
    $subj = "[SuccuTrack] Your verification code: {$otp}";
    $safe = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f1f3f7;font-family:Arial,sans-serif;">
  <div style="max-width:480px;margin:32px auto;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 2px 12px rgba(0,0,0,.08);">
    <div style="background:linear-gradient(135deg,#0d1f14,#1a5235);padding:26px 30px 22px;">
      <div style="font-size:1.2rem;font-weight:700;color:#fff;">🌵 SuccuTrack</div>
      <div style="font-size:.8rem;color:rgba(255,255,255,.5);margin-top:3px;">Email Verification</div>
    </div>
    <div style="padding:26px 30px;">
      <h2 style="font-size:1rem;color:#0f172a;margin:0 0 8px;">Hi {$safe},</h2>
      <p style="font-size:.85rem;color:#64748b;line-height:1.65;margin:0 0 20px;">Enter the code below to verify your email and activate your SuccuTrack account.</p>
      <div style="text-align:center;margin:0 0 20px;">
        <div style="display:inline-block;background:#f0f5f2;border:2px solid #8fceaa;border-radius:10px;padding:14px 40px;">
          <div style="font-size:.65rem;font-weight:700;color:#1a6e3c;letter-spacing:.12em;text-transform:uppercase;margin-bottom:8px;">Your Verification Code</div>
          <div style="font-size:2.6rem;font-weight:700;color:#1a6e3c;letter-spacing:.28em;font-family:monospace;">{$otp}</div>
        </div>
      </div>
      <p style="font-size:.79rem;color:#94a3b8;margin:0 0 6px;">⏱ Expires in <strong style="color:#0f172a;">{$expMin} minutes</strong>.</p>
      <p style="font-size:.79rem;color:#94a3b8;margin:0;">If you did not request this, you can safely ignore this email.</p>
    </div>
    <div style="padding:13px 30px;border-top:1px solid #e2e8f0;background:#f6f7fa;">
      <p style="font-size:.7rem;color:#94a3b8;margin:0;">&copy; {$year} SuccuTrack &middot; Manolo Fortich, Bukidnon</p>
    </div>
  </div>
</body></html>
HTML;
    $text = "Hi {$username},\n\nYour SuccuTrack verification code is:\n\n  {$otp}\n\nExpires in {$expMin} minutes.\n\nIf you did not request this, ignore this email.\n\n© {$year} SuccuTrack";

    $result = smtp_send($email, $username, $subj, $html, $text);
    if (!$result['ok']) {
        error_log("[SuccuTrack] First SMTP attempt failed: " . $result['error'] . " — retrying…");
        sleep(1);
        $result = smtp_send($email, $username, $subj, $html, $text);
    }
    if ($result['ok']) return ['success' => true, 'otp' => $otp];

    error_log("[SuccuTrack] SMTP failed (both attempts) for {$email}: " . $result['error']);
    return ['success' => true, 'otp' => $otp, 'dev_mode' => true, 'smtp_error' => $result['error']];
}