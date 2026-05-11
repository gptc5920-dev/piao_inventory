# OSAEITS Simple

A simplified version of OSAEITS (Office Supplies and Equipment Inventory Tracker System) using **SB Admin 2**, plain PHP, and a flat file structure (no MVC).

## Structure

```
OSAEITS-Simple/
├── config/
│   └── db.php           # Database connection
├── includes/
│   ├── auth-check.php   # Require login
│   ├── header.php       # HTML head + wrapper
│   ├── topbar.php       # Top bar + content start
│   └── footer.php       # Close layout + scripts
├── pages/
│   ├── sidebar.php      # SB Admin 2 sidebar (used by all pages here)
│   ├── dashboard.php    # Dashboard
│   ├── supplies.php
│   ├── supply-form.php
│   ├── supply-delete.php
│   ├── equipment.php
│   ├── equipment-form.php
│   ├── equipment-delete.php
│   ├── inventory.php    # Recent transactions
│   ├── reports.php
│   ├── users.php        # Admin: user list
│   ├── user-form.php
│   └── user-delete.php
├── assets/
│   ├── css/custom.css
│   └── js/custom.js
├── login.php
├── register.php
├── logout.php
├── index.php            # Redirects to pages/dashboard.php
├── migrate.php          # Create DB & tables
└── README.md
```

## Setup

1. **Database**: Ensure MySQL is running (e.g. XAMPP).

2. **Run migration**: Open in browser:
   - `http://localhost/OSAEITS-Simple/migrate.php`
   - Creates `osaeits_db` and tables. Default admin: `admin@osaeits.com` / `admin123`

3. **Use the app**: `http://localhost/OSAEITS-Simple/login.php`

## Deployment

Set these environment variables on your host before running the app:

```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=osaeits_db
DB_USER=root
DB_PASS=
```

You can also use `DATABASE_URL`, for example `mysql://user:password@host:3306/osaeits_db`.
Run `migrate.php` after deploying so tables exist, exact duplicate supplies are merged, and legacy starting supply stock is converted into purchase transactions.

## Tech

- **PHP** (no framework)
- **SB Admin 2** (Bootstrap 4) via CDN
- **MySQL** (same `osaeits_db` as original OSAEITS)
- **Philippine Peso (₱)** for currency

## Features

- Login / Register / Logout
- Dashboard (stats, recent transactions, low stock)
- Supplies CRUD
- Equipment CRUD
- Inventory (transaction list)
- Reports (totals, low stock, supplies value)
- Users management (admin only)
