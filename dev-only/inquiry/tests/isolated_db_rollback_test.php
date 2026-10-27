<?php
declare(strict_types=1);
/**
 * Development-only deterministic rollback test for the inquiry repository.
 * Uses only the dedicated hayanex_test DB; creates no schema objects.
 * Intentionally fails the second notification INSERT after preceding writes.
 */
if (PHP_SAPI !== 'cli') { exit(2); }
require_once __DIR__ . '/../InquiryRepository.php';

function failRollback(string $message): never {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(2);
}
if (getenv('HAYANEX_TEST_WRITE_ENABLE') !== 'YES_DISPOSABLE_DB_ONLY') failRollback('explicit test flag required');
if (getenv('HAYANEX_TEST_DSN') !== 'mysql:host=localhost;dbname=hayanex_test;charset=utf8mb4') failRollback('unexpected database DSN');
if (getenv('HAYANEX_TEST_DB_USER') !== 'hayanex_test01') failRollback('unexpected database user');
$pass = getenv('HAYANEX_TEST_DB_PASS');
if (!is_string($pass) || $pass === '') failRollback('password missing');

final class RollbackFailureStatement extends PDOStatement {
    public static bool $triggered = false;
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        if (str_contains($this->queryString, 'INSERT INTO notification_outbox')
            && ($params[1] ?? null) === 'staff_alert') {
            self::$triggered = true;
            throw new RuntimeException('INTENTIONAL_TEST_FAILURE_AT_SECOND_NOTIFICATION');
        }
        return parent::execute($params);
    }
}

try {
    $db = new PDO(getenv('HAYANEX_TEST_DSN'), getenv('HAYANEX_TEST_DB_USER'), $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'hayanex_test') failRollback('connected DB mismatch');
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RollbackFailureStatement::class, []]);

    $tables = ['inquiries', 'inquiry_requests', 'notification_outbox', 'inquiry_sequences'];
    $before = [];
    foreach ($tables as $table) {
        $engine = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $db->quote($table))->fetchColumn();
        if (strtoupper((string)$engine) !== 'INNODB') failRollback('table is not InnoDB: ' . $table);
        $before[$table] = (int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    // Never rewrite or delete prior synthetic records. Track today's sequence precisely.
    $day = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
    $seq = $db->prepare('SELECT last_number FROM inquiry_sequences WHERE received_day=?');
    $seq->execute([$day]);
    $beforeNum = $seq->fetchColumn();

    $fake = [
        'inquiry_type' => 'estimate',
        'client_type' => 'person',
        'person_name' => '架空ロールバック検証',
        'email' => 'rollback@example.invalid',
        'service' => 'web',
        'message' => '架空データ。第2通知の直前に意図的な例外を発生させる。送信禁止。',
    ];
    $key = bin2hex(random_bytes(32));
    $thrownAsExpected = false;
    try {
        (new InquiryRepository($db))->register($fake, $key, true);
    } catch (RuntimeException $e) {
        $thrownAsExpected = $e->getMessage() === 'INTENTIONAL_TEST_FAILURE_AT_SECOND_NOTIFICATION';
        if (!$thrownAsExpected) throw $e;
    }
    if (!$thrownAsExpected || !RollbackFailureStatement::$triggered) failRollback('expected late-stage failure did not happen');
    if ($db->inTransaction()) failRollback('transaction still open after failure');

    foreach ($tables as $table) {
        $after = (int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        if ($after !== $before[$table]) failRollback('row count changed after rollback: ' . $table);
        echo 'PASS: unchanged ' . $table . ' (' . $after . ')' . PHP_EOL;
    }
    $seq->execute([$day]);
    if ($seq->fetchColumn() !== $beforeNum) failRollback('sequence value changed');
    $check = $db->prepare('SELECT COUNT(*) FROM inquiry_requests WHERE request_key=?');
    $check->execute([$key]);
    if ((int)$check->fetchColumn() !== 0) failRollback('failed request key persisted');
    echo "PASS: late notification failure triggered and transaction rolled back" . PHP_EOL;
    echo "PASS: sequence unchanged and rejected request key absent" . PHP_EOL;
    echo "NOTE: no mail sent; no schema changes; previously saved synthetic rows retained" . PHP_EOL;
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    failRollback('rollback verification failed (' . get_class($e) . ')');
}
