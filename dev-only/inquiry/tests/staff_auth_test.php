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

// Regression: no suffix truncation beyond bcrypt's 72-byte boundary.
$legacy=password_hash(str_repeat('a',72),PASSWORD_BCRYPT);
if(StaffAuth::verifyPassword(str_repeat('a',72).'suffix',$legacy))throw new RuntimeException('bcrypt truncation accepted');
echo "PASS reject legacy bcrypt long suffix\n";
if(defined('PASSWORD_ARGON2ID')) {
    $long=str_repeat('a',72).'correct-suffix';$hash=StaffAuth::hashPassword($long);
    if(!StaffAuth::verifyPassword($long,$hash)||StaffAuth::verifyPassword(str_repeat('a',72).'wrong-suffix',$hash))throw new RuntimeException('Long password mismatch');
    echo "PASS long password suffix is significant\n";
}
