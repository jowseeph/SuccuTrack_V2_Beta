<?php
// ── Timezone ──────────────────────────────────────────────────────────────────
// Set the PHP timezone to Asia/Manila (UTC+8) for all timestamp operations.
// This ensures date() / strtotime() produce correct Philippine time in every
// page that includes this file.  If you ever move the deployment outside PH,
// change only this one constant.
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

    // Keep MySQL session timezone in sync with PHP so that NOW() / TIMESTAMP
    // columns returned via PDO already carry the correct local time.
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    // Never expose raw DB credentials / connection strings in production.
    error_log("DB Connection failed: " . $e->getMessage());
    die("Database unavailable. Please try again later.");
}
