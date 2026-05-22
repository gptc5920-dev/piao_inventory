<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

$page_title = 'Dashboard';
$current_page = 'dashboard';
$base_url = '../';

// Stats
$supplyMovementSummarySql = osaeits_supply_movement_summary_sql();
$supplyStockExpr = osaeits_supply_stock_expression('tx', 's');
$lowStockProductsSql = "
    SELECT
        MIN(s.name) AS name,
        CASE
            WHEN COUNT(DISTINCT COALESCE(NULLIF(TRIM(s.unit), ''), '-')) = 1 THEN MAX(s.unit)
            ELSE 'Mixed'
        END AS unit,
        MAX(s.minimum_stock) AS minimum_stock,
        SUM({$supplyStockExpr}) AS current_stock
    FROM supplies s
    LEFT JOIN ({$supplyMovementSummarySql}) tx ON tx.item_id = s.id
    GROUP BY LOWER(TRIM(s.name))
    HAVING SUM({$supplyStockExpr}) <= MAX(s.minimum_stock)
";

$total_supplies = $pdo->query("SELECT COUNT(*) FROM supplies")->fetchColumn();
$total_equipment = $pdo->query("SELECT COUNT(*) FROM equipment")->fetchColumn();
$low_stock = $pdo->query("SELECT COUNT(*) FROM ({$lowStockProductsSql}) low_stock_products")->fetchColumn();
$available_equipment = $pdo->query("SELECT COUNT(*) FROM equipment WHERE status = 'servicable'")->fetchColumn();
$low_stock_items = $pdo->query($lowStockProductsSql . " ORDER BY current_stock ASC, name ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$stockOutProductsSql = "
    SELECT *
    FROM ({$lowStockProductsSql}) low_stock_products
    WHERE current_stock <= 0
";
$stock_out_count = (int)$pdo->query("SELECT COUNT(*) FROM ({$stockOutProductsSql}) stock_out_products")->fetchColumn();
$stock_out_items = $pdo->query($stockOutProductsSql . " ORDER BY name ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$showStockOutModal = !empty($_SESSION['show_stock_out_modal']) && $stock_out_count > 0 && osaeits_can_access('supplies');
unset($_SESSION['show_stock_out_modal']);

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

<div class="row">
    <?php if (osaeits_can_access('supplies')): ?>
    <div class="col-xl-3 col-md-6 mb-4">
        <a href="supplies.php" class="card-link-wrap">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Supplies</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= (int)$total_supplies ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-boxes fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>
    <?php if (osaeits_can_access('equipment')): ?>
    <div class="col-xl-3 col-md-6 mb-4">
        <a href="equipment.php" class="card-link-wrap">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Equipment</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= (int)$total_equipment ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-laptop fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>
    <?php if (osaeits_can_access('supplies')): ?>
    <div class="col-xl-3 col-md-6 mb-4">
        <a href="supplies.php?filter=low_stock" class="card-link-wrap">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Low Stock</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= (int)$low_stock ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-exclamation-triangle fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>
    <?php if (osaeits_can_access('equipment')): ?>
    <div class="col-xl-3 col-md-6 mb-4">
        <a href="equipment.php?status=servicable" class="card-link-wrap">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Servicable Equipment</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= (int)$available_equipment ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-check-circle fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>
</div>

<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="card shadow">
            <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Quick Action</h6></div>
            <div class="card-body">
                <p class="text-muted mb-3">Record a purchase, issue, or return linked to your supplies and equipment lists.</p>
                <div class="btn-group flex-wrap" role="group" aria-label="Transaction types">
                    <?php if (osaeits_can_access('purchase')): ?>
                    <a href="transaction-form.php?transaction_type=purchase" class="btn btn-primary">
                        <i class="fas fa-cart-plus mr-1"></i> Purchase
                    </a>
                    <?php endif; ?>
                    <?php if (osaeits_can_access('issue')): ?>
                    <a href="transaction-form.php?transaction_type=issue" class="btn btn-outline-primary">
                        <i class="fas fa-share-square mr-1"></i> Issue
                    </a>
                    <?php endif; ?>
                    <?php if (osaeits_can_access('return')): ?>
                    <a href="transaction-form.php?transaction_type=return" class="btn btn-outline-primary">
                        <i class="fas fa-undo mr-1"></i> Return
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php if (osaeits_can_access('supplies')): ?>
    <div class="col-lg-6 mb-4">
        <a href="supplies.php?filter=low_stock" class="card-link-wrap">
            <div class="card shadow">
                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-warning">Low Stock Alert</h6></div>
                <div class="card-body">
                    <?php if (empty($low_stock_items)): ?>
                        <p class="text-muted mb-0">No low stock items.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($low_stock_items as $item): ?>
                                <div class="list-group-item d-flex justify-content-between">
                                    <span><?= htmlspecialchars($item['name']) ?></span>
                                    <span class="text-warning"><?= (int)$item['current_stock'] ?> / <?= (int)$item['minimum_stock'] ?> <?= htmlspecialchars($item['unit']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if ($showStockOutModal): ?>
<div class="modal fade" id="stockOutModal" tabindex="-1" role="dialog" aria-labelledby="stockOutModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="stockOutModalLabel"><i class="fas fa-exclamation-circle mr-1"></i> Stock Out Alert</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p class="mb-3"><?= (int)$stock_out_count ?> supply product<?= $stock_out_count === 1 ? '' : 's' ?> currently <?= $stock_out_count === 1 ? 'has' : 'have' ?> zero stock.</p>
                <div class="list-group list-group-flush">
                    <?php foreach ($stock_out_items as $item): ?>
                        <div class="list-group-item d-flex justify-content-between px-0">
                            <span><?= htmlspecialchars((string)$item['name']) ?></span>
                            <span class="badge badge-danger align-self-center">0 <?= htmlspecialchars((string)$item['unit']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <?php if (osaeits_can_access('supplies')): ?>
                    <a href="supplies.php?filter=stock_out" class="btn btn-danger">View Stock Out</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery) {
        window.jQuery('#stockOutModal').modal('show');
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
