<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

$page_title = 'Supplies';
$current_page = 'supplies';
$base_url = '../';

$search = trim($_GET['search'] ?? '');
$low_stock_only = isset($_GET['filter']) && $_GET['filter'] === 'low_stock';

// Pagination parameters
$limit = 10; // Items per page
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$purchaseSummarySql = osaeits_supply_movement_summary_sql();
$fromSql = " FROM supplies s LEFT JOIN ({$purchaseSummarySql}) tx ON tx.item_id = s.id";
$stockExpr = osaeits_supply_stock_expression('tx', 's');
$supplyCodeExpr = osaeits_item_code_select_expr($pdo, 's', 'supply');

$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = "({$supplyCodeExpr} LIKE ? OR s.name LIKE ? OR s.description LIKE ? OR s.category LIKE ? OR s.supplier LIKE ?)";
    $term = "%$search%";
    array_push($params, $term, $term, $term, $term, $term);
}
$whereSql = !empty($conditions) ? ' WHERE ' . implode(' AND ', $conditions) : '';
$havingSql = $low_stock_only ? " HAVING SUM({$stockExpr}) <= MAX(s.minimum_stock)" : '';

$productGroupSql = "
    SELECT
        LOWER(TRIM(s.name)) AS product_key,
        MIN(s.name) AS product_name,
        COUNT(*) AS variant_count,
        CASE
            WHEN COUNT(DISTINCT COALESCE(NULLIF(TRIM(s.unit), ''), '-')) = 1 THEN MAX(s.unit)
            ELSE 'Mixed'
        END AS unit_label,
        SUM({$stockExpr}) AS current_stock,
        MAX(s.minimum_stock) AS minimum_stock,
        SUM(COALESCE(tx.purchase_quantity, 0)) AS purchase_quantity,
        MAX(tx.last_purchase_at) AS last_purchase_at
    {$fromSql}
    {$whereSql}
    GROUP BY LOWER(TRIM(s.name))
    {$havingSql}
";

// Get total count for pagination
$countSql = "SELECT COUNT(*) FROM ({$productGroupSql}) product_count";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total_items = (int)$countStmt->fetchColumn();
$total_pages = ceil($total_items / $limit);

$sql = "
    SELECT *
    FROM ({$productGroupSql}) products
    ORDER BY product_name ASC
    LIMIT %d OFFSET %d
";
$sql = sprintf($sql, $limit, $offset);

if (!empty($params)) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} else {
    $stmt = $pdo->query($sql);
}
$supplyProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';

?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between">
        <div>
            <h6 class="m-0 font-weight-bold text-primary">Supplies List<?php if ($low_stock_only): ?> <span class="text-warning font-weight-normal">— Low stock</span><?php endif; ?></h6>
            <?php if ($low_stock_only): ?>
                <a href="supplies.php?page=1" class="small">Show all supplies</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <form method="get" class="form-inline mb-3">
            <?php if ($low_stock_only): ?>
                <input type="hidden" name="filter" value="low_stock">
            <?php endif; ?>
            <input type="hidden" name="page" value="1">
            <input type="text" name="search" class="form-control form-control-sm mr-2" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-sm btn-secondary">Search</button>
        </form>
        <div class="table-responsive" style="max-height: 600px; overflow-x: auto; overflow-y: auto; border: 1px solid #dee2e6;">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr>
                        <th width="55">No.</th>
                        <th>Product</th>
                        <th>Variants</th>
                        <th>Unit</th>
                        <th>Total Stock</th>
                        <th>Purchased</th>
                        <th>Product Min</th>
                        <th>Status</th>
                        <th>Last Purchase</th>
                        <th width="120">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplyProducts as $idx => $s): ?>
                        <?php
                            $isLow = (int)($s['current_stock'] ?? 0) <= (int)($s['minimum_stock'] ?? 0);
                            $isOut = (int)($s['current_stock'] ?? 0) <= 0;
                            $rowNumber = ($page - 1) * $limit + $idx + 1;
                            $lastPurchase = !empty($s['last_purchase_at']) ? date('M j, Y', strtotime((string)$s['last_purchase_at'])) : '-';
                            $displayName = trim((string)($s['product_name'] ?? ''));
                            $variantsHref = 'supply-variants.php?' . http_build_query(['name' => (string)($s['product_key'] ?? '')]);
                        ?>
                        <tr class="<?= $isOut ? 'row-stock-out' : ($isLow ? 'row-low-stock' : '') ?>">
                            <td><?= $rowNumber ?></td>
                            <td>
                                <a href="<?= htmlspecialchars($variantsHref) ?>" class="font-weight-bold">
                                    <?= htmlspecialchars($displayName) ?>
                                </a>
                            </td>
                            <td><?= (int)$s['variant_count'] ?></td>
                            <td><?= htmlspecialchars((string)($s['unit_label'] ?? '-')) ?></td>
                            <td><?= (int)$s['current_stock'] ?></td>
                            <td><?= (int)$s['purchase_quantity'] ?></td>
                            <td><?= (int)$s['minimum_stock'] ?></td>
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
                                <a href="<?= htmlspecialchars($variantsHref) ?>" class="btn btn-sm btn-outline-secondary btn-icon-action" title="Open variants" aria-label="Open variants">
                                    <i class="fas fa-list"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_items > 0): ?>
        <div class="d-flex justify-content-between align-items-center mt-3">
            <div class="small text-muted">
                Showing <?= ($offset + 1) ?> to <?= min($offset + count($supplyProducts), $total_items) ?> of <?= $total_items ?> supply products
            </div>
            <nav aria-label="Supplies pagination">
                <ul class="pagination pagination-sm mb-0">
                    <?php
                    // Build base query params, preserving search and filter
                    $baseParams = [];
                    if (!empty($search)) {
                        $baseParams['search'] = $search;
                    }
                    if ($low_stock_only) {
                        $baseParams['filter'] = 'low_stock';
                    }

                    // Helper function to build pagination URL
                    $buildPaginationUrl = function($pageNum, $params) {
                        $params['page'] = $pageNum;
                        return 'supplies.php?' . http_build_query($params);
                    };

                    // Previous button
                    if ($page > 1):
                    ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $buildPaginationUrl($page - 1, $baseParams) ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="page-item disabled">
                            <span class="page-link" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </span>
                        </li>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($total_pages, $page + 2);

                    // First page if not in range
                    if ($startPage > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $buildPaginationUrl(1, $baseParams) ?>">1</a>
                        </li>
                        <?php if ($startPage > 2): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= $buildPaginationUrl($i, $baseParams) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php
                    // Last page if not in range
                    if ($endPage < $total_pages): ?>
                        <?php if ($endPage < $total_pages - 1): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $buildPaginationUrl($total_pages, $baseParams) ?>"><?= $total_pages ?></a>
                        </li>
                    <?php endif; ?>

                    <?php
                    // Next button
                    if ($page < $total_pages):
                    ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $buildPaginationUrl($page + 1, $baseParams) ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="page-item disabled">
                            <span class="page-link" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </span>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
