<?php

function osaeits_audit_action_label(string $action): string
{
    $map = [
        'auth.login' => 'Signed in to the system',
        'auth.logout' => 'Signed out',
        'auth.register' => 'Created a new login account',
        'user.create' => 'Added a user account',
        'user.update' => 'Updated a user account',
        'user.delete' => 'Moved a user account to trash',
        'supply.create' => 'Added a supply item',
        'supply.update' => 'Updated a supply item',
        'supply.delete' => 'Moved a supply item to trash',
        'equipment.create' => 'Added equipment',
        'equipment.update' => 'Updated equipment',
        'equipment.delete' => 'Moved equipment to trash',
        'transaction.create' => 'Recorded a transaction',
        'transaction.update' => 'Updated a transaction',
        'transaction.delete' => 'Moved a transaction to trash',
        'transaction.restore' => 'Restored a transaction',
        'transaction.permanent_delete' => 'Blocked permanent transaction deletion',
        'trash.restore' => 'Restored a trash record',
        'trash.permanent_delete' => 'Blocked permanent record deletion',
        'official.create' => 'Added a barangay official',
        'official.update' => 'Updated a barangay official',
        'official.delete' => 'Moved a barangay official to trash',
        'assign_item.create' => 'Recorded an item assignment',
        'assign_item.update' => 'Updated an item assignment',
        'assign_item.delete' => 'Removed an item assignment',
        'report.display' => 'Displayed a report',
        'report.print' => 'Printed a report',
    ];

    if (isset($map[$action])) {
        return $map[$action];
    }

    $pretty = trim(str_replace(['.', '_'], ' ', $action));
    return $pretty !== '' ? 'Action: ' . ucfirst($pretty) : 'An action was recorded';
}

function osaeits_audit_entity_label(?string $entityType, $entityId): string
{
    $entityType = trim((string)$entityType);
    if ($entityType === '') {
        return '-';
    }

    $map = [
        'user' => 'User profile',
        'supply' => 'Supply item',
        'equipment' => 'Equipment',
        'transaction' => 'Transaction',
        'transaction_trash' => 'Trash transaction',
        'barangay_official' => 'Barangay official',
        'assign_item' => 'Assignment',
        'report' => 'Report',
    ];
    $label = $map[$entityType] ?? ucfirst(str_replace('_', ' ', $entityType));

    return $entityId !== null && $entityId !== ''
        ? $label . ' (record no. ' . (int)$entityId . ')'
        : $label;
}

function osaeits_audit_role_label(?string $role): string
{
    $role = strtolower(trim((string)$role));
    if ($role === 'admin') {
        return 'Administrator';
    }
    if ($role === 'user') {
        return 'Staff';
    }

    return $role;
}

function osaeits_audit_transaction_type_label(?string $type): string
{
    $type = strtolower(trim((string)$type));
    $labels = [
        'purchase' => 'Purchase',
        'issue' => 'Issue',
        'return' => 'Return',
        'adjustment' => 'Adjustment',
    ];

    return $labels[$type] ?? (string)$type;
}

function osaeits_audit_equipment_status_label(?string $status): string
{
    $status = strtolower(trim((string)$status));
    if ($status === 'servicable' || $status === 'serviceable') {
        return 'Working / Serviceable';
    }
    if (in_array($status, ['unservicable', 'unserviceable', 'nonservicesable'], true)) {
        return 'Not working / Nonservicesable';
    }

    return $status;
}

function osaeits_audit_item_kind(?string $itemType): string
{
    $itemType = strtolower(trim((string)$itemType));
    if ($itemType === 'supply') {
        return 'supply';
    }
    if ($itemType === 'equipment') {
        return 'equipment';
    }

    return $itemType !== '' ? $itemType : 'item';
}

function osaeits_audit_has_value($value): bool
{
    return $value !== null && trim((string)$value) !== '';
}

function osaeits_audit_money($value): string
{
    return is_numeric($value) ? 'PHP ' . number_format((float)$value, 2) : trim((string)$value);
}

