<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/audit-display-helpers.php';

$page_title = 'Activity log';
$current_page = 'activity_log';
$base_url = '../';

$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$entity_type = trim($_GET['entity_type'] ?? '');
$q = trim($_GET['q'] ?? '');

// Pagination parameters
$limit = 10; // Items per page
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$logs = [];
$tableMissing = false;

function action_label(string $action): string
{
    $map = [
        'auth.login' => 'Signed in to the system',
        'auth.logout' => 'Signed out',
        'auth.register' => 'Created a new login account (self-registration)',
        'user.create' => 'Added a new user who can sign in',
        'user.update' => 'Changed an existing user’s sign-in details or access level',
        'user.delete' => 'Removed a user from the system',
        'supply.create' => 'Added a new supply (consumable) item',
        'supply.update' => 'Changed details of a supply item',
        'supply.delete' => 'Removed a supply item from the list',
        'equipment.create' => 'Added new equipment',
        'equipment.update' => 'Changed equipment details or status',
        'equipment.delete' => 'Removed equipment from the list',
        'transaction.create' => 'Recorded a transaction (purchase, issue, or return)',
        'transaction.update' => 'Corrected an existing transaction',
        'transaction.delete' => 'Moved a transaction to trash',
        'transaction.restore' => 'Restored a transaction from trash',
        'transaction.permanent_delete' => 'Blocked permanent transaction deletion',
        'trash.restore' => 'Restored a record from trash',
        'trash.permanent_delete' => 'Blocked permanent record deletion',
        'official.create' => 'Added a barangay official',
        'official.update' => 'Updated a barangay official’s information',
        'official.delete' => 'Removed a barangay official from the list',
        'assign_item.create' => 'Recorded who received an assigned item',
        'assign_item.update' => 'Updated an assignment (who holds the item, area, etc.)',
        'assign_item.delete' => 'Removed an assignment record',
        'report.display' => 'Displayed a report',
        'report.print' => 'Printed a report',
    ];
    if (isset($map[$action])) {
        return $map[$action];
    }

    $pretty = str_replace(['.', '_'], ' ', trim($action));
    return $pretty !== '' ? ('Action: ' . ucfirst($pretty)) : 'An action was recorded';
}

function entity_label(?string $entityType, $entityId): string
{
    $entityType = trim((string)$entityType);
    if ($entityType === '') {
        return '—';
    }
    $map = [
        'user' => 'User profile',
        'supply' => 'Supply item',
        'equipment' => 'Equipment',
        'transaction' => 'Transaction',
        'transaction_trash' => 'Trash item',
        'barangay_official' => 'Barangay official',
        'assign_item' => 'Assignment',
        'report' => 'Report',
    ];
    $base = $map[$entityType] ?? ucfirst(str_replace('_', ' ', $entityType));
    if ($entityId !== null && $entityId !== '') {
        return $base . ' (record no. ' . (int)$entityId . ')';
    }
    return $base;
}

function friendly_role_label(?string $role): string
{
    $r = strtolower(trim((string)$role));
    if ($r === 'admin') {
        return 'Administrator (full access)';
    }
    if ($r === 'user') {
        return 'Staff (standard access)';
    }
    return $role !== '' ? (string)$role : '';
}

function friendly_transaction_type(?string $type): string
{
    $t = strtolower(trim((string)$type));
    $labels = [
        'purchase' => 'Purchase — stock received',
        'issue' => 'Issue — stock given out',
        'return' => 'Return — items brought back',
        'adjustment' => 'Adjustment — quantity corrected',
    ];
    return $labels[$t] ?? (string)$type;
}

function friendly_item_kind(?string $itemType): string
{
    $t = strtolower(trim((string)$itemType));
    if ($t === 'supply') {
        return 'supply';
    }
    if ($t === 'equipment') {
        return 'equipment';
    }
    return $t !== '' ? $t : 'item';
}

