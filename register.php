<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: pages/dashboard.php');
    exit;
}

$error = '';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (!$first_name || !$last_name || !$username || !$email || !$password) {
        $errors[] = 'All fields are required.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$email, $username]);
        if ($stmt->fetch()) {
            $errors[] = 'Email or username already registered.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, first_name, last_name, role) VALUES (?, ?, ?, ?, ?, 'user')");
            $stmt->execute([$username, $email, $hash, $first_name, $last_name]);
            $newUserId = (int)$pdo->lastInsertId();
            require_once __DIR__ . '/includes/activity-log.php';
            log_activity($pdo, $newUserId, 'auth.register', 'user', $newUserId, [
                'username' => $username,
                'email' => $email,
            ]);
            $_SESSION['success_message'] = 'Registration successful. You can now login.';
            header('Location: login.php');
            exit;
        }
    }
}

$page_title = 'Register';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - OSAEITS</title>
    <link rel="icon" type="image/png" href="assets/images/piao_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
    <style>
        .login-page { background: var(--osaeits-bg, #F0E8E4); min-height: 100vh; padding: 2rem 0; }
        .login-logo { width: 90px; height: 90px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; box-shadow: 0 4px 15px rgba(0,0,0,0.15); overflow: hidden; }
        .login-logo img { width: 92px; height: 92px; object-fit: contain; }
        .login-logo i { font-size: 2.2rem; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
        .login-title { color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,0.2); font-size: 1.35rem; font-weight: 600; margin-bottom: 0.25rem; }
        .login-subtitle { color: rgba(255,255,255,0.9); font-size: 0.95rem; text-shadow: 0 1px 2px rgba(0,0,0,0.15); margin-bottom: 2rem; }
        .login-card { background: #fff; border-radius: 1rem; box-shadow: 0 10px 40px rgba(0,0,0,0.08); border: none; overflow: hidden; max-width: 480px; margin: 0 auto; }
        .login-card .card-body { padding: 2rem; }
        .login-card h5 { color: #2d3748; font-weight: 600; margin-bottom: 1.5rem; }
        .login-card .form-group label { color: #4a5568; font-size: 0.9rem; font-weight: 500; margin-bottom: 0.35rem; }
        .login-card .form-control { border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.6rem 0.85rem; }
        .login-card .form-control:focus { border-color: var(--osaeits-btn, #8B5A3C); box-shadow: 0 0 0 3px rgba(139,90,60,0.15); }
        .login-btn { background: var(--osaeits-btn, #8B5A3C); border: none; color: #fff; font-weight: 600; padding: 0.65rem 1.5rem; border-radius: 0.5rem; box-shadow: 0 4px 12px rgba(139,90,60,0.3); width: 100%; }
        .login-btn:hover { background: var(--osaeits-btn-hover, #7a4f34); color: #fff; box-shadow: 0 5px 15px rgba(139,90,60,0.35); }
        .login-link { color: var(--osaeits-btn, #8B5A3C); font-size: 0.9rem; }
        .login-link:hover { color: #7a4f34; }
    </style>
</head>
<body class="login-page">
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100 py-4">
            <div class="col-11 col-sm-10 col-md-9 col-lg-6">
                <div class="text-center mb-3 mb-md-4">
                    <div class="login-logo"><img src="assets/images/piao_logo.png" alt="Barangay Piao logo"></div>
                    <h1 class="login-title">Office Supplies and Equipment Inventory Tracker System</h1>
                    <p class="login-subtitle">Barangay Pio Roxas, Zamboanga del Norte</p>
                </div>
                <div class="card login-card">
                    <div class="card-body">
                        <h5 class="text-center">Create Account</h5>
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger small">
                                <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
                            </div>
                        <?php endif; ?>
                        <form method="post">
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>First name *</label>
                                    <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Last name *</label>
                                    <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Username *</label>
                                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Email *</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Password *</label>
                                    <input type="password" name="password" class="form-control" required>
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Confirm password *</label>
                                    <input type="password" name="confirm_password" class="form-control" required>
                                </div>
                            </div>
                            <button type="submit" class="btn login-btn">Register</button>
                        </form>
                        <div class="text-center mt-3">
                            <a class="login-link" href="login.php">Already have an account? Login</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