function osaeits_audit_value_text($value, string $field = ''): string
{
    if ($value === null || $value === '') {
        return 'blank';
    }
    if (is_bool($value)) {
        return $value ? 'yes' : 'no';
    }
    if (in_array($field, ['unit_price', 'total_amount', 'purchase_price'], true)) {
        return osaeits_audit_money($value);
    }
    if ($field === 'transaction_type') {
        return osaeits_audit_transaction_type_label((string)$value);
    }
    if ($field === 'item_type') {
        return osaeits_audit_item_kind((string)$value);
    }

    return trim((string)$value);
}

function osaeits_audit_add_part(array &$parts, string $label, $value, string $field = ''): void
{
    if (!osaeits_audit_has_value($value)) {
        return;
    }

    $parts[] = $label . ': ' . osaeits_audit_value_text($value, $field) . '.';
}

function osaeits_audit_sentence(array $parts, string $fallback): string
{
    $parts = array_values(array_filter(array_map('trim', $parts), static function (string $part): bool {
        return $part !== '';
    }));

    return $parts !== [] ? implode(' ', $parts) : $fallback;
}

function osaeits_audit_changed($old, $new, string $field): bool
{
    if (in_array($field, ['item_id', 'quantity', 'unit_price', 'total_amount', 'purchase_price'], true)
        && is_numeric($old) && is_numeric($new)) {
        return (float)$old !== (float)$new;
    }

    return trim((string)$old) !== trim((string)$new);
}

function osaeits_audit_transaction_changes(array $previous, array $current): array
{
    $labels = [
        'item_type' => 'item type',
        'item_id' => 'item record no.',
        'transaction_type' => 'type',
        'quantity' => 'quantity',
        'unit_price' => 'unit price',
        'total_amount' => 'total amount',
        'reference_number' => 'reference',
    ];
    $changes = [];

    foreach ($labels as $field => $label) {
        if (!array_key_exists($field, $previous) || !array_key_exists($field, $current)) {
            continue;
        }
        if (!osaeits_audit_changed($previous[$field], $current[$field], $field)) {
            continue;
        }
        $changes[] = $label . ' from ' . osaeits_audit_value_text($previous[$field], $field)
            . ' to ' . osaeits_audit_value_text($current[$field], $field);
    }

    return $changes;
}

function osaeits_audit_humanize_transaction_details(array $row, bool $wasRemoved = false): string
{
    $parts = [];
    $kind = osaeits_audit_item_kind($row['item_type'] ?? '');
    $itemId = isset($row['item_id']) ? (int)$row['item_id'] : 0;
    $quantity = isset($row['quantity']) ? (int)$row['quantity'] : 0;
    $type = osaeits_audit_transaction_type_label($row['transaction_type'] ?? '');

    $parts[] = ($wasRemoved ? 'Deleted transaction' : 'Transaction') . ': '
        . $quantity . ' unit(s) for ' . $kind . ' record no. ' . $itemId . '.';
    if ($type !== '') {
        $parts[] = 'Type: ' . $type . '.';
    }
    osaeits_audit_add_part($parts, 'Unit price', $row['unit_price'] ?? null, 'unit_price');
    osaeits_audit_add_part($parts, 'Total amount', $row['total_amount'] ?? null, 'total_amount');
    osaeits_audit_add_part($parts, 'Reference', $row['reference_number'] ?? null);
    osaeits_audit_add_part($parts, 'Supplier', $row['supplier'] ?? null);
    osaeits_audit_add_part($parts, 'Detail', $row['detail'] ?? null);
    osaeits_audit_add_part($parts, 'Assigned to', $row['assigned_to'] ?? null);
    osaeits_audit_add_part($parts, 'Area or purok', $row['assigned_area'] ?? null);
    osaeits_audit_add_part($parts, 'Appropriation', $row['appropriation'] ?? null);
    osaeits_audit_add_part($parts, 'Assigned date', $row['assigned_date'] ?? null);
    osaeits_audit_add_part($parts, 'Returned status', osaeits_audit_equipment_status_label($row['equipment_status'] ?? null));
    osaeits_audit_add_part($parts, 'Notes', $row['notes'] ?? null);

    if (!empty($row['previous']) && is_array($row['previous'])) {
        $changes = osaeits_audit_transaction_changes($row['previous'], $row);
        if ($changes !== []) {
            $parts[] = 'Changed: ' . implode('; ', $changes) . '.';
        }
    }

    return osaeits_audit_sentence($parts, 'Transaction details were saved.');
}

