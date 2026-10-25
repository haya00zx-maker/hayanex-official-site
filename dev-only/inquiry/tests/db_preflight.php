<?php
declare(strict_types=1);
/**
 * CLI-only, read-only development environment preflight.
 * Does not print credentials, staff records, or inquiry content.
 * Requires explicitly configured isolated development database.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$expected = ['inquiry_sequences','inquiries','inquiry_requests','notification_outbox','inquiry_rate_limits','inquiry_staff','inquiry_assignments','inquiry_staff_mfa','inquiry_audit_events'];
$required = ['HAYANEX_DEV_DSN','HAYANEX_DEV_DB_USER','HAYANEX_DEV_DB_PASS'];
foreach ($required as $name) {
    if (getenv($name) === false) {
        fwrite(STDERR, "NOT READY: missing environment setting ".$name."\n");
        exit(2);
    }
}
$dsn = getenv('HAYANEX_DEV_DSN');
if (!str_starts_with($dsn, 'mysql:')) {
    fwrite(STDERR, "NOT READY: mysql PDO DSN required\n");
    exit(2);
}
if (!extension_loaded('pdo_mysql') || !extension_loaded('mbstring')) {
    fwrite(STDERR, "NOT READY: pdo_mysql and mbstring are required\n");
    exit(2);
}
try {
    $db = new PDO($dsn, getenv('HAYANEX_DEV_DB_USER'), getenv('HAYANEX_DEV_DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($database === '' || !str_contains(strtolower($database), 'dev')) {
        fwrite(STDERR, "NOT READY: database name must explicitly include dev\n");
        exit(2);
    }
    $names = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($expected, $names));
    if ($missing) {
        fwrite(STDERR, "NOT READY: missing schema tables: ".implode(', ', $missing)."\n");
        exit(2);
    }
    echo "PASS: isolated development database connection and expected table names\n";
    echo "NOTE: this is a read-only preflight, not a CRUD, auth, MFA, or concurrency test\n";
} catch (Throwable $e) {
    fwrite(STDERR, "NOT READY: database preflight failed (details intentionally hidden)\n");
    exit(2);
}
