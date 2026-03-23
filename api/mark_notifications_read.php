<?php
/**
 * api/mark_notifications_read.php
 * Called via fetch() when a notification tab is opened.
 */
session_start();
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }

$role = $_GET['role'] ?? '';
if (!in_array($role, ['admin','manager'], true)) { http_response_code(400); exit; }

// Only allow marking your own role's notifications
if ($_SESSION['role'] !== $role) { http_response_code(403); exit; }

$pdo->prepare("UPDATE notifications SET is_read=1 WHERE for_role=? AND is_read=0")
    ->execute([$role]);

http_response_code(200);
echo json_encode(['ok' => true]);
