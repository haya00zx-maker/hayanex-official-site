<?php
declare(strict_types=1);
// Isolated development test. Creates synthetic account inside an uncommitted
// InnoDB transaction and rolls it back. Never provides an HTTP route.
if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__.'/../StaffCredentialRepository.php';

if (getenv('HAYANEX_TEST_WRITE_ENABLE') !== 'YES_DISPOSABLE_DB_ONLY'
    || getenv('HAYANEX_TEST_DSN') !== 'mysql:host=localhost;dbname=hayanex_test;charset=utf8mb4'
    || getenv('HAYANEX_TEST_DB_USER') !== 'hayanex_test01'
    || !getenv('HAYANEX_TEST_DB_PASS')) {
    fwrite(STDERR, "STOP: isolated test settings required\n");
    exit(2);
}
function checkCredential(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException($name);
    echo 'PASS: '.$name.PHP_EOL;
}
$db = null;
try {
    $db = new PDO(getenv('HAYANEX_TEST_DSN'), getenv('HAYANEX_TEST_DB_USER'), getenv('HAYANEX_TEST_DB_PASS'), [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    checkCredential($db->query('SELECT DATABASE()')->fetchColumn()==='hayanex_test', 'database identity');
    $engine=$db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_staff'")->fetchColumn();
    checkCredential(strtoupper((string)$engine)==='INNODB', 'transactional staff table');
    $before=(int)$db->query('SELECT COUNT(*) FROM inquiry_staff')->fetchColumn();
    checkCredential($before===0, 'staff table initially empty');

    $db->beginTransaction();
    $login='synthetic-'.bin2hex(random_bytes(8));
    $password='synthetic-'.bin2hex(random_bytes(20));
    $insert=$db->prepare('INSERT INTO inquiry_staff (staff_login,password_hash,staff_role,is_active) VALUES (?,?,?,?)');
    $insert->execute([$login,StaffAuth::hashPassword($password),'owner',1]);
    $id=(int)$db->lastInsertId();

    $repo=new StaffCredentialRepository($db);
    $identity=$repo->verify($login,$password);
    checkCredential($identity!==null && $identity['staff_id']===$id
        && $identity['role']==='owner' && $identity['mfa_required']===true,
        'correct password yields MFA-required identity');
    checkCredential($repo->verify($login,'invalid-value')===null,'wrong password denied');
    checkCredential($repo->verify('absent-'.bin2hex(random_bytes(5)),$password)===null,'unknown account denied');
    $disable=$db->prepare('UPDATE inquiry_staff SET is_active=0 WHERE id=?');
    $disable->execute([$id]);
    checkCredential($repo->verify($login,$password)===null,'disabled account denied');
    $db->rollBack();
    checkCredential((int)$db->query('SELECT COUNT(*) FROM inquiry_staff')->fetchColumn()===$before,'synthetic record rolled back');
    echo "PASS: isolated staff credential integration completed\n";
    echo "NOTE: no HTTP endpoint, real account or email used\n";
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'FAIL: staff credential integration ('.get_class($e).')'.PHP_EOL);
    exit(1);
}
