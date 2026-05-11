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

osaeits_ensure_transaction_trash_table($pdo);

try {
    $stmt = $pdo->prepare("SELECT * FROM transaction_trash WHERE id = ? AND restored_at IS NULL LIMIT 1");
    $stmt->execute([$id]);
    $trash = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trash) {
        $_SESSION['success_message'] = 'Trash item not found or already restored.';
        header('Location: ' . $redirect);
        exit;
    }

    $restoreError = osaeits_validate_transaction_restore($pdo, $trash);
    if ($restoreError !== '') {
        $_SESSION['success_message'] = $restoreError;
        header('Location: ' . $redirect);
        exit;
    }

    $pdo->beginTransaction();
    $referenceNumber = osaeits_clean_inventory_text($trash['reference_number'] ?? '');
    if ($referenceNumber === '') {
        $referenceNumber = osaeits_generate_transaction_reference(
            $pdo,
            (string)$trash['item_type'],
            (string)$trash['transaction_type']
        );
    }

    $stmt = $pdo->prepare("
        INSERT INTO transactions
            (item_type, item_id, transaction_type, quantity, unit_price, total_amount, reference_number, notes, user_id, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        (string)$trash['item_type'],
        (int)$trash['item_id'],
        (string)$trash['transaction_type'],
        (int)$trash['quantity'],
        (float)$trash['unit_price'],
        (float)$trash['total_amount'],
        $referenceNumber,
        $trash['notes'] ?? null,
        (int)$trash['user_id'],
        $trash['original_created_at'] ?: date('Y-m-d H:i:s'),
    ]);
    $newTransactionId = (int)$pdo->lastInsertId();

    osaeits_apply_transaction_effects($pdo, [
        'item_type' => $trash['item_type'],
        'item_id' => (int)$trash['item_id'],
        'transaction_type' => $trash['transaction_type'],
        'quantity' => (int)$trash['quantity'],
    ]);

    $stmt = $pdo->prepare("UPDATE transaction_trash SET restored_at = NOW(), restored_by = ? WHERE id = ?");
    $stmt->execute([(int)$_SESSION['user_id'], $id]);

    $pdo->commit();

    require_once __DIR__ . '/../includes/activity-log.php';
    log_activity($pdo, (int)$_SESSION['user_id'], 'transaction.restore', 'transaction', $newTransactionId, [
        'trash_id' => $id,
        'original_transaction_id' => (int)$trash['original_transaction_id'],
        'item_type' => $trash['item_type'],
        'item_id' => (int)$trash['item_id'],
        'transaction_type' => $trash['transaction_type'],
        'quantity' => (int)$trash['quantity'],
    ]);

    $_SESSION['success_message'] = 'Transaction restored.';
    $redirect = 'inventory.php?' . http_build_query([
        'item_type' => (string)$trash['item_type'],
        'item_id' => (int)$trash['item_id'],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['success_message'] = 'Unable to restore transaction.';
}

header('Location: ' . $redirect);
exit;
