<?php
declare(strict_types=1);
require_once __DIR__.'/../StaffAuth.php';
$assert=static function(bool $ok,string $name): void {
  if (!$ok) { fwrite(STDERR,"FAIL $name\n");exit(1); }
  echo "PASS $name\n";
};
$hash=StaffAuth::hashPassword('example-only-test-2026');
$assert(StaffAuth::verifyPassword('example-only-test-2026',$hash),'valid password');
$assert(!StaffAuth::verifyPassword('incorrect-password',$hash),'bad password');
$assert(StaffAuth::requiresMfa('owner'),'owner MFA required');
$assert(StaffAuth::requiresMfa('admin'),'admin MFA required');
$assert(!StaffAuth::requiresMfa('staff'),'staff MFA recommended not required');
$assert(!StaffAuth::canStartSession('admin',true,true,false),'reject missing admin MFA');
$assert(StaffAuth::canStartSession('admin',true,true,true),'accept completed admin auth');
$assert(!StaffAuth::canStartSession('staff',false,true,true),'reject disabled account');
$assert(!StaffAuth::canStartSession('staff',true,false,true),'reject bad credentials');
$assert(!StaffAuth::canStartSession('unknown',true,true,true),'reject unknown role');
try { StaffAuth::hashPassword('short'); $assert(false,'reject short password'); }
catch (InvalidArgumentException $e) {$assert(true,'reject short password');}
