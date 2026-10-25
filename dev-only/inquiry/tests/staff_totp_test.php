<?php
declare(strict_types=1);
require_once __DIR__.'/../StaffTotp.php';
$ok=static function(bool $yes,string $what): void {
    if(!$yes){fwrite(STDERR,"FAIL $what\n");exit(1);}
    echo "PASS $what\n";
};
// RFC 6238 SHA1 test secret "12345678901234567890", Base32 encoded.
$secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$ok(StaffTotp::code($secret, 1)==='287082','RFC 6238 59s 6-digit vector');
$ok(StaffTotp::verify($secret,'287082',59)===1,'accept expected code');
$ok(StaffTotp::verify($secret,'287082',59,1)===null,'reject replay');
$ok(StaffTotp::verify($secret,'287082',59,2)===null,'reject stale counter');
$ok(StaffTotp::verify($secret,'00000a',59)===null,'reject invalid code');
$generated=StaffTotp::generateSecret();
$ok(strlen($generated)===32,'20-byte secret encoded');
$ok(StaffTotp::verify($generated,StaffTotp::code($generated,100),3000)===100,'generated secret roundtrip');
try{StaffTotp::code('bad!',1);$ok(false,'reject invalid secret');}
catch(InvalidArgumentException $e){$ok(true,'reject invalid secret');}
