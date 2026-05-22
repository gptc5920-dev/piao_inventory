<?php

function osaeits_clean_inventory_text(?string $value): string
{
    return trim((string)preg_replace('/\s+/', ' ', (string)$value));
}

function osaeits_normalize_inventory_text(?string $value): string
{
    return strtolower(osaeits_clean_inventory_text($value));
}

function osaeits_supply_unit_options(?string $selectedUnit = null): array
{
    $presets = [
        'piece' => 'Piece',
        'pcs' => 'Pcs',
        'box' => 'Box',
        'pack' => 'Pack',
        'ream' => 'Ream',
        'roll' => 'Roll',
        'bundle' => 'Bundle',
        'set' => 'Set',
        'pair' => 'Pair',
        'dozen' => 'Dozen',
        'pad' => 'Pad',
        'book' => 'Book',
        'bottle' => 'Bottle',
        'tube' => 'Tube',
        'can' => 'Can',
        'cartridge' => 'Cartridge',
    ];

    $selectedUnit = osaeits_clean_inventory_text($selectedUnit);
    if ($selectedUnit === '') {
        return $presets;
    }

    $options = [];
    $hasSelected = false;
    foreach ($presets as $value => $label) {
        if (strcasecmp($selectedUnit, $value) === 0) {
            $options[$selectedUnit] = $label;
            $hasSelected = true;
        } else {
            $options[$value] = $label;
        }
    }

    if (!$hasSelected) {
        $options[$selectedUnit] = $selectedUnit;
    }

    return $options;
}

function osaeits_item_code_prefix(string $itemType): string
{
    return $itemType === 'equipment' ? 'EQ' : 'SP';
}

function osaeits_format_item_code(string $itemType, int $itemId): string
{
    return osaeits_item_code_prefix($itemType) . '-' . str_pad((string)$itemId, 5, '0', STR_PAD_LEFT);
}

function osaeits_item_table(string $itemType): string
{
    return $itemType === 'equipment' ? 'equipment' : 'supplies';
}

function osaeits_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = in_array($column, osaeits_table_columns($pdo, $table), true);
    }

    return $cache[$key];
}

function osaeits_item_code_select_expr(PDO $pdo, string $tableAlias, string $itemType): string
{
    $prefix = osaeits_item_code_prefix($itemType);
    $table = osaeits_item_table($itemType);
    $fallback = "CONCAT('{$prefix}-', LPAD({$tableAlias}.id, 5, '0'))";
    if (osaeits_table_has_column($pdo, $table, 'item_code')) {
        return "COALESCE(NULLIF({$tableAlias}.item_code, ''), {$fallback})";
    }

    return $fallback;
}

function osaeits_ensure_item_identifier(PDO $pdo, string $itemType, int $itemId): string
{
    if (!in_array($itemType, ['supply', 'equipment'], true) || $itemId <= 0) {
        return '';
    }

    $table = osaeits_item_table($itemType);
    $generated = osaeits_format_item_code($itemType, $itemId);
    if (!osaeits_table_has_column($pdo, $table, 'item_code')) {
        return $generated;
    }

    $stmt = $pdo->prepare("SELECT item_code FROM `{$table}` WHERE id = ? LIMIT 1");
    $stmt->execute([$itemId]);
    $currentCode = osaeits_clean_inventory_text($stmt->fetchColumn() ?: '');
    if ($currentCode !== '') {
        return $currentCode;
    }

    $stmt = $pdo->prepare("UPDATE `{$table}` SET item_code = ? WHERE id = ?");
    $stmt->execute([$generated, $itemId]);

    return $generated;
}

function osaeits_transaction_reference_prefix(string $itemType, string $transactionType): string
{
    $typeCodes = [
        'purchase' => 'PUR',
        'issue' => 'ISS',
        'return' => 'RET',
        'adjustment' => 'ADJ',
    ];

    return osaeits_item_code_prefix($itemType) . '-' . ($typeCodes[$transactionType] ?? 'TX');
}

