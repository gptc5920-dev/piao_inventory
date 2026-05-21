<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';
require_once __DIR__ . '/../includes/audit-display-helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['success_message'] = 'Supply item not found.';
    header('Location: supplies.php');
    exit;
}

$page_title = 'Supply detail';
$current_page = 'supplies';
$base_url = '../';

$supplySummarySql = osaeits_supply_movement_summary_sql();
$stockExpr = osaeits_supply_stock_expression('tx', 's');
$supplyCodeExpr = osaeits_item_code_select_expr($pdo, 's', 'supply');
$detailStmt = $pdo->prepare("
    SELECT
        s.*,
        {$supplyCodeExpr} AS item_code,
        {$stockExpr} AS current_stock,
        COALESCE(tx.purchase_quantity, 0) AS total_purchased,
        COALESCE(tx.purchase_total, 0) AS total_purchase_amount,
        COALESCE(issue.total_issued, 0) + COALESCE(assigned.total_assigned, 0) AS total_issued
    FROM supplies s
    LEFT JOIN ({$supplySummarySql}) tx ON tx.item_id = s.id
    LEFT JOIN (
        SELECT item_id, SUM(quantity) AS total_issued
        FROM transactions
        WHERE item_type = 'supply' AND transaction_type = 'issue'
        GROUP BY item_id
    ) issue ON issue.item_id = s.id
    LEFT JOIN (
        SELECT item_ref_id, SUM(quantity) AS total_assigned
        FROM assign_items
        WHERE item_type = 'supply' AND status = 'assigned'
        GROUP BY item_ref_id
    ) assigned ON assigned.item_ref_id = s.id
    WHERE s.id = ?
    LIMIT 1
");
$detailStmt->execute([$id]);
$supply = $detailStmt->fetch(PDO::FETCH_ASSOC);
if (!$supply) {
    $_SESSION['success_message'] = 'Supply item not found.';
    header('Location: supplies.php');
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
    WHERE t.item_type = 'supply'
      AND t.item_id = ?
";
$historyStmt = $pdo->prepare($historyBaseSql . " AND t.transaction_type = 'purchase' ORDER BY t.created_at DESC, t.id DESC");
$historyStmt->execute([$id]);
$purchaseHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$historyStmt = $pdo->prepare($historyBaseSql . " AND t.transaction_type = 'issue' ORDER BY t.created_at DESC, t.id DESC");
$historyStmt->execute([$id]);
$issuanceHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$assignmentStmt = $pdo->prepare("
    SELECT
        a.id,
        a.assigned_date AS created_at,
        CONCAT('ASG-', a.id) AS reference_number,
        'issue' AS transaction_type,
        a.quantity,
        0 AS unit_price,
        0 AS total_amount,
        CONCAT('Issued to: ', a.assigned_to,
            CASE WHEN COALESCE(a.assigned_area, '') <> '' THEN CONCAT(' | Area: ', a.assigned_area) ELSE '' END,
            CASE WHEN COALESCE(a.notes, '') <> '' THEN CONCAT(' | ', a.notes) ELSE '' END
        ) AS notes,
        u.first_name,
        u.last_name
    FROM assign_items a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE a.item_type = 'supply'
      AND a.item_ref_id = ?
      AND a.status = 'assigned'
    ORDER BY a.assigned_date DESC, a.id DESC
");
$assignmentStmt->execute([$id]);
$issuanceHistory = array_merge($issuanceHistory, $assignmentStmt->fetchAll(PDO::FETCH_ASSOC));
usort($issuanceHistory, static function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

$historyStmt = $pdo->prepare($historyBaseSql . " AND t.transaction_type = 'adjustment' ORDER BY t.created_at DESC, t.id DESC");
$historyStmt->execute([$id]);
$adjustmentHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

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

$auditStmt = $pdo->prepare("
    SELECT a.*, u.first_name, u.last_name, u.username
    FROM activity_logs a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE (a.entity_type = 'supply' AND a.entity_id = ?)
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
    '%"item_type":"supply"%',
    '%"item_id":' . $id . ',%',
    '%"item_id":' . $id . '}%',
    '%"item_id":"' . $id . '"%',
    '%"item_type":"supply"%',
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
    if (($row['transaction_type'] ?? '') === 'purchase' && $notes !== '') {
        $noteLines = preg_split('/\R/', $notes) ?: [];
        $noteLines = array_values(array_filter($noteLines, static function (string $line): bool {
            return !preg_match('/^\s*(supplier|detail)\s*:/i', $line);
        }));
        $notes = trim(implode("\n", $noteLines));
    }
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
$formatPurchaseSupplier = static function (array $row) use ($supply): string {
    $notes = (string)($row['notes'] ?? '');
    foreach (preg_split('/\R/', $notes) ?: [] as $line) {
        if (preg_match('/^\s*Supplier\s*:\s*(.+)$/i', $line, $matches)) {
            $supplier = trim((string)$matches[1]);
            return $supplier !== '' ? $supplier : '-';
        }
    }

    $supplier = trim((string)($supply['supplier'] ?? ''));
    return $supplier !== '' ? $supplier : '-';
};
$formatSupplyAuditDetails = static function (array $row): string {
    $details = $row['details'] ?? null;
    $decoded = json_decode((string)$details, true);
    if (is_array($decoded)
        && ($row['entity_type'] ?? '') === 'transaction'
        && ($decoded['item_type'] ?? '') === 'supply') {
        unset($decoded['supplier'], $decoded['detail']);
        $details = json_encode($decoded);
    }

    return osaeits_audit_details_summary((string)$row['action'], $row['entity_type'] ?? null, $details);
};
$renderHistory = static function (string $title, array $rows, string $empty, string $unit, callable $formatActor, callable $formatNotes, bool $showSupplier = false, $formatSupplier = null): void {
    $emptyColspan = $showSupplier ? 9 : 8;
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
                        <?php if ($showSupplier): ?>
                            <th>Supplier</th>
                        <?php endif; ?>
                        <th>Recorded by</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="<?= $emptyColspan ?>" class="text-center text-muted"><?= htmlspecialchars($empty) ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars(date('M j, Y H:i', strtotime((string)$row['created_at']))) ?></td>
                                <td><?= htmlspecialchars($row['reference_number'] ?? '-') ?></td>
                                <td><?= htmlspecialchars(ucfirst((string)$row['transaction_type'])) ?></td>
                                <td><?= (int)$row['quantity'] ?> <?= htmlspecialchars($unit) ?></td>
                                <td>PHP <?= number_format((float)$row['unit_price'], 2) ?></td>
                                <td>PHP <?= number_format((float)$row['total_amount'], 2) ?></td>
                                <?php if ($showSupplier): ?>
                                    <td><?= htmlspecialchars(is_callable($formatSupplier) ? $formatSupplier($row) : '-') ?></td>
                                <?php endif; ?>
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

$displayName = osaeits_supply_display_name($supply);
$unit = (string)($supply['unit'] ?? '');
$currentStock = max(0, (int)($supply['current_stock'] ?? 0));
$minimumStock = osaeits_supply_product_minimum_stock($pdo, (string)($supply['name'] ?? ''));
$totalPurchased = max(0, (int)($supply['total_purchased'] ?? 0));
$totalIssued = max(0, (int)($supply['total_issued'] ?? 0));
$isLow = $currentStock <= $minimumStock;

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <h6 class="m-0 font-weight-bold text-primary"><?= htmlspecialchars($displayName) ?></h6>
            <p class="small text-muted mb-0"><?= htmlspecialchars((string)$supply['item_code']) ?> &middot; Dedicated supply item page</p>
        </div>
        <div>
            <a href="supplies.php" class="btn btn-sm btn-outline-secondary">Back to supplies</a>
            <a href="supply-form.php?id=<?= (int)$supply['id'] ?>" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-pen"></i> Edit supply
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="border rounded p-3 mb-3">
            <div class="row">
                <div class="col-md-3 col-6 mb-2">
                    <div class="small text-muted">Item name</div>
                    <div class="font-weight-bold"><?= htmlspecialchars($displayName) ?></div>
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <div class="small text-muted">Category</div>
                    <div class="font-weight-bold"><?= htmlspecialchars((string)$supply['category']) ?></div>
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <div class="small text-muted">Unit</div>
                    <div class="font-weight-bold"><?= htmlspecialchars($unit) ?></div>
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <div class="small text-muted">Current stock</div>
                    <div class="font-weight-bold"><?= $currentStock ?> <?= htmlspecialchars($unit) ?></div>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="small text-muted">Stock alert</div>
                    <span class="badge badge-<?= $currentStock <= 0 ? 'danger' : ($isLow ? 'warning' : 'success') ?>">
                        <?= $currentStock <= 0 ? 'Stock Out' : ($isLow ? 'Low Stock' : 'OK') ?>
                    </span>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-2 col-6 mb-2"><div class="small text-muted">Product minimum</div><div class="h6 mb-0"><?= $minimumStock ?></div></div>
                <div class="col-md-2 col-6 mb-2"><div class="small text-muted">Total purchased</div><div class="h6 mb-0"><?= $totalPurchased ?></div></div>
                <div class="col-md-2 col-6 mb-2"><div class="small text-muted">Total issued</div><div class="h6 mb-0"><?= $totalIssued ?></div></div>
                <div class="col-md-2 col-6 mb-2"><div class="small text-muted">Remaining balance</div><div class="h6 mb-0"><?= $currentStock ?></div></div>
                <div class="col-md-4 col-12 mb-2">
                    <div class="small text-muted">Supplier</div>
                    <div class="h6 mb-0"><?= htmlspecialchars(trim((string)($supply['supplier'] ?? '')) ?: '-') ?></div>
                </div>
            </div>
        </div>

        <?php $renderHistory('Purchase history', $purchaseHistory, 'No purchase history for this supply.', $unit, $formatActor, $formatTransactionNotes, true, $formatPurchaseSupplier); ?>
        <?php $renderHistory('Issuance history', $issuanceHistory, 'No issuance history for this supply.', $unit, $formatActor, $formatTransactionNotes); ?>
        <?php $renderHistory('Adjustment history', $adjustmentHistory, 'No adjustment history for this supply.', $unit, $formatActor, $formatTransactionNotes); ?>
        <?php $renderHistory('Damaged/lost records', $damagedLostRecords, 'No damaged or lost records for this supply.', $unit, $formatActor, $formatTransactionNotes); ?>

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
                            <tr><td colspan="4" class="text-center text-muted">No audit records for this supply yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($auditTrail as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('M j, Y H:i', strtotime((string)$row['created_at']))) ?></td>
                                    <td><?= htmlspecialchars(osaeits_audit_action_label((string)$row['action'])) ?></td>
                                    <td><?= htmlspecialchars($formatActor($row)) ?></td>
                                    <td class="small text-break" style="max-width: 420px;"><?= htmlspecialchars($formatSupplyAuditDetails($row)) ?></td>
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
