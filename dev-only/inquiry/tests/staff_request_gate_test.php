<?php
declare(strict_types=1);
require_once __DIR__.'/../StaffRequestGate.php';
function gateCheck(bool $condition,string $message): void {
 if (!$condition) {fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}
 echo 'PASS: '.$message.PHP_EOL;
}
$now=100000;
$owner=StaffSessionPolicy::issue(1,'owner',true,true,true,$now);
$admin=StaffSessionPolicy::issue(2,'admin',true,true,true,$now);
$staff=StaffSessionPolicy::issue(3,'staff',true,true,false,$now);
$o=['id'=>1,'role'=>'owner','active'=>true];
$a=['id'=>2,'role'=>'admin','active'=>true];
$s=['id'=>3,'role'=>'staff','active'=>true];
gateCheck(!StaffRequestGate::permitted(null,$o,'inquiry_view',$now),'missing session denied');
gateCheck(!StaffRequestGate::permitted($owner,null,'inquiry_view',$now),'missing DB identity denied');
gateCheck(StaffRequestGate::permitted($owner,$o,'staff_manage',$now),'owner may manage staff');
gateCheck(!StaffRequestGate::permitted($admin,$a,'staff_manage',$now),'admin cannot manage staff');
gateCheck(StaffRequestGate::permitted($admin,$a,'assign',$now),'admin may assign inquiry');
gateCheck(!StaffRequestGate::permitted($staff,$s,'inquiry_view',$now),'staff needs DB-backed assignment');
gateCheck(StaffRequestGate::permitted($staff,$s,'inquiry_view',$now,3),'assigned staff may view');
gateCheck(!StaffRequestGate::permitted($staff,$s,'inquiry_view',$now,4),'another staff assignment denied');
gateCheck(!StaffRequestGate::permitted($staff,['id'=>4,'role'=>'staff','active'=>true],'inquiry_view',$now,3),'mismatched DB identity denied');
gateCheck(!StaffRequestGate::permitted($owner,['id'=>1,'role'=>'staff','active'=>true],'staff_manage',$now),'role changed denied');
gateCheck(!StaffRequestGate::permitted($owner,['id'=>1,'role'=>'owner','active'=>false],'staff_manage',$now),'disabled account denied');
gateCheck(!StaffRequestGate::permitted($owner,$o,'staff_manage',$now+900),'expired idle session denied');
gateCheck(!StaffRequestGate::permitted($owner,$o,'unknown_action',$now),'unknown permission denied');
gateCheck(!StaffRequestGate::permitted($owner,['id'=>'1','role'=>'owner','active'=>true],'staff_manage',$now),'malformed DB record denied');
echo 'PASS: request gate offline tests; no HTTP, DB or real login'.PHP_EOL;
