<?php
declare(strict_types=1);
/**
 * Offline development test runner. Does not connect to databases, send mail,
 * serve HTTP, or access production. Execute from PHP CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$base=__DIR__;
$incomplete = false;
$tests=[
    'request_guard_test.php',
    'staff_permissions_test.php',
    'staff_auth_test.php',
    'staff_session_policy_test.php',
    'staff_login_flow_test.php',
    'staff_request_gate_test.php',
    'staff_http_guard_test.php',
    'staff_login_rate_policy_test.php',
    'staff_session_storage_test.php',
    'staff_totp_test.php',
    'staff_mfa_crypto_test.php',
    'staff_inquiry_status_test.php',
];
if (extension_loaded('mbstring')) {
    array_unshift($tests,'validator_test.php');
} else {
    fwrite(STDERR, "INCOMPLETE: validator_test.php requires mbstring; do not accept this run as a release gate.\n");
    $incomplete = true;
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
if ($failures > 0) { fwrite(STDERR, "FAIL: $failures offline test suite(s) failed.\n"); exit(1); }
if ($incomplete) { fwrite(STDERR, "INCOMPLETE: available tests passed, but required suite(s) were skipped.\n"); exit(2); }
echo "PASS: all offline suites passed. DB and HTTP integration remain untested.\n";
exit(0);
