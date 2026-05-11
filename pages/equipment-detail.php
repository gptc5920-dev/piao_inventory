<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';
require_once __DIR__ . '/../includes/audit-display-helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['success_message'] = 'Equipment item not found.';
    header('Location: equipment.php');
    exit;
}

$page_title = 'Equipment detail';
$current_page = 'equipment';
$base_url = '../';

$equipmentSummarySql = osaeits_equipment_movement_summary_sql();
$equipmentCodeExpr = osaeits_item_code_select_expr($pdo, 'e', 'equipment');
$detailStmt = $pdo->prepare("
    SELECT
        e.*,
        {$equipmentCodeExpr} AS item_code,
        COALESCE(tx.purchase_quantity, 0) AS total_purchased,
        COALESCE(tx.purchase_total, 0) AS total_purchase_amount,
        COALESCE(tx.issued_balance, 0) AS issued_balance,
        tx.last_purchase_at,
        tx.last_movement_at,
        COALESCE(NULLIF(tx.latest_purchase_price, ''), e.purchase_price, 0) AS latest_purchase_price
    FROM equipment e
    LEFT JOIN ({$equipmentSummarySql}) tx ON tx.item_id = e.id
    WHERE e.id = ?
    LIMIT 1
");
$detailStmt->execute([$id]);
$equipment = $detailStmt->fetch(PDO::FETCH_ASSOC);
if (!$equipment) {
    $_SESSION['success_message'] = 'Equipment item not found.';
    header('Location: equipment.php');
    exit;
}

$historyBaseSql = "
    SELECT
        t.*,
        u.first_name,
        u.last_name,
        ai.assigned_to AS issue_assigned_to,
        ai.assigned_area AS issue_assigned_area,
        ai.appropriation AS issue_appropriation,
        ai.assigned_date AS issue_assigned_date
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN assign_items ai ON ai.id = (
        SELECT ai2.id
        FROM assign_items ai2
        WHERE ai2.item_type = 'inventory'
          AND ai2.item_ref_id = t.id
        ORDER BY ai2.id DESC
        LIMIT 1
    )
    WHERE t.item_type = 'equipment'
      AND t.item_id = ?
";
$historyStmt = $pdo->prepare($historyBaseSql . " AND t.transaction_type = 'purchase' ORDER BY t.created_at DESC, t.id DESC");
$historyStmt->execute([$id]);
$purchaseHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$historyStmt = $pdo->prepare($historyBaseSql . " AND t.transaction_type = 'issue' ORDER BY t.created_at DESC, t.id DESC");
$historyStmt->execute([$id]);
$issueHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$historyStmt = $pdo->prepare($historyBaseSql . " AND t.transaction_type = 'return' ORDER BY t.created_at DESC, t.id DESC");
$historyStmt->execute([$id]);
$returnHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$historyStmt = $pdo->prepare($historyBaseSql . "
    AND (
        t.transaction_type = 'adjustment'
        OR LOWER(COALESCE(t.notes, '')) LIKE '%damag%'
        OR LOWER(COALESCE(t.notes, '')) LIKE '%lost%'
    )
    ORDER BY t.created_at DESC, t.id DESC
");
$historyStmt->execute([$id]);
$damagedLostRecords = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$assignmentStmt = $pdo->prepare("
    SELECT a.*, u.first_name, u.last_name
    FROM assign_items a
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN transactions t ON a.item_type = 'inventory' AND a.item_ref_id = t.id
    WHERE (
            a.item_type = 'equipment'
            AND a.item_ref_id = ?
        )
        OR (
            a.item_type = 'inventory'
            AND t.item_type = 'equipment'
            AND t.item_id = ?
            AND t.transaction_type = 'issue'
        )
    ORDER BY a.assigned_date DESC, a.id DESC
");
$assignmentStmt->execute([$id, $id]);
$assignmentHistory = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);

$auditStmt = $pdo->prepare("
    SELECT a.*, u.first_name, u.last_name, u.username
    FROM activity_logs a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE (a.entity_type = 'equipment' AND a.entity_id = ?)
       OR (
            a.entity_type = 'transaction'
            AND a.details LIKE ?
            AND (
                a.details LIKE ?
                OR a.details LIKE ?
                OR a.details LIKE ?
            )
       )
       OR (
            a.entity_type = 'assign_item'
            AND a.details LIKE ?
            AND (
                a.details LIKE ?
                OR a.details LIKE ?
                OR a.details LIKE ?
            )
       )
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT 50
");
$auditStmt->execute([
    $id,
    '%"item_type":"equipment"%',
    '%"item_id":' . $id . ',%',
    '%"item_id":' . $id . '}%',
    '%"item_id":"' . $id . '"%',
    '%"item_type":"equipment"%',
    '%"item_ref_id":' . $id . ',%',
    '%"item_ref_id":' . $id . '}%',
    '%"item_ref_id":"' . $id . '"%',
]);
$auditTrail = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

$formatActor = static function (array $row): string {
    $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
    return $name !== '' ? $name : (trim((string)($row['username'] ?? '')) ?: '-');
};
$formatTransactionNotes = static function (array $row): string {
    $parts = [];
    $notes = trim((string)($row['notes'] ?? ''));
    if ($notes !== '') {
        $parts[] = $notes;
    }
    if (!empty($row['issue_assigned_to'])) {
        $assignment = 'Issued to: ' . $row['issue_assigned_to'];
        if (!empty($row['issue_assigned_area'])) {
            $assignment .= ' | Area: ' . $row['issue_assigned_area'];
        }
        if (!empty($row['issue_appropriation'])) {
            $assignment .= ' | Appropriation: ' . $row['issue_appropriation'];
        }
        if (!empty($row['issue_assigned_date'])) {
            $assignment .= ' | Date: ' . date('M j, Y', strtotime((string)$row['issue_assigned_date']));
        }
        $parts[] = $assignment;
    }

    return $parts !== [] ? implode(' | ', $parts) : '-';
};
$renderHistory = static function (string $title, array $rows, string $empty, callable $formatActor, callable $formatNotes): void {
?>
    <div class="mb-3">
        <h6 class="font-weight-bold text-secondary"><?= htmlspecialchars($title) ?></h6>
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Type</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                        <th>Recorded by</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" class="text-center text-muted"><?= htmlspecialchars($empty) ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars(date('M j, Y H:i', strtotime((string)$row['created_at']))) ?></td>
                                <td><?= htmlspecialchars($row['reference_number'] ?? '-') ?></td>
                                <td><?= htmlspecialchars(ucfirst((string)$row['transaction_type'])) ?></td>
                                <td><?= (int)$row['quantity'] ?></td>
                                <td>PHP <?= number_format((float)$row['unit_price'], 2) ?></td>
                                <td>PHP <?= number_format((float)$row['total_amount'], 2) ?></td>
                                <td><?= htmlspecialchars($formatActor($row)) ?></td>
                                <td><?= htmlspecialchars($formatNotes($row)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php
};

$isIssued = (int)($equipment['issued_balance'] ?? 0) > 0;
$displayName = osaeits_equipment_display_name($equipment);
$variantsHref = 'equipment-variants.php?' . http_build_query(['name' => osaeits_normalize_inventory_text((string)$equipment['name'])]);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <h6 class="m-0 font-weight-bold text-primary"><?= htmlspecialchars($displayName) ?></h6>
            <p class="small text-muted mb-0"><?= htmlspecialchars((string)$equipment['item_code']) ?> &middot; Dedicated equipment item page</p>
        </div>
        <div>
            <a href="<?= htmlspecialchars($variantsHref) ?>" class="btn btn-sm btn-outline-secondary">Back to assets</a>
            <a href="equipment.php" class="btn btn-sm btn-outline-secondary">Back to equipment</a>
            <a href="equipment-form.php?id=<?= (int)$equipment['id'] ?>" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-pen"></i> Edit equipment
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="border rounded p-3 mb-3">
            <div class="row">
                <div class="col-md-3 col-6 mb-2"><div class="small text-muted">Item name</div><div class="font-weight-bold"><?= htmlspecialchars($displayName) ?></div></div>
                <div class="col-md-2 col-6 mb-2"><div class="small text-muted">Category</div><div class="font-weight-bold"><?= htmlspecialchars((string)$equipment['category']) ?></div></div>
                <div class="col-md-2 col-6 mb-2"><div class="small text-muted">Serial</div><div class="font-weight-bold"><?= htmlspecialchars($equipment['serial_number'] ?: '-') ?></div></div>
                <div class="col-md-2 col-6 mb-2"><div class="small text-muted">Status</div><div class="font-weight-bold"><?= htmlspecialchars(ucfirst((string)$equipment['status'])) ?></div></div>
                <div class="col-md-3 col-6 mb-2"><div class="small text-muted">Inventory state</div><span class="badge badge-<?= $isIssued ? 'info' : 'light' ?>"><?= $isIssued ? 'Issued' : 'Available' ?></span></div>
            </div>
            <div class="row mt-2">
                <div class="col-md-3 col-6 mb-2"><div class="small text-muted">Brand / Model</div><div class="h6 mb-0"><?= htmlspecialchars(trim((string)($equipment['brand'] ?? '') . ' / ' . (string)($equipment['model'] ?? ''), ' /') ?: '-') ?></div></div>
                <div class="col-md-3 col-6 mb-2"><div class="small text-muted">Location</div><div class="h6 mb-0"><?= htmlspecialchars($equipment['location'] ?: '-') ?></div></div>
                <div class="col-md-3 col-6 mb-2"><div class="small text-muted">Person Incharge</div><div class="h6 mb-0"><?= htmlspecialchars($equipment['person_incharge'] ?: '-') ?></div></div>
                <div class="col-md-3 col-6 mb-2"><div class="small text-muted">Latest price</div><div class="h6 mb-0">PHP <?= number_format((float)$equipment['latest_purchase_price'], 2) ?></div></div>
            </div>
        </div>

        <?php $renderHistory('Purchase history', $purchaseHistory, 'No purchase history for this equipment.', $formatActor, $formatTransactionNotes); ?>
        <?php $renderHistory('Issue history', $issueHistory, 'No issue history for this equipment.', $formatActor, $formatTransactionNotes); ?>
        <?php $renderHistory('Return history', $returnHistory, 'No return history for this equipment.', $formatActor, $formatTransactionNotes); ?>
        <?php $renderHistory('Damaged/lost records', $damagedLostRecords, 'No damaged or lost records for this equipment.', $formatActor, $formatTransactionNotes); ?>

        <div class="mb-3">
            <h6 class="font-weight-bold text-secondary">Assignment history</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="thead-light">
                        <tr>
                            <th>Date</th>
                            <th>Assigned To</th>
                            <th>Area</th>
                            <th>Appropriation</th>
                            <th>Status</th>
                            <th>Recorded by</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($assignmentHistory)): ?>
                            <tr><td colspan="7" class="text-center text-muted">No assignment history for this equipment.</td></tr>
                        <?php else: ?>
                            <?php foreach ($assignmentHistory as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('M j, Y', strtotime((string)$row['assigned_date']))) ?></td>
                                    <td><?= htmlspecialchars((string)$row['assigned_to']) ?></td>
                                    <td><?= htmlspecialchars($row['assigned_area'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($row['appropriation'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars(ucfirst((string)$row['status'])) ?></td>
                                    <td><?= htmlspecialchars($formatActor($row)) ?></td>
                                    <td><?= htmlspecialchars($row['notes'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mb-0">
            <h6 class="font-weight-bold text-secondary">Audit trail</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="thead-light">
                        <tr>
                            <th>Date</th>
                            <th>Action</th>
                            <th>User</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($auditTrail)): ?>
                            <tr><td colspan="4" class="text-center text-muted">No audit records for this equipment yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($auditTrail as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('M j, Y H:i', strtotime((string)$row['created_at']))) ?></td>
                                    <td><?= htmlspecialchars(osaeits_audit_action_label((string)$row['action'])) ?></td>
                                    <td><?= htmlspecialchars($formatActor($row)) ?></td>
                                    <td class="small text-break" style="max-width: 420px;"><?= htmlspecialchars(osaeits_audit_details_summary((string)$row['action'], $row['entity_type'] ?? null, $row['details'] ?? null)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
