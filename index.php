<?php
/**
 * index.php — Project root entry point
 * Auto-detects the subfolder and redirects to auth/login.php
 */
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
header("Location: {$base}/auth/login.php");
exit;
