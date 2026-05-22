<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

$page_title = 'Transaction history';
$current_page = 'inventory';
$base_url = '../';

$item_type_filter = $_GET['item_type'] ?? '';
$item_type_filter = in_array($item_type_filter, ['supply', 'equipment'], true) ? $item_type_filter : '';
$item_id_filter = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;

$tx_type_filter = $_GET['transaction_type'] ?? '';
$tx_type_labels = [
    'purchase' => 'Purchase',
    'issue' => 'Issue',
    'return' => 'Return',
];
$valid_tx_types = array_keys($tx_type_labels);
$tx_type_filter = in_array($tx_type_filter, $valid_tx_types, true) ? $tx_type_filter : '';

if ($tx_type_filter === '' && $item_type_filter === '' && $item_id_filter <= 0) {
    header('Location: inventory.php?transaction_type=purchase');
    exit;
}

if ($tx_type_filter !== '') {
    $page_title = $tx_type_labels[$tx_type_filter] . ' history';
} elseif ($item_type_filter !== '' && $item_id_filter > 0) {
    $page_title = 'Item transaction history';
}

$search = osaeits_clean_inventory_text($_GET['search'] ?? '');
$normalizeDate = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
};
$date_from = $normalizeDate($_GET['date_from'] ?? '');
$date_to = $normalizeDate($_GET['date_to'] ?? '');

$per_page_options = [10, 25, 50, 100];
$per_page = (int)($_GET['per_page'] ?? 25);
$per_page = in_array($per_page, $per_page_options, true) ? $per_page : 25;
$page = max(1, (int)($_GET['page'] ?? 1));

$sort_options = [
    'date' => 'Date',
    'reference' => 'Reference',
    'type' => 'Type',
    'for' => 'For',
    'item' => 'Item',
    'quantity' => 'Quantity',
    'total' => 'Total',
    'recorded_by' => 'Recorded by',
];
$showTypeColumn = $tx_type_filter === '';
$showSupplierColumn = $tx_type_filter === 'purchase';
if (!$showTypeColumn) {
    unset($sort_options['type']);
}
$sort = $_GET['sort'] ?? 'date';
$sort = array_key_exists($sort, $sort_options) ? $sort : 'date';
$sort_dir = strtolower((string)($_GET['dir'] ?? 'desc'));
$sort_dir = in_array($sort_dir, ['asc', 'desc'], true) ? $sort_dir : 'desc';

$filter_item_label = '';
if ($item_type_filter !== '' && $item_id_filter > 0) {
    if ($item_type_filter === 'supply') {
        $supplyCodeExpr = osaeits_item_code_select_expr($pdo, 's', 'supply');
        $st = $pdo->prepare("SELECT s.*, {$supplyCodeExpr} AS item_code FROM supplies s WHERE id = ? LIMIT 1");
        $st->execute([$item_id_filter]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $filter_item_label = trim((string)$row['item_code'] . ' - ' . osaeits_supply_display_name($row), ' -');
        }
    } else {
        $equipmentCodeExpr = osaeits_item_code_select_expr($pdo, 'e', 'equipment');
        $st = $pdo->prepare("SELECT e.*, {$equipmentCodeExpr} AS item_code FROM equipment e WHERE id = ? LIMIT 1");
        $st->execute([$item_id_filter]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $filter_item_label = trim((string)$row['item_code'] . ' - ' . osaeits_equipment_display_name($row), ' -');
        }
    }
}

$supplyCodeExpr = osaeits_item_code_select_expr($pdo, 's', 'supply');
$equipmentCodeExpr = osaeits_item_code_select_expr($pdo, 'e', 'equipment');
$fromSql = "
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN supplies s ON t.item_type = 'supply' AND t.item_id = s.id
    LEFT JOIN equipment e ON t.item_type = 'equipment' AND t.item_id = e.id
";

