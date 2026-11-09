<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('HAYANEX_TEST_WRITE_ENABLE')!=='YES_DISPOSABLE_DB_ONLY')exit(2);
require_once __DIR__.'/../StaffLoginLimiter.php';
$db=new PDO('mysql:host=localhost;dbname=hayanex_http_test;charset=utf8mb4',getenv('HAYANEX_TEST_DB_USER'),getenv('HAYANEX_TEST_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($db->query('SELECT DATABASE()')->fetchColumn()!=='hayanex_http_test')exit(2);
$values=[];foreach(file('/runtime/app.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){[$key,$value]=explode('=',$line,2);$values[$key]=$value;}
$limiter=new StaffLoginLimiter($db,base64_decode($values['HAYANEX_DEV_HMAC_KEY'],true));
function verifyLocal(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;}
$db->exec('DELETE FROM staff_login_windows');
for($i=0;$i<5;$i++){
 $result=$limiter->attempt($i%2===0?'synthetic-owner':'SYNTHETIC-OWNER','synthetic-source-'.$i,fn()=>['ok'=>false]);verifyLocal(!$result['limited'],'account failure '.($i+1));
}
$result=$limiter->attempt('Synthetic-Owner','another-source',fn()=>throw new RuntimeException('Must not authenticate'));
verifyLocal($result['limited'],'DB collation variants cannot bypass account limit across sources');
$db->exec('DELETE FROM staff_login_windows');
for($i=0;$i<5;$i++)$limiter->attempt('unknown-'.$i,'one-source',fn()=>['ok'=>false]);
verifyLocal($limiter->attempt('another-unknown','one-source',fn()=>['ok'=>true])['limited'],'source limit spans different unknown accounts');
$db->exec('UPDATE staff_login_windows SET window_started=UNIX_TIMESTAMP()-900');
verifyLocal($limiter->attempt('another-unknown','one-source',fn()=>['ok'=>true])['ok'],'expired window permits authentication');
$db->exec('DELETE FROM staff_login_windows');
$thrown=false;try{$limiter->attempt('test','test',fn()=>throw new RuntimeException('Simulated internal error'));}catch(RuntimeException $e){$thrown=true;}
verifyLocal($thrown&&!$db->inTransaction()&&(int)$db->query('SELECT COUNT(*) FROM staff_login_windows')->fetchColumn()===0,'internal error rolls back limiter, no authentication result');
require_once __DIR__.'/../StaffMfaVerifier.php';
$fixture=json_decode(file_get_contents('/runtime/fixtures.json'),true,512,JSON_THROW_ON_ERROR)['owner'];
$q=$db->prepare('SELECT password_hash FROM inquiry_staff WHERE id=?');$q->execute([$fixture['id']]);$passwordHash=$q->fetchColumn();
$pending=StaffLoginFlow::afterPassword(['staff_id'=>$fixture['id'],'role'=>'owner','mfa_required'=>true],time());$pending['password_stamp']=hash('sha256',$passwordHash);
$verifier=new StaffMfaVerifier($db,base64_decode($values['HAYANEX_DEV_MFA_KEY'],true));
$db->beginTransaction();
$db->prepare('UPDATE inquiry_staff_mfa SET last_accepted_counter=-1 WHERE staff_id=?')->execute([$fixture['id']]);
$stamp=$verifier->verify($pending,StaffTotp::code($fixture['secret'],intdiv(time(),30)),time());
verifyLocal(is_string($stamp)&&preg_match('/^[a-f0-9]{64}$/D',$stamp)===1,'MFA returns credential binding with consumed counter');$db->rollBack();
$db->beginTransaction();$db->prepare('UPDATE inquiry_staff_mfa SET last_accepted_counter=-1 WHERE staff_id=?')->execute([$fixture['id']]);
$pending['password_stamp']=str_repeat('0',64);
verifyLocal($verifier->verify($pending,StaffTotp::code($fixture['secret'],intdiv(time(),30)),time())===null,'password change invalidates pending MFA');$db->rollBack();
