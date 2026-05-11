<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$item = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM equipment WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) { header('Location: equipment.php'); exit; }
}

$page_title = $item ? 'Edit Equipment' : 'Add Equipment';
$current_page = 'equipment';
$base_url = '../';
$fixed_category = 'Equipment';
$item_code_display = $item ? osaeits_ensure_item_identifier($pdo, 'equipment', $id) : 'Auto-generated';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = osaeits_clean_inventory_text($_POST['name'] ?? '');
    $description = osaeits_clean_inventory_text($_POST['description'] ?? '');
    $category = $fixed_category;
    $serial_number = osaeits_clean_inventory_text($_POST['serial_number'] ?? '');
    $model = osaeits_clean_inventory_text($_POST['model'] ?? '');
    $brand = osaeits_clean_inventory_text($_POST['brand'] ?? '');
    $status = osaeits_normalize_equipment_status($_POST['status'] ?? 'servicable');
    $location = osaeits_clean_inventory_text($_POST['location'] ?? '');
    $purchase_date = trim($_POST['purchase_date'] ?? '') ?: null;
    $warranty_expiry = trim($_POST['warranty_expiry'] ?? '') ?: null;
    $purchase_price = (float)($_POST['purchase_price'] ?? 0);
    if (!in_array($status, ['servicable', 'unservicable'], true)) $status = 'servicable';
    if (!$name) {
        $error = 'Name is required.';
    } else {
        $duplicate = osaeits_find_equipment_duplicate($pdo, $name, $serial_number, $brand, $model, $id);
        if ($duplicate) {
            $error = 'Equipment with the same serial number already exists.';
        }

        if ($error === '') {
            if ($item) {
                $stmt = $pdo->prepare("UPDATE equipment SET name=?, description=?, category=?, serial_number=?, model=?, brand=?, status=?, location=?, purchase_date=?, warranty_expiry=?, purchase_price=? WHERE id=?");
                $stmt->execute([$name, $description, $category, $serial_number ?: null, $model ?: null, $brand ?: null, $status, $location ?: null, $purchase_date, $warranty_expiry, $purchase_price, $id]);
                $targetId = $id;
            } else {
                $stmt = $pdo->prepare("INSERT INTO equipment (name, description, category, serial_number, model, brand, status, location, purchase_date, warranty_expiry, purchase_price) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$name, $description, $category, $serial_number ?: null, $model ?: null, $brand ?: null, $status, $location ?: null, $purchase_date, $warranty_expiry, $purchase_price]);
                $targetId = (int)$pdo->lastInsertId();
            }
            $itemCode = osaeits_ensure_item_identifier($pdo, 'equipment', $targetId);
            require_once __DIR__ . '/../includes/activity-log.php';
            $actor = (int)$_SESSION['user_id'];
            if ($item) {
                log_activity($pdo, $actor, 'equipment.update', 'equipment', $targetId, ['name' => $name, 'item_code' => $itemCode, 'status' => $status]);
            } else {
                log_activity($pdo, $actor, 'equipment.create', 'equipment', $targetId, ['name' => $name, 'item_code' => $itemCode, 'status' => $status]);
            }
            $_SESSION['success_message'] = $item ? 'Equipment updated.' : 'Equipment added.';
            header('Location: equipment.php');
            exit;
        }
    }
}

if ($error && $_POST) {
    $item = array_merge(['name'=>'','description'=>'','category'=>$fixed_category,'serial_number'=>'','model'=>'','brand'=>'','status'=>'servicable','location'=>'','purok_area'=>'','appropriation'=>'','person_incharge'=>'','purchase_date'=>'','warranty_expiry'=>'','purchase_price'=>0], $_POST);
    $item['category'] = $fixed_category;
    $item['status'] = osaeits_normalize_equipment_status((string)($item['status'] ?? 'servicable'));
}
if (!$item) {
    $item = ['name'=>'','description'=>'','category'=>$fixed_category,'serial_number'=>'','model'=>'','brand'=>'','status'=>'servicable','location'=>'','purok_area'=>'','appropriation'=>'','person_incharge'=>'','purchase_date'=>'','warranty_expiry'=>'','purchase_price'=>0];
} else {
    $item['category'] = $fixed_category;
    $item['status'] = osaeits_normalize_equipment_status((string)($item['status'] ?? 'servicable'));
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-wrap justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary"><?= htmlspecialchars($page_title) ?></h6>
        <?php if ($id > 0): ?>
            <a href="inventory.php?<?= http_build_query(['item_type' => 'equipment', 'item_id' => $id]) ?>" class="btn btn-sm btn-outline-secondary">
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
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($item['name']) ?>" required>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($item['description'] ?? '') ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Category</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($fixed_category) ?>" readonly>
                    <input type="hidden" name="category" value="<?= htmlspecialchars($fixed_category) ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Serial Number</label>
                    <input type="text" name="serial_number" class="form-control" value="<?= htmlspecialchars($item['serial_number'] ?? '') ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="servicable" <?= ($item['status'] ?? '') === 'servicable' ? 'selected' : '' ?>>Servicable</option>
                        <option value="unservicable" <?= ($item['status'] ?? '') === 'unservicable' ? 'selected' : '' ?>>Unservicable</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Brand</label>
                    <input type="text" name="brand" class="form-control" value="<?= htmlspecialchars($item['brand'] ?? '') ?>">
                </div>
                <div class="form-group col-md-6">
                    <label>Model</label>
                    <input type="text" name="model" class="form-control" value="<?= htmlspecialchars($item['model'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Location</label>
                <input type="text" name="location" class="form-control" value="<?= htmlspecialchars($item['location'] ?? '') ?>">
            </div>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control" value="<?= htmlspecialchars($item['purchase_date'] ?? '') ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Warranty Expiry</label>
                    <input type="date" name="warranty_expiry" class="form-control" value="<?= htmlspecialchars($item['warranty_expiry'] ?? '') ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Purchase Price (₱)</label>
                    <input type="number" name="purchase_price" class="form-control" step="0.01" min="0" value="<?= htmlspecialchars($item['purchase_price'] ?? '0') ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Save</button>
            <a href="equipment.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