$where = [];
$params = [];
if ($item_type_filter !== '') {
    $where[] = 't.item_type = ?';
    $params[] = $item_type_filter;
    if ($item_id_filter > 0) {
        $where[] = 't.item_id = ?';
        $params[] = $item_id_filter;
    }
}
if ($tx_type_filter !== '') {
    $where[] = 't.transaction_type = ?';
    $params[] = $tx_type_filter;
}
if ($search !== '') {
    $term = "%{$search}%";
    $where[] = "(
        t.reference_number LIKE ?
        OR t.notes LIKE ?
        OR {$supplyCodeExpr} LIKE ?
        OR s.name LIKE ?
        OR s.description LIKE ?
        OR s.supplier LIKE ?
        OR {$equipmentCodeExpr} LIKE ?
        OR e.name LIKE ?
        OR e.description LIKE ?
        OR e.serial_number LIKE ?
        OR e.brand LIKE ?
        OR e.model LIKE ?
        OR u.first_name LIKE ?
        OR u.last_name LIKE ?
    )";
    $params = array_merge($params, array_fill(0, 14, $term));
}
if ($date_from !== '') {
    $where[] = 't.created_at >= ?';
    $params[] = $date_from . ' 00:00:00';
}
if ($date_to !== '') {
    $where[] = 't.created_at <= ?';
    $params[] = $date_to . ' 23:59:59';
}
$whereSql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) {$fromSql} {$whereSql}");
$countStmt->execute($params);
$total_items = (int)$countStmt->fetchColumn();
$total_pages = (int)ceil($total_items / $per_page);
if ($total_pages > 0 && $page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

$sortSqlMap = [
    'date' => 't.created_at',
    'reference' => 't.reference_number',
    'type' => 't.transaction_type',
    'for' => 't.item_type',
    'item' => "COALESCE(s.name, e.name)",
    'quantity' => 't.quantity',
    'total' => 't.total_amount',
    'recorded_by' => "CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))",
];
$orderExpr = $sortSqlMap[$sort] ?? $sortSqlMap['date'];
$orderSql = $sort === 'date'
    ? "{$orderExpr} {$sort_dir}, t.id {$sort_dir}"
    : "{$orderExpr} {$sort_dir}, t.created_at DESC, t.id DESC";

$stmt = $pdo->prepare("
    SELECT
        t.*,
        u.first_name,
        u.last_name,
        {$supplyCodeExpr} AS supply_code,
        s.name AS supply_name,
        s.description AS supply_description,
        s.supplier AS supply_supplier,
        s.unit AS supply_unit,
        {$equipmentCodeExpr} AS equipment_code,
        e.name AS equipment_name,
        e.description AS equipment_description,
        e.serial_number AS equipment_serial,
        e.brand AS equipment_brand,
        e.model AS equipment_model
    {$fromSql}
    {$whereSql}
    ORDER BY {$orderSql}
    LIMIT {$per_page} OFFSET {$offset}
");
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$baseParams = [];
if ($tx_type_filter !== '') {
    $baseParams['transaction_type'] = $tx_type_filter;
}
if ($item_type_filter !== '') {
    $baseParams['item_type'] = $item_type_filter;
}
if ($item_id_filter > 0) {
    $baseParams['item_id'] = $item_id_filter;
}
if ($search !== '') {
    $baseParams['search'] = $search;
}
if ($date_from !== '') {
    $baseParams['date_from'] = $date_from;
}
if ($date_to !== '') {
    $baseParams['date_to'] = $date_to;
}
$baseParams['sort'] = $sort;
$baseParams['dir'] = $sort_dir;
$baseParams['per_page'] = $per_page;

$buildUrl = static function (array $overrides = []) use ($baseParams): string {
    $params = array_merge($baseParams, $overrides);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }
    return 'inventory.php' . (!empty($params) ? '?' . http_build_query($params) : '');
};
$sortHeader = static function (string $key, string $label) use ($sort, $sort_dir, $buildUrl): string {
    $nextDir = ($sort === $key && $sort_dir === 'asc') ? 'desc' : 'asc';
    $icon = $sort === $key
        ? ($sort_dir === 'asc' ? ' <i class="fas fa-sort-up"></i>' : ' <i class="fas fa-sort-down"></i>')
        : ' <i class="fas fa-sort text-muted"></i>';
    return '<a class="text-dark" href="' . htmlspecialchars($buildUrl(['sort' => $key, 'dir' => $nextDir, 'page' => 1])) . '">' . htmlspecialchars($label) . $icon . '</a>';
};
$formatPurchaseSupplier = static function (array $row): string {
    foreach (preg_split('/\R/', (string)($row['notes'] ?? '')) ?: [] as $line) {
        if (preg_match('/^\s*Supplier\s*:\s*(.+)$/i', $line, $matches)) {
            $supplier = osaeits_clean_inventory_text($matches[1] ?? '');
            return $supplier !== '' ? $supplier : '-';
        }
    }

    $supplier = osaeits_clean_inventory_text($row['supply_supplier'] ?? '');
    return $supplier !== '' ? $supplier : '-';
};

$resetParams = [];
if ($tx_type_filter !== '') {
    $resetParams['transaction_type'] = $tx_type_filter;
}
if ($item_type_filter !== '' && $item_id_filter > 0) {
    $resetParams['item_type'] = $item_type_filter;
    $resetParams['item_id'] = $item_id_filter;
}
$resetUrl = 'inventory.php' . (!empty($resetParams) ? '?' . http_build_query($resetParams) : '?transaction_type=purchase');

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <h6 class="m-0 font-weight-bold text-primary"><?= htmlspecialchars($page_title) ?></h6>
            <p class="small text-muted mb-0">History of purchases, issues, and returns. Filter, sort, and page through the full record set.</p>
        </div>
        <?php if (osaeits_can_access('trash')): ?>
        <div>
            <a href="trash.php" class="btn btn-outline-secondary btn-sm mr-1"><i class="fas fa-trash"></i> Trash</a>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (!empty($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <?= htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>
        <?php if ($item_type_filter !== '' && $item_id_filter > 0): ?>
            <div class="alert alert-info py-2 mb-3">
                Showing transactions for
                <strong><?= htmlspecialchars($filter_item_label !== '' ? $filter_item_label : (($item_type_filter === 'supply' ? 'supply' : 'equipment') . ' #' . $item_id_filter)) ?></strong>
                (<?= $item_type_filter === 'supply' ? 'from Supplies' : 'from Equipment' ?>).
                <a href="inventory.php?transaction_type=purchase" class="alert-link">Show purchase history</a>
                &middot;
                <?php if ($item_type_filter === 'supply'): ?>
                    <a href="supply-form.php?id=<?= (int)$item_id_filter ?>" class="alert-link">Edit this supply</a>
                <?php else: ?>
                    <a href="equipment-form.php?id=<?= (int)$item_id_filter ?>" class="alert-link">Edit this equipment</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($tx_type_filter !== ''): ?>
            <div class="alert alert-info py-2 mb-3">
                Showing <?= htmlspecialchars($tx_type_labels[$tx_type_filter]) ?> entries.
            </div>
        <?php endif; ?>

        <form method="get" class="border rounded p-3 mb-3 bg-light">
            <?php if ($tx_type_filter !== ''): ?>
                <input type="hidden" name="transaction_type" value="<?= htmlspecialchars($tx_type_filter) ?>">
            <?php endif; ?>
            <?php if ($item_id_filter > 0): ?>
                <input type="hidden" name="item_id" value="<?= (int)$item_id_filter ?>">
            <?php endif; ?>
            <div class="form-row align-items-end">
                <div class="form-group col-lg-3 col-md-6">
                    <label class="small font-weight-bold">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Reference, item, supplier, user..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="form-group col-lg-2 col-md-6">
                    <label class="small font-weight-bold">Item type</label>
                    <select name="item_type" class="form-control form-control-sm" <?= $item_id_filter > 0 ? 'disabled' : '' ?>>
                        <option value="">All items</option>
                        <option value="supply" <?= $item_type_filter === 'supply' ? 'selected' : '' ?>>Supplies</option>
                        <option value="equipment" <?= $item_type_filter === 'equipment' ? 'selected' : '' ?>>Equipment</option>
                    </select>
                    <?php if ($item_id_filter > 0): ?>
                        <input type="hidden" name="item_type" value="<?= htmlspecialchars($item_type_filter) ?>">
                    <?php endif; ?>
                </div>
                <div class="form-group col-lg-2 col-md-6">
                    <label class="small font-weight-bold">From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="form-group col-lg-2 col-md-6">
                    <label class="small font-weight-bold">To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="form-group col-lg-2 col-md-6">
                    <label class="small font-weight-bold">Sort by</label>
                    <select name="sort" class="form-control form-control-sm">
                        <?php foreach ($sort_options as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-lg-1 col-md-6">
                    <label class="small font-weight-bold">Dir</label>
                    <select name="dir" class="form-control form-control-sm">
                        <option value="desc" <?= $sort_dir === 'desc' ? 'selected' : '' ?>>Desc</option>
                        <option value="asc" <?= $sort_dir === 'asc' ? 'selected' : '' ?>>Asc</option>
                    </select>
                </div>
                <div class="form-group col-lg-2 col-md-6">
                    <label class="small font-weight-bold">Per page</label>
                    <select name="per_page" class="form-control form-control-sm">
                        <?php foreach ($per_page_options as $option): ?>
                            <option value="<?= $option ?>" <?= $per_page === $option ? 'selected' : '' ?>><?= $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-lg-3 col-md-6">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter"></i> Apply</button>
                    <a href="<?= htmlspecialchars($resetUrl) ?>" class="btn btn-sm btn-light ml-1">Reset</a>
                </div>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr>
                        <th><?= $sortHeader('date', 'Date') ?></th>
                        <th><?= $sortHeader('reference', 'Reference') ?></th>
                        <?php if ($showTypeColumn): ?>
                            <th><?= $sortHeader('type', 'Type') ?></th>
                        <?php endif; ?>
                        <th><?= $sortHeader('for', 'For') ?></th>
                        <th><?= $sortHeader('item', 'Item') ?></th>
                        <th><?= $sortHeader('quantity', 'Quantity') ?></th>
                        <th><?= $sortHeader('total', 'Total') ?></th>
                        <th><?= $sortHeader('recorded_by', 'Recorded by') ?></th>
                        <th><?= $showSupplierColumn ? 'Supplier' : 'Notes' ?></th>
                        <th width="120">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                        <?php
                            $actorName = trim((string)($t['first_name'] ?? '') . ' ' . (string)($t['last_name'] ?? '')) ?: '-';
                            if ($t['item_type'] === 'supply') {
                                $itemLabel = trim((string)($t['supply_code'] ?? '') . ' - ' . osaeits_supply_display_name([
                                    'name' => $t['supply_name'] ?? ('Supply #' . (int)$t['item_id']),
                                    'description' => $t['supply_description'] ?? '',
                                ]), ' -');
                                $itemHref = 'supply-detail.php?id=' . (int)$t['item_id'];
                            } else {
                                $itemLabel = trim((string)($t['equipment_code'] ?? '') . ' - ' . osaeits_equipment_display_name([
                                    'name' => $t['equipment_name'] ?? ('Equipment #' . (int)$t['item_id']),
                                    'description' => $t['equipment_description'] ?? '',
                                    'brand' => $t['equipment_brand'] ?? '',
                                    'model' => $t['equipment_model'] ?? '',
                                    'serial_number' => $t['equipment_serial'] ?? '',
                                ]), ' -');
                                $itemHref = 'equipment-detail.php?id=' . (int)$t['item_id'];
                            }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars(date('M j, Y H:i', strtotime((string)$t['created_at']))) ?></td>
                            <td><?= htmlspecialchars($t['reference_number'] ?? '-') ?></td>
                            <?php if ($showTypeColumn): ?>
                                <td><?= htmlspecialchars(ucfirst((string)$t['transaction_type'])) ?></td>
                            <?php endif; ?>
                            <td><?= $t['item_type'] === 'supply' ? 'Supply' : 'Equipment' ?></td>
                            <td><a href="<?= htmlspecialchars($itemHref) ?>"><?= htmlspecialchars($itemLabel) ?></a></td>
                            <td><?= (int)$t['quantity'] ?></td>
                            <td>PHP <?= number_format((float)$t['total_amount'], 2) ?></td>
                            <td><?= htmlspecialchars($actorName) ?></td>
                            <td><?= htmlspecialchars($showSupplierColumn ? $formatPurchaseSupplier($t) : ($t['notes'] ?? '-')) ?></td>
                            <td class="table-actions">
                                <?php if (osaeits_can_access((string)$t['transaction_type'])): ?>
                                <a href="transaction-form.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-info btn-icon-action" title="Edit transaction" aria-label="Edit transaction">
                                    <i class="fas fa-pen"></i>
                                </a>
                                <a href="transaction-delete.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-danger btn-icon-action" data-confirm="Move this transaction to trash?" title="Move to trash" aria-label="Move to trash">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_items > 0): ?>
            <div class="d-flex flex-wrap justify-content-between align-items-center mt-3">
                <div class="small text-muted mb-2 mb-md-0">
                    Showing <?= ($offset + 1) ?> to <?= min($offset + count($transactions), $total_items) ?> of <?= $total_items ?> entries
                </div>
                <nav aria-label="Transaction history pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars($buildUrl(['page' => max(1, $page - 1)])) ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($total_pages, $page + 2);
                        ?>
                        <?php if ($startPage > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= htmlspecialchars($buildUrl(['page' => 1])) ?>">1</a></li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= htmlspecialchars($buildUrl(['page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($endPage < $total_pages): ?>
                            <?php if ($endPage < $total_pages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?= htmlspecialchars($buildUrl(['page' => $total_pages])) ?>"><?= $total_pages ?></a></li>
                        <?php endif; ?>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars($buildUrl(['page' => min($total_pages, $page + 1)])) ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0"><?= $item_type_filter !== '' && $item_id_filter > 0 ? 'No transactions recorded for this item yet.' : 'No transactions match your filters.' ?></p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
