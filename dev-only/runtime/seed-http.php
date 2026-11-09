<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('HAYANEX_TEST_WRITE_ENABLE')!=='YES_DISPOSABLE_DB_ONLY')exit(2);
require '/source/dev-only/inquiry/StaffAuth.php';require '/source/dev-only/inquiry/StaffTotp.php';require '/source/dev-only/inquiry/StaffMfaCrypto.php';
$db=new PDO('mysql:host=localhost;dbname=hayanex_http_test;charset=utf8mb4',getenv('HAYANEX_TEST_DB_USER'),getenv('HAYANEX_TEST_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if((int)$db->query('SELECT COUNT(*) FROM inquiry_staff')->fetchColumn()!==0)throw new RuntimeException('Empty disposable database required');
$keys=[];foreach(file('/runtime/app.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){[$k,$v]=explode('=',$line,2);$keys[$k]=$v;}
$fixtures=[];
foreach(['owner'=>'owner','admin'=>'admin','staff-a'=>'staff','staff-b'=>'staff','inactive'=>'staff'] as $name=>$role){
 $password=bin2hex(random_bytes(20));$login='synthetic-'.$name;
 $q=$db->prepare('INSERT INTO inquiry_staff (staff_login,password_hash,staff_role,is_active) VALUES (?,?,?,?)');$q->execute([$login,StaffAuth::hashPassword($password),$role,$name==='inactive'?0:1]);$id=(int)$db->lastInsertId();
 $secret=StaffAuth::requiresMfa($role)?StaffTotp::generateSecret():null;
 if($secret){$q=$db->prepare('INSERT INTO inquiry_staff_mfa (staff_id,encrypted_totp_secret) VALUES (?,?)');$q->execute([$id,StaffMfaCrypto::encrypt($secret,base64_decode($keys['HAYANEX_DEV_MFA_KEY'],true),$id)]);}
 $fixtures[$name]=['id'=>$id,'login'=>$login,'password'=>$password,'secret'=>$secret];
}
file_put_contents('/runtime/fixtures.json',json_encode($fixtures,JSON_THROW_ON_ERROR));chmod('/runtime/fixtures.json',0600);
echo "PASS: synthetic accounts seeded (values suppressed)\n";
