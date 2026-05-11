<?php
/**
 * Database connection.
 *
 * Deployment variables:
 * DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *
 * DATABASE_URL is also supported, for example:
 * mysql://user:password@host:3306/database
 */
function osaeits_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

$databaseUrl = osaeits_env('DATABASE_URL');
$db_host = osaeits_env('DB_HOST', 'localhost');
$db_port = osaeits_env('DB_PORT', '3306');
$db_name = osaeits_env('DB_NAME', osaeits_env('MYSQL_DATABASE', 'osaeits_db'));
$db_user = osaeits_env('DB_USER', osaeits_env('MYSQL_USER', 'root'));
$db_pass = osaeits_env('DB_PASS', osaeits_env('MYSQL_PASSWORD', ''));

if ($databaseUrl) {
    $parts = parse_url($databaseUrl);
    if ($parts !== false) {
        $db_host = $parts['host'] ?? $db_host;
        $db_port = isset($parts['port']) ? (string)$parts['port'] : $db_port;
        $db_user = isset($parts['user']) ? urldecode((string)$parts['user']) : $db_user;
        $db_pass = isset($parts['pass']) ? urldecode((string)$parts['pass']) : $db_pass;
        $db_name = isset($parts['path']) ? ltrim((string)$parts['path'], '/') : $db_name;
    }
}

$candidateDatabases = [$db_name];
if (!osaeits_env('DB_NAME') && !osaeits_env('MYSQL_DATABASE') && $db_name !== 'osaeits_db.sql') {
    $candidateDatabases[] = 'osaeits_db.sql';
}

$lastError = null;
foreach (array_unique(array_filter($candidateDatabases)) as $candidateDb) {
    try {
        $pdo = new PDO(
            "mysql:host={$db_host};port={$db_port};dbname={$candidateDb};charset=utf8mb4",
            (string)$db_user,
            (string)$db_pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $db_name = $candidateDb;
        break;
    } catch (PDOException $e) {
        $lastError = $e;
    }
}

if (!isset($pdo)) {
    $message = $lastError ? $lastError->getMessage() : 'Unable to connect.';
    die('<h1>Database error</h1><p>' . htmlspecialchars($message) . '</p><p>Check DB_* environment variables and run migrate.php first.</p>');
}
