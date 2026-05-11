<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';

if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$page_title = 'Barangay Officials';
$current_page = 'barangay_officials';
$base_url = '../';

$officials = $pdo->query(
    "SELECT id, first_name, middle_name, last_name, suffix, position_title, committee, contact_number, email, term_start, term_end, status
     FROM barangay_officials
     ORDER BY status = 'active' DESC, position_title ASC, last_name ASC, first_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

function official_full_name(array $row): string
{
    $parts = [
        trim((string)($row['first_name'] ?? '')),
        trim((string)($row['middle_name'] ?? '')),
        trim((string)($row['last_name'] ?? '')),
    ];
    $name = trim(implode(' ', array_filter($parts, static fn($v) => $v !== '')));
    $suffix = trim((string)($row['suffix'] ?? ''));
    if ($suffix !== '') {
        $name .= ', ' . $suffix;
    }
    return $name;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<?php if (!empty($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <?= htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
    </div>
<?php endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary">Barangay Officials</h6>
        <a href="barangay-official-form.php" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> Add Official
        </a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr>
                        <th>Name</th>
                        <th>Position</th>
                        <th>Committee</th>
                        <th>Contact</th>
                        <th>Term</th>
                        <th>Status</th>
                        <th width="110">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($officials)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">No barangay officials yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($officials as $o): ?>
                            <tr>
                                <td><?= htmlspecialchars(official_full_name($o)) ?></td>
                                <td><?= htmlspecialchars($o['position_title']) ?></td>
                                <td><?= htmlspecialchars($o['committee'] ?: '-') ?></td>
                                <td>
                                    <div><?= htmlspecialchars($o['contact_number'] ?: '-') ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($o['email'] ?: '-') ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($o['term_start']) || !empty($o['term_end'])): ?>
                                        <?= !empty($o['term_start']) ? htmlspecialchars(date('M j, Y', strtotime($o['term_start']))) : '-' ?>
                                        to
                                        <?= !empty($o['term_end']) ? htmlspecialchars(date('M j, Y', strtotime($o['term_end']))) : '-' ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $o['status'] === 'active' ? 'success' : 'secondary' ?>">
                                        <?= htmlspecialchars(ucfirst($o['status'])) ?>
                                    </span>
                                </td>
                                <td class="table-actions">
                                    <a href="barangay-official-form.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-info btn-icon-action" title="Edit" aria-label="Edit official">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <a href="barangay-official-delete.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-danger btn-icon-action" data-confirm="Move this official to trash?" title="Move to trash" aria-label="Move official to trash">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
