<?php

function osaeits_permission_definitions(): array
{
    return [
        'purchase' => [
            'label' => 'Purchase',
            'group' => 'Inventory Access',
            'default_nonadmin' => true,
        ],
        'supplies' => [
            'label' => 'Supplies',
            'group' => 'Inventory Access',
            'default_nonadmin' => true,
        ],
        'equipment' => [
            'label' => 'Equipment',
            'group' => 'Inventory Access',
            'default_nonadmin' => true,
        ],
        'purchase_history' => [
            'label' => 'Purchase history',
            'group' => 'Inventory Access',
            'default_nonadmin' => true,
        ],
        'issue' => [
            'label' => 'Issue',
            'group' => 'Inventory Access',
            'default_nonadmin' => true,
        ],
        'return' => [
            'label' => 'Return',
            'group' => 'Inventory Access',
            'default_nonadmin' => true,
        ],
        'assign_items' => [
            'label' => 'Item assignments',
            'group' => 'Inventory Access',
            'default_nonadmin' => false,
        ],
        'trash' => [
            'label' => 'Trash',
            'group' => 'Additional / Optional Access',
            'default_nonadmin' => true,
        ],
        'reports' => [
            'label' => 'Reports',
            'group' => 'Additional / Optional Access',
            'default_nonadmin' => true,
        ],
        'barangay_officials' => [
            'label' => 'Barangay officials',
            'group' => 'Administrative Access',
            'default_nonadmin' => false,
        ],
        'users' => [
            'label' => 'Users',
            'group' => 'Administrative Access',
            'default_nonadmin' => false,
        ],
        'activity_log' => [
            'label' => 'Activity log',
            'group' => 'Administrative Access',
            'default_nonadmin' => false,
        ],
        'database_backup' => [
            'label' => 'Database backup',
            'group' => 'Administrative Access',
            'default_nonadmin' => false,
        ],
    ];
}

function osaeits_permission_groups(): array
{
    $groups = [];
    foreach (osaeits_permission_definitions() as $key => $definition) {
        $groups[$definition['group']][$key] = $definition;
    }
    return $groups;
}

function osaeits_all_permission_keys(): array
{
    return array_keys(osaeits_permission_definitions());
}

function osaeits_default_nonadmin_permissions(): array
{
    $keys = [];
    foreach (osaeits_permission_definitions() as $key => $definition) {
        if (!empty($definition['default_nonadmin'])) {
            $keys[] = $key;
        }
    }
    return $keys;
}

function osaeits_filter_permission_keys(array $keys): array
{
    $allowed = array_fill_keys(osaeits_all_permission_keys(), true);
    $filtered = [];
    foreach ($keys as $key) {
        $key = trim((string)$key);
        if (isset($allowed[$key])) {
            $filtered[$key] = true;
        }
    }
    return array_keys($filtered);
}

function osaeits_ensure_access_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_access_profiles (
            user_id INT NOT NULL PRIMARY KEY,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_access_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            permission_key VARCHAR(80) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_permission (user_id, permission_key),
            INDEX idx_user_access_user (user_id)
        )
    ");
}

function osaeits_user_access_configured(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    osaeits_ensure_access_tables($pdo);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_access_profiles WHERE user_id = ?");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn() > 0;
}

function osaeits_load_user_permissions(PDO $pdo, int $userId, string $role = 'user'): array
{
    $role = strtolower(trim($role));
    if ($role === 'admin') {
        return osaeits_all_permission_keys();
    }
    if ($userId <= 0) {
        return osaeits_default_nonadmin_permissions();
    }

    osaeits_ensure_access_tables($pdo);
    if (!osaeits_user_access_configured($pdo, $userId)) {
        return osaeits_default_nonadmin_permissions();
    }

    $stmt = $pdo->prepare("SELECT permission_key FROM user_access_permissions WHERE user_id = ? ORDER BY permission_key ASC");
    $stmt->execute([$userId]);
    return osaeits_filter_permission_keys($stmt->fetchAll(PDO::FETCH_COLUMN));
}

function osaeits_save_user_permissions(PDO $pdo, int $userId, array $keys): void
{
    if ($userId <= 0) {
        return;
    }

    osaeits_ensure_access_tables($pdo);
    $keys = osaeits_filter_permission_keys($keys);

    $pdo->prepare("INSERT INTO user_access_profiles (user_id) VALUES (?) ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP")
        ->execute([$userId]);
    $pdo->prepare("DELETE FROM user_access_permissions WHERE user_id = ?")->execute([$userId]);

    if ($keys === []) {
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO user_access_permissions (user_id, permission_key) VALUES (?, ?)");
    foreach ($keys as $key) {
        $stmt->execute([$userId, $key]);
    }
}

function osaeits_refresh_session_access(PDO $pdo): void
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $stmt = $pdo->prepare("SELECT first_name, last_name, role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        session_unset();
        session_destroy();
        header('Location: ../login.php');
        exit;
    }

    $_SESSION['user_name'] = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    $_SESSION['user_role'] = (string)($user['role'] ?? 'user');
    $_SESSION['user_permissions'] = osaeits_load_user_permissions($pdo, $userId, (string)$_SESSION['user_role']);
}

