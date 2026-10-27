<?php
declare(strict_types=1);
require_once __DIR__.'/../StaffSessionStorage.php';
$results=[];
function testSession(bool $value,string $name): void {
    global $results;
    if (!$value) { fwrite(STDERR, 'FAIL: '.$name.PHP_EOL); exit(1); }
    $results[]='PASS: '.$name;
}
try {
    try { StaffSessionStorage::start(false); testSession(false,'HTTPS guard'); }
    catch (RuntimeException $e) { testSession(true,'HTTPS guard'); }
    StaffSessionStorage::start(true);
    testSession(StaffSessionStorage::current(10000)===null,'unauthenticated access denied');
    try { StaffSessionStorage::login(12,'owner',true,true,false,10000); testSession(false,'owner MFA check'); }
    catch (RuntimeException $e) { testSession(true,'owner MFA check'); }
    StaffSessionStorage::login(12,'owner',true,true,true,10000);
    $current=StaffSessionStorage::current(10000);
    testSession($current!==null && $current['staff_id']===12,'authenticated session available');
    testSession(StaffSessionStorage::authorize(10010,true,'owner','staff_manage'),'owner authorization');
    testSession(!StaffSessionStorage::authorize(10011,true,'owner','nonexistent_permission'),'unknown action rejected');
    testSession(!StaffSessionStorage::authorize(10012,false,'owner','staff_manage'),'disabled account denied');
    testSession(!StaffSessionStorage::authorize(10013,true,'admin','staff_manage'),'changed role denied');
    testSession(StaffSessionStorage::current(10910)===null,'idle timeout enforced');
    StaffSessionStorage::logout();
    testSession(session_status()!==PHP_SESSION_ACTIVE,'session destroyed');
    echo implode(PHP_EOL,$results).PHP_EOL;
    echo 'PASS: development session-storage suite (CLI only, no DB or HTTP)'.PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: session test exception '.get_class($e).': '.$e->getMessage().PHP_EOL);
    exit(1);
}
