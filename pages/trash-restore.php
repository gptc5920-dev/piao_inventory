<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$redirect = 'trash.php';

if ($id <= 0) {
    $_SESSION['success_message'] = 'Invalid trash item.';
    header('Location: ' . $redirect);
    exit;
}

osaeits_ensure_trash_records_table($pdo);

try {
    $stmt = $pdo->prepare("SELECT * FROM trash_records WHERE id = ? AND restored_at IS NULL LIMIT 1");
    $stmt->execute([$id]);
    $trash = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trash) {
        $_SESSION['success_message'] = 'Trash item not found or already restored.';
        header('Location: ' . $redirect);
        exit;
    }

    $restorePermission = [
        'barangay_official' => 'barangay_officials',
        'user' => 'users',
    ][(string)$trash['entity_type']] ?? '';
    if ($restorePermission !== '' && !osaeits_can_access($restorePermission)) {
        $_SESSION['success_message'] = 'You do not have access to restore this record.';
        header('Location: ' . $redirect);
        exit;
    }

    $pdo->beginTransaction();
    $restoreError = osaeits_restore_entity_from_trash($pdo, $trash, (int)$_SESSION['user_id']);
    if ($restoreError !== '') {
        $pdo->rollBack();
        $_SESSION['success_message'] = $restoreError;
        header('Location: ' . $redirect);
        exit;
    }
    $pdo->commit();

    require_once __DIR__ . '/../includes/activity-log.php';
    log_activity($pdo, (int)$_SESSION['user_id'], 'trash.restore', (string)$trash['entity_type'], (int)$trash['entity_id'], [
        'trash_id' => $id,
        'title' => $trash['title'],
    ]);

    $_SESSION['success_message'] = 'Record restored.';
    $redirectMap = osaeits_entity_redirect_map();
    $redirect = $redirectMap[(string)$trash['entity_type']] ?? 'trash.php';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['success_message'] = 'Unable to restore record.';
}

header('Location: ' . $redirect);
exit;
