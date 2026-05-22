<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: pages/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!$login || !$password) {
        $error = 'Username/email and password required.';
    } else {
        $stmt = $pdo->prepare("SELECT id, username, first_name, last_name, password, role FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['show_stock_out_modal'] = true;
            require_once __DIR__ . '/includes/activity-log.php';
            log_activity($pdo, (int)$user['id'], 'auth.login', 'user', (int)$user['id'], [
                'username' => $user['username'],
            ]);
            $redirect = $_GET['redirect'] ?? 'pages/dashboard.php';
            header('Location: ' . $redirect);
            exit;
        }
        $error = 'Invalid username/email or password.';
    }
}

$page_title = 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - OSAEITS</title>
    <link rel="icon" type="image/png" href="assets/images/piao_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
    <style>
        .login-page { background: var(--osaeits-bg, #F0E8E4); min-height: 100vh; padding: 2rem 0; }
        .login-logo {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4a7c59 0%, #2c5f7a 40%, #c9a227 70%, #b85450 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }

        .login-logo img{
            width: 93px;
            height: 93px;
            object-fit: contain;
        }

        .login-logo i {
            font-size: 2.2rem;
            color: #fff;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }
        .login-title { color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,0.2); font-size: 1.35rem; font-weight: 600; margin-bottom: 0.25rem; }
        .login-subtitle { color: rgba(255,255,255,0.9); font-size: 0.95rem; text-shadow: 0 1px 2px rgba(0,0,0,0.15); margin-bottom: 2rem; }
        .login-card { background: #fff; border-radius: 1rem; box-shadow: 0 10px 40px rgba(0,0,0,0.08); border: none; overflow: hidden; max-width: 400px; margin: 0 auto; }
        .login-card .card-body { padding: 2rem; }
        .login-card h5 { color: #2d3748; font-weight: 600; margin-bottom: 1.5rem; }
        .login-card .form-group label { color: #4a5568; font-size: 0.9rem; font-weight: 500; margin-bottom: 0.35rem; }
        .login-card .form-control { border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.6rem 0.85rem; }
        .login-card .form-control:focus { border-color: var(--osaeits-btn, #8B5A3C); box-shadow: 0 0 0 3px rgba(139,90,60,0.15); }
        .login-btn { background: var(--osaeits-btn, #8B5A3C); border: none; color: #fff; font-weight: 600; padding: 0.65rem 1.5rem; border-radius: 0.5rem; box-shadow: 0 4px 12px rgba(139,90,60,0.3); width: 100%; }
        .login-btn:hover { background: #7a4f34; color: #fff; box-shadow: 0 5px 15px rgba(139,90,60,0.35); }
        .login-link { color: var(--osaeits-btn, #8B5A3C); font-size: 0.9rem; }
        .login-link:hover { color: #7a4f34; }
    </style>
</head>
<body class="login-page">
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100 py-4">
            <div class="col-11 col-sm-10 col-md-8 col-lg-5">
                <div class="text-center text-md-center mb-3 mb-md-4">
                <div class="login-logo">
                    <img src="assets/images/piao_logo.png" alt="Logo">
                </div>
                    <h4 class="text-center">Office Supplies and Equipment Inventory Tracker System</h4>
                    <i><p class="login-link">Barangay Pio Roxas, Zamboanga del Norte</p></i>
                </div>
                <div class="card login-card">
                    <div class="card-body">
                        <h5 class="text-center">Access Login</h5>
                        <?php if (!empty($_SESSION['success_message'])): ?>
                            <div class="alert alert-success small"><?= htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger small"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        <form method="post">
                            <div class="form-group">
                                <label for="email">Username</label>
                                <input type="text" id="email" name="email" class="form-control" placeholder="Enter username or email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autocomplete="username">
                            </div>
                            <div class="form-group">
                                <label for="password">Password</label>
                                <input type="password" id="password" name="password" class="form-control" placeholder="Enter password" required autocomplete="current-password">
                            </div>
                            <button type="submit" class="btn login-btn">Login</button>
                        </form>
                       
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
