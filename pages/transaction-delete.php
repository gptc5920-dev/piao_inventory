<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$redirect = 'inventory.php';

if ($id <= 0) {
    $_SESSION['success_message'] = 'Invalid transaction.';
    header('Location: ' . $redirect);
    exit;
}

try {
    osaeits_ensure_transaction_trash_table($pdo);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        $pdo->rollBack();
        $_SESSION['success_message'] = 'Transaction not found.';
        header('Location: ' . $redirect);
        exit;
    }

    // Reverse effects, keep a recoverable snapshot, then remove from active transactions.
    osaeits_apply_transaction_effects($pdo, $tx, -1);
    $trashId = osaeits_move_transaction_to_trash(
        $pdo,
        $tx,
        (int)$_SESSION['user_id'],
        trim((string)($_GET['reason'] ?? '')) ?: null
    );
    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    $pdo->commit();
    require_once __DIR__ . '/../includes/activity-log.php';
    log_activity($pdo, (int)$_SESSION['user_id'], 'transaction.delete', 'transaction', $id, [
        'trash_id' => $trashId,
        'snapshot' => $tx,
    ]);
    $_SESSION['success_message'] = 'Transaction moved to trash. You can restore it from Trash if needed.';
    $it = (string)($tx['item_type'] ?? '');
    $iid = (int)($tx['item_id'] ?? 0);
    if (in_array($it, ['supply', 'equipment'], true) && $iid > 0) {
        $redirect .= '?' . http_build_query(['item_type' => $it, 'item_id' => $iid]);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['success_message'] = 'Unable to move transaction to trash.';
}

header('Location: ' . $redirect);
exit;
