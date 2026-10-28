<?php
declare(strict_types=1);
require_once __DIR__.'/../StaffHttpGuard.php';
function assertHttp(bool $ok,string $label):void{
 if(!$ok){fwrite(STDERR,'FAIL: '.$label.PHP_EOL);exit(1);}
 echo 'PASS: '.$label.PHP_EOL;
}
$a=StaffHttpGuard::newCsrfToken();
$b=StaffHttpGuard::newCsrfToken();
assertHttp(strlen($a)===64 && ctype_xdigit($a),'cryptographic token format');
assertHttp($a!==$b,'distinct tokens');
assertHttp(StaffHttpGuard::validCsrf($a,$a),'matching token accepted');
assertHttp(!StaffHttpGuard::validCsrf($a,$b),'mismatched token rejected');
assertHttp(!StaffHttpGuard::validCsrf($a,null),'missing token rejected');
assertHttp(!StaffHttpGuard::validCsrf($a,'bad'),'malformed token rejected');
$ok=fn($method,$tls,$token,$type,$size)=>StaffHttpGuard::canProcessPost($method,$tls,$a,$token,$type,$size);
assertHttp($ok('POST',true,$a,'application/x-www-form-urlencoded',256),'valid secured form request');
assertHttp(!$ok('GET',true,$a,'application/x-www-form-urlencoded',256),'GET rejected');
assertHttp(!$ok('POST',false,$a,'application/x-www-form-urlencoded',256),'non HTTPS rejected');
assertHttp(!$ok('POST',true,$b,'application/x-www-form-urlencoded',256),'wrong CSRF rejected');
assertHttp(!$ok('POST',true,$a,'application/json',256),'unexpected content type rejected');
assertHttp(!$ok('POST',true,$a,'application/x-www-form-urlencoded',8193),'oversized request rejected');
assertHttp(!$ok('POST',true,$a,'application/x-www-form-urlencoded',-1),'negative request size rejected');
echo 'PASS: HTTP guard pure offline test; no server requests performed'.PHP_EOL;
