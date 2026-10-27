<?php
declare(strict_types=1);
/**
 * Isolated, CLI-only, write-enabled inquiry DB smoke test.
 * NEVER run against the existing development DB or any production DB.
 * Runs only with a disposable, empty database whose name ends in _test.
 * Leaves synthetic rows in the disposable DB for inspection; no mail is sent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(2); }
require_once __DIR__ . '/../InquiryRepository.php';

function stopTest(string $message): never {
    fwrite(STDERR, "STOP/FAIL: ".$message.PHP_EOL);
    exit(2);
}
function checkTest(bool $ok, string $label): void {
    if (!$ok) stopTest($label);
    echo "PASS: ".$label.PHP_EOL;
}
if (getenv('HAYANEX_TEST_WRITE_ENABLE') !== 'YES_DISPOSABLE_DB_ONLY') {
    stopTest('write-enabled test flag is missing');
}
if (!extension_loaded('pdo_mysql') || !extension_loaded('mbstring')) {
    stopTest('required extensions unavailable');
}
$dsn = getenv('HAYANEX_TEST_DSN');
$user = getenv('HAYANEX_TEST_DB_USER');
$pass = getenv('HAYANEX_TEST_DB_PASS');
if (!is_string($dsn) || !is_string($user) || !is_string($pass)
    || $user === '' || $pass === '') stopTest('database settings missing');
if (!preg_match('/^mysql:host=(localhost|127\.0\.0\.1);dbname=([a-zA-Z0-9_]+);charset=utf8mb4$/D', $dsn, $matches)) {
    stopTest('DSN must be local MySQL with utf8mb4');
}
$expectedDb = $matches[2];
if (!preg_match('/_test$/D', $expectedDb) || $expectedDb === 'hayanex_inquirydev') {
    stopTest('database must be a separate disposable database ending in _test');
}
try {
    $db = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== $expectedDb) {
        stopTest('connected database mismatch');
    }
    $tables = ['inquiry_sequences','inquiries','inquiry_requests','notification_outbox'];
    $existing = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (!in_array($table, $existing, true)) stopTest('missing required table: '.$table);
        $count = (int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
        if ($count !== 0) stopTest('disposable database is not empty: '.$table);
    }
    // Make the shared repository's transaction and outbox uniqueness semantics explicit.
    $engine = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiries'")->fetchColumn();
    if (strtoupper((string)$engine) !== 'INNODB') stopTest('InnoDB is required');
    echo "PASS: isolated empty disposable database precheck".PHP_EOL;

    $repository = new InquiryRepository($db);
    $fake = [
        'inquiry_type'=>'estimate','client_type'=>'person','person_name'=>'架空テスト利用者',
        'email'=>'nobody@example.invalid','service'=>'web',
        'message'=>'架空データによる統合テスト。送信禁止。'
    ];
    $requestKey = bin2hex(random_bytes(32));
    $receipt = $repository->register($fake, $requestKey, true);
    checkTest((bool)preg_match('/^HX-[0-9]{8}-[0-9]{4,}$/D', $receipt), 'receipt number format');
    $count = (int)$db->query('SELECT COUNT(*) FROM inquiries')->fetchColumn();
    checkTest($count === 1, 'one synthetic inquiry inserted');
    checkTest((int)$db->query('SELECT COUNT(*) FROM inquiry_requests')->fetchColumn() === 1, 'one request key inserted');
    checkTest((int)$db->query('SELECT COUNT(*) FROM notification_outbox')->fetchColumn() === 2, 'two unsent notification tasks inserted');
    checkTest((int)$db->query("SELECT COUNT(*) FROM notification_outbox WHERE status='pending'")->fetchColumn() === 2, 'notifications stay pending');
    checkTest($repository->register($fake, $requestKey, true) === $receipt, 'replay returns same receipt');
    checkTest((int)$db->query('SELECT COUNT(*) FROM inquiries')->fetchColumn() === 1, 'replay creates no new inquiry');
    checkTest((int)$db->query('SELECT COUNT(*) FROM notification_outbox')->fetchColumn() === 2, 'replay creates no new notification');
    $altered = $fake;
    $altered['message'] .= '（変更）';
    $rejected = false;
    try { $repository->register($altered, $requestKey, true); }
    catch (InvalidArgumentException $e) { $rejected = true; }
    checkTest($rejected, 'changed payload with same key rejected');
    checkTest((int)$db->query('SELECT COUNT(*) FROM inquiries')->fetchColumn() === 1, 'rejected replay leaves data intact');
    $rejected = false;
    try { $repository->register($fake, bin2hex(random_bytes(32)), false); }
    catch (InvalidArgumentException $e) { $rejected = true; }
    checkTest($rejected, 'missing consent rejected');
    checkTest((int)$db->query('SELECT COUNT(*) FROM inquiries')->fetchColumn() === 1, 'no-consent request does not write');
    echo "PASS: isolated DB smoke test completed (DB-01 to DB-04 partial)".PHP_EOL;
    echo "NOTE: disposable DB retains synthetic rows. No email sent; concurrency, rollback, web and permissions untested.".PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: isolated DB smoke test failed; inspect disposable database before retry (details suppressed)".PHP_EOL);
    exit(1);
}
