<?php
declare(strict_types=1);
require_once __DIR__ . '/../InquiryValidator.php';
$base = [
    'inquiry_type'=>'estimate','client_type'=>'person','person_name'=>'テスト太郎',
    'email'=>'sample@example.com','service'=>'web','message'=>'架空の問い合わせ'
];
$assert = function(bool $pass, string $name): void {
    if (!$pass) { fwrite(STDERR, "FAIL $name\n"); exit(1); }
    echo "PASS $name\n";
};
$assert(InquiryValidator::normalize($base)['person_name']==='テスト太郎','valid form');
$cases = [
    'missing name'=>['person_name'=>''],
    'invalid email'=>['email'=>'not-an-email'],
    'invalid selection'=>['service'=>'unrecognized'],
    'long message'=>['message'=>str_repeat('a',2001)],
    'organization needed'=>['client_type'=>'corporate'],
    'invalid type'=>['email'=>['unexpected']],
    'control character'=>['message'=>"bad\x00text"],
];
foreach ($cases as $name=>$changes) {
    try { InquiryValidator::normalize(array_replace($base,$changes)); $assert(false,$name); }
    catch (InvalidArgumentException $e) { $assert(true,$name); }
}
echo "Validation tests passed; no DB or real customer data used.\n";