function osaeits_audit_known_details(array $decoded): string
{
    $labels = [
        'username' => 'Username',
        'email' => 'Email',
        'name' => 'Name',
        'item_code' => 'Item code',
        'serial_number' => 'Serial no.',
        'position_title' => 'Position',
        'status' => 'Status',
        'title' => 'Record',
        'trash_id' => 'Trash record no.',
    ];
    $parts = [];

    foreach ($labels as $key => $label) {
        osaeits_audit_add_part($parts, $label, $decoded[$key] ?? null, $key);
    }

    return osaeits_audit_sentence($parts, '');
}

function osaeits_audit_fallback_details(array $decoded): string
{
    $hidden = ['password', 'password_hash', 'snapshot', 'previous'];
    $parts = [];

    foreach ($decoded as $key => $value) {
        if (in_array((string)$key, $hidden, true) || is_array($value) || is_object($value)) {
            continue;
        }
        if (!osaeits_audit_has_value($value)) {
            continue;
        }
        $label = ucfirst(str_replace('_', ' ', (string)$key));
        $parts[] = $label . ': ' . osaeits_audit_value_text($value, (string)$key) . '.';
        if (count($parts) >= 6) {
            break;
        }
    }

    return osaeits_audit_sentence($parts, 'Extra data was attached but could not be summarized.');
}

