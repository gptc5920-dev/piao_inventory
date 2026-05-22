<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';
require_once __DIR__ . '/../includes/quarter-report.php';

$page_title = 'Reports';
$current_page = 'reports';
$base_url = '../';

$report_generated_at = osaeits_report_generated_at();

[$defaultYear, $defaultQuarter] = osaeits_current_quarter();
$requestData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$selectedYear = (int)($requestData['quarter_year'] ?? $defaultYear);
$selectedQuarter = (int)($requestData['quarter_num'] ?? $defaultQuarter);
if ($selectedYear < 2000 || $selectedYear > 2100) $selectedYear = $defaultYear;
if ($selectedQuarter < 1 || $selectedQuarter > 4) $selectedQuarter = $defaultQuarter;
$showReport = isset($_GET['show_report']) && $_GET['show_report'] === '1';

[$date_from, $date_to] = osaeits_quarter_bounds($selectedYear, $selectedQuarter);
$quarterLabel = osaeits_quarter_label($selectedYear, $selectedQuarter);
$periodLine = "Reporting period: {$quarterLabel} ({$date_from} to {$date_to})";
$reportAuditDetails = [
    'report' => 'Statement of Turn Over of Accountability',
    'quarter' => $quarterLabel,
    'period' => "{$date_from} to {$date_to}",
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['report_action'] ?? '') === 'print') {
    require_once __DIR__ . '/../includes/activity-log.php';
    log_activity($pdo, (int)($_SESSION['user_id'] ?? 0), 'report.print', 'report', null, $reportAuditDetails);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

if ($showReport) {
    require_once __DIR__ . '/../includes/activity-log.php';
    log_activity($pdo, (int)($_SESSION['user_id'] ?? 0), 'report.display', 'report', null, $reportAuditDetails);
}

$serviceable_equipment_items = [];
$unserviceable_equipment_items = [];
$inventory_items = [];
$quarterPurchaseCount = 0;
$quarterIssueCount = 0;
$quarterReturnCount = 0;
$quarterPurchaseTotal = 0.0;
$preparedByName = 'Treasurer';
$notedByName = 'Barangay Captain';

if ($showReport) {

$equipmentCodeExpr = osaeits_item_code_select_expr($pdo, 'e', 'equipment');
$supplyCodeExpr = osaeits_item_code_select_expr($pdo, 's', 'supply');

$serviceableEquipmentStmt = $pdo->prepare(
    "SELECT {$equipmentCodeExpr} AS item_code, name, description, serial_number, brand, model, location, purok_area, appropriation, person_incharge
     FROM equipment
     e
     WHERE LOWER(TRIM(status)) IN ('servicable', 'serviceable', 'available', 'in_use', 'maintenance')
     ORDER BY name ASC"
);
$serviceableEquipmentStmt->execute();
$serviceable_equipment_items = $serviceableEquipmentStmt->fetchAll(PDO::FETCH_ASSOC);

$unserviceableEquipmentStmt = $pdo->prepare(
    "SELECT {$equipmentCodeExpr} AS item_code, name, description, serial_number, brand, model, location, purok_area, appropriation, person_incharge
     FROM equipment
     e
     WHERE LOWER(TRIM(status)) IN ('unservicable', 'unserviceable', 'nonservicesable', 'non_serviceable', 'retired')
     ORDER BY name ASC"
);
$unserviceableEquipmentStmt->execute();
$unserviceable_equipment_items = $unserviceableEquipmentStmt->fetchAll(PDO::FETCH_ASSOC);

$inventoryStmt = $pdo->prepare(
    "SELECT t.created_at, t.reference_number, t.item_type, t.transaction_type, t.quantity, t.unit_price, t.total_amount,
            t.notes,
            {$supplyCodeExpr} AS supply_code, s.name AS supply_name, s.description AS supply_description, s.supplier AS supply_supplier,
            {$equipmentCodeExpr} AS equipment_code, e.name AS equipment_name, e.description AS equipment_description,
            e.serial_number AS equipment_serial, e.brand AS equipment_brand, e.model AS equipment_model
     FROM transactions t
     LEFT JOIN supplies s ON t.item_type = 'supply' AND t.item_id = s.id
     LEFT JOIN equipment e ON t.item_type = 'equipment' AND t.item_id = e.id
     WHERE DATE(t.created_at) BETWEEN ? AND ?
     ORDER BY t.created_at DESC, t.id DESC"
);
$inventoryStmt->execute([$date_from, $date_to]);
$inventory_items = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);

$treasurerStmt = $pdo->prepare(
    "SELECT first_name, middle_name, last_name, suffix
     FROM barangay_officials
     WHERE status = 'active'
       AND LOWER(position_title) LIKE '%treasurer%'
     ORDER BY term_end IS NULL DESC, term_end DESC, id DESC
     LIMIT 1"
);
$treasurerStmt->execute();
$treasurer = $treasurerStmt->fetch(PDO::FETCH_ASSOC);

$captainStmt = $pdo->prepare(
    "SELECT first_name, middle_name, last_name, suffix
     FROM barangay_officials
     WHERE status = 'active'
       AND (
            LOWER(position_title) LIKE '%barangay captain%'
            OR LOWER(position_title) LIKE '%punong barangay%'
            OR LOWER(position_title) LIKE '%captain%'
       )
     ORDER BY term_end IS NULL DESC, term_end DESC, id DESC
     LIMIT 1"
);
$captainStmt->execute();
$captain = $captainStmt->fetch(PDO::FETCH_ASSOC);

$officialName = static function (mixed $row, string $fallback): string {
    if (!$row) return $fallback;
    $parts = [
        trim((string)($row['first_name'] ?? '')),
        trim((string)($row['middle_name'] ?? '')),
        trim((string)($row['last_name'] ?? '')),
    ];
    $name = trim(implode(' ', array_filter($parts, static fn($v) => $v !== '')));
    $suffix = trim((string)($row['suffix'] ?? ''));
    if ($suffix !== '') $name .= ', ' . $suffix;
    return $name !== '' ? $name : $fallback;
};

$preparedByName = $officialName($treasurer, 'Treasurer');
$notedByName = $officialName($captain, 'Barangay Captain');
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
$formatTransactionRemark = static function (array $row) use ($formatPurchaseSupplier): string {
    if (($row['transaction_type'] ?? '') === 'purchase') {
        return $formatPurchaseSupplier($row);
    }

    $notes = osaeits_clean_inventory_text($row['notes'] ?? '');
    return $notes !== '' ? $notes : '-';
};

$quarterPurchaseCount = 0;
$quarterIssueCount = 0;
$quarterReturnCount = 0;
$quarterPurchaseTotal = 0.0;
foreach ($inventory_items as $row) {
    if (($row['transaction_type'] ?? '') === 'purchase') {
        $quarterPurchaseCount++;
        $quarterPurchaseTotal += (float)($row['total_amount'] ?? 0);
    } elseif (($row['transaction_type'] ?? '') === 'issue') {
        $quarterIssueCount++;
    } elseif (($row['transaction_type'] ?? '') === 'return') {
        $quarterReturnCount++;
    }
}
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center no-print">
        <h6 class="m-0 font-weight-bold text-primary">Statement of Turn Over of Accountability</h6>
        <div class="no-print">
            <a href="reports.php?quarter_year=<?= (int)$defaultYear ?>&quarter_num=<?= (int)$defaultQuarter ?>&show_report=1" class="btn btn-outline-secondary btn-sm mr-1">Current quarter</a>
            <?php if ($showReport): ?>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="printStatementReport()">
                <i class="fas fa-print"></i> Print Statement
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body statement-report">
        <form method="get" class="mb-3 pb-3 border-bottom no-print">
            <input type="hidden" name="show_report" value="1">
            <div class="form-row align-items-end">
                <div class="form-group col-md-2">
                    <label class="small mb-1">Year</label>
                    <select name="quarter_year" class="form-control form-control-sm">
                        <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 5; $y--): ?>
                            <option value="<?= $y ?>" <?= $y === $selectedYear ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label class="small mb-1">Quarter</label>
                    <select name="quarter_num" class="form-control form-control-sm">
                        <?php for ($q = 1; $q <= 4; $q++): ?>
                            <option value="<?= $q ?>" <?= $q === $selectedQuarter ? 'selected' : '' ?>>Q<?= $q ?> (<?= ['Jan-Mar', 'Apr-Jun', 'Jul-Sep', 'Oct-Dec'][$q - 1] ?>)</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <button type="submit" class="btn btn-primary btn-sm"><?= $showReport ? 'Refresh Report' : 'Display Report' ?></button>
                </div>
            </div>
            <p class="small text-muted mb-0">This report is generated from Inventory (transactions), Supplies, and Equipment records within the selected calendar quarter.</p>
        </form>

        <?php if (!$showReport): ?>
            <div class="text-center text-muted py-5 no-print">
                <div class="h6 mb-1">No report displayed.</div>
                <div class="small">Choose a year and quarter, then click Display Report.</div>
            </div>
        <?php else: ?>
        <div class="report-print-header mb-3 d-none d-print-flex">
            <img src="<?= htmlspecialchars($base_url) ?>assets/images/piao_logo.png" alt="Barangay Piao logo" class="report-print-logo">
            <div class="report-print-copy text-center">
                <div class="small">Republic of the Philippines</div>
                <div class="small font-weight-bold text-uppercase">Province of Zamboanga del Norte</div>
                <div class="small font-weight-bold text-uppercase">Barangay Piao</div>
                <div class="font-weight-bold mt-2 text-uppercase">Statement of Turn Over of Accountability</div>
                <div class="small">as of <?= htmlspecialchars(date('F Y', strtotime($date_to))) ?></div>
                <div class="small mt-1"><?= htmlspecialchars($periodLine) ?></div>
                <div class="small mt-1 font-weight-bold">Generated: <?= htmlspecialchars($report_generated_at) ?></div>
            </div>
            <div class="report-print-logo-spacer" aria-hidden="true"></div>
        </div>

        <div class="table-responsive no-mobile-cardview mb-4">
            <table class="table table-bordered table-sm statement-table">
                <thead class="thead-light">
                    <tr><th colspan="6" class="text-left">QUARTER SUMMARY</th></tr>
                    <tr>
                        <th>Serviceable Equipment</th>
                        <th>Nonservicesable Equipment</th>
                        <th>Purchases</th>
                        <th>Issues</th>
                        <th>Returns</th>
                        <th>Purchase Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= count($serviceable_equipment_items) ?></td>
                        <td><?= count($unserviceable_equipment_items) ?></td>
                        <td><?= $quarterPurchaseCount ?></td>
                        <td><?= $quarterIssueCount ?></td>
                        <td><?= $quarterReturnCount ?></td>
                        <td>PHP <?= number_format($quarterPurchaseTotal, 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="table-responsive no-mobile-cardview mb-4">
            <table class="table table-bordered table-sm statement-table">
                <thead class="thead-light">
                    <tr><th colspan="8" class="text-left">SERVICEABLE ITEMS</th></tr>
                    <tr>
                        <th width="40">Item No.</th>
                        <th>Items &amp; Description</th>
                        <th width="70">Quantity</th>
                        <th width="70">Unit</th>
                        <th width="110">Purok/Area</th>
                        <th>Appropriation</th>
                        <th width="130">Person Incharge</th>
                        <th width="110">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($serviceable_equipment_items)): ?>
                        <tr><td colspan="8" class="text-center text-muted">No serviceable equipment found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($serviceable_equipment_items as $idx => $it): ?>
                            <tr>
                                <td><?= (int)($idx + 1) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars(osaeits_equipment_display_name($it)) ?></strong>
                                    <div class="small text-muted"><?= htmlspecialchars($it['item_code']) ?> | Equipment</div>
                                </td>
                                <td>1</td>
                                <td>unit</td>
                                <td><?= htmlspecialchars(($it['purok_area'] ?: $it['location']) ?: '-') ?></td>
                                <td><?= htmlspecialchars($it['appropriation'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($it['person_incharge'] ?: '-') ?></td>
                                <td>Serviceable</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-responsive no-mobile-cardview">
            <table class="table table-bordered table-sm statement-table">
                <thead class="thead-light">
                    <tr><th colspan="8" class="text-left">NONSERVICESABLE ITEMS</th></tr>
                    <tr>
                        <th width="40">Item No.</th>
                        <th>Items &amp; Description</th>
                        <th width="70">Quantity</th>
                        <th width="70">Unit</th>
                        <th width="110">Purok/Area</th>
                        <th>Appropriation</th>
                        <th width="130">Person Incharge</th>
                        <th width="110">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($unserviceable_equipment_items)): ?>
                        <tr><td colspan="8" class="text-center text-muted">No nonservicesable equipment found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($unserviceable_equipment_items as $idx => $it): ?>
                            <tr>
                                <td><?= (int)($idx + 1) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars(osaeits_equipment_display_name($it)) ?></strong>
                                    <div class="small text-muted"><?= htmlspecialchars($it['item_code']) ?> | Equipment</div>
                                </td>
                                <td>1</td>
                                <td>unit</td>
                                <td><?= htmlspecialchars(($it['purok_area'] ?: $it['location']) ?: '-') ?></td>
                                <td><?= htmlspecialchars($it['appropriation'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($it['person_incharge'] ?: '-') ?></td>
                                <td>Nonservicesable</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-responsive no-mobile-cardview mt-4">
            <table class="table table-bordered table-sm statement-table">
                <thead class="thead-light">
                    <tr><th colspan="9" class="text-left">INVENTORY TRANSACTIONS (QUARTERLY)</th></tr>
                    <tr>
                        <th width="145">Date</th>
                        <th width="110">Reference</th>
                        <th>Item</th>
                        <th width="95">Item Type</th>
                        <th width="110">Transaction</th>
                        <th width="75">Quantity</th>
                        <th width="95">Unit Price</th>
                        <th width="95">Total</th>
                        <th width="120">Supplier / Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inventory_items)): ?>
                        <tr><td colspan="9" class="text-center text-muted">No inventory transactions found for this quarter.</td></tr>
                    <?php else: ?>
                        <?php foreach ($inventory_items as $it): ?>
                            <?php
                                if ($it['item_type'] === 'supply') {
                                    $itemName = trim((string)($it['supply_code'] ?? '') . ' - ' . osaeits_supply_display_name([
                                        'name' => $it['supply_name'] ?? 'Supply',
                                        'description' => $it['supply_description'] ?? '',
                                    ]), ' -');
                                } else {
                                    $itemName = trim((string)($it['equipment_code'] ?? '') . ' - ' . osaeits_equipment_display_name([
                                        'name' => $it['equipment_name'] ?? 'Equipment',
                                        'description' => $it['equipment_description'] ?? '',
                                        'brand' => $it['equipment_brand'] ?? '',
                                        'model' => $it['equipment_model'] ?? '',
                                        'serial_number' => $it['equipment_serial'] ?? '',
                                    ]), ' -');
                                }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars(date('M j, Y H:i', strtotime($it['created_at']))) ?></td>
                                <td><?= htmlspecialchars($it['reference_number'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($itemName) ?></td>
                                <td><?= htmlspecialchars(ucfirst((string)$it['item_type'])) ?></td>
                                <td><?= htmlspecialchars(ucfirst((string)$it['transaction_type'])) ?></td>
                                <td><?= (int)$it['quantity'] ?></td>
                                <td>PHP <?= number_format((float)$it['unit_price'], 2) ?></td>
                                <td>PHP <?= number_format((float)$it['total_amount'], 2) ?></td>
                                <td><?= htmlspecialchars($formatTransactionRemark($it)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-none d-print-flex justify-content-between mt-5 px-4">
            <div class="text-center" style="min-width: 240px;">
                <div style="margin-bottom: 15px; font-size: 12px;">Prepared by:</div>
                <div class="border-bottom border-dark pt-1 font-weight-bold text-uppercase" style="font-size: 12px;"><?= htmlspecialchars($preparedByName) ?></div>
                <div style="font-size: 11px;">Treasurer</div>
            </div>
            <div class="text-center" style="min-width: 240px;">
                <div style="margin-bottom: 15px; font-size: 12px;">Noted by:</div>
                <div class="border-bottom border-dark pt-1 font-weight-bold text-uppercase" style="font-size: 12px;"><?= htmlspecialchars($notedByName) ?></div>
                <div style="font-size: 11px;">Barangay Captain</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($showReport): ?>
<script>
function printStatementReport() {
    const data = new FormData();
    data.append('report_action', 'print');
    data.append('quarter_year', '<?= (int)$selectedYear ?>');
    data.append('quarter_num', '<?= (int)$selectedQuarter ?>');

    if (navigator.sendBeacon) {
        navigator.sendBeacon('reports.php', data);
        window.print();
        return;
    }

    fetch('reports.php', {
        method: 'POST',
        body: data,
        credentials: 'same-origin'
    }).finally(function () {
        window.print();
    });
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
