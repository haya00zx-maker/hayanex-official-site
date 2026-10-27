<?php
declare(strict_types=1);
require_once __DIR__.'/../StaffAuth.php';
require_once __DIR__.'/../StaffPermissions.php';
require_once __DIR__.'/../StaffSessionPolicy.php';
function checkSession(bool $ok,string $label): void {
    if (!$ok) { fwrite(STDERR,'FAIL: '.$label.PHP_EOL); exit(1); }
    echo 'PASS: '.$label.PHP_EOL;
}
$now=100000;
$owner=StaffSessionPolicy::issue(1,'owner',true,true,true,$now);
$admin=StaffSessionPolicy::issue(2,'admin',true,true,true,$now);
$staff=StaffSessionPolicy::issue(3,'staff',true,true,false,$now);
checkSession(StaffSessionPolicy::valid($owner,$now),'owner active session');
checkSession(StaffSessionPolicy::valid($staff,$now),'staff active session without mandatory MFA');
try { StaffSessionPolicy::issue(1,'owner',true,true,false,$now); checkSession(false,'owner without MFA'); }
catch(RuntimeException $e){ checkSession(true,'reject owner without MFA'); }
try { StaffSessionPolicy::issue(2,'admin',false,true,true,$now); checkSession(false,'disabled admin'); }
catch(RuntimeException $e){ checkSession(true,'reject disabled admin'); }
checkSession(!StaffSessionPolicy::valid($owner,$now+900),'idle timeout');
checkSession(StaffSessionPolicy::valid($owner,$now+899),'inside idle timeout');
$touched=StaffSessionPolicy::touched($owner,$now+500);
checkSession(StaffSessionPolicy::valid($touched,$now+1000),'touch renews idle clock');
$touched['created_at']=$now-28800;
checkSession(!StaffSessionPolicy::valid($touched,$now),'absolute timeout');
$tampered=$owner;$tampered['role']='unknown';
checkSession(!StaffSessionPolicy::valid($tampered,$now),'reject unknown session role');
$tampered=$owner;$tampered['mfa_verified']=false;
checkSession(!StaffSessionPolicy::valid($tampered,$now),'reject owner without session MFA');
checkSession(StaffSessionPolicy::authorize($owner,$now,true,'owner','staff_manage'),'owner may manage');
checkSession(!StaffSessionPolicy::authorize($admin,$now,true,'admin','staff_manage'),'admin may not manage staff');
checkSession(!StaffSessionPolicy::authorize($owner,$now,false,'owner','staff_manage'),'disabled account denied');
checkSession(!StaffSessionPolicy::authorize($owner,$now,true,'staff','staff_manage'),'changed DB role denied');
checkSession(StaffSessionPolicy::authorize($staff,$now,true,'staff','inquiry_view',3),'assigned staff may view');
checkSession(!StaffSessionPolicy::authorize($staff,$now,true,'staff','inquiry_view',4),'unassigned staff denied');
echo "PASS: session-policy offline suite (no HTTP, no DB, no cookies)".PHP_EOL;
