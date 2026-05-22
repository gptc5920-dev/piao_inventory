    <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
        <a class="sidebar-brand d-flex align-items-center justify-content-center" href="dashboard.php">
            <img src="<?= htmlspecialchars($base_url ?? '../') ?>assets/images/piao_logo.png" alt="Barangay Piao logo" class="sidebar-brand-logo">
            <div class="sidebar-brand-text mx-3">OSAEITS</div>
        </a>
        <hr class="sidebar-divider my-0">
        <li class="nav-item <?= ($current_page ?? '') === 'dashboard' ? 'active' : '' ?>">
            <a class="nav-link" href="dashboard.php">
                <i class="fas fa-fw fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
        </li>
        <?php
        $sidebar_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $sidebar_tx_type = $_GET['transaction_type'] ?? '';
        $sidebar_tx_type = in_array($sidebar_tx_type, ['purchase', 'issue', 'return'], true) ? $sidebar_tx_type : '';
        $sidebar_is_inventory_list = $sidebar_script === 'inventory.php';
        $sidebar_is_transaction_form = $sidebar_script === 'transaction-form.php';
        $sidebar_form_default_purchase = $sidebar_is_transaction_form && empty($_GET['id']) && $sidebar_tx_type === '';
        ?>
        <?php if (osaeits_can_access('purchase')): ?>
         <li class="nav-item <?= ($sidebar_is_transaction_form && ($sidebar_tx_type === 'purchase' || $sidebar_form_default_purchase)) ? 'active' : '' ?>">
            <a class="nav-link" href="transaction-form.php?transaction_type=purchase">
                <i class="fas fa-fw fa-cart-plus"></i>
                <span>Purchase</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (osaeits_can_access('supplies')): ?>
        <li class="nav-item <?= ($current_page ?? '') === 'supplies' ? 'active' : '' ?>">
            <a class="nav-link" href="supplies.php">
                <i class="fas fa-fw fa-warehouse"></i>
                <span>Supplies</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (osaeits_can_access('equipment')): ?>
        <li class="nav-item <?= ($current_page ?? '') === 'equipment' ? 'active' : '' ?>">
            <a class="nav-link" href="equipment.php">
                <i class="fas fa-fw fa-laptop"></i>
                <span>Equipment</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (osaeits_can_access('purchase_history')): ?>
        <li class="nav-item <?= (($current_page ?? '') === 'inventory' && $sidebar_is_inventory_list && $sidebar_tx_type === 'purchase') ? 'active' : '' ?>">
            <a class="nav-link" href="inventory.php?transaction_type=purchase">
                <i class="fas fa-fw fa-receipt"></i>
                <span>Purchase history</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (osaeits_can_access('issue')): ?>
        <li class="nav-item <?= ($sidebar_is_transaction_form && $sidebar_tx_type === 'issue') ? 'active' : '' ?>">
            <a class="nav-link" href="transaction-form.php?transaction_type=issue">
                <i class="fas fa-fw fa-share-square"></i>
                <span>Issue</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (osaeits_can_access('return')): ?>
        <li class="nav-item <?= ($sidebar_is_transaction_form && $sidebar_tx_type === 'return') ? 'active' : '' ?>">
            <a class="nav-link" href="transaction-form.php?transaction_type=return">
                <i class="fas fa-fw fa-undo"></i>
                <span>Return</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (osaeits_can_access('assign_items')): ?>
        <li class="nav-item <?= ($current_page ?? '') === 'assign_items' ? 'active' : '' ?>">
            <a class="nav-link" href="assign-items.php">
                <i class="fas fa-fw fa-clipboard-list"></i>
                <span>Assignments</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (osaeits_can_access('trash')): ?>
        <li class="nav-item <?= ($current_page ?? '') === 'trash' ? 'active' : '' ?>">
            <a class="nav-link" href="trash.php">
                <i class="fas fa-fw fa-trash"></i>
                <span>Trash</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (osaeits_can_access('reports')): ?>
        <li class="nav-item <?= ($current_page ?? '') === 'reports' ? 'active' : '' ?>">
            <a class="nav-link" href="reports.php">
                <i class="fas fa-fw fa-chart-bar"></i>
                <span>Reports</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (osaeits_can_access('users') || osaeits_can_access('barangay_officials') || osaeits_can_access('activity_log') || osaeits_can_access('database_backup')): ?>
        <hr class="sidebar-divider">
        <?php endif; ?>
        <?php if (osaeits_can_access('users')): ?>
        <li class="nav-item <?= ($current_page ?? '') === 'users' ? 'active' : '' ?>">
            <a class="nav-link" href="users.php">
                <i class="fas fa-fw fa-users"></i>
                <span>Users</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (osaeits_can_access('barangay_officials')): ?>
        <li class="nav-item <?= ($current_page ?? '') === 'barangay_officials' ? 'active' : '' ?>">
            <a class="nav-link" href="barangay-officials.php">
                <i class="fas fa-fw fa-user-tie"></i>
                <span>Barangay Officials</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (osaeits_can_access('activity_log')): ?>
        <li class="nav-item <?= ($current_page ?? '') === 'activity_log' ? 'active' : '' ?>">
            <a class="nav-link" href="ActivityLog.php">
                <i class="fas fa-fw fa-history"></i>
                <span>Activity Log</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (osaeits_can_access('database_backup')): ?>
        <li class="nav-item <?= ($current_page ?? '') === 'database_backup' ? 'active' : '' ?>">
            <a class="nav-link" href="database-backup.php">
                <i class="fas fa-fw fa-database"></i>
                <span>Database Backup</span>
            </a>
        </li>
        <?php endif; ?>
        <hr class="sidebar-divider d-none d-md-block">
    </ul>