function osaeits_transaction_reference_exists(
    PDO $pdo,
    string $referenceNumber,
    int $excludeTransactionId = 0,
    int $excludeTrashId = 0
): bool
{
    $referenceNumber = osaeits_clean_inventory_text($referenceNumber);
    if ($referenceNumber === '') {
        return false;
    }

    $sql = "SELECT COUNT(*) FROM transactions WHERE reference_number = ?";
    $params = [$referenceNumber];
    if ($excludeTransactionId > 0) {
        $sql .= " AND id <> ?";
        $params[] = $excludeTransactionId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ((int)$stmt->fetchColumn() > 0) {
        return true;
    }

    try {
        $sql = "SELECT COUNT(*) FROM transaction_trash WHERE reference_number = ? AND restored_at IS NULL";
        $params = [$referenceNumber];
        if ($excludeTrashId > 0) {
            $sql .= " AND id <> ?";
            $params[] = $excludeTrashId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function osaeits_generate_transaction_reference(PDO $pdo, string $itemType, string $transactionType): string
{
    $prefix = osaeits_transaction_reference_prefix($itemType, $transactionType);
    for ($i = 0; $i < 20; $i++) {
        $reference = $prefix . '-' . date('Ymd') . '-' . random_int(1000, 9999);
        if (!osaeits_transaction_reference_exists($pdo, $reference)) {
            return $reference;
        }
    }

    return $prefix . '-' . date('YmdHis') . '-' . random_int(1000, 9999);
}

function osaeits_merge_inventory_details(?string $existing, ?string $incoming): string
{
    $details = [];
    $seen = [];
    foreach ([$existing, $incoming] as $value) {
        foreach (preg_split('/,/', osaeits_clean_inventory_text($value)) ?: [] as $part) {
            $part = osaeits_clean_inventory_text($part);
            if ($part === '') {
                continue;
            }
            $key = osaeits_normalize_inventory_text($part);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $details[] = $part;
        }
    }

    return implode(', ', $details);
}

function osaeits_supply_display_name(array $supply): string
{
    $name = osaeits_clean_inventory_text($supply['name'] ?? '');
    $details = osaeits_clean_inventory_text($supply['description'] ?? '');

    return $details !== '' ? "{$name} {$details}" : $name;
}

function osaeits_find_supply_duplicate(PDO $pdo, string $name, string $description, string $unit, int $excludeId = 0): ?array
{
    $sql = "
        SELECT id, name, description, unit
        FROM supplies
        WHERE LOWER(TRIM(name)) = ?
          AND LOWER(TRIM(COALESCE(description, ''))) = ?
          AND LOWER(TRIM(unit)) = ?
    ";
    $params = [
        osaeits_normalize_inventory_text($name),
        osaeits_normalize_inventory_text($description),
        osaeits_normalize_inventory_text($unit),
    ];
    if ($excludeId > 0) {
        $sql .= " AND id <> ?";
        $params[] = $excludeId;
    }
    $sql .= " ORDER BY id ASC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function osaeits_normalize_equipment_status(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['servicable', 'available', 'in_use', 'maintenance'], true)) {
        return 'servicable';
    }
    if (in_array($status, ['unservicable', 'unserviceable', 'nonservicesable', 'non_serviceable', 'retired'], true)) {
        return 'unservicable';
    }
    return 'servicable';
}

function osaeits_find_equipment_duplicate(
    PDO $pdo,
    string $name,
    string $serialNumber = '',
    string $brand = '',
    string $model = '',
    int $excludeId = 0
): ?array {
    $serialNumber = osaeits_clean_inventory_text($serialNumber);
    if ($serialNumber === '') {
        return null;
    }

    $sql = "
        SELECT id, name, description, serial_number, brand, model
        FROM equipment
        WHERE LOWER(TRIM(COALESCE(serial_number, ''))) = ?
    ";
    $params = [osaeits_normalize_inventory_text($serialNumber)];

    if ($excludeId > 0) {
        $sql .= " AND id <> ?";
        $params[] = $excludeId;
    }
    $sql .= " ORDER BY id ASC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function osaeits_equipment_display_name(array $equipment): string
{
    $name = osaeits_clean_inventory_text($equipment['name'] ?? '');
    $details = osaeits_clean_inventory_text($equipment['description'] ?? '');
    $makeModel = trim(implode(' ', array_filter([
        osaeits_clean_inventory_text($equipment['brand'] ?? ''),
        osaeits_clean_inventory_text($equipment['model'] ?? ''),
    ])));
    $serial = osaeits_clean_inventory_text($equipment['serial_number'] ?? '');
    $parts = [];
    $seen = [];
    foreach ([$name, $details, $makeModel, $serial !== '' ? 'SN: ' . $serial : ''] as $part) {
        $part = osaeits_clean_inventory_text($part);
        if ($part === '') {
            continue;
        }
        $key = osaeits_normalize_inventory_text($part);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $parts[] = $part;
    }

    return implode(' - ', $parts);
}

function osaeits_supply_movement_summary_sql(): string
{
    return "
        SELECT
            item_id,
            COUNT(*) AS movement_count,
            SUM(CASE WHEN transaction_type = 'purchase' THEN quantity ELSE 0 END) AS purchase_quantity,
            SUM(CASE WHEN transaction_type = 'purchase' THEN total_amount ELSE 0 END) AS purchase_total,
            MAX(CASE WHEN transaction_type = 'purchase' THEN created_at ELSE NULL END) AS last_purchase_at,
            SUM(
                CASE
                    WHEN transaction_type IN ('purchase', 'return') THEN quantity
                    WHEN transaction_type = 'issue' THEN -quantity
                    ELSE 0
                END
            ) AS stock_on_hand,
            SUBSTRING_INDEX(
                GROUP_CONCAT(
                    CASE WHEN transaction_type = 'purchase' THEN unit_price ELSE NULL END
                    ORDER BY created_at DESC, id DESC
                ),
                ',',
                1
            ) AS latest_purchase_price
        FROM transactions
        WHERE item_type = 'supply'
        GROUP BY item_id
    ";
}

function osaeits_supply_stock_expression(string $txAlias = 'tx', string $supplyAlias = 's'): string
{
    return "GREATEST(
        CASE
            WHEN COALESCE({$txAlias}.movement_count, 0) > 0 THEN COALESCE({$txAlias}.stock_on_hand, 0)
            ELSE COALESCE({$supplyAlias}.current_stock, 0)
        END,
        0
    )";
}

function osaeits_supply_current_stock(PDO $pdo, int $supplyId): int
{
    $summarySql = osaeits_supply_movement_summary_sql();
    $stockExpr = osaeits_supply_stock_expression('tx', 's');
    $stmt = $pdo->prepare("
        SELECT {$stockExpr} AS current_stock
        FROM supplies s
        LEFT JOIN ({$summarySql}) tx ON tx.item_id = s.id
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->execute([$supplyId]);

    return max(0, (int)$stmt->fetchColumn());
}

function osaeits_supply_product_minimum_stock(PDO $pdo, string $name): int
{
    $productKey = osaeits_normalize_inventory_text($name);
    if ($productKey === '') {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(minimum_stock), 0)
        FROM supplies
        WHERE LOWER(TRIM(name)) = ?
    ");
    $stmt->execute([$productKey]);

    return max(0, (int)$stmt->fetchColumn());
}

function osaeits_sync_supply_product_minimum_stock(PDO $pdo, string $name, int $minimumStock): void
{
    $productKey = osaeits_normalize_inventory_text($name);
    if ($productKey === '') {
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE supplies
        SET minimum_stock = ?
        WHERE LOWER(TRIM(name)) = ?
    ");
    $stmt->execute([max(0, $minimumStock), $productKey]);
}

function osaeits_equipment_movement_summary_sql(): string
{
    return "
        SELECT
            item_id,
            SUM(CASE WHEN transaction_type = 'purchase' THEN quantity ELSE 0 END) AS purchase_quantity,
            SUM(CASE WHEN transaction_type = 'purchase' THEN total_amount ELSE 0 END) AS purchase_total,
            MAX(CASE WHEN transaction_type = 'purchase' THEN created_at ELSE NULL END) AS last_purchase_at,
            MAX(CASE WHEN transaction_type IN ('issue', 'return') THEN created_at ELSE NULL END) AS last_movement_at,
            SUM(
                CASE
                    WHEN transaction_type = 'issue' THEN quantity
                    WHEN transaction_type = 'return' THEN -quantity
                    ELSE 0
                END
            ) AS issued_balance,
            SUBSTRING_INDEX(
                GROUP_CONCAT(
                    CASE WHEN transaction_type = 'purchase' THEN unit_price ELSE NULL END
                    ORDER BY created_at DESC, id DESC
                ),
                ',',
                1
            ) AS latest_purchase_price
        FROM transactions
        WHERE item_type = 'equipment'
        GROUP BY item_id
    ";
}

function osaeits_equipment_issued_balance(PDO $pdo, int $itemId, int $excludeTransactionId = 0): int
{
    $sql = "
        SELECT COALESCE(SUM(
            CASE
                WHEN transaction_type = 'issue' THEN quantity
                WHEN transaction_type = 'return' THEN -quantity
                ELSE 0
            END
        ), 0)
        FROM transactions
        WHERE item_type = 'equipment'
          AND item_id = ?
    ";
    $params = [$itemId];
    if ($excludeTransactionId > 0) {
        $sql .= " AND id <> ?";
        $params[] = $excludeTransactionId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return max(0, (int)$stmt->fetchColumn());
}

function osaeits_transaction_stock_delta(string $type, int $qty): int
{
    if ($type === 'purchase' || $type === 'return') {
        return $qty;
    }
    if ($type === 'issue') {
        return -$qty;
    }
    return 0;
}

function osaeits_apply_transaction_effects(PDO $pdo, array $tx, int $direction = 1): void
{
    $itemType = (string)($tx['item_type'] ?? '');
    $itemId = (int)($tx['item_id'] ?? 0);
    if ($itemType !== 'supply' || $itemId <= 0) {
        return;
    }

    $delta = osaeits_transaction_stock_delta((string)$tx['transaction_type'], (int)$tx['quantity']) * $direction;
    if ($delta !== 0) {
        $stmt = $pdo->prepare("UPDATE supplies SET current_stock = GREATEST(current_stock + ?, 0) WHERE id = ?");
        $stmt->execute([$delta, $itemId]);
    }
}

function osaeits_ensure_transaction_trash_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transaction_trash (
            id INT AUTO_INCREMENT PRIMARY KEY,
            original_transaction_id INT NOT NULL,
            item_type ENUM('supply', 'equipment') NOT NULL,
            item_id INT NOT NULL,
            transaction_type ENUM('purchase', 'issue', 'return', 'adjustment') NOT NULL,
            quantity INT NOT NULL,
            unit_price DECIMAL(10,2) DEFAULT 0.00,
            total_amount DECIMAL(10,2) DEFAULT 0.00,
            reference_number VARCHAR(100) NULL,
            notes TEXT NULL,
            user_id INT NOT NULL,
            original_created_at DATETIME NULL,
            deleted_by INT NULL,
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            delete_reason TEXT NULL,
            restored_at DATETIME NULL,
            restored_by INT NULL,
            INDEX idx_trash_original (original_transaction_id),
            INDEX idx_trash_active (restored_at, deleted_at),
            INDEX idx_trash_item (item_type, item_id)
        )
    ");
}

function osaeits_move_transaction_to_trash(PDO $pdo, array $tx, int $deletedBy, ?string $reason = null): int
{
    $stmt = $pdo->prepare("
        INSERT INTO transaction_trash
            (original_transaction_id, item_type, item_id, transaction_type, quantity, unit_price, total_amount,
             reference_number, notes, user_id, original_created_at, deleted_by, delete_reason)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        (int)$tx['id'],
        (string)$tx['item_type'],
        (int)$tx['item_id'],
        (string)$tx['transaction_type'],
        (int)$tx['quantity'],
        (float)$tx['unit_price'],
        (float)$tx['total_amount'],
        $tx['reference_number'] ?? null,
        $tx['notes'] ?? null,
        (int)$tx['user_id'],
        $tx['created_at'] ?? null,
        $deletedBy,
        $reason,
    ]);

    return (int)$pdo->lastInsertId();
}

function osaeits_validate_transaction_restore(PDO $pdo, array $tx): string
{
    $itemType = (string)($tx['item_type'] ?? '');
    $itemId = (int)($tx['item_id'] ?? 0);
    $transactionType = (string)($tx['transaction_type'] ?? '');
    $quantity = (int)($tx['quantity'] ?? 0);

    if (!in_array($itemType, ['supply', 'equipment'], true) || $itemId <= 0 || $quantity <= 0) {
        return 'This trash entry is missing required transaction data.';
    }

    $referenceNumber = osaeits_clean_inventory_text($tx['reference_number'] ?? '');
    if ($referenceNumber !== '' && osaeits_transaction_reference_exists($pdo, $referenceNumber, 0, (int)($tx['id'] ?? 0))) {
        return 'Unable to restore: reference number is already in use.';
    }

    if ($itemType === 'supply') {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM supplies WHERE id = ?");
        $exists->execute([$itemId]);
        if ((int)$exists->fetchColumn() <= 0) {
            return 'The related supply no longer exists.';
        }

        if ($transactionType === 'return') {
            return 'Supplies are consumable and cannot be restored as return transactions.';
        }
        if ($transactionType === 'issue') {
            $available = osaeits_supply_current_stock($pdo, $itemId);
            if ($quantity > $available) {
                return "Unable to restore: supply stock is only {$available}.";
            }
        }
        return '';
    }

    $stmt = $pdo->prepare("SELECT status FROM equipment WHERE id = ? LIMIT 1");
    $stmt->execute([$itemId]);
    $status = strtolower(trim((string)$stmt->fetchColumn()));
    if ($status === '') {
        return 'The related equipment no longer exists.';
    }
    if ($status !== 'servicable') {
        return 'Only servicable equipment transactions can be restored.';
    }

    $issuedBalance = osaeits_equipment_issued_balance($pdo, $itemId);
    if ($transactionType === 'issue' && $issuedBalance > 0) {
        return 'Unable to restore: this equipment is already issued.';
    }
    if ($transactionType === 'return' && $issuedBalance <= 0) {
        return 'Unable to restore: this equipment is not currently issued.';
    }

    return '';
}

function osaeits_ensure_trash_records_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS trash_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            payload LONGTEXT NOT NULL,
            deleted_by INT NULL,
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            restored_at DATETIME NULL,
            restored_by INT NULL,
            INDEX idx_trash_records_active (restored_at, deleted_at),
            INDEX idx_trash_records_entity (entity_type, entity_id)
        )
    ");
}

function osaeits_entity_table_map(): array
{
    return [
        'supply' => 'supplies',
        'equipment' => 'equipment',
        'assign_item' => 'assign_items',
        'barangay_official' => 'barangay_officials',
        'user' => 'users',
    ];
}

function osaeits_entity_redirect_map(): array
{
    return [
        'supply' => 'supplies.php',
        'equipment' => 'equipment.php',
        'assign_item' => 'assign-items.php',
        'barangay_official' => 'barangay-officials.php',
        'user' => 'users.php',
    ];
}

function osaeits_move_entity_to_trash(PDO $pdo, string $entityType, int $entityId, string $title, array $payload, int $deletedBy): int
{
    $stmt = $pdo->prepare("
        INSERT INTO trash_records (entity_type, entity_id, title, payload, deleted_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $entityType,
        $entityId,
        $title,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $deletedBy,
    ]);

    return (int)$pdo->lastInsertId();
}

function osaeits_table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("DESCRIBE `{$table}`");
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[] = (string)$row['Field'];
    }
    return $columns;
}

