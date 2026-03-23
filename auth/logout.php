<?php
/**
 * auth/logout.php
 * Destroys session and redirects to login.
 */
session_start();
session_unset();
session_destroy();
require_once __DIR__ . '/../config/config.php';
redirect_to('auth/login.php');
