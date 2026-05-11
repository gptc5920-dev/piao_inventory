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
        $stmt = $pdo->prepare('SELECT * FROM supplies WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $itemCode = osaeits_ensure_item_identifier($pdo, 'supply', $id);
            $row['item_code'] = $itemCode;
            $title = trim($itemCode . ' - ' . (string)$row['name'], ' -');
            $trashId = osaeits_move_entity_to_trash($pdo, 'supply', $id, $title, $row, (int)$_SESSION['user_id']);
            $pdo->prepare('DELETE FROM supplies WHERE id = ?')->execute([$id]);
            $moved = true;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }

    if ($moved) {
        require_once __DIR__ . '/../includes/activity-log.php';
        log_activity($pdo, (int)$_SESSION['user_id'], 'supply.delete', 'supply', $id, [
            'name' => $row['name'],
            'item_code' => $itemCode,
            'trash_id' => $trashId,
        ]);
    }
}
$_SESSION['success_message'] = $moved ? 'Supply moved to trash.' : 'Unable to move supply to trash.';
header('Location: supplies.php');
exit;
