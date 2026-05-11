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
