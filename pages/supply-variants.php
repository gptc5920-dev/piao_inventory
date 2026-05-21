<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

$productName = osaeits_normalize_inventory_text($_GET['name'] ?? '');
if ($productName === '') {
    $_SESSION['success_message'] = 'Supply product not found.';
    header('Location: supplies.php');
    exit;
}

$page_title = 'Supply variants';
$current_page = 'supplies';
$base_url = '../';

$purchaseSummarySql = osaeits_supply_movement_summary_sql();
$stockExpr = osaeits_supply_stock_expression('tx', 's');
$supplyCodeExpr = osaeits_item_code_select_expr($pdo, 's', 'supply');

$stmt = $pdo->prepare("
    SELECT
        s.id,
        {$supplyCodeExpr} AS item_code,
        s.name,
        s.description,
        s.category,
        s.unit,
        {$stockExpr} AS current_stock,
        s.minimum_stock,
        COALESCE(tx.purchase_quantity, 0) AS purchase_quantity,
        tx.last_purchase_at
    FROM supplies s
    LEFT JOIN ({$purchaseSummarySql}) tx ON tx.item_id = s.id
    WHERE LOWER(TRIM(s.name)) = ?
    ORDER BY
        CASE WHEN COALESCE(TRIM(s.description), '') = '' THEN 1 ELSE 0 END,
        s.description ASC,
        s.unit ASC,
        s.id ASC
");
$stmt->execute([$productName]);
$variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($variants)) {
    $_SESSION['success_message'] = 'Supply product not found.';
    header('Location: supplies.php');
    exit;
}

$productDisplayName = trim((string)($variants[0]['name'] ?? 'Supply'));
$totalStock = 0;
$totalPurchased = 0;
$totalMinimum = 0;
foreach ($variants as $variant) {
    $totalStock += (int)($variant['current_stock'] ?? 0);
    $totalPurchased += (int)($variant['purchase_quantity'] ?? 0);
    $totalMinimum = max($totalMinimum, (int)($variant['minimum_stock'] ?? 0));
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between">
        <div>
            <h6 class="m-0 font-weight-bold text-primary"><?= htmlspecialchars($productDisplayName) ?> Variants</h6>
            <p class="small text-muted mb-0"><?= count($variants) ?> variant(s) &middot; <?= $totalStock ?> total stock &middot; <?= $totalPurchased ?> purchased</p>
        </div>
        <div>
            <a href="supplies.php" class="btn btn-sm btn-outline-secondary">Back to supplies</a>
            <a href="supply-form.php" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Add Supply</a>
        </div>
    </div>
    <div class="card-body">
        <div class="border rounded p-3 mb-3">
            <div class="row">
                <div class="col-md-3 col-6 mb-2">
                    <div class="small text-muted">Product</div>
                    <div class="font-weight-bold"><?= htmlspecialchars($productDisplayName) ?></div>
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <div class="small text-muted">Variants</div>
                    <div class="font-weight-bold"><?= count($variants) ?></div>
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <div class="small text-muted">Total stock</div>
                    <div class="font-weight-bold"><?= $totalStock ?></div>
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <div class="small text-muted">Product minimum</div>
                    <div class="font-weight-bold"><?= $totalMinimum ?></div>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="small text-muted">Stock alert</div>
                    <span class="badge badge-<?= $totalStock <= 0 ? 'danger' : ($totalStock <= $totalMinimum ? 'warning' : 'success') ?>">
                        <?= $totalStock <= 0 ? 'Stock Out' : ($totalStock <= $totalMinimum ? 'Low Stock' : 'OK') ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="table-responsive" style="max-height: 600px; overflow-x: auto; overflow-y: auto; border: 1px solid #dee2e6;">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr>
                        <th width="55">No.</th>
                        <th>Code</th>
                        <th>Variant</th>
                        <th>Unit</th>
                        <th>Stock</th>
                        <th>Purchased</th>
                        <th>Product Min</th>
                        <th>Status</th>
                        <th>Last Purchase</th>
                        <th width="170">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($variants as $idx => $variant): ?>
                        <?php
                            $currentStock = (int)($variant['current_stock'] ?? 0);
                            $minimumStock = $totalMinimum;
                            $isOut = $currentStock <= 0;
                            $isLow = $currentStock <= $minimumStock;
                            $detailHref = 'supply-detail.php?' . http_build_query(['id' => (int)$variant['id']]);
                            $lastPurchase = !empty($variant['last_purchase_at']) ? date('M j, Y', strtotime((string)$variant['last_purchase_at'])) : '-';
                        ?>
                        <tr class="<?= $isOut ? 'row-stock-out' : ($isLow ? 'row-low-stock' : '') ?>">
                            <td><?= $idx + 1 ?></td>
                            <td><?= htmlspecialchars((string)$variant['item_code']) ?></td>
                            <td>
                                <a href="<?= htmlspecialchars($detailHref) ?>" class="font-weight-bold">
                                    <?= htmlspecialchars(osaeits_supply_display_name($variant)) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars((string)$variant['unit']) ?></td>
                            <td><?= $currentStock ?></td>
                            <td><?= (int)($variant['purchase_quantity'] ?? 0) ?></td>
                            <td><?= $minimumStock ?></td>
                            <td>
                                <?php if ($isOut): ?>
                                    <span class="badge badge-danger">Stock Out</span>
                                <?php elseif ($isLow): ?>
                                    <span class="badge badge-warning">Low Stock</span>
                                <?php else: ?>
                                    <span class="badge badge-success">OK</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($lastPurchase) ?></td>
                            <td class="table-actions">
                                <a href="<?= htmlspecialchars($detailHref) ?>" class="btn btn-sm btn-outline-secondary btn-icon-action" title="Open supply detail" aria-label="Open supply detail">
                                    <i class="fas fa-exchange-alt"></i>
                                </a>
                                <a href="supply-form.php?id=<?= (int)$variant['id'] ?>" class="btn btn-sm btn-info btn-icon-action" title="Edit" aria-label="Edit supply">
                                    <i class="fas fa-pen"></i>
                                </a>
                                <a href="supply-delete.php?id=<?= (int)$variant['id'] ?>" class="btn btn-sm btn-danger btn-icon-action" data-confirm="Move this supply variant to trash?" title="Move to trash" aria-label="Move supply variant to trash">
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