function humanize_transaction_details(array $row, bool $wasRemoved = false): string
{
    $kind = friendly_item_kind($row['item_type'] ?? '');
    $tt = friendly_transaction_type($row['transaction_type'] ?? '');
    $qty = (int)($row['quantity'] ?? 0);
    $itemId = (int)($row['item_id'] ?? 0);
    $ref = trim((string)($row['reference_number'] ?? ''));
    $total = $row['total_amount'] ?? null;

    $prefix = $wasRemoved ? 'The deleted record was: ' : '';

    $body = $prefix . sprintf(
        '%d unit(s) affecting %s (list record no. %d). ',
        $qty,
        $kind,
        $itemId > 0 ? $itemId : 0
    );
    $body .= 'Transaction type: ' . $tt . '.';
    if ($ref !== '') {
        $body .= ' Reference / document no.: ' . $ref . '.';
    }
    if ($total !== null && $total !== '' && is_numeric($total)) {
        $body .= ' Total amount: ₱' . number_format((float)$total, 2) . '.';
    }
    return $body;
}

function details_summary(string $action, ?string $entityType, $rawDetails): string
{
    if ($rawDetails === null || $rawDetails === '') {
        if ($action === 'auth.logout') {
            return 'Session ended.';
        }
        return 'No extra description was saved for this entry.';
    }
    $decoded = json_decode((string)$rawDetails, true);
    if (!is_array($decoded)) {
        return (string)$rawDetails;
    }

    // Transaction removed - details are under snapshot.
    if ($action === 'transaction.delete' && !empty($decoded['snapshot']) && is_array($decoded['snapshot'])) {
        return humanize_transaction_details($decoded['snapshot'], true);
    }

    // Transaction added or edited.
    if (in_array($action, ['transaction.create', 'transaction.update'], true)
        && isset($decoded['transaction_type'], $decoded['item_type'], $decoded['quantity'])) {
        return humanize_transaction_details($decoded, false);
    }

    if ($action === 'auth.login' && !empty($decoded['username'])) {
        return 'Signed in with username: ' . $decoded['username'] . '.';
    }

    if ($action === 'auth.register') {
        $parts = ['Someone registered a new account.'];
        if (!empty($decoded['username'])) {
            $parts[] = 'Username: ' . $decoded['username'] . '.';
        }
        if (!empty($decoded['email'])) {
            $parts[] = 'Email on file: ' . $decoded['email'] . '.';
        }
        return implode(' ', $parts);
    }

    if (in_array($action, ['user.create', 'user.update'], true)) {
        $parts = [];
        if (!empty($decoded['username'])) {
            $parts[] = 'Account username: ' . $decoded['username'] . '.';
        }
        if (!empty($decoded['email'])) {
            $parts[] = 'Email: ' . $decoded['email'] . '.';
        }
        $rl = friendly_role_label($decoded['role'] ?? null);
        if ($rl !== '') {
            $parts[] = 'Access level: ' . $rl . '.';
        }
        return $parts !== [] ? implode(' ', $parts) : 'User account details were updated.';
    }

    if ($action === 'user.delete') {
        $u = $decoded['deleted_username'] ?? '';
        $e = $decoded['deleted_email'] ?? '';
        if ($u !== '' || $e !== '') {
            return 'Removed user “' . ($u !== '' ? $u : $e) . '”' . ($e !== '' && $u !== '' ? ' (' . $e . ')' : '') . ' from the system.';
        }
        return 'A user account was removed.';
    }

    if (in_array($action, ['supply.create', 'supply.update', 'supply.delete'], true) && !empty($decoded['name'])) {
        return 'Supply name: “' . $decoded['name'] . '”.';
    }

    if ($action === 'equipment.delete') {
        $n = $decoded['name'] ?? '';
        $sn = trim((string)($decoded['serial_number'] ?? ''));
        if ($n !== '') {
            return $sn !== ''
                ? 'Equipment: “' . $n . '”, serial no.: ' . $sn . '.'
                : 'Equipment: “' . $n . '”.';
        }
    }

    if (in_array($action, ['equipment.create', 'equipment.update'], true)) {
        $parts = [];
        if (!empty($decoded['name'])) {
            $parts[] = 'Equipment: “' . $decoded['name'] . '”.';
        }
        if (!empty($decoded['status'])) {
            $parts[] = 'Status: ' . $decoded['status'] . '.';
        }
        return $parts !== [] ? implode(' ', $parts) : 'Equipment details were saved.';
    }

    if (in_array($action, ['official.create', 'official.update', 'official.delete'], true)) {
        $parts = [];
        if (!empty($decoded['name'])) {
            $parts[] = 'Name: ' . $decoded['name'] . '.';
        }
        if (!empty($decoded['position_title'])) {
            $parts[] = 'Position: ' . $decoded['position_title'] . '.';
        }
        if (!empty($decoded['status'])) {
            $parts[] = 'Status: ' . $decoded['status'] . '.';
        }
        return $parts !== [] ? implode(' ', $parts) : 'Barangay official record.';
    }

    if (in_array($action, ['assign_item.create', 'assign_item.update', 'assign_item.delete'], true)) {
        $parts = [];
        $kind = friendly_item_kind($decoded['item_type'] ?? '');
        if ($kind !== '') {
            $ref = isset($decoded['item_ref_id']) ? (int)$decoded['item_ref_id'] : 0;
            $parts[] = sprintf('Item type: %s (linked to record no. %d).', $kind, $ref);
        }
        if (!empty($decoded['assigned_to'])) {
            $parts[] = 'Assigned to / person in charge: ' . $decoded['assigned_to'] . '.';
        }
        if (!empty($decoded['assigned_area'])) {
            $parts[] = 'Area or purok: ' . $decoded['assigned_area'] . '.';
        }
        if (!empty($decoded['appropriation'])) {
            $parts[] = 'Appropriation: ' . $decoded['appropriation'] . '.';
        }
        if (isset($decoded['quantity']) && $decoded['quantity'] !== '' && $decoded['quantity'] !== null) {
            $parts[] = 'Quantity: ' . (int)$decoded['quantity'] . '.';
        }
        if (!empty($decoded['status'])) {
            $parts[] = 'Status: ' . $decoded['status'] . '.';
        }
        return $parts !== [] ? implode(' ', $parts) : 'Assignment details were saved or removed.';
    }

    $bits = [];
    if (!empty($decoded['username'])) {
        $bits[] = 'Username: ' . $decoded['username'];
    }
    if (!empty($decoded['email'])) {
        $bits[] = 'Email: ' . $decoded['email'];
    }
    if (!empty($decoded['name'])) {
        $bits[] = 'Name: ' . $decoded['name'];
    }
    if (!empty($decoded['position_title'])) {
        $bits[] = 'Position: ' . $decoded['position_title'];
    }
    if (!empty($decoded['status'])) {
        $bits[] = 'Status: ' . $decoded['status'];
    }

    if (empty($bits)) {
        return 'Technical note: extra data was attached but could not be summarized in plain language.';
    }
    return implode(' ', array_map(static function ($b) {
        return (strpos($b, '.') === strlen($b) - 1) ? $b : $b . '.';
    }, $bits));
}

