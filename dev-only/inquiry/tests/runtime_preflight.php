<?php
declare(strict_types=1);
/**
 * CLI-only, no-network diagnostic for the isolated inquiry development runtime.
 * Does not connect to a database or read environment secrets.
 * Exit 0: required runtime features available; exit 2: incomplete.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(2); }
$minimum = 80100;
$requirements = [
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'mbstring' => extension_loaded('mbstring'),
    'sodium' => extension_loaded('sodium'),
    'json' => extension_loaded('json'),
];
$ok = PHP_VERSION_ID >= $minimum;
echo 'PHP version: '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.' (required: >= 8.1)'.PHP_EOL;
echo 'PHP version check: '.($ok ? 'PASS' : 'NOT READY').PHP_EOL;
foreach ($requirements as $name => $available) {
    echo $name.': '.($available ? 'PASS' : 'NOT READY').PHP_EOL;
    if (!$available) $ok = false;
}
echo 'Development runtime readiness: '.($ok ? 'PASS' : 'INCOMPLETE').PHP_EOL;
echo 'NOTE: no DB connection, HTTP request, mail sending, or security integration test performed.'.PHP_EOL;
exit($ok ? 0 : 2);
