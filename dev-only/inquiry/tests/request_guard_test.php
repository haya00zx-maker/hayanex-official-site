<?php
declare(strict_types=1);
require_once __DIR__ . '/../InquiryRequestGuard.php';
$assert = static function(bool $ok, string $label): void {
    if (!$ok) { fwrite(STDERR,"FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
};
$token=InquiryRequestGuard::issueToken();
$assert(strlen($token)===64,'random token length');
$assert(InquiryRequestGuard::verifyToken($token,$token),'valid token');
$assert(!InquiryRequestGuard::verifyToken($token, InquiryRequestGuard::issueToken()),'reject mismatched token');
$assert(!InquiryRequestGuard::verifyToken('bad',$token),'reject invalid token format');
$secret=str_repeat('a',32);
$key=InquiryRequestGuard::deriveRequestKey($token,$secret);
$assert(strlen($key)===64,'derived idempotency key length');
$assert(hash_equals($key,InquiryRequestGuard::deriveRequestKey($token,$secret)),'same token same request key');
try {
    InquiryRequestGuard::deriveRequestKey($token,'short');
    $assert(false,'reject short secret');
} catch (InvalidArgumentException $e) { $assert(true,'reject short secret'); }
