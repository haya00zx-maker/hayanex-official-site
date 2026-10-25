<?php
declare(strict_types=1);
require_once __DIR__ . '/../StaffPermissions.php';
$checks=[
    ['owner','staff_manage',null,null,true],
    ['owner','audit_view',null,null,true],
    ['admin','assign',null,null,true],
    ['admin','staff_manage',null,null,false],
    ['admin','system_config',null,null,false],
    ['staff','inquiry_view',10,10,true],
    ['staff','inquiry_reply',10,11,false],
    ['staff','inquiry_update',10,null,false],
    ['staff','assign',10,10,false],
    ['unknown','inquiry_view',10,10,false],
    ['owner','unrecognized',null,null,false],
];
foreach ($checks as $i=>$row) {
    [$role,$action,$staffId,$assignedId,$expected]=$row;
    if (StaffPermissions::can($role,$action,$staffId,$assignedId)!==$expected) {
        fwrite(STDERR,'FAIL case '.($i+1)."\n");
        exit(1);
    }
    echo 'PASS case '.($i+1)."\n";
}
