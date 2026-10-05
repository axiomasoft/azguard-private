<?php

require 'vendor/autoload.php';
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\TenantRef;

$scope = AccessScope::in(TenantRef::global());
$grant = Grant::of(PermissionPattern::of('admin', 'orders.view'), 'folder', $scope);
$decision = Decision::allow(DecisionReason::Granted, CodeStateToken::of('admin', 'build', 'fingerprint'), $scope, grants: [&$grant]);
$grant = new stdClass;
echo 'observed_grant_type='.get_debug_type($decision->grants[0]).PHP_EOL;
exit($decision->grants[0] instanceof Grant ? 0 : 1);
