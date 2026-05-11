<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';

if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$page_title = 'Users';
$current_page = 'users';
$base_url = '../';

$users = $pdo->query("SELECT id, username, email, first_name, last_name, role, created_at FROM users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary">Users</h6>
        <a href="user-form.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add User</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Created</th>
                        <th width="100">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td><span class="badge badge-<?= $u['role'] === 'admin' ? 'danger' : 'secondary' ?>"><?= htmlspecialchars($u['role']) ?></span></td>
                            <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                            <td class="table-actions">
                                <a href="user-form.php?id=<?= (int)$u['id'] ?>" class="btn btn-sm btn-info btn-icon-action" title="Edit" aria-label="Edit user">
                                    <i class="fas fa-pen"></i>
                                </a>
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                    <a href="user-delete.php?id=<?= (int)$u['id'] ?>" class="btn btn-sm btn-danger btn-icon-action" data-confirm="Move this user to trash?" title="Move to trash" aria-label="Move user to trash">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
