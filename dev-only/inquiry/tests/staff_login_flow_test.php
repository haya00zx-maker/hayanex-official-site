<?php
declare(strict_types=1);
require_once __DIR__.'/../StaffAuth.php';
require_once __DIR__.'/../StaffLoginFlow.php';

function checkLogin(bool $ok,string $label): void {
    if (!$ok) {fwrite(STDERR,'FAIL: '.$label.PHP_EOL);exit(1);}
    echo 'PASS: '.$label.PHP_EOL;
}
function loginRejected(callable $fn,string $label): void {
    try {$fn();checkLogin(false,$label);}
    catch(RuntimeException $e){checkLogin(true,$label);}
}
$now=100000;
checkLogin(StaffLoginFlow::afterPassword(null,$now)===null,'unverified password denied');
checkLogin(StaffLoginFlow::afterPassword(['staff_id'=>1,'role'=>'owner','mfa_required'=>false],$now)===null,'inconsistent owner MFA rejected');
$owner=StaffLoginFlow::afterPassword(['staff_id'=>1,'role'=>'owner','mfa_required'=>true],$now);
$staff=StaffLoginFlow::afterPassword(['staff_id'=>3,'role'=>'staff','mfa_required'=>false],$now);
checkLogin(is_array($owner)&&!isset($owner['created_at']),'password phase does not issue session');
checkLogin(StaffLoginFlow::pendingValid($owner,$now+299),'pending password valid within 5 minutes');
checkLogin(!StaffLoginFlow::pendingValid($owner,$now+300),'pending password expires at 5 minutes');
loginRejected(fn()=>StaffLoginFlow::complete($owner,$now,true,'owner',false),'owner blocked without MFA');
loginRejected(fn()=>StaffLoginFlow::complete($owner,$now,false,'owner',true),'disabled owner denied');
loginRejected(fn()=>StaffLoginFlow::complete($owner,$now,true,'admin',true),'changed role denied');
loginRejected(fn()=>StaffLoginFlow::complete($owner,$now+300,true,'owner',true),'expired pending challenge denied');
$session=StaffLoginFlow::complete($owner,$now+1,true,'owner',true);
checkLogin($session['staff_id']===1 && $session['mfa_verified']===true,'owner permitted only after MFA');
$session=StaffLoginFlow::complete($staff,$now+1,true,'staff',false);
checkLogin($session['staff_id']===3 && $session['mfa_verified']===false,'staff may complete without mandatory MFA');
checkLogin(StaffLoginFlow::afterPassword(['staff_id'=>'1','role'=>'staff','mfa_required'=>false],$now)===null,'malformed identity rejected');
echo 'PASS: login-flow offline suite; no HTTP route or session cookie'.PHP_EOL;
