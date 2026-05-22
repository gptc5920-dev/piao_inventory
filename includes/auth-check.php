<?php
/**
 * Require login - include at top of every protected page
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? 'pages/dashboard.php';
    header('Location: login.php?redirect=' . urlencode($redirect));
    exit;
}
require_once __DIR__ . '/access-control.php';
if (isset($pdo) && $pdo instanceof PDO) {
    osaeits_refresh_session_access($pdo);
    osaeits_enforce_current_route_access($pdo);
}
