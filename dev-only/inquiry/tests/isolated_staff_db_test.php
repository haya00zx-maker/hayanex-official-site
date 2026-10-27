<?php
declare(strict_types=1);
/**
 * Development-only staff database integration test.
 * All test INSERTs are executed in one uncommitted transaction and rolled back.
 * No real accounts, secrets, emails or HTTP sessions are created.
 */
if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__.'/../StaffAuth.php';
require_once __DIR__.'/../StaffPermissions.php';

function stopStaff(string $msg): never { fwrite(STDERR, 'FAIL: '.$msg.PHP_EOL); exit(2); }
function assertStaff(bool $ok, string $msg): void { if (!$ok) throw new RuntimeException($msg); echo 'PASS: '.$msg.PHP_EOL; }
if (getenv('HAYANEX_TEST_WRITE_ENABLE') !== 'YES_DISPOSABLE_DB_ONLY') stopStaff('explicit write flag required');
if (getenv('HAYANEX_TEST_DSN') !== 'mysql:host=localhost;dbname=hayanex_test;charset=utf8mb4') stopStaff('unexpected DSN');
if (getenv('HAYANEX_TEST_DB_USER') !== 'hayanex_test01') stopStaff('unexpected DB user');
$pass = getenv('HAYANEX_TEST_DB_PASS');
if (!is_string($pass) || $pass === '') stopStaff('missing password');

$db = null;
try {
    $db = new PDO(getenv('HAYANEX_TEST_DSN'), getenv('HAYANEX_TEST_DB_USER'), $pass, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'hayanex_test') stopStaff('database mismatch');

    $tables=['inquiry_staff','inquiry_assignments','inquiry_staff_mfa','inquiries'];
    $before=[];
    foreach ($tables as $table) {
        $stmt=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        if (strtoupper((string)$stmt->fetchColumn()) !== 'INNODB') stopStaff('table is not InnoDB: '.$table);
        $before[$table]=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
    }
    if ($before['inquiry_staff'] !== 0 || $before['inquiry_assignments'] !== 0 || $before['inquiry_staff_mfa'] !== 0)
        stopStaff('staff test tables must initially be empty');
    if ($before['inquiries'] < 1) stopStaff('synthetic inquiry is missing');
    $inquiryId=(int)$db->query('SELECT id FROM inquiries ORDER BY id DESC LIMIT 1')->fetchColumn();

    $db->beginTransaction();
    $insert=$db->prepare('INSERT INTO inquiry_staff (staff_login,password_hash,staff_role,is_active) VALUES (?,?,?,?)');
    $ids=[]; $passwords=[];
    foreach (['owner','admin','staff'] as $role) {
        $password='fake-'.bin2hex(random_bytes(18));
        $hash=StaffAuth::hashPassword($password);
        $insert->execute(['integration-'.bin2hex(random_bytes(8)).'-'.$role,$hash,$role,1]);
        $ids[$role]=(int)$db->lastInsertId();
        $passwords[$role]=$password;
        $query=$db->prepare('SELECT password_hash,staff_role,is_active FROM inquiry_staff WHERE id=?');
        $query->execute([$ids[$role]]);
        $stored=$query->fetch();
        assertStaff($stored && $stored['staff_role']===$role && (int)$stored['is_active']===1, $role.' account stored with correct role');
        assertStaff(StaffAuth::verifyPassword($password,$stored['password_hash']),$role.' password verification');
        assertStaff(!StaffAuth::verifyPassword('incorrect-password',$stored['password_hash']),$role.' invalid password rejected');
    }
    assertStaff(!StaffAuth::canStartSession('owner',true,true,false),'owner requires MFA');
    assertStaff(!StaffAuth::canStartSession('admin',true,true,false),'admin requires MFA');
    assertStaff(StaffAuth::canStartSession('staff',true,true,false),'staff session policy evaluated');
    assertStaff(!StaffAuth::canStartSession('staff',false,true,true),'disabled staff cannot start session');
    $assign=$db->prepare('INSERT INTO inquiry_assignments (inquiry_id,staff_id) VALUES (?,?)');
    $assign->execute([$inquiryId,$ids['staff']]);
    $q=$db->prepare('SELECT staff_id FROM inquiry_assignments WHERE inquiry_id=?');
    $q->execute([$inquiryId]);
    $assigned=(int)$q->fetchColumn();
    assertStaff($assigned === $ids['staff'],'assignment stored with foreign keys');
    assertStaff(StaffPermissions::can('staff','inquiry_view',$ids['staff'],$assigned),'assigned staff may view');
    assertStaff(!StaffPermissions::can('staff','inquiry_view',$ids['admin'],$assigned),'different staff may not view');
    assertStaff(!StaffPermissions::can('staff','assign',$ids['staff'],$assigned),'ordinary staff may not assign');
    assertStaff(StaffPermissions::can('admin','assign'),'admin may assign');
    assertStaff(!StaffPermissions::can('admin','staff_manage'),'admin cannot manage staff');
    assertStaff(StaffPermissions::can('owner','staff_manage'),'owner can manage staff');

    // Explicit rollback prevents even synthetic login records from persisting.
    $db->rollBack();
    foreach ($tables as $table) {
        $after=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
        assertStaff($after===$before[$table],'unchanged after rollback: '.$table);
    }
    echo "PASS: isolated staff DB integration completed; no test accounts retained".PHP_EOL;
    echo "NOTE: HTTP login, real encrypted MFA persistence and session authorization are not tested".PHP_EOL;
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    stopStaff('staff DB integration failed ('.get_class($e).': '.$e->getMessage().')');
}