function osaeits_audit_details_summary(string $action, ?string $entityType, $rawDetails): string
{
    if ($rawDetails === null || $rawDetails === '') {
        return $action === 'auth.logout'
            ? 'Session ended.'
            : 'No extra details were saved for this entry.';
    }

    $decoded = json_decode((string)$rawDetails, true);
    if (!is_array($decoded)) {
        return (string)$rawDetails;
    }

    if ($action === 'transaction.delete' && !empty($decoded['snapshot']) && is_array($decoded['snapshot'])) {
        return osaeits_audit_humanize_transaction_details($decoded['snapshot'], true);
    }

    if (in_array($action, ['transaction.create', 'transaction.update', 'transaction.restore'], true)
        && isset($decoded['transaction_type'], $decoded['item_type'], $decoded['quantity'])) {
        $prefix = $action === 'transaction.restore' && isset($decoded['trash_id'])
            ? 'Restored from trash record no. ' . (int)$decoded['trash_id'] . '. '
            : '';
        return $prefix . osaeits_audit_humanize_transaction_details($decoded, false);
    }

    if ($action === 'auth.login') {
        return !empty($decoded['username'])
            ? 'Signed in with username: ' . $decoded['username'] . '.'
            : 'User signed in.';
    }

    if ($action === 'auth.register') {
        $parts = ['A new account was registered.'];
        osaeits_audit_add_part($parts, 'Username', $decoded['username'] ?? null);
        osaeits_audit_add_part($parts, 'Email', $decoded['email'] ?? null);
        return osaeits_audit_sentence($parts, 'A new account was registered.');
    }

    if (in_array($action, ['user.create', 'user.update'], true)) {
        $parts = [];
        osaeits_audit_add_part($parts, 'Username', $decoded['username'] ?? null);
        osaeits_audit_add_part($parts, 'Email', $decoded['email'] ?? null);
        $role = osaeits_audit_role_label($decoded['role'] ?? null);
        osaeits_audit_add_part($parts, 'Role', $role);
        return osaeits_audit_sentence($parts, 'User account details were saved.');
    }

    if ($action === 'user.delete') {
        $name = trim((string)($decoded['deleted_username'] ?? ''));
        $email = trim((string)($decoded['deleted_email'] ?? ''));
        if ($name !== '' || $email !== '') {
            return 'Moved user ' . ($name !== '' ? $name : $email) . ' to trash'
                . ($name !== '' && $email !== '' ? ' (' . $email . ')' : '') . '.';
        }
        return 'A user account was moved to trash.';
    }

    if (in_array($action, ['supply.create', 'supply.update', 'supply.delete'], true)) {
        $parts = [];
        osaeits_audit_add_part($parts, 'Supply', $decoded['name'] ?? null);
        osaeits_audit_add_part($parts, 'Item code', $decoded['item_code'] ?? null);
        osaeits_audit_add_part($parts, 'Trash record no.', $decoded['trash_id'] ?? null);
        return osaeits_audit_sentence($parts, 'Supply details were saved.');
    }

    if (in_array($action, ['equipment.create', 'equipment.update', 'equipment.delete'], true)) {
        $parts = [];
        osaeits_audit_add_part($parts, 'Equipment', $decoded['name'] ?? null);
        osaeits_audit_add_part($parts, 'Item code', $decoded['item_code'] ?? null);
        osaeits_audit_add_part($parts, 'Serial no.', $decoded['serial_number'] ?? null);
        osaeits_audit_add_part($parts, 'Status', osaeits_audit_equipment_status_label($decoded['status'] ?? null));
        osaeits_audit_add_part($parts, 'Trash record no.', $decoded['trash_id'] ?? null);
        return osaeits_audit_sentence($parts, 'Equipment details were saved.');
    }

    if (in_array($action, ['official.create', 'official.update', 'official.delete'], true)) {
        $parts = [];
        osaeits_audit_add_part($parts, 'Name', $decoded['name'] ?? null);
        osaeits_audit_add_part($parts, 'Position', $decoded['position_title'] ?? null);
        osaeits_audit_add_part($parts, 'Status', $decoded['status'] ?? null);
        osaeits_audit_add_part($parts, 'Trash record no.', $decoded['trash_id'] ?? null);
        return osaeits_audit_sentence($parts, 'Barangay official details were saved.');
    }

    if (in_array($action, ['assign_item.create', 'assign_item.update', 'assign_item.delete'], true)) {
        $parts = [];
        $kind = osaeits_audit_item_kind($decoded['item_type'] ?? '');
        if ($kind !== '') {
            $ref = isset($decoded['item_ref_id']) ? (int)$decoded['item_ref_id'] : 0;
            $parts[] = 'Item: ' . $kind . ' record no. ' . $ref . '.';
        }
        osaeits_audit_add_part($parts, 'Assigned to', $decoded['assigned_to'] ?? null);
        osaeits_audit_add_part($parts, 'Area or purok', $decoded['assigned_area'] ?? null);
        osaeits_audit_add_part($parts, 'Appropriation', $decoded['appropriation'] ?? null);
        osaeits_audit_add_part($parts, 'Assigned date', $decoded['assigned_date'] ?? null);
        osaeits_audit_add_part($parts, 'Quantity', $decoded['quantity'] ?? null);
        osaeits_audit_add_part($parts, 'Status', $decoded['status'] ?? null);
        return osaeits_audit_sentence($parts, 'Assignment details were saved.');
    }

    if (in_array($action, ['report.display', 'report.print'], true)) {
        $parts = [];
        osaeits_audit_add_part($parts, 'Report', $decoded['report'] ?? null);
        osaeits_audit_add_part($parts, 'Quarter', $decoded['quarter'] ?? null);
        osaeits_audit_add_part($parts, 'Period', $decoded['period'] ?? null);
        return osaeits_audit_sentence($parts, 'Report activity was recorded.');
    }

    if ($action === 'trash.restore') {
        $known = osaeits_audit_known_details($decoded);
        return $known !== '' ? 'Restored record. ' . $known : 'A trash record was restored.';
    }

    $known = osaeits_audit_known_details($decoded);
    return $known !== '' ? $known : osaeits_audit_fallback_details($decoded);
}
