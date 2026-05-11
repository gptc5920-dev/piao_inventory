<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';

$_SESSION['success_message'] = 'Permanent deletion is disabled. Transactions stay in trash for audit history.';
header('Location: trash.php');
exit;
