<?php
/**
 * Run once to create database and tables.
 * Uses same DB as original OSAEITS (osaeits_db) - run from XAMPP or create DB first.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

function osaeits_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

$databaseUrl = osaeits_env('DATABASE_URL');
$db_host = osaeits_env('DB_HOST', 'tswkc0kg0wg4s0cc0g40woo8');
$db_port = osaeits_env('DB_PORT', '3306');
$db_name = osaeits_env('DB_NAME', osaeits_env('MYSQL_DATABASE', 'default'));
$db_user = osaeits_env('DB_USER', osaeits_env('MYSQL_USER', 'mysql'));
$db_pass = osaeits_env('DB_PASS', osaeits_env('MYSQL_PASSWORD', 'OziCKHrZSWAh5fXpv10n1r4ltO4xLGcsFoI7NEWs3KdLFtrFKZZKfV2FAjsOqZrO'));

if ($databaseUrl) {
    $parts = parse_url($databaseUrl);
    if ($parts !== false) {
        $db_host = $parts['host'] ?? $db_host;
        $db_port = isset($parts['port']) ? (string)$parts['port'] : $db_port;
        $db_user = isset($parts['user']) ? urldecode((string)$parts['user']) : $db_user;
        $db_pass = isset($parts['pass']) ? urldecode((string)$parts['pass']) : $db_pass;
        $db_name = isset($parts['path']) ? ltrim((string)$parts['path'], '/') : $db_name;
    }
}

try {
    $pdo = new PDO(
        "mysql:host={$db_host};port={$db_port};charset=utf8mb4",
        (string)$db_user,
        (string)$db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name`");
    $pdo->exec("USE `$db_name`");
} catch (PDOException $e) {
    die('Connection failed: ' . $e->getMessage());
}

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    role ENUM('admin', 'user') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS supplies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(30) UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(50) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    current_stock INT DEFAULT 0,
    minimum_stock INT DEFAULT 0,
    unit_price DECIMAL(10,2) DEFAULT 0.00,
    supplier VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS equipment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(30) UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(50) NOT NULL,
    serial_number VARCHAR(100) UNIQUE,
    model VARCHAR(100),
    brand VARCHAR(100),
    status ENUM('servicable', 'unservicable') DEFAULT 'servicable',
    location VARCHAR(100),
    purok_area VARCHAR(120) NULL,
    appropriation VARCHAR(180) NULL,
    person_incharge VARCHAR(120) NULL,
    assigned_to INT,
    purchase_date DATE,
    warranty_expiry DATE,
    purchase_price DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Ensure every inventory item has a stable visible identifier.
try { $pdo->exec("ALTER TABLE supplies ADD COLUMN item_code VARCHAR(30) NULL AFTER id"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE equipment ADD COLUMN item_code VARCHAR(30) NULL AFTER id"); } catch (Throwable $e) {}
try { $pdo->exec("UPDATE supplies SET item_code = CONCAT('SP-', LPAD(id, 5, '0')) WHERE item_code IS NULL OR TRIM(item_code) = ''"); } catch (Throwable $e) {}
try { $pdo->exec("UPDATE equipment SET item_code = CONCAT('EQ-', LPAD(id, 5, '0')) WHERE item_code IS NULL OR TRIM(item_code) = ''"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE supplies ADD UNIQUE KEY uniq_supplies_item_code (item_code)"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE equipment ADD UNIQUE KEY uniq_equipment_item_code (item_code)"); } catch (Throwable $e) {}

// Ensure new reporting columns exist on old databases.
try { $pdo->exec("ALTER TABLE equipment ADD COLUMN purok_area VARCHAR(120) NULL AFTER location"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE equipment ADD COLUMN appropriation VARCHAR(180) NULL AFTER purok_area"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE equipment ADD COLUMN person_incharge VARCHAR(120) NULL AFTER appropriation"); } catch (Throwable $e) {}

// Normalize legacy status values, then enforce current enum.
try {
    $pdo->exec("UPDATE equipment SET status = 'servicable' WHERE status IN ('available','in_use','maintenance')");
    $pdo->exec("UPDATE equipment SET status = 'unservicable' WHERE status IN ('retired')");
    $pdo->exec("ALTER TABLE equipment MODIFY status ENUM('servicable', 'unservicable') DEFAULT 'servicable'");
} catch (Throwable $e) {
    // Ignore on fresh installs or DBs that already match this schema.
}

$pdo->exec("CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_type ENUM('supply', 'equipment') NOT NULL,
    item_id INT NOT NULL,
    quantity INT DEFAULT 0,
    action ENUM('in', 'out', 'adjustment') NOT NULL,
    reference_number VARCHAR(100),
    notes TEXT,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_type ENUM('supply', 'equipment') NOT NULL,
    item_id INT NOT NULL,
    transaction_type ENUM('purchase', 'issue', 'return', 'adjustment') NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(10,2) DEFAULT 0.00,
    reference_number VARCHAR(100),
    notes TEXT,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS transaction_trash (
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
)");

// Backfill trace numbers for legacy transactions and keep lookup fast.
try {
    $pdo->exec("
        UPDATE transactions
        SET reference_number = CONCAT(
            CASE WHEN item_type = 'equipment' THEN 'EQ' ELSE 'SP' END,
            '-',
            CASE
                WHEN transaction_type = 'purchase' THEN 'PUR'
                WHEN transaction_type = 'issue' THEN 'ISS'
                WHEN transaction_type = 'return' THEN 'RET'
                WHEN transaction_type = 'adjustment' THEN 'ADJ'
                ELSE 'TX'
            END,
            '-',
            DATE_FORMAT(COALESCE(created_at, NOW()), '%Y%m%d'),
            '-',
            LPAD(id, 5, '0')
        )
        WHERE reference_number IS NULL OR TRIM(reference_number) = ''
    ");
} catch (Throwable $e) {}
try {
    $pdo->exec("
        UPDATE transaction_trash
        SET reference_number = CONCAT(
            CASE WHEN item_type = 'equipment' THEN 'EQ' ELSE 'SP' END,
            '-',
            CASE
                WHEN transaction_type = 'purchase' THEN 'PUR'
                WHEN transaction_type = 'issue' THEN 'ISS'
                WHEN transaction_type = 'return' THEN 'RET'
                WHEN transaction_type = 'adjustment' THEN 'ADJ'
                ELSE 'TX'
            END,
            '-',
            DATE_FORMAT(COALESCE(original_created_at, deleted_at, NOW()), '%Y%m%d'),
            '-',
            LPAD(id, 5, '0')
        )
        WHERE reference_number IS NULL OR TRIM(reference_number) = ''
    ");
} catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE transactions ADD INDEX idx_transactions_reference (reference_number)"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE transaction_trash ADD INDEX idx_transaction_trash_reference (reference_number)"); } catch (Throwable $e) {}

$pdo->exec("CREATE TABLE IF NOT EXISTS trash_records (
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
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id INT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_created (created_at),
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_entity (entity_type, entity_id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS barangay_officials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(60) NOT NULL,
    middle_name VARCHAR(60) NULL,
    last_name VARCHAR(60) NOT NULL,
    suffix VARCHAR(20) NULL,
    position_title VARCHAR(120) NOT NULL,
    committee VARCHAR(120) NULL,
    contact_number VARCHAR(30) NULL,
    email VARCHAR(120) NULL,
    term_start DATE NULL,
    term_end DATE NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_official_position (position_title),
    INDEX idx_official_status (status)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS assign_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_type ENUM('supply', 'equipment', 'inventory') NOT NULL,
    item_ref_id INT NOT NULL,
    quantity INT DEFAULT 1,
    assigned_to VARCHAR(120) NOT NULL,
    assigned_area VARCHAR(120) NULL,
    appropriation VARCHAR(180) NULL,
    assigned_date DATE NOT NULL,
    status ENUM('assigned', 'returned') DEFAULT 'assigned',
    notes TEXT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_assign_item (item_type, item_ref_id),
    INDEX idx_assign_status (status),
    INDEX idx_assign_date (assigned_date)
)");

// Ensure assignment appropriation column exists on old databases.
try { $pdo->exec("ALTER TABLE assign_items ADD COLUMN appropriation VARCHAR(180) NULL AFTER assigned_area"); } catch (Throwable $e) {}

// Merge exact duplicate supply catalog rows while preserving their movements.
try {
    $duplicateGroups = $pdo->query("
        SELECT
            MIN(id) AS canonical_id,
            GROUP_CONCAT(id ORDER BY id) AS ids,
            SUM(current_stock) AS total_stock,
            MAX(minimum_stock) AS minimum_stock,
            SUBSTRING_INDEX(GROUP_CONCAT(unit ORDER BY updated_at DESC, id DESC), ',', 1) AS unit,
            SUBSTRING_INDEX(GROUP_CONCAT(unit_price ORDER BY updated_at DESC, id DESC), ',', 1) AS unit_price,
            SUBSTRING_INDEX(GROUP_CONCAT(supplier ORDER BY updated_at DESC, id DESC), ',', 1) AS supplier
        FROM supplies
        GROUP BY LOWER(TRIM(name)), LOWER(TRIM(COALESCE(description, ''))), LOWER(TRIM(unit))
        HAVING COUNT(*) > 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($duplicateGroups as $group) {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)$group['ids']))));
        $canonicalId = (int)$group['canonical_id'];
        $duplicateIds = array_values(array_filter($ids, static fn($id) => $id !== $canonicalId));
        if (empty($duplicateIds)) {
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($duplicateIds), '?'));
        $pdo->beginTransaction();
        $duplicateRowsStmt = $pdo->prepare("SELECT * FROM supplies WHERE id IN ({$placeholders})");
        $duplicateRowsStmt->execute($duplicateIds);
        $trashStmt = $pdo->prepare("
            INSERT INTO trash_records (entity_type, entity_id, title, payload, deleted_by)
            VALUES ('supply', ?, ?, ?, 1)
        ");
        foreach ($duplicateRowsStmt->fetchAll(PDO::FETCH_ASSOC) as $duplicateRow) {
            $title = trim((string)($duplicateRow['item_code'] ?? '') . ' - ' . (string)$duplicateRow['name'], ' -');
            $trashStmt->execute([
                (int)$duplicateRow['id'],
                $title !== '' ? $title : ('Supply #' . (int)$duplicateRow['id']),
                json_encode($duplicateRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        $params = array_merge([$canonicalId], $duplicateIds);
        $pdo->prepare("UPDATE transactions SET item_id = ? WHERE item_type = 'supply' AND item_id IN ({$placeholders})")->execute($params);
        $pdo->prepare("UPDATE assign_items SET item_ref_id = ? WHERE item_type = 'supply' AND item_ref_id IN ({$placeholders})")->execute($params);
        $pdo->prepare("
            UPDATE supplies
            SET current_stock = ?,
                minimum_stock = ?,
                unit = CASE WHEN ? <> '' THEN ? ELSE unit END,
                unit_price = ?,
                supplier = ?
            WHERE id = ?
        ")->execute([
            max(0, (int)$group['total_stock']),
            max(0, (int)$group['minimum_stock']),
            (string)($group['unit'] ?? ''),
            (string)($group['unit'] ?? ''),
            (float)$group['unit_price'],
            $group['supplier'] ?: null,
            $canonicalId,
        ]);
        $pdo->prepare("DELETE FROM supplies WHERE id IN ({$placeholders})")->execute($duplicateIds);
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// Keep one product-level minimum across variants with the same supply name.
try {
    $pdo->exec("
        UPDATE supplies s
        INNER JOIN (
            SELECT product_key, product_minimum
            FROM (
                SELECT
                    LOWER(TRIM(name)) AS product_key,
                    MAX(minimum_stock) AS product_minimum
                FROM supplies
                GROUP BY LOWER(TRIM(name))
            ) grouped_minimums
        ) product_minimums ON LOWER(TRIM(s.name)) = product_minimums.product_key
        SET s.minimum_stock = product_minimums.product_minimum
        WHERE s.minimum_stock <> product_minimums.product_minimum
    ");
} catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE supplies DROP INDEX uniq_supplies_name"); } catch (Throwable $e) {}
try { $pdo->exec("UPDATE supplies SET description = '' WHERE description IS NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE supplies ADD UNIQUE KEY uniq_supplies_master_item (name, description(191), unit)"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE equipment DROP INDEX uniq_equipment_name"); } catch (Throwable $e) {}

// Convert legacy starting supply stock into purchase transactions.
try {
    $legacyStockRows = $pdo->query("
        SELECT s.id, s.current_stock, s.unit_price
        FROM supplies s
        LEFT JOIN transactions t ON t.item_type = 'supply' AND t.item_id = s.id
        WHERE s.current_stock > 0
        GROUP BY s.id, s.current_stock, s.unit_price
        HAVING COUNT(t.id) = 0
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        INSERT INTO transactions
            (item_type, item_id, transaction_type, quantity, unit_price, total_amount, reference_number, notes, user_id)
        VALUES
            ('supply', ?, 'purchase', ?, ?, ?, ?, 'Opening stock migrated from supply record', 1)
    ");
    foreach ($legacyStockRows as $row) {
        $quantity = max(0, (int)$row['current_stock']);
        $unitPrice = (float)$row['unit_price'];
        $stmt->execute([
            (int)$row['id'],
            $quantity,
            $unitPrice,
            $quantity * $unitPrice,
            'SP-OPENING-' . (int)$row['id'],
        ]);
    }
} catch (Throwable $e) {}

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
if ($stmt->fetchColumn() == 0) {
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, email, password, first_name, last_name, role) VALUES ('admin', 'admin@osaeits.com', ?, 'Admin', 'User', 'admin')")->execute([$hash]);
    echo "Default admin created: admin@osaeits.com / admin123<br>";
}

echo "<h2>Migration done.</h2>";
echo "<p><a href='login.php'>Go to Login</a></p>";
