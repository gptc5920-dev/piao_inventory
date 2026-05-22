<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth-check.php';

$page_title = 'Database Backup & Restore';
$current_page = 'database_backup';
$base_url = '../';

function split_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
            }
            continue;
        }
        if ($inBlockComment) {
            if ($char === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }
        if (!$inSingle && !$inDouble) {
            if ($char === '-' && $next === '-') {
                $inLineComment = true;
                $i++;
                continue;
            }
            if ($char === '#') {
                $inLineComment = true;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($char === "'" && !$inDouble) {
            $escaped = $i > 0 && $sql[$i - 1] === '\\';
            if (!$escaped) {
                $inSingle = !$inSingle;
            }
        } elseif ($char === '"' && !$inSingle) {
            $escaped = $i > 0 && $sql[$i - 1] === '\\';
            if (!$escaped) {
                $inDouble = !$inDouble;
            }
        }

        if ($char === ';' && !$inSingle && !$inDouble) {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

function build_backup_sql(PDO $pdo): string
{
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    $out = [];
    $out[] = '-- OSAEITS SQL Backup';
    $out[] = '-- Database: `' . $dbName . '`';
    $out[] = '-- Generated at: ' . date('Y-m-d H:i:s');
    $out[] = 'SET NAMES utf8mb4;';
    $out[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $out[] = '';

    foreach ($tables as $table) {
        $table = (string)$table;
        $createRow = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_ASSOC);
        $createSql = $createRow['Create Table'] ?? '';

        $out[] = '-- --------------------------------------------------------';
        $out[] = '-- Table structure for `' . $table . '`';
        $out[] = 'DROP TABLE IF EXISTS `' . $table . '`;';
        $out[] = $createSql . ';';
        $out[] = '';

        $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $columns = array_keys($rows[0]);
            $columnSql = '`' . implode('`, `', array_map(static fn($c) => str_replace('`', '``', $c), $columns)) . '`';

            $out[] = '-- Dumping data for `' . $table . '`';
            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $col) {
                    $value = $row[$col];
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = $pdo->quote((string)$value);
                    }
                }
                $out[] = 'INSERT INTO `' . $table . '` (' . $columnSql . ') VALUES (' . implode(', ', $values) . ');';
            }
            $out[] = '';
        }
    }

    $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $out[] = '';
    return implode("\n", $out);
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'download_backup') {
        try {
            $sql = build_backup_sql($pdo);
            $filename = 'osaeits-backup-' . date('Ymd-His') . '.sql';
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . (string)strlen($sql));
            echo $sql;
            exit;
        } catch (Throwable $e) {
            $error = 'Unable to generate backup.';
        }
    } elseif ($action === 'restore_backup') {
        if (!isset($_FILES['backup_file']) || (int)$_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please select a valid .sql backup file.';
        } else {
            $tmp = (string)$_FILES['backup_file']['tmp_name'];
            $name = (string)$_FILES['backup_file']['name'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== 'sql') {
                $error = 'Only .sql files are allowed.';
            } else {
                $sql = (string)file_get_contents($tmp);
                if (trim($sql) === '') {
                    $error = 'Backup file is empty.';
                } else {
                    try {
                        $statements = split_sql_statements($sql);
                        if (empty($statements)) {
                            $error = 'No executable SQL statements found in the file.';
                        } else {
                            // DDL (DROP TABLE / CREATE TABLE) causes an implicit commit in MySQL,
                            // so wrapping in a PDO transaction is not possible here.
                            // Run each statement directly instead.
                            $pdo->exec('SET NAMES utf8mb4');
                            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

                            $executed = 0;
                            $skipped  = 0;
                            foreach ($statements as $stmtSql) {
                                // Skip CREATE DATABASE / USE / CREATE SCHEMA — they may reference a
                                // different DB name or require elevated privileges.
                                $upper = strtoupper(ltrim($stmtSql));
                                if (
                                    str_starts_with($upper, 'CREATE DATABASE') ||
                                    str_starts_with($upper, 'CREATE SCHEMA')   ||
                                    str_starts_with($upper, 'USE ')            ||
                                    str_starts_with($upper, 'SET NAMES')       ||
                                    str_starts_with($upper, 'SET FOREIGN_KEY_CHECKS')
                                ) {
                                    $skipped++;
                                    continue;
                                }
                                $pdo->exec($stmtSql);
                                $executed++;
                            }

                            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                            $success = "Database restored successfully. {$executed} statement(s) executed" .
                                       ($skipped > 0 ? ", {$skipped} skipped (non-data directives)." : '.');
                        }
                    } catch (Throwable $e) {
                        try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
                        $error = 'Restore failed: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>

<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">Database Backup & Restore</h6>
    </div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="border rounded p-3 h-100">
                    <h6 class="font-weight-bold mb-2">Create Backup</h6>
                    <p class="text-muted small mb-3">Download a full SQL backup of the current database.</p>
                    <form method="post">
                        <input type="hidden" name="action" value="download_backup">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-download mr-1"></i> Download SQL Backup
                        </button>
                    </form>
                </div>
            </div>
            <div class="col-lg-6 mb-4">
                <div class="border rounded p-3 h-100">
                    <h6 class="font-weight-bold mb-2">Restore Backup</h6>
                    <p class="text-danger small mb-3">Warning: Restoring will overwrite existing data with the uploaded SQL backup.</p>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="restore_backup">
                        <div class="form-group">
                            <label class="small">SQL Backup File</label>
                            <input type="file" name="backup_file" class="form-control-file" accept=".sql" required>
                        </div>
                        <button type="submit" class="btn btn-danger" data-confirm="Restore database from this backup file? This may overwrite current data.">
                            <i class="fas fa-upload mr-1"></i> Restore Database
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
