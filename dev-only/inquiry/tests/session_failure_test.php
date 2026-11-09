<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require_once __DIR__.'/../StaffSessionStorage.php';
// A never-created path: no real session or credential is loaded or written.
ini_set('session.save_path',sys_get_temp_dir().'/hayanex-unavailable-'.bin2hex(random_bytes(12)).'/missing');
$failed=false;
try{StaffSessionStorage::start(true);}catch(RuntimeException $e){$failed=$e->getMessage()==='Cannot start session';}
if(!$failed||session_status()===PHP_SESSION_ACTIVE){fwrite(STDERR,"FAIL: storage error must not create a session\n");exit(1);}
echo "PASS: session storage failure rejects authentication without logging sensitive warnings\n";
