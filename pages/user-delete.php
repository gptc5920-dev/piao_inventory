<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$moved = false;
if ($id > 0 && $id != $_SESSION['user_id']) {
    osaeits_ensure_trash_records_table($pdo);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $trashId = osaeits_move_entity_to_trash($pdo, 'user', $id, (string)$row['username'], $row, (int)$_SESSION['user_id']);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $moved = true;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }

    if ($moved) {
        require_once __DIR__ . '/../includes/activity-log.php';
        log_activity($pdo, (int)$_SESSION['user_id'], 'user.delete', 'user', $id, [
            'deleted_username' => $row['username'],
            'deleted_email' => $row['email'],
            'trash_id' => $trashId,
        ]);
    }
}
$_SESSION['success_message'] = $moved ? 'User moved to trash.' : 'Unable to move user to trash.';
header('Location: users.php');
exit;
