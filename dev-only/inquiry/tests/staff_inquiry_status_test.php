<?php
declare(strict_types=1);
require_once __DIR__ . '/../StaffInquiryStatus.php';
$valid=['new','reviewing','in_progress','completed','on_hold'];
foreach ($valid as $status) {
    if (!StaffInquiryStatus::isValidStatus($status)) {
        fwrite(STDERR,"FAIL valid status: ".$status."\n"); exit(1);
    }
    echo "PASS valid status: ".$status."\n";
}
foreach (['','deleted','NEW','pending'] as $status) {
    if (StaffInquiryStatus::isValidStatus($status)) {
        fwrite(STDERR,"FAIL rejected status: ".$status."\n"); exit(1);
    }
    echo "PASS invalid status rejected\n";
}
echo "Status-value tests passed; no database used.\n";
