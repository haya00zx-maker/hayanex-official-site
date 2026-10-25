<?php
declare(strict_types=1);
/**
 * Offline development test runner. Does not connect to databases, send mail,
 * serve HTTP, or access production. Execute from PHP CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$base=__DIR__;
$tests=[
    'request_guard_test.php',
    'staff_permissions_test.php',
    'staff_auth_test.php',
    'staff_totp_test.php',
    'staff_inquiry_status_test.php',
];
if (extension_loaded('mbstring')) {
    array_unshift($tests,'validator_test.php');
} else {
    echo "SKIP validator_test.php: mbstring missing\n";
}
if (!function_exists('exec') || !function_exists('escapeshellarg')) {
    fwrite(STDERR, "NOT RUN: PHP CLI subprocess functions are unavailable. Run the test files individually.\n");
    exit(2);
}
$failures=0;
foreach ($tests as $test) {
    $file=$base.'/'.$test;
    if (!is_file($file)) { fwrite(STDERR,"MISSING: $test\n"); $failures++; continue; }
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($file);
    $output=[]; $code=0;
    exec($command.' 2>&1',$output,$code);
    echo implode("\n",$output)."\n";
    echo ($code===0?'PASS ':'FAIL ').$test."\n";
    if($code!==0) $failures++;
}
echo $failures===0?"Completed offline checks; DB and HTTP integration remain untested.\n":"Offline failures: $failures\n";
exit($failures===0?0:1);
