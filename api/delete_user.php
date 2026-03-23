<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    redirect_to("auth/login.php"); exit;
}
require_once __DIR__ . '/../config/config.php';
$id = intval($_GET['id'] ?? 0);
if ($id && $id !== $_SESSION['user_id']) {
    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$id]);
}
redirect_to("admin/manage_users.php?deleted=1");
exit;