function osaeits_apply_assignment_effects(PDO $pdo, string $itemType, int $itemRefId, string $status, int $qty, int $direction = 1): void
{
    if ($itemType !== 'supply') {
        return;
    }

    $delta = ($status === 'assigned' ? -$qty : 0) * $direction;
    if ($delta !== 0) {
        $pdo->prepare("UPDATE supplies SET current_stock = GREATEST(current_stock + ?, 0) WHERE id = ?")
            ->execute([$delta, $itemRefId]);
    }
}

function osaeits_validate_entity_restore(PDO $pdo, string $entityType, array $payload): string
{
    $map = osaeits_entity_table_map();
    if (!isset($map[$entityType])) {
        return 'Unsupported trash record type.';
    }

    $entityId = (int)($payload['id'] ?? 0);
    if ($entityId <= 0) {
        return 'This trash record is missing its original ID.';
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$map[$entityType]}` WHERE id = ?");
    $stmt->execute([$entityId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return 'A record with this original ID already exists.';
    }

    if (in_array($entityType, ['supply', 'equipment'], true)
        && osaeits_table_has_column($pdo, $map[$entityType], 'item_code')) {
        $itemCode = osaeits_clean_inventory_text($payload['item_code'] ?? '');
        if ($itemCode !== '') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$map[$entityType]}` WHERE item_code = ?");
            $stmt->execute([$itemCode]);
            if ((int)$stmt->fetchColumn() > 0) {
                return 'An item with the same item code already exists.';
            }
        }
    }

    if ($entityType === 'supply') {
        $duplicate = osaeits_find_supply_duplicate(
            $pdo,
            (string)($payload['name'] ?? ''),
            (string)($payload['description'] ?? ''),
            (string)($payload['unit'] ?? '')
        );
        if ($duplicate) {
            return 'A supply with the same item name, details, and unit already exists.';
        }
    }

    if ($entityType === 'equipment') {
        $duplicate = osaeits_find_equipment_duplicate(
            $pdo,
            (string)($payload['name'] ?? ''),
            (string)($payload['serial_number'] ?? ''),
            (string)($payload['brand'] ?? ''),
            (string)($payload['model'] ?? '')
        );
        if ($duplicate) {
            return 'Equipment with the same item name or serial number already exists.';
        }
    }

    if ($entityType === 'assign_item'
        && (string)($payload['item_type'] ?? '') === 'supply') {
        if ((string)($payload['status'] ?? '') === 'returned') {
            return 'Supplies are consumable and cannot be restored as returned assignments.';
        }
        if ((string)($payload['status'] ?? '') === 'assigned') {
            $available = osaeits_supply_current_stock($pdo, (int)$payload['item_ref_id']);
            $qty = (int)($payload['quantity'] ?? 0);
            if ($qty > $available) {
                return "Unable to restore: supply stock is only {$available}.";
            }
        }
    }

    return '';
}