function osaeits_can_access(string $permission): bool
{
    $permission = trim($permission);
    if ($permission === '' || $permission === 'dashboard') {
        return true;
    }
    if (($_SESSION['user_role'] ?? '') === 'admin') {
        return true;
    }
    $permissions = $_SESSION['user_permissions'] ?? [];
    return is_array($permissions) && in_array($permission, $permissions, true);
}

function osaeits_transaction_permission(PDO $pdo, int $transactionId, string $fallback = 'purchase'): string
{
    $type = $fallback;
    if ($transactionId > 0) {
        $stmt = $pdo->prepare("SELECT transaction_type FROM transactions WHERE id = ? LIMIT 1");
        $stmt->execute([$transactionId]);
        $found = trim((string)$stmt->fetchColumn());
        if ($found !== '') {
            $type = $found;
        }
    }

    return match ($type) {
        'issue' => 'issue',
        'return' => 'return',
        default => 'purchase',
    };
}

function osaeits_current_route_permission(PDO $pdo): ?string
{
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

    $map = [
        'supplies.php' => 'supplies',
        'supply-form.php' => 'supplies',
        'supply-delete.php' => 'supplies',
        'supply-detail.php' => 'supplies',
        'supply-variants.php' => 'supplies',
        'equipment.php' => 'equipment',
        'equipment-form.php' => 'equipment',
        'equipment-delete.php' => 'equipment',
        'equipment-detail.php' => 'equipment',
        'equipment-variants.php' => 'equipment',
        'assign-items.php' => 'assign_items',
        'assign-item-form.php' => 'assign_items',
        'assign-item-delete.php' => 'assign_items',
        'trash.php' => 'trash',
        'trash-restore.php' => 'trash',
        'trash-delete.php' => 'trash',
        'transaction-restore.php' => 'trash',
        'transaction-trash-delete.php' => 'trash',
        'reports.php' => 'reports',
        'users.php' => 'users',
        'user-form.php' => 'users',
        'user-delete.php' => 'users',
        'barangay-officials.php' => 'barangay_officials',
        'barangay-official-form.php' => 'barangay_officials',
        'barangay-official-delete.php' => 'barangay_officials',
        'ActivityLog.php' => 'activity_log',
        'database-backup.php' => 'database_backup',
    ];

    if (isset($map[$script])) {
        return $map[$script];
    }

    if ($script === 'inventory.php') {
        $txType = $_GET['transaction_type'] ?? '';
        if ($txType === 'issue') {
            return 'issue';
        }
        if ($txType === 'return') {
            return 'return';
        }
        $itemType = $_GET['item_type'] ?? '';
        if ($itemType === 'supply') {
            return 'supplies';
        }
        if ($itemType === 'equipment') {
            return 'equipment';
        }
        return 'purchase_history';
    }

    if ($script === 'transaction-form.php') {
        $id = (int)($_GET['id'] ?? 0);
        $fallback = $_POST['transaction_type'] ?? ($_GET['transaction_type'] ?? 'purchase');
        return osaeits_transaction_permission($pdo, $id, (string)$fallback);
    }

    if ($script === 'transaction-delete.php') {
        return osaeits_transaction_permission($pdo, (int)($_GET['id'] ?? 0));
    }

    return null;
}

function osaeits_enforce_current_route_access(PDO $pdo): void
{
    $permission = osaeits_current_route_permission($pdo);
    if ($permission === null || osaeits_can_access($permission)) {
        return;
    }

    $_SESSION['success_message'] = 'You do not have access to that section.';
    header('Location: dashboard.php');
    exit;
}

function osaeits_access_summary(array $keys, string $role): string
{
    if ($role === 'admin') {
        return 'Full access';
    }
    $definitions = osaeits_permission_definitions();
    $labels = [];
    foreach ($keys as $key) {
        if (isset($definitions[$key])) {
            $labels[] = $definitions[$key]['label'];
        }
    }
    return $labels === [] ? 'Dashboard only' : implode(', ', array_slice($labels, 0, 3)) . (count($labels) > 3 ? ' +' . (count($labels) - 3) : '');
}
