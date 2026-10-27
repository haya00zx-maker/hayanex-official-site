<?php
declare(strict_types=1);
/**
 * CLI-only, disposable hayanex_test MFA database integration verification.
 * Synthetic owner and encrypted secret live only in an uncommitted transaction.
 * No real users, HTTP requests, email or persistent encryption keys.
 */
if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__.'/../StaffAuth.php';
require_once __DIR__.'/../StaffTotp.php';
require_once __DIR__.'/../StaffMfaCrypto.php';

function requireMfaTest(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS: '.$label.PHP_EOL;
}
if (getenv('HAYANEX_TEST_WRITE_ENABLE') !== 'YES_DISPOSABLE_DB_ONLY'
    || getenv('HAYANEX_TEST_DSN') !== 'mysql:host=localhost;dbname=hayanex_test;charset=utf8mb4'
    || getenv('HAYANEX_TEST_DB_USER') !== 'hayanex_test01'
    || !is_string(getenv('HAYANEX_TEST_DB_PASS'))
    || getenv('HAYANEX_TEST_DB_PASS') === '') {
    fwrite(STDERR, "STOP: isolated test settings required".PHP_EOL);
    exit(2);
}
$db=null;
try {
    $db=new PDO(getenv('HAYANEX_TEST_DSN'),getenv('HAYANEX_TEST_DB_USER'),getenv('HAYANEX_TEST_DB_PASS'),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    requireMfaTest($db->query('SELECT DATABASE()')->fetchColumn()==='hayanex_test','disposable database verified');
    $before=[];
    foreach (['inquiry_staff','inquiry_staff_mfa'] as $table) {
        $q=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $q->execute([$table]);
        requireMfaTest(strtoupper((string)$q->fetchColumn())==='INNODB',$table.' supports rollback');
        $before[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
        requireMfaTest($before[$table]===0,$table.' initially empty');
    }

    $db->beginTransaction();
    $login='synthetic-mfa-'.bin2hex(random_bytes(8));
    $password=StaffAuth::hashPassword('synthetic-'.bin2hex(random_bytes(20)));
    $q=$db->prepare('INSERT INTO inquiry_staff (staff_login,password_hash,staff_role,is_active) VALUES (?,?,?,1)');
    $q->execute([$login,$password,'owner']);
    $id=(int)$db->lastInsertId();
    $secret=StaffTotp::generateSecret();
    $key=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $cipher=StaffMfaCrypto::encrypt($secret,$key,$id);
    $q=$db->prepare('INSERT INTO inquiry_staff_mfa (staff_id,encrypted_totp_secret,last_accepted_counter) VALUES (?,?,-1)');
    $q->execute([$id,$cipher]);

    $q=$db->prepare('SELECT encrypted_totp_secret,last_accepted_counter FROM inquiry_staff_mfa WHERE staff_id=? FOR UPDATE');
    $q->execute([$id]); $row=$q->fetch(PDO::FETCH_ASSOC);
    requireMfaTest(is_array($row) && !str_contains($row['encrypted_totp_secret'],$secret),'encrypted secret saved without plaintext');
    $decoded=StaffMfaCrypto::decrypt($row['encrypted_totp_secret'],$key,$id);
    requireMfaTest($decoded===$secret,'secret decrypted for matching staff');

    $counter=intdiv(time(),30);
    $otp=StaffTotp::code($decoded,$counter);
    $accepted=StaffTotp::verify($decoded,$otp,$counter*30,(int)$row['last_accepted_counter']);
    requireMfaTest($accepted===$counter,'first valid TOTP accepted');

    // Atomic conditional update prevents two requests accepting the same counter.
    $update=$db->prepare('UPDATE inquiry_staff_mfa SET last_accepted_counter=? WHERE staff_id=? AND last_accepted_counter<?');
    $update->execute([$accepted,$id,$accepted]);
    requireMfaTest($update->rowCount()===1,'accepted counter recorded atomically');
    $update->execute([$accepted,$id,$accepted]);
    requireMfaTest($update->rowCount()===0,'counter replay rejected by database');

    $q=$db->prepare('SELECT last_accepted_counter FROM inquiry_staff_mfa WHERE staff_id=?');
    $q->execute([$id]);$last=(int)$q->fetchColumn();
    requireMfaTest(StaffTotp::verify($decoded,$otp,$counter*30,$last)===null,'same TOTP replay rejected');
    requireMfaTest(StaffTotp::verify($decoded,'abcdef',$counter*30,$last)===null,'invalid TOTP rejected');

    $db->rollBack();
    foreach ($before as $table=>$count) {
        requireMfaTest((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$count,'no synthetic rows retained in '.$table);
    }
    echo 'PASS: isolated MFA DB integration completed; no real keys or accounts retained'.PHP_EOL;
    echo 'NOTE: HTTP login, real key management and separate-transaction race test remain untested'.PHP_EOL;
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'FAIL: isolated MFA DB integration ('.get_class($e).')'.PHP_EOL);
    exit(1);
}