function osaeits_restore_entity_from_trash(PDO $pdo, array $trash, int $restoredBy): string
{
    $entityType = (string)$trash['entity_type'];
    $payload = json_decode((string)$trash['payload'], true);
    if (!is_array($payload)) {
        return 'This trash record has invalid saved data.';
    }

    $validation = osaeits_validate_entity_restore($pdo, $entityType, $payload);
    if ($validation !== '') {
        return $validation;
    }

    $map = osaeits_entity_table_map();
    $table = $map[$entityType];
    $columns = array_values(array_intersect(osaeits_table_columns($pdo, $table), array_keys($payload)));
    if (empty($columns)) {
        return 'No restorable columns were found.';
    }

    $quotedColumns = array_map(static fn($col) => "`{$col}`", $columns);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $values = array_map(static fn($col) => $payload[$col], $columns);

    $stmt = $pdo->prepare("INSERT INTO `{$table}` (" . implode(', ', $quotedColumns) . ") VALUES ({$placeholders})");
    $stmt->execute($values);

    if (in_array($entityType, ['supply', 'equipment'], true)) {
        osaeits_ensure_item_identifier($pdo, $entityType, (int)$payload['id']);
    }
    if ($entityType === 'supply') {
        $minimumStock = max(
            osaeits_supply_product_minimum_stock($pdo, (string)($payload['name'] ?? '')),
            max(0, (int)($payload['minimum_stock'] ?? 0))
        );
        osaeits_sync_supply_product_minimum_stock($pdo, (string)($payload['name'] ?? ''), $minimumStock);
    }

    if ($entityType === 'assign_item') {
        osaeits_apply_assignment_effects(
            $pdo,
            (string)$payload['item_type'],
            (int)$payload['item_ref_id'],
            (string)$payload['status'],
            (int)$payload['quantity'],
            1
        );
    }

    $stmt = $pdo->prepare("UPDATE trash_records SET restored_at = NOW(), restored_by = ? WHERE id = ?");
    $stmt->execute([$restoredBy, (int)$trash['id']]);

    return '';
}
