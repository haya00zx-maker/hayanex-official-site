<?php
declare(strict_types=1);
// Deliberately unusable as a production application or generic proxy target.
if (getenv('HAYANEX_TEST_WRITE_ENABLE')!=='YES_DISPOSABLE_DB_ONLY'
    || !filter_var(getenv('HAYANEX_DEV_GATEWAY_PEER'),FILTER_VALIDATE_IP)
    || ($_SERVER['REMOTE_ADDR']??'')!==getenv('HAYANEX_DEV_GATEWAY_PEER')
    || strlen((string)getenv('HAYANEX_DEV_GATEWAY_KEY'))<32
    || !hash_equals((string)getenv('HAYANEX_DEV_GATEWAY_KEY'),(string)($_SERVER['HTTP_X_HAYANEX_DEV_TLS']??''))) {
    http_response_code(403);exit('非公開開発ゲートが必要です。');
}
$dsn=(string)getenv('HAYANEX_DEV_DSN');
if (!preg_match('/^mysql:host=(?:127\.0\.0\.1|localhost);dbname=hayanex_http_test;charset=utf8mb4$/D',$dsn)) {
    http_response_code(503);exit('隔離された検証DBの設定が必要です。');
}
$base=dirname(__DIR__).'/inquiry/';
foreach(['StaffSessionStorage','StaffLoginFlow','StaffHttpGuard','StaffRequestGate','StaffCredentialRepository','StaffLoginLimiter','StaffMfaVerifier','InquiryRepository','InquiryRequestGuard','InquiryRateLimiter','StaffInquiryList','StaffInquiryStatus','DevNotificationWorker'] as $component) require_once $base.$component.'.php';
date_default_timezone_set('Asia/Tokyo');
ini_set('display_errors','0');
header('Cache-Control: no-store, private');header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Content-Type: text/html; charset=UTF-8');
function devDb(): PDO {
    $db=new PDO((string)getenv('HAYANEX_DEV_DSN'),(string)getenv('HAYANEX_TEST_DB_USER'),(string)getenv('HAYANEX_TEST_DB_PASS'),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3,
    ]);
    $db->exec("SET time_zone='+09:00'");
    if($db->query('SELECT DATABASE()')->fetchColumn()!=='hayanex_http_test')throw new RuntimeException('DB mismatch');
    return $db;
}
function devSecret(string $name): string {
    $value=base64_decode((string)getenv($name),true);
    if(!is_string($value)||strlen($value)!==32)throw new RuntimeException('Private key missing');return $value;
}
function esc(mixed $s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
final class DevHttpError extends RuntimeException {}
function deny(int $status): never {throw new DevHttpError('拒否',$status);}
function csrf(): string {return $_SESSION['csrf']??=StaffHttpGuard::newCsrfToken();}
function hiddenCsrf(): string {return '<input type="hidden" name="csrf" value="'.esc(csrf()).'">';}
function submitted(string $key): string {if(!isset($_POST[$key])||!is_string($_POST[$key]))deny(400);return $_POST[$key];}
function postGuard(int $max=8192): void {
    $raw=file_get_contents('php://input',false,null,0,$max+1);
    if($raw===false||strlen($raw)>$max)deny(413);
    if(($_SERVER['HTTP_ORIGIN']??'')!==getenv('HAYANEX_DEV_ORIGIN'))deny(403);
    $received=isset($_POST['csrf'])&&is_string($_POST['csrf'])?$_POST['csrf']:null;
    if($max===8192) {
        if(!StaffHttpGuard::canProcessPost($_SERVER['REQUEST_METHOD'],true,csrf(),$received,$_SERVER['CONTENT_TYPE']??null,strlen($raw)))deny(403);
    } elseif($_SERVER['REQUEST_METHOD']!=='POST' || !StaffHttpGuard::validCsrf(csrf(),$received)
        || !preg_match('~^application/x-www-form-urlencoded(?:\s*;\s*charset=(?:utf-8|UTF-8))?$~D',trim($_SERVER['CONTENT_TYPE']??'')))deny(403);
}
function account(PDO $db,int $id,bool $lock=false): ?array {
    $q=$db->prepare('SELECT s.id,s.staff_role,s.is_active,s.password_hash,m.encrypted_totp_secret FROM inquiry_staff s LEFT JOIN inquiry_staff_mfa m ON m.staff_id=s.id WHERE s.id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);$r=$q->fetch();
    return $r?['id'=>(int)$r['id'],'role'=>$r['staff_role'],'active'=>(int)$r['is_active']===1,'password_stamp'=>hash('sha256',$r['password_hash']),'account_stamp'=>hash('sha256',$r['password_hash'].'|'.($r['encrypted_totp_secret']??''))]:null;
}
function identity(PDO $db): array {
    $s=StaffSessionStorage::current(time());$a=$s?account($db,$s['staff_id']):null;
    if(!StaffRequestGate::permitted($s,$a,'inquiry_view',time(),$s['staff_id']??null) || !isset($_SESSION['account_stamp']) || !hash_equals($_SESSION['account_stamp'],$a['account_stamp']??'')) {
        if($s)StaffSessionStorage::logout();deny(401);
    }
    StaffSessionStorage::authorize(time(),$a['active'],$a['role'],'inquiry_view',$s['staff_id']);return $s;
}
function permitted(PDO $db,array $s,string $action,?int $assigned=null): void {
    if(!StaffRequestGate::permitted($s,account($db,$s['staff_id']),$action,time(),$assigned))deny(403);
}
function finishLogin(array $pending,bool $mfa): never {
    $db=devDb();$a=account($db,$pending['staff_id']);
    if(!isset($pending['password_stamp'])||!hash_equals($pending['password_stamp'],$a['password_stamp']??''))deny(401);
    if($mfa && (!isset($pending['mfa_stamp'])||!hash_equals($pending['mfa_stamp'],$a['account_stamp']??'')))deny(401);
    StaffLoginFlow::complete($pending,time(),$a['active']??false,$a['role']??'', $mfa);
    StaffSessionStorage::login($pending['staff_id'],$a['role'],$a['active'],true,$mfa,time());$_SESSION['account_stamp']=$a['account_stamp'];csrf();
    header('Location: /staff',true,303);exit;
}
