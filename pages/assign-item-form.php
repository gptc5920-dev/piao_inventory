<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

/**
 * Apply (or revert) the stock effect of an assignment.
 *  Supply  assigned  +1 -> current_stock -= qty
 *  Supply items are consumable; returned supply assignments are not allowed.
 *  Equipment assignment does not change serviceability condition.
 *  Inventory type has no direct stock effect (references a transaction).
 */
function applyAssignmentEffects(PDO $pdo, string $itemType, int $itemRefId, string $status, int $qty, int $direction = 1): void
{
    if ($itemType === 'supply') {
        $delta = ($status === 'assigned' ? -$qty : 0) * $direction;
        if ($delta !== 0) {
            // GREATEST ensures the column never drops below 0 at the DB level.
            $pdo->prepare("UPDATE supplies SET current_stock = GREATEST(current_stock + ?, 0) WHERE id = ?")
                ->execute([$delta, $itemRefId]);
        }
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM assign_items WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { header('Location: assign-items.php'); exit; }
}

$page_title = $row ? 'Edit Assigned Item' : 'Assign Item';
$current_page = 'assign_items';
$base_url = '../';

$supplySummarySql = osaeits_supply_movement_summary_sql();
$supplyStockExpr = osaeits_supply_stock_expression('tx', 's');
$supplyCodeExpr = osaeits_item_code_select_expr($pdo, 's', 'supply');
$supplies = $pdo->query("
    SELECT s.id, {$supplyCodeExpr} AS item_code, s.name, s.description, {$supplyStockExpr} AS current_stock
    FROM supplies s
    LEFT JOIN ({$supplySummarySql}) tx ON tx.item_id = s.id
    ORDER BY s.name ASC, s.description ASC
")->fetchAll(PDO::FETCH_ASSOC);
$equipmentCodeExpr = osaeits_item_code_select_expr($pdo, 'e', 'equipment');
$equipment = $pdo->query("SELECT id, {$equipmentCodeExpr} AS item_code, name, serial_number, status FROM equipment e ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$inventory = $pdo->query("SELECT id, item_type, transaction_type, quantity, created_at FROM transactions ORDER BY created_at DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_type = $_POST['item_type'] ?? '';
    $item_ref_id = (int)($_POST['item_ref_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 1);
    $assigned_to = trim($_POST['assigned_to'] ?? '');
    $assigned_area = trim($_POST['assigned_area'] ?? '');
    $appropriation = trim($_POST['appropriation'] ?? '');
    $assigned_date = trim($_POST['assigned_date'] ?? '');
    $status = $_POST['status'] ?? 'assigned';
    $notes = trim($_POST['notes'] ?? '');

    if (!in_array($item_type, ['supply', 'equipment', 'inventory'], true)) $item_type = '';
    if (!in_array($status, ['assigned', 'returned'], true)) $status = 'assigned';
    if ($quantity < 1) $quantity = 1;

    if ($item_type === '' || $item_ref_id <= 0 || $assigned_to === '' || $assigned_date === '') {
        $error = 'Item type, item, assigned to, and assigned date are required.';
    } elseif ($item_type === 'supply' && $status === 'returned') {
        $error = 'Supplies are consumable and cannot be returned. Use equipment for returnable items.';
    } else {
        // Validate referenced item exists.
        if ($item_type === 'supply') {
            $chk = $pdo->prepare("SELECT id FROM supplies WHERE id = ?");
        } elseif ($item_type === 'equipment') {
            $chk = $pdo->prepare("SELECT id FROM equipment WHERE id = ?");
            $quantity = 1;
        } else {
            $chk = $pdo->prepare("SELECT id FROM transactions WHERE id = ?");
        }
        $chk->execute([$item_ref_id]);
        $itemRow = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$itemRow) {
            $error = 'Selected item does not exist anymore.';
        } else {
            // For supplies being assigned, verify enough stock is available after reverting old effect.
            if ($item_type === 'supply' && $status === 'assigned') {
                $availableStock = osaeits_supply_current_stock($pdo, $item_ref_id);
                // If editing, the old assignment may have already reduced the stock — add it back for comparison.
                if ($row && $row['item_type'] === 'supply' && (int)$row['item_ref_id'] === $item_ref_id && $row['status'] === 'assigned') {
                    $availableStock += (int)$row['quantity'];
                }
                if ($quantity > $availableStock) {
                    $error = "Insufficient stock. Available: {$availableStock}";
                }
            }

            if ($error === '') {
                try {
                    $pdo->beginTransaction();

                    // Revert the old assignment's stock effect before applying the new one.
                    if ($row) {
                        applyAssignmentEffects($pdo, (string)$row['item_type'], (int)$row['item_ref_id'], (string)$row['status'], (int)$row['quantity'], -1);
                    }

                    if ($row) {
                        $stmt = $pdo->prepare("UPDATE assign_items
                            SET item_type=?, item_ref_id=?, quantity=?, assigned_to=?, assigned_area=?, appropriation=?, assigned_date=?, status=?, notes=?, user_id=?
                            WHERE id=?");
                        $stmt->execute([
                            $item_type, $item_ref_id, $quantity, $assigned_to, $assigned_area ?: null, $appropriation ?: null,
                            $assigned_date, $status, $notes ?: null, (int)$_SESSION['user_id'], $id
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO assign_items
                            (item_type, item_ref_id, quantity, assigned_to, assigned_area, appropriation, assigned_date, status, notes, user_id)
                            VALUES (?,?,?,?,?,?,?,?,?,?)");
                        $stmt->execute([
                            $item_type, $item_ref_id, $quantity, $assigned_to, $assigned_area ?: null, $appropriation ?: null,
                            $assigned_date, $status, $notes ?: null, (int)$_SESSION['user_id']
                        ]);
                    }

                    // Apply the new assignment's stock effect.
                    applyAssignmentEffects($pdo, $item_type, $item_ref_id, $status, $quantity, 1);

                    // Source of truth for these fields is assignment for equipment.
                    if ($item_type === 'equipment') {
                        $sync = $pdo->prepare("UPDATE equipment SET purok_area = ?, appropriation = ?, person_incharge = ? WHERE id = ?");
                        $sync->execute([$assigned_area !== '' ? $assigned_area : null, $appropriation !== '' ? $appropriation : null, $assigned_to, $item_ref_id]);
                    }

                    $pdo->commit();

                    require_once __DIR__ . '/../includes/activity-log.php';
                    $entityId = $row ? $id : (int)$pdo->lastInsertId();
                    log_activity($pdo, (int)$_SESSION['user_id'], $row ? 'assign_item.update' : 'assign_item.create', 'assign_item', $entityId, [
                        'item_type' => $item_type,
                        'item_ref_id' => $item_ref_id,
                        'assigned_to' => $assigned_to,
                        'assigned_area' => $assigned_area !== '' ? $assigned_area : null,
                        'appropriation' => $appropriation !== '' ? $appropriation : null,
                        'status' => $status,
                        'quantity' => $quantity,
                    ]);

                    $_SESSION['success_message'] = $row ? 'Assigned item updated.' : 'Item assigned successfully.';
                    header('Location: assign-items.php');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = 'Unable to save assignment. Please try again.';
                }
            }
        }
    }

    $row = array_merge($row ?? [], [
        'item_type' => $item_type,
        'item_ref_id' => $item_ref_id,
        'quantity' => $quantity,
        'assigned_to' => $assigned_to,
        'assigned_area' => $assigned_area,
        'appropriation' => $appropriation,
        'assigned_date' => $assigned_date,
        'status' => $status,
        'notes' => $notes,
    ]);
}

if (!$row) {
    $row = [
        'item_type' => 'supply',
        'item_ref_id' => 0,
        'quantity' => 1,
        'assigned_to' => '',
        'assigned_area' => '',
        'appropriation' => '',
        'assigned_date' => date('Y-m-d'),
        'status' => 'assigned',
        'notes' => '',
    ];
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><?= htmlspecialchars($page_title) ?></h6></div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Item Type *</label>
                    <select name="item_type" id="item_type" class="form-control" required>
                        <option value="supply" <?= ($row['item_type'] ?? '') === 'supply' ? 'selected' : '' ?>>Supply</option>
                        <option value="equipment" <?= ($row['item_type'] ?? '') === 'equipment' ? 'selected' : '' ?>>Equipment</option>
                        <option value="inventory" <?= ($row['item_type'] ?? '') === 'inventory' ? 'selected' : '' ?>>Inventory</option>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label>Item *</label>
                    <select name="item_ref_id" id="item_ref_id" class="form-control" required></select>
                    <small id="stock_hint" class="form-text text-muted"></small>
                </div>
                <div class="form-group col-md-4">
                    <label>Quantity *</label>
                    <input type="number" min="1" name="quantity" id="quantity" class="form-control" value="<?= (int)($row['quantity'] ?? 1) ?>" required>
                    <div id="qty_error" class="invalid-feedback" style="display:block;"></div>
                </div>
            </div>

            <!-- Stock info panel — shown after an item is selected -->
            <div id="stock_info_panel" class="alert mb-3" style="display:none;"></div>

            <div class="form-row">
            </div>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Assigned To *</label>
                    <input type="text" name="assigned_to" class="form-control" value="<?= htmlspecialchars((string)($row['assigned_to'] ?? '')) ?>" required>
                </div>
                <div class="form-group col-md-4">
                    <label>Purok/Area</label>
                    <input type="text" name="assigned_area" class="form-control" value="<?= htmlspecialchars((string)($row['assigned_area'] ?? '')) ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Appropriation</label>
                    <input type="text" name="appropriation" class="form-control" value="<?= htmlspecialchars((string)($row['appropriation'] ?? '')) ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Assigned Date *</label>
                    <input type="date" name="assigned_date" class="form-control" value="<?= htmlspecialchars((string)($row['assigned_date'] ?? '')) ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="assigned" <?= ($row['status'] ?? '') === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                        <option value="returned" <?= ($row['status'] ?? '') === 'returned' ? 'selected' : '' ?>>Returned</option>
                    </select>
                </div>
                <div class="form-group col-md-8">
                    <label>Notes</label>
                    <input type="text" name="notes" class="form-control" value="<?= htmlspecialchars((string)($row['notes'] ?? '')) ?>">
                </div>
            </div>
            <button type="submit" id="save_btn" class="btn btn-primary">Save</button>
            <a href="assign-items.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<script>
const supplyItems    = <?= json_encode($supplies,   JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const equipmentItems = <?= json_encode($equipment,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryItems = <?= json_encode($inventory,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const selectedRefId  = <?= (int)($row['item_ref_id'] ?? 0) ?>;

// Edit-mode: old saved values needed to calculate "how much stock is already
// held by this assignment record" so we don't double-count it when validating.
const editOldItemType = <?= json_encode((string)($id > 0 && isset($row['item_type'])  ? $row['item_type']  : '')) ?>;
const editOldRefId    = <?= $id > 0 ? (int)($row['item_ref_id'] ?? 0) : 0 ?>;
const editOldStatus   = <?= json_encode((string)($id > 0 && isset($row['status'])     ? $row['status']     : '')) ?>;
const editOldQty      = <?= $id > 0 ? (int)($row['quantity']    ?? 0) : 0 ?>;

function sourceForType(type) {
    if (type === 'supply')    return supplyItems;
    if (type === 'equipment') return equipmentItems;
    return inventoryItems;
}

function itemLabel(type, item) {
    if (type === 'supply') {
        const code = item.item_code ? item.item_code + ' - ' : '';
        const details = item.description ? ' - ' + item.description : '';
        return code + item.name + details + ' (stock: ' + item.current_stock + ')';
    }
    if (type === 'equipment') {
        const code = item.item_code ? item.item_code + ' - ' : '';
        const label = item.serial_number
            ? (item.name + ' (' + item.serial_number + ')')
            : item.name;
        return code + label + ' - ' + item.status;
    }
    return 'TX #' + item.id + ' - ' + item.item_type + ' / ' + item.transaction_type + ' / qty ' + item.quantity;
}

/**
 * For a supply + "assigned" combo, return how many units are actually
 * available to assign right now.
 * In edit mode: if we are editing the same supply that was previously
 * assigned, the stock was already decremented by editOldQty, so we add
 * it back to get the true available amount.
 */
function getAvailableStock(refId) {
    const item = supplyItems.find(function(i) { return parseInt(i.id, 10) === refId; });
    if (!item) return 0;
    let available = parseInt(item.current_stock, 10);
    if (editOldItemType === 'supply' && editOldRefId === refId && editOldStatus === 'assigned') {
        available += editOldQty;
    }
    return available;
}

function validateQty() {
    const type   = document.getElementById('item_type').value;
    const refId  = parseInt(document.getElementById('item_ref_id').value || '0', 10);
    const status = document.querySelector('select[name="status"]').value;
    const qtyEl  = document.getElementById('quantity');
    const errEl  = document.getElementById('qty_error');
    const saveBtn = document.getElementById('save_btn');

    // Reset
    qtyEl.classList.remove('is-invalid');
    if (errEl) errEl.textContent = '';
    if (saveBtn) saveBtn.disabled = false;

    if (type === 'supply' && status === 'assigned' && refId > 0) {
        const available = getAvailableStock(refId);
        const entered   = parseInt(qtyEl.value || '0', 10);

        // Enforce the html max so the browser spinner won't go beyond stock
        qtyEl.max = available;

        if (available <= 0) {
            qtyEl.classList.add('is-invalid');
            if (errEl) errEl.textContent = 'No stock available for this item.';
            if (saveBtn) saveBtn.disabled = true;
        } else if (entered > available) {
            qtyEl.classList.add('is-invalid');
            if (errEl) errEl.textContent = 'Quantity exceeds available stock (' + available + '). Maximum allowed: ' + available + '.';
            if (saveBtn) saveBtn.disabled = true;
        } else if (entered < 1) {
            qtyEl.classList.add('is-invalid');
            if (errEl) errEl.textContent = 'Quantity must be at least 1.';
            if (saveBtn) saveBtn.disabled = true;
        }
    } else {
        // No supply-assign restriction — remove html max
        qtyEl.removeAttribute('max');
    }
}

function updateStatusOptions() {
    const type = document.getElementById('item_type').value;
    const statusSelect = document.querySelector('select[name="status"]');
    const returnedOption = statusSelect.querySelector('option[value="returned"]');
    if (!returnedOption) return;

    returnedOption.disabled = type === 'supply';
    if (type === 'supply' && statusSelect.value === 'returned') {
        statusSelect.value = 'assigned';
    }
}

function updateStockHint() {
    const type   = document.getElementById('item_type').value;
    const refId  = parseInt(document.getElementById('item_ref_id').value || '0', 10);
    const hint   = document.getElementById('stock_hint');
    const panel  = document.getElementById('stock_info_panel');

    // Reset
    if (hint)  { hint.textContent = ''; }
    if (panel) { panel.style.display = 'none'; panel.innerHTML = ''; panel.className = 'alert mb-3'; }

    if (type === 'supply' && refId > 0) {
        const item      = supplyItems.find(function(i) { return parseInt(i.id, 10) === refId; });
        const available = getAvailableStock(refId);
        const rawStock  = item ? parseInt(item.current_stock, 10) : 0;

        // Determine color
        let alertClass, icon, statusLabel;
        if (rawStock <= 0) {
            alertClass  = 'alert-danger';
            icon        = '&#9888;';
            statusLabel = '<strong>Stock Out</strong>';
        } else if (available <= 0) {
            alertClass  = 'alert-warning';
            icon        = '&#9888;';
            statusLabel = '<strong>Fully Assigned</strong>';
        } else {
            alertClass  = 'alert-info';
            icon        = '&#8505;';
            statusLabel = '<strong>In Stock</strong>';
        }

        if (panel) {
            panel.className   = 'alert mb-3 ' + alertClass;
            panel.style.display = '';
            panel.innerHTML   =
                '<span style="font-size:1.1em;">' + icon + '</span> ' +
                '<strong>' + (item ? item.name : 'Item') + '</strong> &mdash; ' + statusLabel +
                '<span class="mx-3">|</span>' +
                'Current Stock: <strong>' + rawStock + '</strong>' +
                (editOldItemType === 'supply' && editOldRefId === refId && editOldStatus === 'assigned' && editOldQty > 0
                    ? '&nbsp;<small class="text-muted">(+' + editOldQty + ' held by this record)</small>'
                    : '') +
                '<span class="mx-3">|</span>' +
                'Available to Assign: <strong>' + available + '</strong>';
        }

        // Also keep the small hint
        if (hint) {
            hint.textContent = 'Available to assign: ' + available;
            hint.className   = available <= 0 ? 'form-text text-danger font-weight-bold' : 'form-text text-muted';
        }

    } else if (type === 'equipment' && refId > 0) {
        const item = equipmentItems.find(function(i) { return parseInt(i.id, 10) === refId; });
        if (item && panel) {
            const isServiceable = item.status === 'servicable';
            panel.className   = 'alert mb-3 ' + (isServiceable ? 'alert-success' : 'alert-warning');
            panel.style.display = '';
            panel.innerHTML   =
                '<span style="font-size:1.1em;">&#8505;</span> ' +
                '<strong>' + item.name + (item.serial_number ? ' (' + item.serial_number + ')' : '') + '</strong>' +
                '<span class="mx-3">|</span>' +
                'Status: <strong>' + item.status + '</strong>';
        }
        if (hint) {
            hint.textContent = item ? 'Current status: ' + item.status : '';
            hint.className   = 'form-text text-muted';
        }
    }
}

function renderItems() {
    const type   = document.getElementById('item_type').value;
    const select = document.getElementById('item_ref_id');
    const qty    = document.getElementById('quantity');
    const source = sourceForType(type);

    select.innerHTML = '';
    const first = document.createElement('option');
    first.value = '';
    first.textContent = '-- Select item --';
    select.appendChild(first);

    source.forEach(function(item) {
        const opt = document.createElement('option');
        opt.value = item.id;
        opt.textContent = itemLabel(type, item);
        if (parseInt(item.id, 10) === selectedRefId) {
            opt.selected = true;
        }
        select.appendChild(opt);
    });

    if (type === 'equipment') {
        qty.value    = 1;
        qty.readOnly = true;
    } else {
        qty.readOnly = false;
    }
}

// Block form submit if qty is still invalid (defence-in-depth)
document.querySelector('form').addEventListener('submit', function(e) {
    const saveBtn = document.getElementById('save_btn');
    if (saveBtn && saveBtn.disabled) {
        e.preventDefault();
    }
});

document.getElementById('item_type').addEventListener('change', function() {
    document.getElementById('item_ref_id').value = '';
    updateStatusOptions();
    renderItems();
    updateStockHint();
    validateQty();
});
document.getElementById('item_ref_id').addEventListener('change', function() {
    updateStockHint();
    validateQty();
});
document.getElementById('quantity').addEventListener('input', validateQty);
document.querySelector('select[name="status"]').addEventListener('change', function() {
    updateStockHint();
    validateQty();
});

updateStatusOptions();
renderItems();
updateStockHint();
validateQty();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
