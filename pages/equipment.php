<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

$page_title = 'Equipment';
$current_page = 'equipment';
$base_url = '../';

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$limit = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$equipmentSummarySql = osaeits_equipment_movement_summary_sql();
$fromSql = " FROM equipment e LEFT JOIN ({$equipmentSummarySql}) tx ON tx.item_id = e.id";
$equipmentCodeExpr = osaeits_item_code_select_expr($pdo, 'e', 'equipment');

$params = [];
$where = [];
if ($search !== '') {
    $where[] = "({$equipmentCodeExpr} LIKE ? OR e.name LIKE ? OR e.description LIKE ? OR e.category LIKE ? OR e.serial_number LIKE ? OR e.brand LIKE ? OR e.model LIKE ?)";
    $term = "%{$search}%";
    $params = [$term, $term, $term, $term, $term, $term, $term];
}
if (in_array($status, ['servicable', 'unservicable'], true)) {
    $where[] = "e.status = ?";
    $params[] = $status;
}
$whereSql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

$productGroupSql = "
    SELECT
        LOWER(TRIM(e.name)) AS product_key,
        MIN(e.name) AS product_name,
        COUNT(*) AS asset_count,
        SUM(CASE WHEN COALESCE(tx.issued_balance, 0) > 0 THEN 1 ELSE 0 END) AS issued_count,
        SUM(CASE WHEN COALESCE(tx.issued_balance, 0) > 0 THEN 0 ELSE 1 END) AS available_count,
        SUM(CASE WHEN e.status = 'servicable' THEN 1 ELSE 0 END) AS servicable_count,
        SUM(CASE WHEN e.status = 'unservicable' THEN 1 ELSE 0 END) AS unservicable_count,
        MAX(tx.last_movement_at) AS last_movement_at
    {$fromSql}
    {$whereSql}
    GROUP BY LOWER(TRIM(e.name))
";

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$productGroupSql}) product_count");
$countStmt->execute($params);
$total_items = (int)$countStmt->fetchColumn();
$total_pages = (int)ceil($total_items / $limit);

$sql = "
    SELECT *
    FROM ({$productGroupSql}) products
    ORDER BY product_name ASC
    LIMIT {$limit} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$equipmentProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between">
        <h6 class="m-0 font-weight-bold text-primary">Equipment List</h6>
        <a href="equipment-form.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add Equipment</a>
    </div>
    <div class="card-body">
        <form method="get" class="form-inline mb-3">
            <input type="text" name="search" class="form-control form-control-sm mr-2" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
            <select name="status" class="form-control form-control-sm mr-2">
                <option value="">All Status</option>
                <option value="servicable" <?= $status === 'servicable' ? 'selected' : '' ?>>Servicable</option>
                <option value="unservicable" <?= $status === 'unservicable' ? 'selected' : '' ?>>Unservicable</option>
            </select>
            <button type="submit" class="btn btn-sm btn-secondary">Search</button>
            <a href="equipment.php" class="btn btn-sm btn-light ml-2">Reset</a>
        </form>
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr>
                        <th width="55">No.</th>
                        <th>Product</th>
                        <th>Assets</th>
                        <th>Available</th>
                        <th>Issued</th>
                        <th>Serviceable</th>
                        <th>Unserviceable</th>
                        <th>Last Issue/Return</th>
                        <th width="120">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($equipmentProducts as $idx => $e): ?>
                        <?php
                            $rowNumber = ($page - 1) * $limit + $idx + 1;
                            $lastMovement = !empty($e['last_movement_at']) ? date('M j, Y', strtotime((string)$e['last_movement_at'])) : '-';
                            $productName = trim((string)($e['product_name'] ?? ''));
                            $variantsHref = 'equipment-variants.php?' . http_build_query(['name' => (string)($e['product_key'] ?? '')]);
                        ?>
                        <tr>
                            <td><?= $rowNumber ?></td>
                            <td><a href="<?= htmlspecialchars($variantsHref) ?>" class="font-weight-bold"><?= htmlspecialchars($productName) ?></a></td>
                            <td><?= (int)$e['asset_count'] ?></td>
                            <td><?= (int)$e['available_count'] ?></td>
                            <td><?= (int)$e['issued_count'] ?></td>
                            <td><span class="badge badge-success"><?= (int)$e['servicable_count'] ?></span></td>
                            <td><span class="badge badge-secondary"><?= (int)$e['unservicable_count'] ?></span></td>
                            <td><?= htmlspecialchars($lastMovement) ?></td>
                            <td class="table-actions">
                                <a href="<?= htmlspecialchars($variantsHref) ?>" class="btn btn-sm btn-outline-secondary btn-icon-action" title="Open equipment assets" aria-label="Open equipment assets">
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
                Showing <?= ($offset + 1) ?> to <?= min($offset + count($equipmentProducts), $total_items) ?> of <?= $total_items ?> equipment products
            </div>
            <nav aria-label="Equipment pagination">
                <ul class="pagination pagination-sm mb-0">
                    <?php
                    $baseParams = [];
                    if (!empty($search)) {
                        $baseParams['search'] = $search;
                    }
                    if (!empty($status)) {
                        $baseParams['status'] = $status;
                    }

                    $buildPaginationUrl = function ($pageNum, $params) {
                        $params['page'] = $pageNum;
                        return 'equipment.php?' . http_build_query($params);
                    };

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

                    <?php if ($endPage < $total_pages): ?>
                        <?php if ($endPage < $total_pages - 1): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $buildPaginationUrl($total_pages, $baseParams) ?>"><?= $total_pages ?></a>
                        </li>
                    <?php endif; ?>

                    <?php if ($page < $total_pages): ?>
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
        <?php else: ?>
            <div class="text-center text-muted py-4">No equipment found.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
