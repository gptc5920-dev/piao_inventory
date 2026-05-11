<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

/**
 * Revert the stock effect of an assignment being deleted.
 * Mirrors applyAssignmentEffects in assign-item-form.php with direction = -1.
 */
function revertAssignmentEffects(PDO $pdo, string $itemType, int $itemRefId, string $status, int $qty): void
{
    osaeits_apply_assignment_effects($pdo, $itemType, $itemRefId, $status, $qty, -1);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$moved = false;
if ($id > 0) {
    osaeits_ensure_trash_records_table($pdo);
    $stmt = $pdo->prepare("SELECT * FROM assign_items WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        try {
            $pdo->beginTransaction();

            revertAssignmentEffects($pdo, (string)$row['item_type'], (int)$row['item_ref_id'], (string)$row['status'], (int)$row['quantity']);
            $trashTitle = trim((string)$row['assigned_to']) !== '' ? (string)$row['assigned_to'] : ('Assignment #' . $id);
            $trashId = osaeits_move_entity_to_trash($pdo, 'assign_item', $id, $trashTitle, $row, (int)$_SESSION['user_id']);
            $pdo->prepare("DELETE FROM assign_items WHERE id = ?")->execute([$id]);
            $moved = true;

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }

        if ($moved) {
            require_once __DIR__ . '/../includes/activity-log.php';
            log_activity($pdo, (int)$_SESSION['user_id'], 'assign_item.delete', 'assign_item', $id, [
                'item_type' => $row['item_type'],
                'item_ref_id' => (int)$row['item_ref_id'],
                'assigned_to' => $row['assigned_to'],
                'trash_id' => $trashId ?? null,
            ]);
        }
    }
}

$_SESSION['success_message'] = $moved ? 'Assigned item moved to trash.' : 'Unable to move assigned item to trash.';
header('Location: assign-items.php');
exit;
