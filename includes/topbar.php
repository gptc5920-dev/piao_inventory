    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                <button id="sidebarToggle" class="btn btn-link d-none d-md-inline-block rounded-circle mr-3" title="Toggle sidebar">
                    <i class="fa fa-bars"></i>
                </button>
                <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3" title="Toggle sidebar">
                    <i class="fa fa-bars"></i>
                </button>
                <span class="navbar-brand text-gray-800 d-inline-flex align-items-center app-title-brand">
                    <img src="<?= htmlspecialchars($base_url ?? '') ?>assets/images/piao_logo.png" alt="Barangay Piao logo" class="app-title-logo">
                    <span><?= htmlspecialchars($page_title ?? 'Dashboard') ?></span>
                </span>
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item dropdown no-arrow">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                            <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></span>
                            <i class="fas fa-user-circle fa-fw"></i>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right">
                            <a class="dropdown-item" href="<?= htmlspecialchars($base_url ?? '') ?>logout.php"><i class="fas fa-sign-out-alt fa-sm fa-fw mr-2"></i> Logout</a>
                        </div>
                    </li>
                </ul>
            </nav>
            <div class="container-fluid">
