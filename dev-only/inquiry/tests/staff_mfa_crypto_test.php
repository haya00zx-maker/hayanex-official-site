<?php
declare(strict_types=1);
/** Offline encryption tests only: no DB, no file keys, no HTTP. */
require_once __DIR__.'/../StaffMfaCrypto.php';
require_once __DIR__.'/../StaffTotp.php';
function checkMfa(bool $condition,string $name):void{
    if (!$condition) { fwrite(STDERR,"FAIL: ".$name.PHP_EOL);exit(1); }
    echo "PASS: ".$name.PHP_EOL;
}
if (!extension_loaded('sodium')) { fwrite(STDERR,"FAIL: sodium missing".PHP_EOL);exit(1); }
$key=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$secret=StaffTotp::generateSecret();
$encrypted=StaffMfaCrypto::encrypt($secret,$key,17);
checkMfa(!str_contains($encrypted,$secret),'secret is not stored in plaintext');
checkMfa(StaffMfaCrypto::decrypt($encrypted,$key,17)===$secret,'roundtrip decryption for same staff');
checkMfa($encrypted!==StaffMfaCrypto::encrypt($secret,$key,17),'nonce produces distinct ciphertext');
foreach ([
    ['wrong key',static fn()=>StaffMfaCrypto::decrypt($encrypted,random_bytes(32),17)],
    ['wrong account',static fn()=>StaffMfaCrypto::decrypt($encrypted,$key,18)],
    ['malformed ciphertext',static fn()=>StaffMfaCrypto::decrypt('sodium-v1:broken',$key,17)],
    ['short key',static fn()=>StaffMfaCrypto::encrypt($secret,'short',17)],
] as [$label,$attempt]) {
    $rejected=false;
    try { $attempt(); } catch (Throwable $e) { $rejected=true; }
    checkMfa($rejected,'reject '.$label);
}
echo 'PASS: MFA encryption offline tests completed; no real keys used'.PHP_EOL;
