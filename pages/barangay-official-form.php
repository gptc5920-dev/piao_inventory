<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$official = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM barangay_officials WHERE id = ?");
    $stmt->execute([$id]);
    $official = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$official) {
        header('Location: barangay-officials.php');
        exit;
    }
}

$page_title = $official ? 'Edit Barangay Official' : 'Add Barangay Official';
$current_page = 'barangay_officials';
$base_url = '../';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    $position_title = trim($_POST['position_title'] ?? '');
    $committee = trim($_POST['committee'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $term_start = trim($_POST['term_start'] ?? '');
    $term_end = trim($_POST['term_end'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $notes = trim($_POST['notes'] ?? '');

    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }
    if ($term_start === '') {
        $term_start = null;
    }
    if ($term_end === '') {
        $term_end = null;
    }

    if ($first_name === '' || $last_name === '' || $position_title === '') {
        $error = 'First name, last name, and position are required.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($term_start !== null && $term_end !== null && $term_start > $term_end) {
        $error = 'Term end must be on or after term start.';
    } else {
        if ($official) {
            $stmt = $pdo->prepare(
                "UPDATE barangay_officials
                 SET first_name=?, middle_name=?, last_name=?, suffix=?, position_title=?, committee=?, contact_number=?, email=?, term_start=?, term_end=?, status=?, notes=?
                 WHERE id=?"
            );
            $stmt->execute([
                $first_name, $middle_name ?: null, $last_name, $suffix ?: null, $position_title, $committee ?: null,
                $contact_number ?: null, $email ?: null, $term_start, $term_end, $status, $notes ?: null, $id
            ]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO barangay_officials
                 (first_name, middle_name, last_name, suffix, position_title, committee, contact_number, email, term_start, term_end, status, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $first_name, $middle_name ?: null, $last_name, $suffix ?: null, $position_title, $committee ?: null,
                $contact_number ?: null, $email ?: null, $term_start, $term_end, $status, $notes ?: null
            ]);
        }

        require_once __DIR__ . '/../includes/activity-log.php';
        $actor = (int)$_SESSION['user_id'];
        if ($official) {
            log_activity($pdo, $actor, 'official.update', 'barangay_official', $id, [
                'name' => $first_name . ' ' . $last_name,
                'position_title' => $position_title,
                'status' => $status,
            ]);
        } else {
            $newId = (int)$pdo->lastInsertId();
            log_activity($pdo, $actor, 'official.create', 'barangay_official', $newId, [
                'name' => $first_name . ' ' . $last_name,
                'position_title' => $position_title,
                'status' => $status,
            ]);
        }

        $_SESSION['success_message'] = $official ? 'Barangay official updated.' : 'Barangay official added.';
        header('Location: barangay-officials.php');
        exit;
    }
}

if ($error && $_POST) {
    $official = array_merge($official ?? [], $_POST);
}
if (!$official) {
    $official = [
        'first_name' => '',
        'middle_name' => '',
        'last_name' => '',
        'suffix' => '',
        'position_title' => '',
        'committee' => '',
        'contact_number' => '',
        'email' => '',
        'term_start' => '',
        'term_end' => '',
        'status' => 'active',
        'notes' => '',
    ];
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
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post">
            <div class="form-row">
                <div class="form-group col-md-3">
                    <label>First Name *</label>
                    <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($official['first_name'] ?? '') ?>" required>
                </div>
                <div class="form-group col-md-3">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($official['middle_name'] ?? '') ?>">
                </div>
                <div class="form-group col-md-3">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($official['last_name'] ?? '') ?>" required>
                </div>
                <div class="form-group col-md-3">
                    <label>Suffix</label>
                    <input type="text" name="suffix" class="form-control" value="<?= htmlspecialchars($official['suffix'] ?? '') ?>" placeholder="e.g. Jr., III">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Position *</label>
                    <input type="text" name="position_title" class="form-control" value="<?= htmlspecialchars($official['position_title'] ?? '') ?>" required>
                </div>
                <div class="form-group col-md-4">
                    <label>Committee</label>
                    <input type="text" name="committee" class="form-control" value="<?= htmlspecialchars($official['committee'] ?? '') ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="active" <?= ($official['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($official['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Contact Number</label>
                    <input type="text" name="contact_number" class="form-control" value="<?= htmlspecialchars($official['contact_number'] ?? '') ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($official['email'] ?? '') ?>">
                </div>
                <div class="form-group col-md-2">
                    <label>Term Start</label>
                    <input type="date" name="term_start" class="form-control" value="<?= htmlspecialchars($official['term_start'] ?? '') ?>">
                </div>
                <div class="form-group col-md-2">
                    <label>Term End</label>
                    <input type="date" name="term_end" class="form-control" value="<?= htmlspecialchars($official['term_end'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="3" class="form-control"><?= htmlspecialchars($official['notes'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Save</button>
            <a href="barangay-officials.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
