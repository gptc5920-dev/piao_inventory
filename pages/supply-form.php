<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$supply = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM supplies WHERE id = ?");
    $stmt->execute([$id]);
    $supply = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$supply) { header('Location: supplies.php'); exit; }
}

$page_title = $supply ? 'Edit Supply' : 'Add Supply';
$current_page = 'supplies';
$base_url = '../';
$fixed_category = 'Supplies';
$current_stock_display = $supply ? osaeits_supply_current_stock($pdo, $id) : 0;
$item_code_display = $supply ? osaeits_ensure_item_identifier($pdo, 'supply', $id) : 'Auto-generated';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = osaeits_clean_inventory_text($_POST['name'] ?? '');
    $description = osaeits_clean_inventory_text($_POST['description'] ?? '');
    $category = $fixed_category;
    $unit = osaeits_clean_inventory_text($_POST['unit'] ?? '');
    $purchase_quantity = max(0, (int)($_POST['purchase_quantity'] ?? 0));
    $minimum_stock = max(0, (int)($_POST['minimum_stock'] ?? 0));
    $unit_price = (float)($_POST['unit_price'] ?? 0);
    $supplier = osaeits_clean_inventory_text($_POST['supplier'] ?? '');
    if (!$name || !$unit) {
        $error = 'Name and unit are required.';
    } else {
        $duplicate = osaeits_find_supply_duplicate($pdo, $name, $description, $unit, $id);
        if ($duplicate) {
            $duplicateCode = osaeits_ensure_item_identifier($pdo, 'supply', (int)$duplicate['id']);
            $duplicateLabel = trim($duplicateCode . ' - ' . osaeits_supply_display_name($duplicate), ' -');
            $error = $supply
                ? "Warning: {$duplicateLabel} already exists. Use a unique item name, details, or unit."
                : "Warning: {$duplicateLabel} already exists. No supply record was replaced. Use Record purchase > Existing item to restock it.";
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();
                $targetId = $id;
                $createdNewSupply = false;
                $purchaseTxId = 0;

                if ($supply) {
                    $stmt = $pdo->prepare("UPDATE supplies SET name=?, description=?, category=?, unit=?, minimum_stock=?, unit_price=?, supplier=? WHERE id=?");
                    $stmt->execute([$name, $description, $category, $unit, $minimum_stock, $unit_price, $supplier, $id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO supplies (name, description, category, unit, current_stock, minimum_stock, unit_price, supplier) VALUES (?,?,?,?,?,?,?,?)");
                    $stmt->execute([$name, $description, $category, $unit, 0, $minimum_stock, $unit_price, $supplier]);
                    $targetId = (int)$pdo->lastInsertId();
                    $createdNewSupply = true;
                }

                if (!$supply && $purchase_quantity > 0) {
                    $reference = osaeits_generate_transaction_reference($pdo, 'supply', 'purchase');
                    $total_amount = $purchase_quantity * $unit_price;
                    $purchaseNotes = 'Initial purchase from supply form' . ($supplier !== '' ? "\nSupplier: {$supplier}" : '');
                    $stmt = $pdo->prepare("INSERT INTO transactions (item_type, item_id, transaction_type, quantity, unit_price, total_amount, reference_number, notes, user_id) VALUES (?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([
                        'supply',
                        $targetId,
                        'purchase',
                        $purchase_quantity,
                        $unit_price,
                        $total_amount,
                        $reference,
                        $purchaseNotes,
                        (int)$_SESSION['user_id']
                    ]);
                    $purchaseTxId = (int)$pdo->lastInsertId();
                    osaeits_apply_transaction_effects($pdo, [
                        'item_type' => 'supply',
                        'item_id' => $targetId,
                        'transaction_type' => 'purchase',
                        'quantity' => $purchase_quantity,
                    ]);
                }

                $itemCode = osaeits_ensure_item_identifier($pdo, 'supply', $targetId);
                $pdo->commit();

                require_once __DIR__ . '/../includes/activity-log.php';
                $actor = (int)$_SESSION['user_id'];
                if ($supply) {
                    log_activity($pdo, $actor, 'supply.update', 'supply', $targetId, ['name' => $name, 'item_code' => $itemCode]);
                } elseif ($createdNewSupply) {
                    log_activity($pdo, $actor, 'supply.create', 'supply', $targetId, ['name' => $name, 'item_code' => $itemCode]);
                }

                if ($purchaseTxId > 0) {
                    log_activity($pdo, $actor, 'transaction.create', 'transaction', $purchaseTxId, [
                        'item_type' => 'supply',
                        'item_id' => $targetId,
                        'transaction_type' => 'purchase',
                        'quantity' => $purchase_quantity,
                        'unit_price' => $unit_price,
                        'total_amount' => $purchase_quantity * $unit_price,
                    ]);
                }

                $_SESSION['success_message'] = $supply
                    ? 'Supply updated.'
                    : 'Supply added.';
                header('Location: ' . ($purchaseTxId > 0 ? ('inventory.php?' . http_build_query(['item_type' => 'supply', 'item_id' => $targetId])) : 'supplies.php'));
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Unable to save supply. Please try again.';
            }
        }
    }
    $supply = array_merge(['name'=>'','description'=>'','category'=>$fixed_category,'unit'=>'','current_stock'=>0,'purchase_quantity'=>0,'minimum_stock'=>0,'unit_price'=>0,'supplier'=>''], $_POST);
    $supply['category'] = $fixed_category;
} elseif (!$supply) {
    $supply = ['name'=>'','description'=>'','category'=>$fixed_category,'unit'=>'','current_stock'=>0,'purchase_quantity'=>0,'minimum_stock'=>0,'unit_price'=>0,'supplier'=>''];
} else {
    $supply['category'] = $fixed_category;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-wrap justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary"><?= htmlspecialchars($page_title) ?></h6>
        <?php if ($id > 0): ?>
            <a href="inventory.php?<?= http_build_query(['item_type' => 'supply', 'item_id' => $id]) ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-exchange-alt"></i> Transaction history
            </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post">
            <div class="form-group">
                <label>Item Code</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($item_code_display) ?>" readonly>
            </div>
            <div class="form-group">
                <label>Name *</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($supply['name']) ?>" required>
            </div>
            <div class="form-group">
                <label>Details / Size</label>
                <input type="text" name="description" class="form-control" placeholder="e.g. A4, long, letter" value="<?= htmlspecialchars($supply['description'] ?? '') ?>">
            </div>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Category</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($fixed_category) ?>" readonly>
                    <input type="hidden" name="category" value="<?= htmlspecialchars($fixed_category) ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Unit *</label>
                    <input type="text" name="unit" class="form-control" placeholder="e.g. piece, box" value="<?= htmlspecialchars($supply['unit']) ?>" required>
                </div>
                <div class="form-group col-md-4">
                    <label>Supplier</label>
                    <input type="text" name="supplier" class="form-control" value="<?= htmlspecialchars($supply['supplier'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <?php if ($id > 0): ?>
                        <label>Current Stock</label>
                        <input type="number" class="form-control" value="<?= (int)$current_stock_display ?>" readonly>
                    <?php else: ?>
                        <label>Purchase Quantity</label>
                        <input type="number" name="purchase_quantity" class="form-control" min="0" value="<?= (int)($supply['purchase_quantity'] ?? 0) ?>">
                    <?php endif; ?>
                </div>
                <div class="form-group col-md-4">
                    <label>Minimum Stock</label>
                    <input type="number" name="minimum_stock" class="form-control" min="0" value="<?= (int)($supply['minimum_stock'] ?? 0) ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Unit Price (₱)</label>
                    <input type="number" name="unit_price" class="form-control" step="0.01" min="0" value="<?= htmlspecialchars($supply['unit_price'] ?? '0') ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Save</button>
            <a href="supplies.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
