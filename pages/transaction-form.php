<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

function osaeits_find_open_equipment_issue_assignment(PDO $pdo, int $equipmentId): ?array
{
    if ($equipmentId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            a.*,
            t.id AS issue_transaction_id,
            t.reference_number AS issue_reference_number,
            t.quantity AS issue_quantity,
            t.unit_price AS issue_unit_price,
            t.total_amount AS issue_total_amount,
            t.notes AS issue_notes,
            t.created_at AS issue_created_at,
            u.first_name AS issue_user_first_name,
            u.last_name AS issue_user_last_name
        FROM assign_items a
        INNER JOIN transactions t ON a.item_type = 'inventory' AND a.item_ref_id = t.id
        LEFT JOIN users u ON t.user_id = u.id
        WHERE t.item_type = 'equipment'
          AND t.item_id = ?
          AND t.transaction_type = 'issue'
          AND a.status = 'assigned'
        ORDER BY t.created_at DESC, t.id DESC, a.id DESC
        LIMIT 1
    ");
    $stmt->execute([$equipmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }

    $stmt = $pdo->prepare("
        SELECT
            a.*,
            NULL AS issue_transaction_id,
            NULL AS issue_reference_number,
            a.quantity AS issue_quantity,
            NULL AS issue_unit_price,
            NULL AS issue_total_amount,
            a.notes AS issue_notes,
            a.created_at AS issue_created_at,
            NULL AS issue_user_first_name,
            NULL AS issue_user_last_name
        FROM assign_items a
        WHERE a.item_type = 'equipment'
          AND a.item_ref_id = ?
          AND a.status = 'assigned'
        ORDER BY a.assigned_date DESC, a.id DESC
        LIMIT 1
    ");
    $stmt->execute([$equipmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function osaeits_find_latest_equipment_issue_transaction(PDO $pdo, int $equipmentId): ?array
{
    if ($equipmentId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            NULL AS id,
            NULL AS assigned_to,
            NULL AS assigned_area,
            NULL AS appropriation,
            NULL AS assigned_date,
            NULL AS notes,
            t.id AS issue_transaction_id,
            t.reference_number AS issue_reference_number,
            t.quantity AS issue_quantity,
            t.unit_price AS issue_unit_price,
            t.total_amount AS issue_total_amount,
            t.notes AS issue_notes,
            t.created_at AS issue_created_at,
            u.first_name AS issue_user_first_name,
            u.last_name AS issue_user_last_name
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        WHERE t.item_type = 'equipment'
          AND t.item_id = ?
          AND t.transaction_type = 'issue'
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT 1
    ");
    $stmt->execute([$equipmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function osaeits_strict_equipment_status_choice(string $status): string
{
    $status = strtolower(trim($status));

    if (in_array($status, ['servicable', 'unservicable'], true)) {
        return $status;
    }
    if (in_array($status, ['unserviceable', 'nonservicesable', 'non_serviceable'], true)) {
        return 'unservicable';
    }
    return '';
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$prefill_item_type = $_GET['item_type'] ?? '';
$prefill_item_type = in_array($prefill_item_type, ['supply', 'equipment'], true) ? $prefill_item_type : '';
$prefill_item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
$movement_type_labels = [
    'purchase' => 'Purchase',
    'issue' => 'Issue',
    'return' => 'Return',
];
$prefill_transaction_type = $_GET['transaction_type'] ?? '';
$prefill_transaction_type = in_array($prefill_transaction_type, array_keys($movement_type_labels), true) ? $prefill_transaction_type : '';

$tx = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) {
        $_SESSION['success_message'] = 'Transaction not found.';
        header('Location: inventory.php');
        exit;
    }
}

$issueAssignment = null;
if ($tx && ($tx['transaction_type'] ?? '') === 'issue') {
    $assignmentStmt = $pdo->prepare("
        SELECT *
        FROM assign_items
        WHERE item_type = 'inventory'
          AND item_ref_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $assignmentStmt->execute([$id]);
    $issueAssignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$current_page = 'inventory';
$base_url = '../';
$supplySummarySql = osaeits_supply_movement_summary_sql();
$supplyStockExpr = osaeits_supply_stock_expression('tx', 's');
$supplyCodeExpr = osaeits_item_code_select_expr($pdo, 's', 'supply');
$supplies = $pdo->query("
    SELECT
        s.id,
        {$supplyCodeExpr} AS item_code,
        s.name,
        s.description,
        s.supplier,
        s.unit,
        {$supplyStockExpr} AS current_stock,
        COALESCE(NULLIF(tx.latest_purchase_price, ''), s.unit_price, 0) AS unit_price
    FROM supplies s
    LEFT JOIN ({$supplySummarySql}) tx ON tx.item_id = s.id
    ORDER BY s.name ASC, s.description ASC, s.unit ASC
")->fetchAll(PDO::FETCH_ASSOC);

$equipmentSummarySql = osaeits_equipment_movement_summary_sql();
$equipmentCodeExpr = osaeits_item_code_select_expr($pdo, 'e', 'equipment');
$equipment = $pdo->query("
    SELECT
        e.id,
        {$equipmentCodeExpr} AS item_code,
        e.name,
        e.description,
        e.brand,
        e.model,
        e.serial_number,
        e.status,
        e.location,
        e.purchase_price,
        COALESCE(tx.issued_balance, 0) AS issued_balance
    FROM equipment e
    LEFT JOIN ({$equipmentSummarySql}) tx ON tx.item_id = e.id
    ORDER BY e.name ASC, e.serial_number ASC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($equipment as &$equipmentRow) {
    $openAssignment = osaeits_find_open_equipment_issue_assignment($pdo, (int)$equipmentRow['id']);
    $openIssue = $openAssignment;
    if (!$openIssue || empty($openIssue['issue_transaction_id'])) {
        $latestIssue = osaeits_find_latest_equipment_issue_transaction($pdo, (int)$equipmentRow['id']);
        if ($latestIssue) {
            $openIssue = array_merge($latestIssue, $openAssignment ?: []);
            foreach ([
                'issue_transaction_id',
                'issue_reference_number',
                'issue_quantity',
                'issue_unit_price',
                'issue_total_amount',
                'issue_notes',
                'issue_created_at',
                'issue_user_first_name',
                'issue_user_last_name',
            ] as $issueField) {
                $openIssue[$issueField] = $latestIssue[$issueField] ?? ($openIssue[$issueField] ?? null);
            }
        }
    }
    $equipmentRow['open_assignment_id'] = $openAssignment ? (int)$openAssignment['id'] : 0;
    $equipmentRow['open_issue_transaction_id'] = $openIssue ? (int)($openIssue['issue_transaction_id'] ?? 0) : 0;
    $equipmentRow['open_issue_reference_number'] = $openIssue['issue_reference_number'] ?? null;
    $equipmentRow['open_assigned_to'] = $openAssignment['assigned_to'] ?? null;
    $equipmentRow['open_assigned_area'] = $openAssignment['assigned_area'] ?? null;
    $equipmentRow['open_appropriation'] = $openAssignment['appropriation'] ?? null;
    $equipmentRow['open_assigned_date'] = $openAssignment['assigned_date'] ?? null;
    $equipmentRow['open_assignment_notes'] = $openAssignment['notes'] ?? null;
    $equipmentRow['open_issue_quantity'] = $openIssue ? (int)($openIssue['issue_quantity'] ?? 0) : 0;
    $equipmentRow['open_issue_unit_price'] = $openIssue['issue_unit_price'] ?? null;
    $equipmentRow['open_issue_total_amount'] = $openIssue['issue_total_amount'] ?? null;
    $equipmentRow['open_issue_notes'] = $openIssue['issue_notes'] ?? null;
    $equipmentRow['open_issue_created_at'] = $openIssue['issue_created_at'] ?? null;
    $issueUser = $openIssue
        ? trim((string)($openIssue['issue_user_first_name'] ?? '') . ' ' . (string)($openIssue['issue_user_last_name'] ?? ''))
        : '';
    $equipmentRow['open_issue_recorded_by'] = $issueUser !== '' ? $issueUser : null;
}
unset($equipmentRow);

$error = '';
$return_equipment_status = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnAssignment = null;
    $item_type = $_POST['item_type'] ?? '';
    $item_id = (int)($_POST['item_id'] ?? 0);
    $transaction_type = $_POST['transaction_type'] ?? '';
    $purchase_item_mode = (!$tx && $transaction_type === 'purchase' && ($_POST['purchase_item_mode'] ?? '') === 'new') ? 'new' : 'existing';
    $quantity = (int)($_POST['quantity'] ?? 0);
    $unit_price = (float)($_POST['unit_price'] ?? 0);
    $reference_number = $tx
        ? osaeits_clean_inventory_text($tx['reference_number'] ?? '')
        : osaeits_clean_inventory_text($_POST['reference_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $purchase_supplier = osaeits_clean_inventory_text($_POST['purchase_supplier'] ?? '');
    $purchase_detail = osaeits_clean_inventory_text($_POST['purchase_detail'] ?? '');
    $new_supply_name = osaeits_clean_inventory_text($_POST['new_supply_name'] ?? '');
    $new_supply_description = osaeits_clean_inventory_text($_POST['new_supply_description'] ?? '');
    $new_supply_unit = osaeits_clean_inventory_text($_POST['new_supply_unit'] ?? '');
    $new_supply_minimum_stock = max(0, (int)($_POST['new_supply_minimum_stock'] ?? 0));
    $new_supply_supplier = osaeits_clean_inventory_text($_POST['new_supply_supplier'] ?? '');
    $new_equipment_name = osaeits_clean_inventory_text($_POST['new_equipment_name'] ?? '');
    $new_equipment_description = osaeits_clean_inventory_text($_POST['new_equipment_description'] ?? '');
    $new_equipment_serial_number = osaeits_clean_inventory_text($_POST['new_equipment_serial_number'] ?? '');
    $new_equipment_model = osaeits_clean_inventory_text($_POST['new_equipment_model'] ?? '');
    $new_equipment_brand = osaeits_clean_inventory_text($_POST['new_equipment_brand'] ?? '');
    $new_equipment_status = osaeits_normalize_equipment_status($_POST['new_equipment_status'] ?? 'servicable');
    $return_equipment_status = osaeits_strict_equipment_status_choice($_POST['return_equipment_status'] ?? '');
    $new_equipment_location = osaeits_clean_inventory_text($_POST['new_equipment_location'] ?? '');
    $new_equipment_purchase_date = trim($_POST['new_equipment_purchase_date'] ?? '') ?: null;
    $new_equipment_warranty_expiry = trim($_POST['new_equipment_warranty_expiry'] ?? '') ?: null;
    $issue_assigned_to = osaeits_clean_inventory_text($_POST['assigned_to'] ?? '');
    $issue_assigned_area = osaeits_clean_inventory_text($_POST['assigned_area'] ?? '');
    $issue_appropriation = osaeits_clean_inventory_text($_POST['appropriation'] ?? '');
    $issue_assigned_date = trim((string)($_POST['assigned_date'] ?? ''));
    $validItemTypes = ['supply', 'equipment'];
    $validTxTypesByItem = [
        'supply' => ['purchase', 'issue'],
        'equipment' => ['purchase', 'issue', 'return'],
    ];
    if ($tx && ($tx['item_type'] ?? '') === 'supply' && ($tx['transaction_type'] ?? '') === 'adjustment') {
        $validTxTypesByItem['supply'][] = 'adjustment';
    }

    if (!in_array($item_type, $validItemTypes, true) || !in_array($transaction_type, $validTxTypesByItem[$item_type] ?? [], true)) {
        $error = 'Invalid item type or transaction type.';
    } elseif ($purchase_item_mode === 'existing' && $item_id <= 0) {
        $error = 'Item is required.';
    } elseif ($purchase_item_mode === 'new' && $item_type === 'supply' && ($new_supply_name === '' || $new_supply_unit === '')) {
        $error = 'New supply name and unit are required.';
    } elseif ($purchase_item_mode === 'new' && $item_type === 'equipment' && $new_equipment_name === '') {
        $error = 'New equipment name is required.';
    } elseif ($quantity <= 0) {
        $error = 'Quantity is required.';
    } elseif ($transaction_type === 'issue' && ($issue_assigned_to === '' || $issue_assigned_date === '')) {
        $error = 'Assigned to and assigned date are required for issue transactions.';
    } elseif ($transaction_type === 'issue' && $issue_assigned_date !== '' && strtotime($issue_assigned_date) === false) {
        $error = 'Assigned date is invalid.';
    } elseif ($transaction_type === 'return' && $item_type === 'equipment' && $return_equipment_status === '') {
        $error = 'Returned equipment status is required.';
    } else {
        if ($transaction_type === 'purchase' && $purchase_item_mode === 'new' && $item_type === 'supply') {
            $duplicate = osaeits_find_supply_duplicate($pdo, $new_supply_name, $new_supply_description, $new_supply_unit);
            if ($duplicate) {
                $duplicateCode = osaeits_ensure_item_identifier($pdo, 'supply', (int)$duplicate['id']);
                $duplicateLabel = trim($duplicateCode . ' - ' . osaeits_supply_display_name($duplicate), ' -');
                $error = "Warning: {$duplicateLabel} already exists. No supply record was replaced. Choose Existing item to record this purchase.";
            }
        } elseif ($transaction_type === 'purchase' && $purchase_item_mode === 'new' && $item_type === 'equipment') {
            $duplicate = osaeits_find_equipment_duplicate(
                $pdo,
                $new_equipment_name,
                $new_equipment_serial_number,
                $new_equipment_brand,
                $new_equipment_model
            );
            if ($duplicate) {
                $duplicateCode = osaeits_ensure_item_identifier($pdo, 'equipment', (int)$duplicate['id']);
                $duplicateLabel = trim($duplicateCode . ' - ' . osaeits_equipment_display_name($duplicate), ' -');
                $error = "Warning: {$duplicateLabel} already exists with the same serial number. No equipment record was replaced.";
            }
        }

        if ($error === '' && in_array($transaction_type, ['issue', 'return'], true)) {
            $reference_number = osaeits_clean_inventory_text($tx['reference_number'] ?? '');
            if ($reference_number === '') {
                $reference_number = osaeits_generate_transaction_reference($pdo, $item_type, $transaction_type);
            }
        } elseif ($error === '' && $reference_number === '') {
            $reference_number = osaeits_generate_transaction_reference($pdo, $item_type, $transaction_type);
        } elseif ($error === '' && osaeits_transaction_reference_exists($pdo, $reference_number, $tx ? (int)$tx['id'] : 0)) {
            $error = 'Reference number already exists. Use a unique trace/reference number.';
        }

        if ($item_type === 'equipment') {
            $quantity = 1;
        }

        // For supply issues, verify there is enough stock before proceeding.
        if ($item_type === 'supply' && $transaction_type === 'issue' && $error === '') {
            $currentStock = osaeits_supply_current_stock($pdo, $item_id);
            // In edit mode, the old issued qty was already deducted — add it back for comparison.
            $editedOldQty = ($tx && $tx['item_type'] === 'supply' && (int)$tx['item_id'] === $item_id && $tx['transaction_type'] === 'issue')
                ? (int)$tx['quantity']
                : 0;
            $availableForIssue = $currentStock + $editedOldQty;
            if ($quantity > $availableForIssue) {
                $error = "Insufficient stock to issue. Available: {$availableForIssue}";
            }
        }

        if ($item_type === 'equipment' && $transaction_type !== 'purchase' && $error === '') {
            $equipmentStmt = $pdo->prepare("SELECT status FROM equipment WHERE id = ? LIMIT 1");
            $equipmentStmt->execute([$item_id]);
            $equipmentStatus = strtolower(trim((string)$equipmentStmt->fetchColumn()));
            if ($equipmentStatus === '') {
                $error = 'Selected equipment does not exist anymore.';
            } else {
                $issuedBalance = osaeits_equipment_issued_balance($pdo, $item_id, $tx ? (int)$tx['id'] : 0);
                if ($transaction_type === 'issue') {
                    if ($equipmentStatus !== 'servicable') {
                        $error = 'Only servicable equipment can be issued.';
                    } elseif ($issuedBalance > 0) {
                        $error = 'This equipment is already issued. Record a return before issuing it again.';
                    }
                } elseif ($transaction_type === 'return') {
                    if ($issuedBalance <= 0) {
                        $error = 'This equipment is not currently issued.';
                    } else {
                        $returnAssignment = osaeits_find_open_equipment_issue_assignment($pdo, $item_id);
                    }
                }
            }
        }
    }
    if ($error === '') {
        try {
            $pdo->beginTransaction();
            $createdEntityType = '';
            $createdEntityId = 0;
            $existingPurchaseSameDetails = false;
            $existingPurchaseChangedDetails = false;
            $createdSupplyVariant = false;
            $usedExistingSupplyVariant = false;
            $purchaseHistorySupplier = '';
            $assignmentEntityId = 0;
            $assignmentLogAction = '';
            $assignmentAuditDetails = null;

            if ($tx) {
                // Revert old effects then apply updated effects.
                osaeits_apply_transaction_effects($pdo, $tx, -1);
            }

            if (!$tx && $transaction_type === 'purchase' && $purchase_item_mode === 'new') {
                if ($item_type === 'supply') {
                    $duplicate = osaeits_find_supply_duplicate($pdo, $new_supply_name, $new_supply_description, $new_supply_unit);
                    if ($duplicate) {
                        $duplicateCode = osaeits_ensure_item_identifier($pdo, 'supply', (int)$duplicate['id']);
                        $duplicateLabel = trim($duplicateCode . ' - ' . osaeits_supply_display_name($duplicate), ' -');
                        $error = "Warning: {$duplicateLabel} already exists. No supply record was replaced. Choose Existing item to record this purchase.";
                        throw new RuntimeException($error);
                    } else {
                        $productMinimum = osaeits_supply_product_minimum_stock($pdo, $new_supply_name);
                        $minimumStockForNewSupply = ($new_supply_minimum_stock === 0 && $productMinimum > 0)
                            ? $productMinimum
                            : $new_supply_minimum_stock;
                        $stmt = $pdo->prepare("
                            INSERT INTO supplies
                                (name, description, category, unit, current_stock, minimum_stock, unit_price, supplier)
                            VALUES (?, ?, ?, ?, 0, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $new_supply_name,
                            $new_supply_description,
                            'Supplies',
                            $new_supply_unit,
                            $minimumStockForNewSupply,
                            $unit_price,
                            $new_supply_supplier,
                        ]);
                        $item_id = (int)$pdo->lastInsertId();
                        $createdEntityType = 'supply';
                        $createdEntityId = $item_id;
                        osaeits_sync_supply_product_minimum_stock($pdo, $new_supply_name, $minimumStockForNewSupply);
                    }
                } else {
                    $duplicate = osaeits_find_equipment_duplicate(
                        $pdo,
                        $new_equipment_name,
                        $new_equipment_serial_number,
                        $new_equipment_brand,
                        $new_equipment_model
                    );
                    if ($duplicate) {
                        $duplicateCode = osaeits_ensure_item_identifier($pdo, 'equipment', (int)$duplicate['id']);
                        $duplicateLabel = trim($duplicateCode . ' - ' . osaeits_equipment_display_name($duplicate), ' -');
                        $error = "Warning: {$duplicateLabel} already exists with the same serial number. No equipment record was replaced.";
                        throw new RuntimeException($error);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO equipment
                                (name, description, category, serial_number, model, brand, status, location, purchase_date, warranty_expiry, purchase_price)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $new_equipment_name,
                            $new_equipment_description,
                            'Equipment',
                            $new_equipment_serial_number !== '' ? $new_equipment_serial_number : null,
                            $new_equipment_model !== '' ? $new_equipment_model : null,
                            $new_equipment_brand !== '' ? $new_equipment_brand : null,
                            $new_equipment_status,
                            $new_equipment_location !== '' ? $new_equipment_location : null,
                            $new_equipment_purchase_date,
                            $new_equipment_warranty_expiry,
                            $unit_price,
                        ]);
                        $item_id = (int)$pdo->lastInsertId();
                        $createdEntityType = 'equipment';
                        $createdEntityId = $item_id;
                    }
                }
                osaeits_ensure_item_identifier($pdo, $item_type, $item_id);
            } elseif ($transaction_type === 'purchase' && $item_id > 0) {
                if ($item_type === 'supply') {
                    $stmt = $pdo->prepare("SELECT name, description, unit, minimum_stock, unit_price, supplier FROM supplies WHERE id = ? LIMIT 1");
                    $stmt->execute([$item_id]);
                    $currentSupply = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    if (!$currentSupply) {
                        $error = 'Selected supply does not exist anymore.';
                        throw new RuntimeException($error);
                    }
                    $savedDetail = osaeits_clean_inventory_text($currentSupply['description'] ?? '');
                    $detailChanged = $purchase_detail !== ''
                        && osaeits_normalize_inventory_text($savedDetail) !== osaeits_normalize_inventory_text($purchase_detail);

                    if ($detailChanged) {
                        $duplicate = osaeits_find_supply_duplicate(
                            $pdo,
                            (string)($currentSupply['name'] ?? ''),
                            $purchase_detail,
                            (string)($currentSupply['unit'] ?? ''),
                            $item_id
                        );
                        if ($duplicate) {
                            $item_id = (int)$duplicate['id'];
                            $usedExistingSupplyVariant = true;
                        } else {
                            $productMinimum = osaeits_supply_product_minimum_stock($pdo, (string)($currentSupply['name'] ?? ''));
                            $stmt = $pdo->prepare("
                                INSERT INTO supplies
                                    (name, description, category, unit, current_stock, minimum_stock, unit_price, supplier)
                                VALUES (?, ?, 'Supplies', ?, 0, ?, ?, ?)
                            ");
                            $stmt->execute([
                                (string)$currentSupply['name'],
                                $purchase_detail,
                                (string)$currentSupply['unit'],
                                $productMinimum,
                                $unit_price,
                                $purchase_supplier !== '' ? $purchase_supplier : null,
                            ]);
                            $item_id = (int)$pdo->lastInsertId();
                            $createdEntityType = 'supply';
                            $createdEntityId = $item_id;
                            $createdSupplyVariant = true;
                            osaeits_sync_supply_product_minimum_stock($pdo, (string)($currentSupply['name'] ?? ''), $productMinimum);
                        }
                        osaeits_ensure_item_identifier($pdo, 'supply', $item_id);
                    } else {
                        $existingPurchaseSameDetails = true;
                    }
                    $purchaseHistorySupplier = $purchase_supplier;
                } elseif ($item_type === 'equipment' && $unit_price > 0) {
                    $stmt = $pdo->prepare("SELECT purchase_price FROM equipment WHERE id = ? LIMIT 1");
                    $stmt->execute([$item_id]);
                    $savedPrice = (float)$stmt->fetchColumn();
                    if (abs($savedPrice - $unit_price) > 0.009) {
                        $stmt = $pdo->prepare("UPDATE equipment SET purchase_price = ? WHERE id = ?");
                        $stmt->execute([$unit_price, $item_id]);
                        $existingPurchaseChangedDetails = true;
                    } else {
                        $existingPurchaseSameDetails = true;
                    }
                }
            }

            if ($item_id > 0) {
                osaeits_ensure_item_identifier($pdo, $item_type, $item_id);
            }
            if ($transaction_type === 'purchase'
                && $item_type === 'supply'
                && $purchase_item_mode === 'new'
                && $purchaseHistorySupplier === ''
                && $new_supply_supplier !== '') {
                $purchaseHistorySupplier = $new_supply_supplier;
            }
            if ($transaction_type === 'purchase'
                && $item_type === 'supply'
                && $purchaseHistorySupplier !== ''
                && stripos($notes, 'Supplier:') === false) {
                $notes = trim($notes . "\nSupplier: " . $purchaseHistorySupplier);
            }
            if ($transaction_type === 'purchase'
                && $item_type === 'supply'
                && $purchase_detail !== ''
                && stripos($notes, 'Detail:') === false) {
                $notes = trim($notes . "\nDetail: " . $purchase_detail);
            }
            if (in_array($transaction_type, ['issue', 'return'], true) && $item_id > 0) {
                if ($item_type === 'supply') {
                    $issuePriceStmt = $pdo->prepare("
                        SELECT COALESCE(NULLIF(tx.latest_purchase_price, ''), s.unit_price, 0) AS inventory_price
                        FROM supplies s
                        LEFT JOIN ({$supplySummarySql}) tx ON tx.item_id = s.id
                        WHERE s.id = ?
                        LIMIT 1
                    ");
                    $issuePriceStmt->execute([$item_id]);
                    $unit_price = (float)$issuePriceStmt->fetchColumn();
                } elseif ($item_type === 'equipment') {
                    $issuePriceStmt = $pdo->prepare("SELECT purchase_price FROM equipment WHERE id = ? LIMIT 1");
                    $issuePriceStmt->execute([$item_id]);
                    $unit_price = (float)$issuePriceStmt->fetchColumn();
                }
            }
            $total_amount = $quantity * $unit_price;

            $newTxId = 0;
            if ($tx) {
                $stmt = $pdo->prepare("UPDATE transactions SET item_type=?, item_id=?, transaction_type=?, quantity=?, unit_price=?, total_amount=?, reference_number=?, notes=?, user_id=? WHERE id=?");
                $stmt->execute([
                    $item_type, $item_id, $transaction_type, $quantity, $unit_price, $total_amount,
                    $reference_number !== '' ? $reference_number : null,
                    $notes !== '' ? $notes : null,
                    (int)$_SESSION['user_id'],
                    $id
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO transactions (item_type, item_id, transaction_type, quantity, unit_price, total_amount, reference_number, notes, user_id) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $item_type, $item_id, $transaction_type, $quantity, $unit_price, $total_amount,
                    $reference_number !== '' ? $reference_number : null,
                    $notes !== '' ? $notes : null,
                    (int)$_SESSION['user_id']
                ]);
                $newTxId = (int)$pdo->lastInsertId();
            }
            $transactionId = $tx ? $id : $newTxId;

            if ($transaction_type === 'issue') {
                $assignmentStmt = $pdo->prepare("
                    SELECT id
                    FROM assign_items
                    WHERE item_type = 'inventory'
                      AND item_ref_id = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $assignmentStmt->execute([$transactionId]);
                $existingAssignmentId = (int)$assignmentStmt->fetchColumn();
                if ($existingAssignmentId > 0) {
                    $assignmentStmt = $pdo->prepare("
                        UPDATE assign_items
                        SET quantity = ?,
                            assigned_to = ?,
                            assigned_area = ?,
                            appropriation = ?,
                            assigned_date = ?,
                            status = 'assigned',
                            notes = ?,
                            user_id = ?
                        WHERE id = ?
                    ");
                    $assignmentStmt->execute([
                        $quantity,
                        $issue_assigned_to,
                        $issue_assigned_area !== '' ? $issue_assigned_area : null,
                        $issue_appropriation !== '' ? $issue_appropriation : null,
                        $issue_assigned_date,
                        $notes !== '' ? $notes : null,
                        (int)$_SESSION['user_id'],
                        $existingAssignmentId,
                    ]);
                    $assignmentEntityId = $existingAssignmentId;
                    $assignmentLogAction = 'assign_item.update';
                } else {
                    $assignmentStmt = $pdo->prepare("
                        INSERT INTO assign_items
                            (item_type, item_ref_id, quantity, assigned_to, assigned_area, appropriation, assigned_date, status, notes, user_id)
                        VALUES ('inventory', ?, ?, ?, ?, ?, ?, 'assigned', ?, ?)
                    ");
                    $assignmentStmt->execute([
                        $transactionId,
                        $quantity,
                        $issue_assigned_to,
                        $issue_assigned_area !== '' ? $issue_assigned_area : null,
                        $issue_appropriation !== '' ? $issue_appropriation : null,
                        $issue_assigned_date,
                        $notes !== '' ? $notes : null,
                        (int)$_SESSION['user_id'],
                    ]);
                    $assignmentEntityId = (int)$pdo->lastInsertId();
                    $assignmentLogAction = 'assign_item.create';
                }

                if ($item_type === 'equipment') {
                    $sync = $pdo->prepare("UPDATE equipment SET purok_area = ?, appropriation = ?, person_incharge = ? WHERE id = ?");
                    $sync->execute([
                        $issue_assigned_area !== '' ? $issue_assigned_area : null,
                        $issue_appropriation !== '' ? $issue_appropriation : null,
                        $issue_assigned_to,
                        $item_id,
                    ]);
                }

                $assignmentAuditDetails = [
                    'item_type' => $item_type,
                    'item_ref_id' => $item_id,
                    'transaction_id' => $transactionId,
                    'assigned_to' => $issue_assigned_to,
                    'assigned_area' => $issue_assigned_area !== '' ? $issue_assigned_area : null,
                    'appropriation' => $issue_appropriation !== '' ? $issue_appropriation : null,
                    'assigned_date' => $issue_assigned_date,
                    'status' => 'assigned',
                    'quantity' => $quantity,
                ];
            }
            if ($transaction_type === 'return' && $item_type === 'equipment') {
                if (!$returnAssignment) {
                    $returnAssignment = osaeits_find_open_equipment_issue_assignment($pdo, $item_id);
                }
                if ($returnAssignment) {
                    $assignmentStmt = $pdo->prepare("
                        UPDATE assign_items
                        SET status = 'returned',
                            notes = ?,
                            user_id = ?
                        WHERE id = ?
                    ");
                    $assignmentStmt->execute([
                        $notes !== '' ? $notes : ($returnAssignment['notes'] ?? null),
                        (int)$_SESSION['user_id'],
                        (int)$returnAssignment['id'],
                    ]);
                    $assignmentEntityId = (int)$returnAssignment['id'];
                    $assignmentLogAction = 'assign_item.update';
                    $assignmentAuditDetails = [
                        'item_type' => 'equipment',
                        'item_ref_id' => $item_id,
                        'transaction_id' => $transactionId,
                        'assigned_to' => $returnAssignment['assigned_to'] ?? null,
                        'assigned_area' => $returnAssignment['assigned_area'] ?? null,
                        'appropriation' => $returnAssignment['appropriation'] ?? null,
                        'assigned_date' => $returnAssignment['assigned_date'] ?? null,
                        'status' => 'returned',
                        'quantity' => $quantity,
                    ];
                }
            }

            $newTx = [
                'item_type' => $item_type,
                'item_id' => $item_id,
                'transaction_type' => $transaction_type,
                'quantity' => $quantity
            ];
            osaeits_apply_transaction_effects($pdo, $newTx, 1);
            if ($transaction_type === 'return' && $item_type === 'equipment') {
                $remainingIssued = osaeits_equipment_issued_balance($pdo, $item_id);
                if ($remainingIssued <= 0) {
                    $clearAssignmentStmt = $pdo->prepare("UPDATE equipment SET status = ?, purok_area = NULL, appropriation = NULL, person_incharge = NULL WHERE id = ?");
                    $clearAssignmentStmt->execute([$return_equipment_status, $item_id]);
                } else {
                    $statusStmt = $pdo->prepare("UPDATE equipment SET status = ? WHERE id = ?");
                    $statusStmt->execute([$return_equipment_status, $item_id]);
                }
            }

            $pdo->commit();

            require_once __DIR__ . '/../includes/activity-log.php';
            $actor = (int)$_SESSION['user_id'];
            if ($createdEntityType !== '' && $createdEntityId > 0) {
                log_activity(
                    $pdo,
                    $actor,
                    $createdEntityType . '.create',
                    $createdEntityType,
                    $createdEntityId,
                    ['name' => $createdEntityType === 'supply' ? $new_supply_name : $new_equipment_name]
                );
            }
            $logDetails = [
                'item_type' => $item_type,
                'item_id' => $item_id,
                'transaction_type' => $transaction_type,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'total_amount' => $total_amount,
                'reference_number' => $reference_number !== '' ? $reference_number : null,
            ];
            if ($transaction_type === 'purchase' && $item_type === 'supply' && $purchaseHistorySupplier !== '') {
                $logDetails['supplier'] = $purchaseHistorySupplier;
            }
            if ($transaction_type === 'purchase' && $item_type === 'supply' && $purchase_detail !== '') {
                $logDetails['detail'] = $purchase_detail;
            }
            if ($transaction_type === 'issue') {
                $logDetails['assigned_to'] = $issue_assigned_to;
                $logDetails['assigned_area'] = $issue_assigned_area !== '' ? $issue_assigned_area : null;
                $logDetails['appropriation'] = $issue_appropriation !== '' ? $issue_appropriation : null;
                $logDetails['assigned_date'] = $issue_assigned_date;
            } elseif ($transaction_type === 'return' && is_array($returnAssignment)) {
                $logDetails['returned_from_assignment_id'] = (int)($returnAssignment['id'] ?? 0);
                $logDetails['assigned_to'] = $returnAssignment['assigned_to'] ?? null;
                $logDetails['assigned_area'] = $returnAssignment['assigned_area'] ?? null;
                $logDetails['appropriation'] = $returnAssignment['appropriation'] ?? null;
                $logDetails['assigned_date'] = $returnAssignment['assigned_date'] ?? null;
            }
            if ($transaction_type === 'return' && $item_type === 'equipment') {
                $logDetails['equipment_status'] = $return_equipment_status;
            }
            if ($tx) {
                $logDetails['previous'] = [
                    'item_type' => $tx['item_type'] ?? null,
                    'item_id' => isset($tx['item_id']) ? (int)$tx['item_id'] : null,
                    'transaction_type' => $tx['transaction_type'] ?? null,
                    'quantity' => isset($tx['quantity']) ? (int)$tx['quantity'] : null,
                    'unit_price' => isset($tx['unit_price']) ? (float)$tx['unit_price'] : null,
                    'total_amount' => isset($tx['total_amount']) ? (float)$tx['total_amount'] : null,
                    'reference_number' => $tx['reference_number'] ?? null,
                ];
                log_activity($pdo, $actor, 'transaction.update', 'transaction', $id, $logDetails);
            } else {
                log_activity($pdo, $actor, 'transaction.create', 'transaction', $newTxId, $logDetails);
            }
            if ($assignmentLogAction !== '' && $assignmentEntityId > 0 && is_array($assignmentAuditDetails)) {
                log_activity($pdo, $actor, $assignmentLogAction, 'assign_item', $assignmentEntityId, $assignmentAuditDetails);
            }

            if ($tx) {
                $_SESSION['success_message'] = 'Transaction updated.';
            } elseif ($createdSupplyVariant) {
                $_SESSION['success_message'] = 'Purchase recorded. A new supply variant was added.';
            } elseif ($usedExistingSupplyVariant) {
                $_SESSION['success_message'] = 'Purchase recorded under the matching supply variant.';
            } elseif ($existingPurchaseSameDetails) {
                $_SESSION['success_message'] = 'Purchase recorded. Existing item was restocked.';
            } elseif ($existingPurchaseChangedDetails) {
                $_SESSION['success_message'] = $item_type === 'supply'
                    ? 'Purchase recorded.'
                    : 'Purchase recorded. Existing equipment history was updated and latest price was saved.';
            } else {
                $_SESSION['success_message'] = 'Transaction recorded.';
            }
            if ($item_id > 0) {
                $invLoc = $item_type === 'supply'
                    ? 'supply-detail.php?' . http_build_query(['id' => $item_id])
                    : 'equipment-detail.php?' . http_build_query(['id' => $item_id]);
            } else {
                $invLoc = 'inventory.php?' . http_build_query(['transaction_type' => $transaction_type]);
            }
            header('Location: ' . $invLoc);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error === '') {
                $error = 'Unable to save transaction. Please try again.';
            }
        }
    }

    $tx = array_merge($tx ?? [], [
        'item_type' => $item_type,
        'item_id' => $item_id,
        'transaction_type' => $transaction_type,
        'quantity' => $quantity,
        'unit_price' => $unit_price,
        'reference_number' => $reference_number,
        'notes' => $notes
    ]);
    $issueAssignment = [
        'assigned_to' => $issue_assigned_to,
        'assigned_area' => $issue_assigned_area,
        'appropriation' => $issue_appropriation,
        'assigned_date' => $issue_assigned_date !== '' ? $issue_assigned_date : date('Y-m-d'),
    ];
}

if (!$tx) {
    $defaultTransactionType = $prefill_transaction_type !== '' ? $prefill_transaction_type : 'purchase';
    $defaultItemType = $prefill_item_type !== '' ? $prefill_item_type : 'supply';
    if ($prefill_item_type === '' && $defaultTransactionType === 'return') {
        $defaultItemType = 'equipment';
    }
    $tx = [
        'item_type' => $defaultItemType,
        'item_id' => $prefill_item_id > 0 ? $prefill_item_id : 0,
        'transaction_type' => $defaultTransactionType,
        'quantity' => 1,
        'unit_price' => 0,
        'reference_number' => '',
        'notes' => ''
    ];
}

if (!$issueAssignment) {
    $issueAssignment = [
        'assigned_to' => '',
        'assigned_area' => '',
        'appropriation' => '',
        'assigned_date' => date('Y-m-d'),
    ];
}

$selected_transaction_type = (string)($tx['transaction_type'] ?? ($prefill_transaction_type !== '' ? $prefill_transaction_type : 'purchase'));
$selected_transaction_label = $movement_type_labels[$selected_transaction_type] ?? ucfirst($selected_transaction_type);
$allowed_item_types = $selected_transaction_type === 'return'
    ? ['equipment']
    : (in_array($selected_transaction_type, ['purchase', 'issue'], true) ? ['supply', 'equipment'] : ['supply']);
if (isset($tx['id']) && in_array((string)($tx['item_type'] ?? ''), ['supply', 'equipment'], true)
    && !in_array((string)$tx['item_type'], $allowed_item_types, true)) {
    $allowed_item_types[] = (string)$tx['item_type'];
}
$selected_item_type = (string)($tx['item_type'] ?? ($allowed_item_types[0] ?? 'supply'));
if (!in_array($selected_item_type, $allowed_item_types, true)) {
    $selected_item_type = $allowed_item_types[0] ?? 'supply';
}
$selected_return_equipment_status = '';
if ($selected_transaction_type === 'return') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $selected_return_equipment_status = $return_equipment_status;
    }
}
$page_title = $tx && isset($tx['id']) ? 'Edit transaction' : 'Record ' . strtolower($selected_transaction_label);
$can_create_purchase_item = $id <= 0 && $selected_transaction_type === 'purchase';
$selected_purchase_item_mode = (
    $can_create_purchase_item
    && ($_SERVER['REQUEST_METHOD'] === 'POST')
    && ($_POST['purchase_item_mode'] ?? '') === 'new'
) ? 'new' : 'existing';
$transaction_item_col_class = $can_create_purchase_item ? 'col-md-4' : ($selected_transaction_type === 'return' ? 'col-md-12' : 'col-md-6');
$item_type_group_class = $selected_transaction_type === 'return' ? 'd-none' : $transaction_item_col_class;
$supply_unit_options = osaeits_supply_unit_options((string)($_POST['new_supply_unit'] ?? ''));

$cancel_inv_href = 'inventory.php';
if (!empty($tx['item_type']) && in_array($tx['item_type'], ['supply', 'equipment'], true) && (int)($tx['item_id'] ?? 0) > 0) {
    $cancel_inv_href .= '?' . http_build_query([
        'item_type' => $tx['item_type'],
        'item_id' => (int)$tx['item_id'],
        'transaction_type' => $selected_transaction_type,
    ]);
} elseif ($selected_transaction_type !== '') {
    $cancel_inv_href .= '?' . http_build_query(['transaction_type' => $selected_transaction_type]);
}
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary"><?= htmlspecialchars($page_title) ?></h6>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="transaction_type" id="transaction_type" value="<?= htmlspecialchars($selected_transaction_type) ?>">
            <div class="form-row">
                <?php if ($can_create_purchase_item): ?>
                <div class="form-group col-md-4">
                    <label>Purchase entry *</label>
                    <select name="purchase_item_mode" id="purchase_item_mode" class="form-control">
                        <option value="existing" <?= $selected_purchase_item_mode === 'existing' ? 'selected' : '' ?>>Existing item</option>
                        <option value="new" <?= $selected_purchase_item_mode === 'new' ? 'selected' : '' ?>>New item</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group <?= htmlspecialchars($item_type_group_class) ?>">
                    <label id="item_type_label">This transaction is for *</label>
                    <select name="item_type" id="item_type" class="form-control" required>
                        <?php if (in_array('supply', $allowed_item_types, true)): ?>
                            <option value="supply" <?= $selected_item_type === 'supply' ? 'selected' : '' ?>>Supply</option>
                        <?php endif; ?>
                        <?php if (in_array('equipment', $allowed_item_types, true)): ?>
                            <option value="equipment" <?= $selected_item_type === 'equipment' ? 'selected' : '' ?>>Equipment</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div id="existing_item_group" class="form-group <?= htmlspecialchars($transaction_item_col_class) ?>">
                    <label id="item_id_label">Which item *</label>
                    <select name="item_id" id="item_id" class="form-control" required></select>
                </div>
            </div>

            <?php if ($selected_transaction_type === 'return'): ?>
            <div id="return_issue_details" class="return-summary-panel mb-3"></div>
            <div class="return-condition-panel border rounded p-3 mb-3">
                <h6 class="font-weight-bold text-secondary mb-3">Returned status</h6>
                <div class="form-row">
                    <div class="form-group col-md-4 mb-md-0">
                        <label>Condition when returned *</label>
                        <select name="return_equipment_status" id="return_equipment_status" class="form-control" required>
                            <option value="" <?= $selected_return_equipment_status === '' ? 'selected' : '' ?>>Select status</option>
                            <option value="servicable" <?= $selected_return_equipment_status === 'servicable' ? 'selected' : '' ?>>Working / Serviceable</option>
                            <option value="nonservicesable" <?= $selected_return_equipment_status === 'unservicable' ? 'selected' : '' ?>>Not working / Nonservicesable</option>
                        </select>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($selected_transaction_type === 'issue'): ?>
            <div class="border rounded p-3 mb-3">
                <h6 class="font-weight-bold text-secondary mb-3">Assignment details</h6>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Assigned To *</label>
                        <input type="text" name="assigned_to" class="form-control" value="<?= htmlspecialchars((string)($issueAssignment['assigned_to'] ?? '')) ?>" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Purok / Area</label>
                        <input type="text" name="assigned_area" class="form-control" value="<?= htmlspecialchars((string)($issueAssignment['assigned_area'] ?? '')) ?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Appropriation</label>
                        <input type="text" name="appropriation" class="form-control" value="<?= htmlspecialchars((string)($issueAssignment['appropriation'] ?? '')) ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4 mb-md-0">
                        <label>Assigned Date *</label>
                        <input type="date" name="assigned_date" class="form-control" value="<?= htmlspecialchars((string)($issueAssignment['assigned_date'] ?? date('Y-m-d'))) ?>" required>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($can_create_purchase_item): ?>
            <div id="existing_purchase_details" class="border rounded p-3 mb-3">
                <div class="form-row align-items-end">
                    <div id="purchase_detail_group" class="form-group col-md-4 mb-md-0">
                        <label>Details / Size</label>
                        <input type="text" name="purchase_detail" id="purchase_detail" class="form-control" placeholder="e.g. A4, long, letter" value="<?= htmlspecialchars((string)($_POST['purchase_detail'] ?? '')) ?>">
                    </div>
                    <div id="purchase_supplier_group" class="form-group col-md-4 mb-md-0">
                        <label>Supplier for this purchase</label>
                        <input type="text" name="purchase_supplier" id="purchase_supplier" class="form-control" value="<?= htmlspecialchars((string)($_POST['purchase_supplier'] ?? '')) ?>">
                    </div>
                    <div class="form-group col-md-4 mb-0">
                        <div id="existing_purchase_check" class="alert alert-info py-2 mb-0 d-none"></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($can_create_purchase_item): ?>
            <div id="new_supply_fields" class="border rounded p-3 mb-3 d-none">
                <div class="form-group">
                    <label>Supply Name *</label>
                    <input type="text" name="new_supply_name" id="new_supply_name" class="form-control" value="<?= htmlspecialchars((string)($_POST['new_supply_name'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>Details / Size</label>
                    <input type="text" name="new_supply_description" class="form-control" placeholder="e.g. A4, long, letter" value="<?= htmlspecialchars((string)($_POST['new_supply_description'] ?? '')) ?>">
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Category</label>
                        <input type="text" class="form-control" value="Supplies" readonly>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Unit *</label>
                        <select name="new_supply_unit" id="new_supply_unit" class="form-control">
                            <option value="">Select unit</option>
                            <?php foreach ($supply_unit_options as $unit_value => $unit_label): ?>
                                <option value="<?= htmlspecialchars((string)$unit_value) ?>" <?= (string)($_POST['new_supply_unit'] ?? '') === (string)$unit_value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$unit_label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Supplier</label>
                        <input type="text" name="new_supply_supplier" class="form-control" value="<?= htmlspecialchars((string)($_POST['new_supply_supplier'] ?? '')) ?>">
                    </div>
                </div>
                <div class="form-group mb-0">
                    <label>Product Minimum Stock</label>
                    <input type="number" name="new_supply_minimum_stock" class="form-control" min="0" value="<?= (int)($_POST['new_supply_minimum_stock'] ?? 0) ?>">
                </div>
            </div>

            <div id="new_equipment_fields" class="border rounded p-3 mb-3 d-none">
                <div class="form-group">
                    <label>Equipment Name *</label>
                    <input type="text" name="new_equipment_name" id="new_equipment_name" class="form-control" value="<?= htmlspecialchars((string)($_POST['new_equipment_name'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="new_equipment_description" class="form-control" rows="2"><?= htmlspecialchars((string)($_POST['new_equipment_description'] ?? '')) ?></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Category</label>
                        <input type="text" class="form-control" value="Equipment" readonly>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Serial Number</label>
                        <input type="text" name="new_equipment_serial_number" class="form-control" value="<?= htmlspecialchars((string)($_POST['new_equipment_serial_number'] ?? '')) ?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Status</label>
                        <?php $posted_equipment_status = osaeits_normalize_equipment_status($_POST['new_equipment_status'] ?? 'servicable'); ?>
                        <select name="new_equipment_status" class="form-control">
                            <option value="servicable" <?= $posted_equipment_status === 'servicable' ? 'selected' : '' ?>>Servicable</option>
                            <option value="nonservicesable" <?= $posted_equipment_status === 'unservicable' ? 'selected' : '' ?>>Nonservicesable</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Brand</label>
                        <input type="text" name="new_equipment_brand" class="form-control" value="<?= htmlspecialchars((string)($_POST['new_equipment_brand'] ?? '')) ?>">
                    </div>
                    <div class="form-group col-md-6">
                        <label>Model</label>
                        <input type="text" name="new_equipment_model" class="form-control" value="<?= htmlspecialchars((string)($_POST['new_equipment_model'] ?? '')) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="new_equipment_location" class="form-control" value="<?= htmlspecialchars((string)($_POST['new_equipment_location'] ?? '')) ?>">
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Purchase Date</label>
                        <input type="date" name="new_equipment_purchase_date" class="form-control" value="<?= htmlspecialchars((string)($_POST['new_equipment_purchase_date'] ?? '')) ?>">
                    </div>
                    <div class="form-group col-md-6">
                        <label>Warranty Expiry</label>
                        <input type="date" name="new_equipment_warranty_expiry" class="form-control" value="<?= htmlspecialchars((string)($_POST['new_equipment_warranty_expiry'] ?? '')) ?>">
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-row">
                <div class="form-group col-md-3">
                    <label id="quantity_label">Quantity *</label>
                    <input type="number" name="quantity" class="form-control" min="1" value="<?= (int)($tx['quantity'] ?? 1) ?>" required>
                </div>
                <div class="form-group col-md-3">
                    <label id="unit_price_label">Unit Price (PHP)</label>
                    <input type="number" name="unit_price" class="form-control" min="0" step="0.01" value="<?= htmlspecialchars((string)($tx['unit_price'] ?? '0')) ?>" <?= in_array($selected_transaction_type, ['issue', 'return'], true) ? 'readonly' : '' ?>>
                </div>
                <div class="form-group col-md-6">
                    <label>Trace / Reference Number</label>
                    <input type="text" name="reference_number" class="form-control" value="<?= htmlspecialchars((string)($tx['reference_number'] ?? '')) ?>" readonly>
                </div>
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars((string)($tx['notes'] ?? '')) ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Save <?= htmlspecialchars(strtolower($selected_transaction_label)) ?></button>
            <a href="<?= htmlspecialchars($cancel_inv_href) ?>" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<script>
const supplyItems = <?= json_encode($supplies, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const equipmentItems = <?= json_encode($equipment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const selectedItem = <?= (int)($tx['item_id'] ?? 0) ?>;
const selectedType = <?= json_encode($selected_item_type) ?>;
const selectedTransactionType = <?= json_encode($selected_transaction_type) ?>;
const canCreatePurchaseItem = <?= $can_create_purchase_item ? 'true' : 'false' ?>;
const initialPurchaseItemMode = <?= json_encode($selected_purchase_item_mode) ?>;
const isEditMode = <?= $tx && isset($tx['id']) ? 'true' : 'false' ?>;

function compactParts(parts) {
    return parts.filter(function (part) {
        return String(part || '').trim() !== '';
    });
}

function formatMoney(value) {
    const amount = parseFloat(value || 0);
    return Number.isNaN(amount) ? '0.00' : amount.toFixed(2);
}

function normalizeCompare(value) {
    return String(value || '').trim().toLowerCase();
}

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatDateLabel(value) {
    if (!value) return '-';
    const parsed = new Date(String(value) + 'T00:00:00');
    if (Number.isNaN(parsed.getTime())) {
        return String(value);
    }
    return parsed.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateTimeLabel(value) {
    if (!value) return '-';
    const normalized = String(value).replace(' ', 'T');
    const parsed = new Date(normalized);
    if (Number.isNaN(parsed.getTime())) {
        return String(value);
    }
    return parsed.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
    });
}

function getPurchaseItemMode() {
    const modeSelect = document.getElementById('purchase_item_mode');
    return canCreatePurchaseItem && modeSelect ? modeSelect.value : 'existing';
}

function setRequired(id, required) {
    const field = document.getElementById(id);
    if (field) {
        field.required = required;
    }
}

function supplyOptionLabel(item) {
    const code = item.item_code ? item.item_code + ' - ' : '';
    const details = compactParts([item.name, item.description]).join(' - ');
    const unit = item.unit ? ' ' + item.unit : '';
    return code + details + ' (' + item.current_stock + unit + ')';
}

function equipmentOptionLabel(item) {
    const code = item.item_code ? item.item_code : '';
    const itemName = compactParts([item.name, item.description]).join(' - ');
    const makeModel = compactParts([item.brand, item.model]).join(' ');
    const serial = item.serial_number ? 'SN: ' + item.serial_number : '';
    const location = item.location ? 'Loc: ' + item.location : '';
    const state = parseInt(item.issued_balance || '0', 10) > 0 ? 'issued' : 'available';
    return compactParts([code, itemName, makeModel, serial, location, state]).join(' - ');
}

function updateReturnIssueDetails() {
    const panel = document.getElementById('return_issue_details');
    if (!panel) return;

    const item = getSelectedItemData();
    if (!item) {
        panel.className = 'return-summary-panel return-summary-panel-empty border rounded p-3 bg-light mb-3';
        panel.innerHTML = '<div class="return-summary-empty">'
            + '<div class="return-summary-empty-icon"><i class="fas fa-undo"></i></div>'
            + '<div>'
            + '<div class="return-summary-title">Return details</div>'
            + '<div class="return-summary-subtitle">Select issued equipment to review the matching issue record.</div>'
            + '</div>'
            + '</div>';
        return;
    }

    const issueReference = item.open_issue_reference_number || 'No issue reference';
    const hasIssueRecord = !!item.open_issue_transaction_id;
    const readonlyInput = function (label, value, columnClass) {
        return '<div class="form-group ' + columnClass + '">'
            + '<label>' + escapeHtml(label) + '</label>'
            + '<input type="text" class="form-control form-control-sm bg-light return-readonly-control" readonly value="' + escapeHtml(value || '-') + '">'
            + '</div>';
    };

    panel.className = hasIssueRecord
        ? 'return-summary-panel return-form-panel border rounded p-3 mb-3'
        : 'return-summary-panel return-form-panel return-summary-panel-warning border rounded p-3 mb-3';
    panel.innerHTML = '<div class="return-form-body pt-0">'
        + '<div class="form-row">'
        + readonlyInput('Issue reference', issueReference, 'col-md-3')
        + readonlyInput('Issue transaction no.', item.open_issue_transaction_id ? '#' + item.open_issue_transaction_id : '-', 'col-md-3')
        + readonlyInput('Issue recorded', formatDateTimeLabel(item.open_issue_created_at), 'col-md-3')
        + readonlyInput('Recorded by', item.open_issue_recorded_by || '-', 'col-md-3')
        + '</div>'
        + '<div class="form-row">'
        + readonlyInput('Assigned to', item.open_assigned_to || '-', 'col-md-3')
        + readonlyInput('Purok / Area', item.open_assigned_area || '-', 'col-md-3')
        + readonlyInput('Appropriation', item.open_appropriation || '-', 'col-md-3')
        + readonlyInput('Assigned date', formatDateLabel(item.open_assigned_date), 'col-md-3')
        + '</div>'
        + '<div class="form-row">'
        + readonlyInput('Quantity', item.open_issue_quantity || item.issued_balance || '0', 'col-md-3')
        + '</div>'
        + '</div>'
        + (hasIssueRecord ? '' : '<div class="return-summary-warning">This equipment is marked as issued, but the matching issue transaction could not be found.</div>');
}

function uniqueLabel(label, itemId, seenLabels) {
    const key = String(label).toLowerCase();
    const count = seenLabels.get(key) || 0;
    seenLabels.set(key, count + 1);
    return count > 0 ? label + ' #' + itemId : label;
}

function updatePurchaseLabels() {
    const type = document.getElementById('item_type').value;
    const isPurchase = selectedTransactionType === 'purchase';
    const isIssue = selectedTransactionType === 'issue';
    const isReturn = selectedTransactionType === 'return';
    const isInventoryMovement = isIssue || isReturn;
    const isEquipment = type === 'equipment';

    const itemTypeLabel = document.getElementById('item_type_label');
    if (itemTypeLabel) {
        itemTypeLabel.textContent = isPurchase
            ? 'Purchase is for *'
            : (isIssue ? 'Issue is for *' : (isReturn ? 'Return is for *' : 'This transaction is for *'));
    }
    document.getElementById('item_id_label').textContent = isReturn
        ? 'Issued equipment to return *'
        : (isEquipment ? 'Equipment asset *' : 'Supply item *');
    document.getElementById('quantity_label').textContent = isPurchase
        ? (isEquipment ? 'Asset Quantity *' : 'Quantity Purchased *')
        : (isIssue ? 'Quantity to Issue *' : (isReturn ? 'Quantity to Return *' : 'Quantity *'));
    document.getElementById('unit_price_label').textContent = isEquipment ? 'Purchase Price (PHP)' : 'Unit Cost (PHP)';
    document.querySelector('input[name="unit_price"]').readOnly = isInventoryMovement;
    document.querySelector('input[name="reference_number"]').readOnly = true;
}

function updatePurchaseModeVisibility() {
    if (!canCreatePurchaseItem) return;

    const mode = getPurchaseItemMode();
    const type = document.getElementById('item_type').value;
    const isNew = mode === 'new';
    const isSupply = type === 'supply';
    const existingGroup = document.getElementById('existing_item_group');
    const existingDetails = document.getElementById('existing_purchase_details');
    const detailGroup = document.getElementById('purchase_detail_group');
    const supplierGroup = document.getElementById('purchase_supplier_group');
    const itemSelect = document.getElementById('item_id');
    const supplyFields = document.getElementById('new_supply_fields');
    const equipmentFields = document.getElementById('new_equipment_fields');

    existingGroup.classList.toggle('d-none', isNew);
    existingDetails.classList.toggle('d-none', isNew);
    detailGroup.classList.toggle('d-none', !isSupply);
    supplierGroup.classList.toggle('d-none', !isSupply);
    itemSelect.required = !isNew;
    if (isNew) {
        itemSelect.value = '';
    }

    supplyFields.classList.toggle('d-none', !(isNew && isSupply));
    equipmentFields.classList.toggle('d-none', !(isNew && !isSupply));

    setRequired('new_supply_name', isNew && isSupply);
    setRequired('new_supply_unit', isNew && isSupply);
    setRequired('new_equipment_name', isNew && !isSupply);
    updateExistingPurchaseCheck();
}

function updateExistingPurchaseCheck() {
    const check = document.getElementById('existing_purchase_check');
    if (!check) return;

    const type = document.getElementById('item_type').value;
    const item = getSelectedItemData();
    const isExistingPurchase = selectedTransactionType === 'purchase' && getPurchaseItemMode() === 'existing';
    if (!isExistingPurchase || !item) {
        check.classList.add('d-none');
        check.textContent = '';
        return;
    }

    const priceInput = document.querySelector('input[name="unit_price"]');
    const enteredPrice = parseFloat(priceInput.value || 0);
    const savedPrice = type === 'equipment'
        ? parseFloat(item.purchase_price || 0)
        : parseFloat(item.unit_price || 0);
    const messages = [];
    let hasDifference = false;
    let samePrice = false;
    let sameDetail = type !== 'supply';
    let sameSupplier = type !== 'supply';

    if (savedPrice > 0 && enteredPrice > 0) {
        if (Math.abs(savedPrice - enteredPrice) > 0.009) {
            hasDifference = true;
            messages.push('Different price: saved PHP ' + formatMoney(savedPrice) + ', entered PHP ' + formatMoney(enteredPrice) + '.');
        } else {
            samePrice = true;
            messages.push('Same price: PHP ' + formatMoney(savedPrice) + '.');
        }
    } else if (savedPrice > 0) {
        messages.push('Saved price: PHP ' + formatMoney(savedPrice) + '.');
    } else if (enteredPrice > 0) {
        messages.push('No saved price yet. Entered PHP ' + formatMoney(enteredPrice) + ' will be used for this purchase.');
    }

    if (type === 'supply') {
        const detailInput = document.getElementById('purchase_detail');
        const savedDetail = String(item.description || '').trim();
        const enteredDetail = detailInput ? detailInput.value.trim() : '';
        if (savedDetail !== '' && enteredDetail !== '') {
            if (normalizeCompare(savedDetail) !== normalizeCompare(enteredDetail)) {
                hasDifference = true;
                messages.push('Different detail: saved "' + savedDetail + '", entered "' + enteredDetail + '".');
                const duplicateDetail = supplyItems.find(function (supply) {
                    return parseInt(supply.id, 10) !== parseInt(item.id, 10)
                        && normalizeCompare(supply.name) === normalizeCompare(item.name)
                        && normalizeCompare(supply.description) === normalizeCompare(enteredDetail)
                        && normalizeCompare(supply.unit) === normalizeCompare(item.unit);
                });
                if (duplicateDetail) {
                    messages.push('Existing variant found: ' + supplyOptionLabel(duplicateDetail) + '. This purchase will be recorded under that variant.');
                } else {
                    messages.push('A new supply variant will be created for "' + enteredDetail + '".');
                }
            } else {
                sameDetail = true;
                messages.push('Same detail: ' + savedDetail + '.');
            }
        } else if (savedDetail !== '') {
            sameDetail = true;
            messages.push('Saved detail: ' + savedDetail + '.');
        } else if (enteredDetail !== '') {
            hasDifference = true;
            messages.push('A new supply variant will be created for "' + enteredDetail + '".');
        } else {
            sameDetail = true;
        }

        const supplierInput = document.getElementById('purchase_supplier');
        const savedSupplier = String(item.supplier || '').trim();
        const enteredSupplier = supplierInput ? supplierInput.value.trim() : '';
        if (savedSupplier !== '' && enteredSupplier !== '') {
            if (normalizeCompare(savedSupplier) !== normalizeCompare(enteredSupplier)) {
                hasDifference = true;
                messages.push('Different supplier: saved "' + savedSupplier + '", entered "' + enteredSupplier + '".');
            } else {
                sameSupplier = true;
                messages.push('Same supplier: ' + savedSupplier + '.');
            }
        } else if (savedSupplier !== '') {
            messages.push('Saved supplier: ' + savedSupplier + '.');
        } else if (enteredSupplier !== '') {
            messages.push('Supplier "' + enteredSupplier + '" will be recorded on this purchase only.');
        }
    }

    if (messages.length === 0) {
        messages.push(type === 'supply' ? 'No saved price or supplier yet.' : 'No saved price yet.');
    }
    if (hasDifference) {
        messages.push(type === 'supply'
            ? 'This will record the purchase without editing the selected supply. Price and supplier stay on this purchase; a different detail creates or uses a separate supply variant.'
            : 'This will append purchase history and update the master record latest price.');
    }
    if (!hasDifference && samePrice && sameDetail && sameSupplier) {
        messages.push(type === 'supply'
            ? 'This will restock the selected supply only.'
            : 'This will record another purchase for the existing equipment.');
    }

    check.className = 'alert py-2 mb-0 ' + (hasDifference ? 'alert-warning' : 'alert-info');
    check.textContent = messages.join(' ');
    check.classList.remove('d-none');
}

function renderItemOptions() {
    const typeSelect = document.getElementById('item_type');
    const itemSelect = document.getElementById('item_id');
    let source = typeSelect.value === 'equipment' ? equipmentItems : supplyItems;
    if (selectedTransactionType === 'return' && typeSelect.value === 'equipment') {
        source = source.filter(function (item) {
            return parseInt(item.issued_balance || '0', 10) > 0
                || parseInt(item.id, 10) === selectedItem;
        });
    }
    const seenLabels = new Map();

    itemSelect.innerHTML = '';
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = selectedTransactionType === 'return'
        ? '-- Select issued equipment --'
        : (typeSelect.value === 'equipment' ? '-- Select equipment --' : '-- Select supply --');
    itemSelect.appendChild(defaultOption);

    source.forEach(function (item) {
        const option = document.createElement('option');
        option.value = item.id;
        const label = typeSelect.value === 'equipment' ? equipmentOptionLabel(item) : supplyOptionLabel(item);
        option.textContent = uniqueLabel(label, item.id, seenLabels);
        if (parseInt(item.id, 10) === selectedItem) {
            option.selected = true;
        }
        itemSelect.appendChild(option);
    });

    const qtyInput = document.querySelector('input[name="quantity"]');
    if (typeSelect.value === 'equipment') {
        qtyInput.value = 1;
        qtyInput.readOnly = true;
    } else {
        qtyInput.readOnly = false;
    }
    updatePurchaseLabels();
    updatePurchaseModeVisibility();
    updateReturnIssueDetails();
}

function getSelectedItemData() {
    const type = document.getElementById('item_type').value;
    const id = parseInt(document.getElementById('item_id').value || '0', 10);
    const source = type === 'equipment' ? equipmentItems : supplyItems;
    return source.find(function (i) { return parseInt(i.id, 10) === id; }) || null;
}

function autoFillPriceAndReference() {
    const type = document.getElementById('item_type').value;
    const txType = document.getElementById('transaction_type').value;
    const refInput = document.querySelector('input[name="reference_number"]');
    const priceInput = document.querySelector('input[name="unit_price"]');
    const detailInput = document.getElementById('purchase_detail');
    const supplierInput = document.getElementById('purchase_supplier');
    const item = getSelectedItemData();

    if (!item) {
        updateExistingPurchaseCheck();
        updateReturnIssueDetails();
        if (getPurchaseItemMode() === 'new' && (!isEditMode || refInput.value.trim() === '')) {
            const now = new Date();
            const y = now.getFullYear();
            const m = String(now.getMonth() + 1).padStart(2, '0');
            const d = String(now.getDate()).padStart(2, '0');
            const rand = String(Math.floor(Math.random() * 900) + 100);
            const prefix = (type === 'equipment' ? 'EQ' : 'SP') + '-' + txType.substring(0, 3).toUpperCase();
            refInput.value = prefix + '-' + y + m + d + '-' + rand;
        }
        return;
    }
    const itemPrice = type === 'equipment'
        ? parseFloat(item.purchase_price || 0)
        : parseFloat(item.unit_price || 0);
    if (!Number.isNaN(itemPrice) && itemPrice > 0) {
        priceInput.value = itemPrice.toFixed(2);
    }

    if (detailInput && type === 'supply' && selectedTransactionType === 'purchase' && getPurchaseItemMode() === 'existing') {
        const selectedItemKey = String(item.id || '');
        if (detailInput.dataset.itemId !== selectedItemKey) {
            detailInput.value = item.description || '';
            detailInput.dataset.itemId = selectedItemKey;
        }
    } else if (detailInput) {
        detailInput.dataset.itemId = '';
    }

    if (supplierInput && type === 'supply' && selectedTransactionType === 'purchase' && getPurchaseItemMode() === 'existing') {
        const selectedItemKey = String(item.id || '');
        if (supplierInput.dataset.itemId !== selectedItemKey) {
            supplierInput.value = item.supplier || '';
            supplierInput.dataset.itemId = selectedItemKey;
        }
    } else if (supplierInput) {
        supplierInput.dataset.itemId = '';
    }

    if (!isEditMode || refInput.value.trim() === '') {
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        const d = String(now.getDate()).padStart(2, '0');
        const rand = String(Math.floor(Math.random() * 900) + 100);
        const prefix = (type === 'equipment' ? 'EQ' : 'SP') + '-' + txType.substring(0, 3).toUpperCase();
        refInput.value = prefix + '-' + y + m + d + '-' + rand;
    }

    if (type === 'equipment' && selectedTransactionType === 'purchase') {
        priceInput.value = formatMoney(item.purchase_price || priceInput.value);
    }
    updateExistingPurchaseCheck();
    updateReturnIssueDetails();
}
document.getElementById('item_type').addEventListener('change', function () {
    renderItemOptions();
    document.getElementById('item_id').value = '';
    autoFillPriceAndReference();
});
if (document.getElementById('purchase_item_mode')) {
    document.getElementById('purchase_item_mode').value = initialPurchaseItemMode;
    document.getElementById('purchase_item_mode').addEventListener('change', function () {
        updatePurchaseModeVisibility();
        autoFillPriceAndReference();
    });
}
document.getElementById('item_id').addEventListener('change', autoFillPriceAndReference);
document.querySelector('input[name="unit_price"]').addEventListener('input', updateExistingPurchaseCheck);
if (document.getElementById('purchase_detail')) {
    document.getElementById('purchase_detail').addEventListener('input', updateExistingPurchaseCheck);
}
if (document.getElementById('purchase_supplier')) {
    document.getElementById('purchase_supplier').addEventListener('input', updateExistingPurchaseCheck);
}
document.getElementById('item_type').value = selectedType;
renderItemOptions();
if (selectedItem > 0) {
    document.getElementById('item_id').value = String(selectedItem);
}
autoFillPriceAndReference();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