try {
    $where = ['1=1'];
    $params = [];

    if ($date_from !== '') {
        $where[] = 'DATE(a.created_at) >= ?';
        $params[] = $date_from;
    }
    if ($date_to !== '') {
        $where[] = 'DATE(a.created_at) <= ?';
        $params[] = $date_to;
    }
    if ($entity_type !== '' && strlen($entity_type) <= 50) {
        $where[] = 'a.entity_type = ?';
        $params[] = $entity_type;
    }
    if ($q !== '') {
        $term = '%' . $q . '%';
        $where[] = '(a.action LIKE ? OR a.details LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR CONCAT(u.first_name, \' \', u.last_name) LIKE ?)';
        array_push($params, $term, $term, $term, $term, $term);
    }

    // Get total count for pagination
    $countSql = 'SELECT COUNT(*) FROM activity_logs a
                 LEFT JOIN users u ON a.user_id = u.id
                 WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total_items = $countStmt->fetchColumn();
    $total_pages = ceil($total_items / $limit);

    $sql = 'SELECT a.*, u.username, u.email, u.first_name, u.last_name
            FROM activity_logs a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY a.created_at DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $tableMissing = true;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">Activity log</h6>
    </div>
    <div class="card-body">
        <?php if ($tableMissing): ?>
            <div class="alert alert-warning mb-0">
                The activity log is not set up yet. Ask your technical contact to open <strong>migrate.php</strong> once in the browser to create the log table, then reload this page.
            </div>
        <?php else: ?>
            <form method="get" class="mb-3">
                <div class="form-row">
                    <div class="form-group col-md-2">
                        <label class="small mb-1">Date from</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    <div class="form-group col-md-2">
                        <label class="small mb-1">Date to</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                    <div class="form-group col-md-2">
                        <label class="small mb-1">Record area</label>
                        <select name="entity_type" class="form-control form-control-sm">
                            <option value="">All areas</option>
                            <option value="transaction" <?= $entity_type === 'transaction' ? 'selected' : '' ?>>Transactions</option>
                            <option value="supply" <?= $entity_type === 'supply' ? 'selected' : '' ?>>Supplies</option>
                            <option value="equipment" <?= $entity_type === 'equipment' ? 'selected' : '' ?>>Equipment</option>
                            <option value="user" <?= $entity_type === 'user' ? 'selected' : '' ?>>User accounts</option>
                            <option value="barangay_official" <?= $entity_type === 'barangay_official' ? 'selected' : '' ?>>Barangay officials</option>
                            <option value="assign_item" <?= $entity_type === 'assign_item' ? 'selected' : '' ?>>Assignments</option>
                            <option value="report" <?= $entity_type === 'report' ? 'selected' : '' ?>>Reports</option>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label class="small mb-1">Search</label>
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Name, keyword, email…" value="<?= htmlspecialchars($q) ?>">
                    </div>
                    <div class="form-group col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm mr-2">Filter</button>
                        <a href="ActivityLog.php" class="btn btn-secondary btn-sm">Reset</a>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="thead-light">
                        <tr>
                            <th>Date &amp; time</th>
                            <th>Who did it</th>
                            <th>What happened</th>
                            <th>What it relates to</th>
                            <th>More detail (in plain words)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $row): ?>
                            <?php
                            $who = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                            if ($who === '') {
                                $who = $row['username'] ?? '';
                            }
                            if ($who === '') {
                                $who = '—';
                            }
                            $actionText = osaeits_audit_action_label((string)$row['action']);
                            $entityLabel = osaeits_audit_entity_label($row['entity_type'] ?? null, $row['entity_id'] ?? null);
                            $detailsStr = htmlspecialchars(osaeits_audit_details_summary((string)$row['action'], $row['entity_type'] ?? null, $row['details'] ?? null));
                            ?>
                            <tr>
                                <td nowrap><?= htmlspecialchars(date('F j, Y \a\t g:i A', strtotime($row['created_at']))) ?></td>
                                <td><?= htmlspecialchars($who) ?></td>
                                <td><?= htmlspecialchars($actionText) ?></td>
                                <td class="small"><?= htmlspecialchars($entityLabel) ?></td>
                                <td class="small text-break" style="max-width: 360px;"><?= $detailsStr ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($logs) && $total_items > 0): ?>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <div class="small text-muted">
                    Showing <?= ($offset + 1) ?> to <?= min($offset + count($logs), $total_items) ?> of <?= $total_items ?> log entries
                </div>
                <nav aria-label="Activity log pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        // Build base query params, preserving search and filter
                        $baseParams = [];
                        if (!empty($date_from)) {
                            $baseParams['date_from'] = $date_from;
                        }
                        if (!empty($date_to)) {
                            $baseParams['date_to'] = $date_to;
                        }
                        if (!empty($entity_type)) {
                            $baseParams['entity_type'] = $entity_type;
                        }
                        if (!empty($q)) {
                            $baseParams['q'] = $q;
                        }

                        // Helper function to build pagination URL
                        $buildPaginationUrl = function($pageNum, $params) {
                            $params['page'] = $pageNum;
                            return 'ActivityLog.php?' . http_build_query($params);
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
            <?php if (empty($logs)): ?>
                <p class="text-muted mb-0">No log entries match your filters.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
