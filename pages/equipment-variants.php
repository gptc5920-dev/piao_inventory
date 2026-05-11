<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

$productName = osaeits_normalize_inventory_text($_GET['name'] ?? '');
if ($productName === '') {
    $_SESSION['success_message'] = 'Equipment product not found.';
    header('Location: equipment.php');
    exit;
}

$page_title = 'Equipment assets';
$current_page = 'equipment';
$base_url = '../';

$equipmentSummarySql = osaeits_equipment_movement_summary_sql();
$equipmentCodeExpr = osaeits_item_code_select_expr($pdo, 'e', 'equipment');
$priceExpr = "COALESCE(NULLIF(tx.latest_purchase_price, ''), e.purchase_price, 0)";

$stmt = $pdo->prepare("
    SELECT
        e.id,
        {$equipmentCodeExpr} AS item_code,
        e.name,
        e.description,
        e.category,
        e.serial_number,
        e.model,
        e.brand,
        e.status,
        e.location,
        e.purok_area,
        e.appropriation,
        e.person_incharge,
        {$priceExpr} AS purchase_price,
        COALESCE(tx.issued_balance, 0) AS issued_balance,
        tx.last_movement_at
    FROM equipment e
    LEFT JOIN ({$equipmentSummarySql}) tx ON tx.item_id = e.id
    WHERE LOWER(TRIM(e.name)) = ?
    ORDER BY
        CASE WHEN COALESCE(TRIM(e.description), '') = '' THEN 1 ELSE 0 END,
        e.description ASC,
        e.brand ASC,
        e.model ASC,
        e.serial_number ASC,
        e.id ASC
");
$stmt->execute([$productName]);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($assets)) {
    $_SESSION['success_message'] = 'Equipment product not found.';
    header('Location: equipment.php');
    exit;
}

$productDisplayName = trim((string)($assets[0]['name'] ?? 'Equipment'));
$issuedCount = 0;
$serviceableCount = 0;
foreach ($assets as $asset) {
    if ((int)($asset['issued_balance'] ?? 0) > 0) {
        $issuedCount++;
    }
    if (osaeits_normalize_equipment_status((string)($asset['status'] ?? '')) === 'servicable') {
        $serviceableCount++;
    }
}
$availableCount = count($assets) - $issuedCount;
$unserviceableCount = count($assets) - $serviceableCount;

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between">
        <div>
            <h6 class="m-0 font-weight-bold text-primary"><?= htmlspecialchars($productDisplayName) ?> Assets</h6>
            <p class="small text-muted mb-0"><?= count($assets) ?> asset(s) &middot; <?= $availableCount ?> available &middot; <?= $issuedCount ?> issued</p>
        </div>
        <div>
            <a href="equipment.php" class="btn btn-sm btn-outline-secondary">Back to equipment</a>
            <a href="equipment-form.php" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Add Equipment</a>
        </div>
    </div>
    <div class="card-body">
        <div class="border rounded p-3 mb-3">
            <div class="row">
                <div class="col-md-3 col-6 mb-2">
                    <div class="small text-muted">Product</div>
                    <div class="font-weight-bold"><?= htmlspecialchars($productDisplayName) ?></div>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="small text-muted">Assets</div>
                    <div class="font-weight-bold"><?= count($assets) ?></div>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="small text-muted">Available / Issued</div>
                    <div class="font-weight-bold"><?= $availableCount ?> / <?= $issuedCount ?></div>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="small text-muted">Condition</div>
                    <span class="badge badge-success"><?= $serviceableCount ?> serviceable</span>
                    <span class="badge badge-secondary"><?= $unserviceableCount ?> unserviceable</span>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr>
                        <th width="55">No.</th>
                        <th>Code</th>
                        <th>Asset</th>
                        <th>Serial</th>
                        <th>Brand / Model</th>
                        <th>Condition</th>
                        <th>Inventory State</th>
                        <th>Location</th>
                        <th>Person Incharge</th>
                        <th>Purchase Price</th>
                        <th>Last Issue/Return</th>
                        <th width="170">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assets as $idx => $asset): ?>
                        <?php
                            $detailHref = 'equipment-detail.php?' . http_build_query(['id' => (int)$asset['id']]);
                            $isIssued = (int)($asset['issued_balance'] ?? 0) > 0;
                            $condition = osaeits_normalize_equipment_status((string)($asset['status'] ?? ''));
                            $lastMovement = !empty($asset['last_movement_at']) ? date('M j, Y', strtotime((string)$asset['last_movement_at'])) : '-';
                            $brandModel = trim((string)($asset['brand'] ?? '') . ' / ' . (string)($asset['model'] ?? ''), ' /');
                        ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><?= htmlspecialchars((string)$asset['item_code']) ?></td>
                            <td>
                                <a href="<?= htmlspecialchars($detailHref) ?>" class="font-weight-bold">
                                    <?= htmlspecialchars(osaeits_equipment_display_name($asset)) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($asset['serial_number'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($brandModel !== '' ? $brandModel : '-') ?></td>
                            <td><span class="badge badge-<?= $condition === 'servicable' ? 'success' : 'secondary' ?>"><?= $condition === 'servicable' ? 'Servicable' : 'Unservicable' ?></span></td>
                            <td><span class="badge badge-<?= $isIssued ? 'info' : 'light' ?>"><?= $isIssued ? 'Issued' : 'Available' ?></span></td>
                            <td><?= htmlspecialchars($asset['location'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($asset['person_incharge'] ?: '-') ?></td>
                            <td>PHP <?= number_format((float)$asset['purchase_price'], 2) ?></td>
                            <td><?= htmlspecialchars($lastMovement) ?></td>
                            <td class="table-actions">
                                <a href="<?= htmlspecialchars($detailHref) ?>" class="btn btn-sm btn-outline-secondary btn-icon-action" title="Open equipment detail" aria-label="Open equipment detail">
                                    <i class="fas fa-exchange-alt"></i>
                                </a>
                                <a href="equipment-form.php?id=<?= (int)$asset['id'] ?>" class="btn btn-sm btn-info btn-icon-action" title="Edit" aria-label="Edit equipment">
                                    <i class="fas fa-pen"></i>
                                </a>
                                <a href="equipment-delete.php?id=<?= (int)$asset['id'] ?>" class="btn btn-sm btn-danger btn-icon-action" data-confirm="Move this equipment asset to trash?" title="Move to trash" aria-label="Move equipment asset to trash">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
