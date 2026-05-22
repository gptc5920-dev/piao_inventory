<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$moved = false;
if ($id > 0) {
    osaeits_ensure_trash_records_table($pdo);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM barangay_officials WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $name = trim($row['first_name'] . ' ' . $row['last_name']);
            $trashId = osaeits_move_entity_to_trash($pdo, 'barangay_official', $id, $name, $row, (int)$_SESSION['user_id']);
            $pdo->prepare("DELETE FROM barangay_officials WHERE id = ?")->execute([$id]);
            $moved = true;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }

    if ($moved) {
        require_once __DIR__ . '/../includes/activity-log.php';
        log_activity($pdo, (int)$_SESSION['user_id'], 'official.delete', 'barangay_official', $id, [
            'name' => $name,
            'position_title' => $row['position_title'],
            'trash_id' => $trashId,
        ]);
    }
}

$_SESSION['success_message'] = $moved ? 'Barangay official moved to trash.' : 'Unable to move barangay official to trash.';
header('Location: barangay-officials.php');
exit;
