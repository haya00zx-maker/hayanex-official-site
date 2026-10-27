<?php
declare(strict_types=1);
/**
 * CLI-only development parallel registration test. Runs two simultaneous
 * synthetic inquiry registrations in the isolated hayanex_test database.
 * No email; no schema changes; never run on a web-accessible path.
 */
if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__ . '/../InquiryRepository.php';

function stopParallel(string $message): never {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(2);
}
if (getenv('HAYANEX_TEST_WRITE_ENABLE') !== 'YES_DISPOSABLE_DB_ONLY') stopParallel('write flag missing');
if (getenv('HAYANEX_TEST_DSN') !== 'mysql:host=localhost;dbname=hayanex_test;charset=utf8mb4') stopParallel('unexpected DSN');
if (getenv('HAYANEX_TEST_DB_USER') !== 'hayanex_test01') stopParallel('unexpected user');
$pass = getenv('HAYANEX_TEST_DB_PASS');
if (!is_string($pass) || $pass === '') stopParallel('database password missing');

function connectParallel(): PDO {
    $db = new PDO(getenv('HAYANEX_TEST_DSN'), getenv('HAYANEX_TEST_DB_USER'), getenv('HAYANEX_TEST_DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'hayanex_test') stopParallel('connected database mismatch');
    return $db;
}
function fakeParallel(int $n): array {
    return [
        'inquiry_type'=>'estimate','client_type'=>'person',
        'person_name'=>'架空並列テスト'.$n, 'email'=>'parallel'.$n.'@example.invalid',
        'service'=>'web','message'=>'架空の同時送信検証。メール送信禁止。番号'.$n,
    ];
}
if (($argv[1] ?? '') === '--worker') {
    if (count($argv) !== 5 || !in_array($argv[4], ['1','2'], true)
        || !preg_match('/^[a-f0-9]{64}$/D', $argv[2])) stopParallel('invalid worker arguments');
    $key = $argv[2];
    $signal = $argv[3];
    $n = (int)$argv[4];
    $deadline = microtime(true) + 12.0;
    while (!is_file($signal) && microtime(true) < $deadline) usleep(10000);
    if (!is_file($signal)) stopParallel('start signal timed out');
    try {
        $receipt = (new InquiryRepository(connectParallel()))->register(fakeParallel($n), $key, true);
        echo 'WORKER PASS' . PHP_EOL;
    } catch (Throwable $e) {
        stopParallel('worker registration failed (' . get_class($e) . ')');
    }
    exit(0);
}
if (count($argv) !== 1) stopParallel('unexpected arguments');
if (!function_exists('proc_open')) stopParallel('proc_open unavailable');
$db = connectParallel();
$tables = ['inquiries','inquiry_requests','notification_outbox','inquiry_sequences'];
$before = [];
foreach ($tables as $table) {
    $engine = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=".$db->quote($table))->fetchColumn();
    if (strtoupper((string)$engine) !== 'INNODB') stopParallel('non-InnoDB table: '.$table);
    $before[$table] = (int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
}
$day = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
$seq = $db->prepare('SELECT last_number FROM inquiry_sequences WHERE received_day=?');
$seq->execute([$day]);
$prior = $seq->fetchColumn();
$prior = $prior === false ? 0 : (int)$prior;

$private = sys_get_temp_dir().'/hayanex-parallel-'.bin2hex(random_bytes(12));
if (!mkdir($private, 0700)) stopParallel('unable to create private synchronization folder');
$signal = $private.'/start';
$keys = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];
$processes = [];
$streams = [];
try {
    for ($i = 0; $i < 2; $i++) {
        $spec = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $command = [PHP_BINARY, __FILE__, '--worker', $keys[$i], $signal, (string)($i+1)];
        $proc = proc_open($command, $spec, $pipes, null, null, ['bypass_shell'=>true]);
        if (!is_resource($proc)) stopParallel('unable to launch worker');
        fclose($pipes[0]);
        $processes[] = $proc;
        $streams[] = [$pipes[1], $pipes[2]];
    }
    if (file_put_contents($signal, 'go', LOCK_EX) === false) stopParallel('unable to issue start signal');
    $allWorkersOk = true;
    foreach ($processes as $i => $proc) {
        $out = stream_get_contents($streams[$i][0]);
        $err = stream_get_contents($streams[$i][1]);
        fclose($streams[$i][0]); fclose($streams[$i][1]);
        $exit = proc_close($proc);
        if ($exit !== 0 || trim($out) !== 'WORKER PASS' || trim($err) !== '') {
            $allWorkersOk = false;
            echo 'WORKER '.($i+1).': FAIL (exit '.$exit.')'.PHP_EOL;
        } else echo 'WORKER '.($i+1).': PASS'.PHP_EOL;
    }
    if (!$allWorkersOk) stopParallel('one or more workers failed; inspect data before any retry');
    $expectedDeltas = ['inquiries'=>2,'inquiry_requests'=>2,'notification_outbox'=>4,'inquiry_sequences'=>0];
    foreach ($expectedDeltas as $table=>$delta) {
        $after = (int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
        if ($after !== $before[$table]+$delta) stopParallel('unexpected row count: '.$table);
        echo 'PASS: '.$table.' increased by '.$delta.PHP_EOL;
    }
    $seq->execute([$day]);
    if ((int)$seq->fetchColumn() !== $prior+2) stopParallel('sequence did not advance by two');
    $q = $db->prepare('SELECT i.receipt_no, i.id FROM inquiry_requests r JOIN inquiries i ON i.id=r.inquiry_id WHERE r.request_key=?');
    $receipts = []; $ids = [];
    foreach ($keys as $key) {
        $q->execute([$key]);
        $row = $q->fetch();
        if (!$row || !preg_match('/^HX-[0-9]{8}-[0-9]{4,}$/D', $row['receipt_no'])) stopParallel('receipt not found or invalid');
        $receipts[] = $row['receipt_no']; $ids[] = (int)$row['id'];
    }
    if ($receipts[0] === $receipts[1]) stopParallel('duplicate receipt number');
    $nq = $db->prepare("SELECT COUNT(*) FROM notification_outbox WHERE inquiry_id=? AND status='pending'");
    foreach ($ids as $id) {
        $nq->execute([$id]);
        if ((int)$nq->fetchColumn() !== 2) stopParallel('notification tasks missing');
    }
    echo 'PASS: two distinct receipt numbers and sequential counter'.PHP_EOL;
    echo 'PASS: two pending notifications per inquiry; no emails sent'.PHP_EOL;
    echo 'NOTE: new synthetic records remain in hayanex_test; never rerun automatically'.PHP_EOL;
} finally {
    if (is_file($signal)) unlink($signal);
    if (is_dir($private)) rmdir($private);
}
