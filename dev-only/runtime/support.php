<?php
declare(strict_types=1);
// CLI-only fixture controls. No HTTP route and no arbitrary SQL interface.
if(PHP_SAPI!=='cli'||getenv('HAYANEX_TEST_WRITE_ENABLE')!=='YES_DISPOSABLE_DB_ONLY')exit(2);
require '/source/dev-only/inquiry/StaffTotp.php';require '/source/dev-only/inquiry/DevNotificationWorker.php';require '/source/dev-only/inquiry/StaffAuth.php';
$args=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$db=new PDO('mysql:host=localhost;dbname=hayanex_http_test;charset=utf8mb4',getenv('HAYANEX_TEST_DB_USER'),getenv('HAYANEX_TEST_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
if($db->query('SELECT DATABASE()')->fetchColumn()!=='hayanex_http_test')exit(2);
$fixtures=json_decode(file_get_contents('/runtime/fixtures.json'),true,512,JSON_THROW_ON_ERROR);
$result=[];
switch($args['op']??''){
 case 'audit-tamper':
  try{$db->exec("UPDATE inquiry_audit_events SET new_value='tampered'");$result=['denied'=>false];}catch(PDOException $e){$result=['denied'=>(int)($e->errorInfo[1]??0)===1142];}break;
 case 'counts':foreach(['inquiries','inquiry_requests','notification_outbox','inquiry_audit_events','dev_notification_deliveries','dev_staff_audit'] as $table)$result[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();break;
 case 'inquiries':$result=$db->query('SELECT id,receipt_no,status FROM inquiries ORDER BY id')->fetchAll();break;
 case 'reset-limiter':$db->exec('DELETE FROM staff_login_windows');break;
 case 'reset-mfa':$db->exec('UPDATE inquiry_staff_mfa SET last_accepted_counter=-1');break;
 case 'expire-session':
  $id=$args['id']??'';if(!is_string($id)||!preg_match('/^[A-Za-z0-9,-]{16,128}$/D',$id))exit(2);
  ini_set('session.save_path','/runtime/sessions');session_id($id);session_start();
  if(!isset($_SESSION['hayanex_staff_v1']))exit(2);
  if(($args['kind']??'')==='absolute'){$_SESSION['hayanex_staff_v1']['created_at']=time()-28801;}
  else{$_SESSION['hayanex_staff_v1']['last_seen_at']=time()-901;$_SESSION['hayanex_staff_v1']['created_at']=time()-1000;}
  session_write_close();break;
 case 'change-password':$q=$db->prepare('UPDATE inquiry_staff SET password_hash=? WHERE id=?');$q->execute([StaffAuth::hashPassword(bin2hex(random_bytes(24))),$fixtures['staff-a']['id']]);break;
 case 'worker':$result=['processed'=>(new DevNotificationWorker($db))->once((bool)($args['fail']??false))];break;
 case 'queue':$result=$db->query('SELECT id,status,attempts FROM notification_outbox ORDER BY id')->fetchAll();break;
 default:exit(2);
}
echo json_encode($result,JSON_THROW_ON_ERROR);
