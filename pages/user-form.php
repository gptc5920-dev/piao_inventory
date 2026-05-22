<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT id, username, email, first_name, last_name, role FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { header('Location: users.php'); exit; }
}

$page_title = $user ? 'Edit User' : 'Add User';
$current_page = 'users';
$base_url = '../';
$permissionGroups = osaeits_permission_groups();
$allPermissionKeys = osaeits_all_permission_keys();
$defaultUserAccess = osaeits_default_nonadmin_permissions();
$selectedAccess = $user
    ? osaeits_load_user_permissions($pdo, (int)$user['id'], (string)$user['role'])
    : $defaultUserAccess;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';
    if (!in_array($role, ['admin', 'user'])) $role = 'user';
    $postedAccess = osaeits_filter_permission_keys((array)($_POST['access_keys'] ?? []));
    $selectedAccess = $role === 'admin' ? $allPermissionKeys : $postedAccess;
    if (!$first_name || !$last_name || !$username || !$email) {
        $error = 'Name, username and email are required.';
    } elseif (!$user && strlen($password) < 6) {
        $error = 'Password must be at least 6 characters for new user.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ?");
        $stmt->execute([$email, $username, $id]);
        if ($stmt->fetch()) {
            $error = 'Email or username already in use.';
        } else {
            if ($user) {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET first_name=?, last_name=?, username=?, email=?, password=?, role=? WHERE id=?");
                    $stmt->execute([$first_name, $last_name, $username, $email, $hash, $role, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET first_name=?, last_name=?, username=?, email=?, role=? WHERE id=?");
                    $stmt->execute([$first_name, $last_name, $username, $email, $role, $id]);
                }
                $targetUserId = $id;
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, username, email, password, role) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$first_name, $last_name, $username, $email, $hash, $role]);
                $targetUserId = (int)$pdo->lastInsertId();
            }
            osaeits_save_user_permissions($pdo, $targetUserId, $selectedAccess);
            if ($targetUserId === (int)$_SESSION['user_id']) {
                osaeits_refresh_session_access($pdo);
            }
            require_once __DIR__ . '/../includes/activity-log.php';
            $actor = (int)$_SESSION['user_id'];
            if ($user) {
                log_activity($pdo, $actor, 'user.update', 'user', $id, [
                    'username' => $username,
                    'email' => $email,
                    'role' => $role,
                    'access' => $selectedAccess,
                ]);
            } else {
                log_activity($pdo, $actor, 'user.create', 'user', $targetUserId, [
                    'username' => $username,
                    'email' => $email,
                    'role' => $role,
                    'access' => $selectedAccess,
                ]);
            }
            $_SESSION['success_message'] = $user ? 'User updated.' : 'User added.';
            header('Location: users.php');
            exit;
        }
    }
}

if ($error && $_POST) {
    $user = array_merge($user ?? [], $_POST);
}
if (!$user) {
    $user = ['first_name'=>'','last_name'=>'','username'=>'','email'=>'','role'=>'user'];
}
if (($user['role'] ?? '') === 'admin') {
    $selectedAccess = $allPermissionKeys;
}
$selectedAccessLookup = array_fill_keys($selectedAccess, true);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><?= $page_title ?></h6></div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>First name *</label>
                    <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($user['first_name']) ?>" required>
                </div>
                <div class="form-group col-md-6">
                    <label>Last name *</label>
                    <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($user['last_name']) ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label>Username *</label>
                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
            </div>
            <div class="form-group">
                <label>Email *</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
            </div>
            <div class="form-group">
                <label>Password <?= $user && isset($user['id']) ? '(leave blank to keep)' : '*' ?></label>
                <input type="password" name="password" class="form-control" <?= ($user && isset($user['id'])) ? '' : 'required' ?>>
            </div>
            <div class="form-row align-items-end">
                <div class="form-group col-md-6">
                    <label>Role</label>
                    <select name="role" id="user_role" class="form-control">
                        <option value="user" <?= ($user['role'] ?? '') === 'user' ? 'selected' : '' ?>>Non-admin</option>
                        <option value="admin" <?= ($user['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <div class="form-group col-md-6">
                    <label>Access</label>
                    <button type="button" class="btn btn-outline-primary btn-block" data-toggle="modal" data-target="#accessModal">
                        <i class="fas fa-user-shield mr-1"></i> Manage Role Access
                    </button>
                </div>
            </div>

            <div class="modal fade" id="accessModal" tabindex="-1" role="dialog" aria-labelledby="accessModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-scrollable" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="accessModalLabel">Configure Access</h5>
                                <p class="small text-muted mb-0">Choose the sections this account can open.</p>
                            </div>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-info py-2 small" id="adminAccessNote">
                                Admin accounts automatically receive all access.
                            </div>
                            <?php foreach ($permissionGroups as $groupName => $permissions): ?>
                                <h6 class="font-weight-bold mt-3"><?= htmlspecialchars($groupName) ?></h6>
                                <?php foreach ($permissions as $key => $definition): ?>
                                    <div class="custom-control custom-checkbox mb-2">
                                        <input type="checkbox"
                                               class="custom-control-input access-checkbox"
                                               id="access_<?= htmlspecialchars($key) ?>"
                                               name="access_keys[]"
                                               value="<?= htmlspecialchars($key) ?>"
                                               <?= isset($selectedAccessLookup[$key]) ? 'checked' : '' ?>>
                                        <label class="custom-control-label" for="access_<?= htmlspecialchars($key) ?>">
                                            <?= htmlspecialchars($definition['label']) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" data-dismiss="modal">Save Access</button>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save</button>
            <a href="users.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var roleSelect = document.getElementById('user_role');
    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.access-checkbox'));
    var note = document.getElementById('adminAccessNote');

    function syncAccessForRole() {
        var isAdmin = roleSelect && roleSelect.value === 'admin';
        checkboxes.forEach(function (checkbox) {
            checkbox.disabled = isAdmin;
            if (isAdmin) {
                checkbox.checked = true;
            }
        });
        if (!note) {
            return;
        }
        if (isAdmin) {
            note.classList.remove('d-none');
        } else {
            note.classList.add('d-none');
        }
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', syncAccessForRole);
    }
    syncAccessForRole();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
