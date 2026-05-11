<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

$page_title = 'Assign Items';
$current_page = 'assign_items';
$base_url = '../';

$item_type = trim($_GET['item_type'] ?? '');
$status = trim($_GET['status'] ?? '');

$where = ['1=1'];
$params = [];

if (in_array($item_type, ['supply', 'equipment', 'inventory'], true)) {
    $where[] = 'a.item_type = ?';
    $params[] = $item_type;
}
if (in_array($status, ['assigned', 'returned'], true)) {
    $where[] = 'a.status = ?';
    $params[] = $status;
}

$supplyCodeExpr = osaeits_item_code_select_expr($pdo, 's', 'supply');
$equipmentCodeExpr = osaeits_item_code_select_expr($pdo, 'e', 'equipment');
$sql = "SELECT a.*, u.first_name, u.last_name,
               {$supplyCodeExpr} AS supply_code,
               s.name AS supply_name,
               {$equipmentCodeExpr} AS equipment_code,
               e.name AS equipment_name,
               e.serial_number AS equipment_serial,
               t.transaction_type,
               t.item_type AS tx_item_type,
               t.item_id AS tx_item_id
        FROM assign_items a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN supplies s ON a.item_type = 'supply' AND a.item_ref_id = s.id
        LEFT JOIN equipment e ON a.item_type = 'equipment' AND a.item_ref_id = e.id
        LEFT JOIN transactions t ON a.item_type = 'inventory' AND a.item_ref_id = t.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.assigned_date DESC, a.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<?php if (!empty($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <?= htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
    </div>
<?php endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between">
        <h6 class="m-0 font-weight-bold text-primary">Assigned Items</h6>
        <a href="assign-item-form.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Assign Item</a>
    </div>
    <div class="card-body">
        <form method="get" class="form-inline mb-3">
            <select name="item_type" class="form-control form-control-sm mr-2">
                <option value="">All Types</option>
                <option value="supply" <?= $item_type === 'supply' ? 'selected' : '' ?>>Supply</option>
                <option value="equipment" <?= $item_type === 'equipment' ? 'selected' : '' ?>>Equipment</option>
                <option value="inventory" <?= $item_type === 'inventory' ? 'selected' : '' ?>>Inventory</option>
            </select>
            <select name="status" class="form-control form-control-sm mr-2">
                <option value="">All Status</option>
                <option value="assigned" <?= $status === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                <option value="returned" <?= $status === 'returned' ? 'selected' : '' ?>>Returned</option>
            </select>
            <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
            <a href="assign-items.php" class="btn btn-sm btn-light ml-2">Reset</a>
        </form>

        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Assigned To</th>
                        <th>Area</th>
                        <th>Appropriation</th>
                        <th>Status</th>
                        <th>By</th>
                        <th width="120">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="10" class="text-center text-muted">No assignment records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $itemLabel = '-';
                                if ($r['item_type'] === 'supply') {
                                    $itemLabel = trim((string)($r['supply_code'] ?? '') . ' - ' . (string)($r['supply_name'] ?? ('Supply #' . (int)$r['item_ref_id'])), ' -');
                                } elseif ($r['item_type'] === 'equipment') {
                                    $itemLabel = trim((string)($r['equipment_code'] ?? '') . ' - ' . (string)($r['equipment_name'] ?? ('Equipment #' . (int)$r['item_ref_id'])), ' -');
                                    if (!empty($r['equipment_serial'])) {
                                        $itemLabel .= ' (' . $r['equipment_serial'] . ')';
                                    }
                                } else {
                                    $itemLabel = 'Transaction #' . (int)$r['item_ref_id'];
                                    if (!empty($r['transaction_type'])) {
                                        $itemLabel .= ' - ' . ucfirst((string)$r['transaction_type']);
                                    }
                                }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($r['assigned_date']))) ?></td>
                                <td><?= htmlspecialchars(ucfirst((string)$r['item_type'])) ?></td>
                                <td><?= htmlspecialchars($itemLabel) ?></td>
                                <td><?= (int)$r['quantity'] ?></td>
                                <td><?= htmlspecialchars($r['assigned_to']) ?></td>
                                <td><?= htmlspecialchars($r['assigned_area'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($r['appropriation'] ?: '-') ?></td>
                                <td><span class="badge badge-<?= $r['status'] === 'assigned' ? 'info' : 'secondary' ?>"><?= htmlspecialchars(ucfirst((string)$r['status'])) ?></span></td>
                                <td><?= htmlspecialchars(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: '-') ?></td>
                                <td class="table-actions">
                                    <a href="assign-item-form.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-info btn-icon-action" title="Edit" aria-label="Edit assignment"><i class="fas fa-pen"></i></a>
                                    <a href="assign-item-delete.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-danger btn-icon-action" data-confirm="Move this assignment to trash?" title="Move to trash" aria-label="Move assignment to trash"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
