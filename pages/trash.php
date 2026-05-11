<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

$page_title = 'Trash';
$current_page = 'trash';
$base_url = '../';

osaeits_ensure_transaction_trash_table($pdo);
osaeits_ensure_trash_records_table($pdo);

$stmt = $pdo->query("
    SELECT tt.*,
           u.first_name AS user_first_name,
           u.last_name AS user_last_name,
           du.first_name AS deleted_first_name,
           du.last_name AS deleted_last_name,
           s.name AS supply_name,
           s.description AS supply_description,
           e.name AS equipment_name,
           e.serial_number AS equipment_serial
    FROM transaction_trash tt
    LEFT JOIN users u ON tt.user_id = u.id
    LEFT JOIN users du ON tt.deleted_by = du.id
    LEFT JOIN supplies s ON tt.item_type = 'supply' AND tt.item_id = s.id
    LEFT JOIN equipment e ON tt.item_type = 'equipment' AND tt.item_id = e.id
    WHERE tt.restored_at IS NULL
    ORDER BY tt.deleted_at DESC, tt.id DESC
    LIMIT 300
");
$trashItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("
    SELECT tr.*,
           du.first_name AS deleted_first_name,
           du.last_name AS deleted_last_name
    FROM trash_records tr
    LEFT JOIN users du ON tr.deleted_by = du.id
    WHERE tr.restored_at IS NULL
    ORDER BY tr.deleted_at DESC, tr.id DESC
    LIMIT 300
");
$recordTrashItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <h6 class="m-0 font-weight-bold text-primary">Trash</h6>
            <p class="small text-muted mb-0">Deleted records are retained permanently unless restored.</p>
        </div>
        <a href="inventory.php?transaction_type=purchase" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-receipt"></i> Purchase history
        </a>
    </div>
    <div class="card-body">
        <?php if (!empty($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <?= htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <h6 class="font-weight-bold text-secondary">Transaction trash</h6>
        <div class="table-responsive mb-4">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr>
                        <th>Deleted</th>
                        <th>Original Date</th>
                        <th>Reference</th>
                        <th>Type</th>
                        <th>Item</th>
                        <th>Quantity</th>
                        <th>Total (₱)</th>
                        <th>Deleted by</th>
                        <th>Notes</th>
                        <th width="80">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trashItems as $item): ?>
                        <?php
                            $itemName = $item['item_type'] === 'supply'
                                ? ($item['supply_name'] ?? ('Supply #' . (int)$item['item_id']))
                                : ($item['equipment_name'] ?? ('Equipment #' . (int)$item['item_id']));
                            if ($item['item_type'] === 'supply' && !empty($item['supply_description'])) {
                                $itemName .= ' - ' . $item['supply_description'];
                            }
                            if ($item['item_type'] === 'equipment' && !empty($item['equipment_serial'])) {
                                $itemName .= ' (' . $item['equipment_serial'] . ')';
                            }
                            $deletedBy = trim((string)($item['deleted_first_name'] ?? '') . ' ' . (string)($item['deleted_last_name'] ?? ''));
                        ?>
                        <tr>
                            <td><?= htmlspecialchars(date('M j, Y H:i', strtotime((string)$item['deleted_at']))) ?></td>
                            <td><?= $item['original_created_at'] ? htmlspecialchars(date('M j, Y H:i', strtotime((string)$item['original_created_at']))) : '-' ?></td>
                            <td><?= htmlspecialchars($item['reference_number'] ?? '-') ?></td>
                            <td><?= htmlspecialchars(ucfirst((string)$item['transaction_type'])) ?></td>
                            <td>
                                <span class="badge badge-<?= $item['item_type'] === 'supply' ? 'primary' : 'info' ?> mr-1">
                                    <?= $item['item_type'] === 'supply' ? 'Supply' : 'Equipment' ?>
                                </span>
                                <?= htmlspecialchars($itemName) ?>
                            </td>
                            <td><?= (int)$item['quantity'] ?></td>
                            <td>₱<?= number_format((float)$item['total_amount'], 2) ?></td>
                            <td><?= htmlspecialchars($deletedBy !== '' ? $deletedBy : '-') ?></td>
                            <td><?= htmlspecialchars($item['notes'] ?? '-') ?></td>
                            <td class="table-actions">
                                <a href="transaction-restore.php?id=<?= (int)$item['id'] ?>" class="btn btn-sm btn-success btn-icon-action" data-confirm="Restore this transaction? This will apply its inventory effect again." title="Restore" aria-label="Restore">
                                    <i class="fas fa-undo"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (empty($trashItems)): ?>
            <p class="text-muted">No deleted transactions.</p>
        <?php endif; ?>

        <h6 class="font-weight-bold text-secondary">Record trash</h6>
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr>
                        <th>Deleted</th>
                        <th>Type</th>
                        <th>Record</th>
                        <th>Deleted by</th>
                        <th width="80">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recordTrashItems as $item): ?>
                        <?php
                            $typeLabels = [
                                'supply' => 'Supply',
                                'equipment' => 'Equipment',
                                'assign_item' => 'Assigned item',
                                'barangay_official' => 'Barangay official',
                                'user' => 'User',
                            ];
                            $deletedBy = trim((string)($item['deleted_first_name'] ?? '') . ' ' . (string)($item['deleted_last_name'] ?? ''));
                        ?>
                        <tr>
                            <td><?= htmlspecialchars(date('M j, Y H:i', strtotime((string)$item['deleted_at']))) ?></td>
                            <td><?= htmlspecialchars($typeLabels[$item['entity_type']] ?? ucfirst((string)$item['entity_type'])) ?></td>
                            <td><?= htmlspecialchars((string)$item['title']) ?></td>
                            <td><?= htmlspecialchars($deletedBy !== '' ? $deletedBy : '-') ?></td>
                            <td class="table-actions">
                                <a href="trash-restore.php?id=<?= (int)$item['id'] ?>" class="btn btn-sm btn-success btn-icon-action" data-confirm="Restore this record?" title="Restore" aria-label="Restore">
                                    <i class="fas fa-undo"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (empty($recordTrashItems)): ?>
            <p class="text-muted mb-0">No deleted records.</p>
        <?php endif; ?>

        <?php if (empty($trashItems) && empty($recordTrashItems)): ?>
            <p class="text-muted mb-0">Trash is empty.</p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
