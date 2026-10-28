<?php
declare(strict_types=1);
require_once __DIR__.'/../StaffLoginRatePolicy.php';
function rateCheck(bool $ok,string $label):void{
 if(!$ok){fwrite(STDERR,'FAIL: '.$label.PHP_EOL);exit(1);}
 echo 'PASS: '.$label.PHP_EOL;
}
$t=100000;
rateCheck(StaffLoginRatePolicy::allowed(0,$t,$t),'initial attempt allowed');
rateCheck(StaffLoginRatePolicy::allowed(4,$t,$t+10),'four prior failures allowed');
rateCheck(!StaffLoginRatePolicy::allowed(5,$t,$t+10),'fifth failure locks attempts');
rateCheck(StaffLoginRatePolicy::retryAfter(5,$t,$t+10)===890,'remaining lock time');
rateCheck(!StaffLoginRatePolicy::allowed(5,$t,$t+899),'lock persists until expiry');
rateCheck(StaffLoginRatePolicy::allowed(5,$t,$t+900),'window expiry allows fresh attempt');
rateCheck(StaffLoginRatePolicy::retryAfter(5,$t,$t+900)===0,'expired window has no lock');
rateCheck(StaffLoginRatePolicy::failure(4,$t,$t+40)===[5,$t],'failures accumulate');
rateCheck(StaffLoginRatePolicy::failure(5,$t,$t+900)===[1,$t+900],'expiry resets counter on new failure');
rateCheck(!StaffLoginRatePolicy::allowed(-1,$t,$t),'negative count fail-closed');
rateCheck(!StaffLoginRatePolicy::allowed(0,$t,$t-1),'backwards clock fail-closed');
$threw=false;
try{StaffLoginRatePolicy::failure(-1,$t,$t);}catch(InvalidArgumentException $e){$threw=true;}
rateCheck($threw,'invalid state throws');
echo 'PASS: login rate policy offline only; DB atomic counters not yet implemented'.PHP_EOL;
