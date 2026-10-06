<?php

declare(strict_types=1);

use AzGuard\Authorization\Cache\PermissionSetCache;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use Random\Engine\Mt19937;
use Random\Randomizer;

it('V04 preserves 100000 seeded distinct framed cache tuple digests', function (): void {
    $random = new Randomizer(new Mt19937(480004));
    $seen = [];
    for ($index = 0; $index < 100000; $index++) {
        $tenant = TenantRef::of('org.'.$random->getInt(0, 7), '00'.$random->getInt(0, 10000));
        $scopes = [AccessScope::in($tenant, AssignmentScopeRef::of('project', $random->getInt(0, 10000))), AccessScope::in($tenant)];
        $state = StateToken::of('store:'.$random->getInt(0, 9), 'admin', 'epoch:'.$random->getInt(0, 9), $random->getInt(0, 100), $random->getInt(0, 9), 'fp:'.$random->getInt(0, 9));
        $key = PermissionSetCache::key($state, SubjectRef::of('user', 'subject:'.$index), $tenant, $scopes, 'source:'.$random->getInt(0, 9), $index % 2 ? 'primary' : 'default', 'handle:'.$random->getInt(0, 9), 1);
        expect(isset($seen[$key]))->toBeFalse('seed 480004, tuple '.$index);
        $seen[$key] = true;
    }
    expect($seen)->toHaveCount(100000);
});

it('isolates every authority identity component while canonicalizing scope order', function (): void {
    $tenant = TenantRef::of('org', '007');
    $scopes = [AccessScope::in($tenant), AccessScope::in($tenant, AssignmentScopeRef::of('project', '3'))];
    $tuple = [StateToken::of('store', 'admin', 'epoch', 1, 2, 'fp'), SubjectRef::of('user', '1'), $tenant, $scopes, 'source', 'primary', 'authority', 2];
    $baseline = PermissionSetCache::key(...$tuple);
    $reordered = $tuple;
    $reordered[3] = array_reverse($scopes);
    expect(PermissionSetCache::key(...$reordered))->toBe($baseline);
    $changes = [
        [0, StateToken::of('other', 'admin', 'epoch', 1, 2, 'fp')],
        [0, StateToken::of('store', 'cabinet', 'epoch', 1, 2, 'fp')],
        [0, StateToken::of('store', 'admin', 'other', 1, 2, 'fp')],
        [0, StateToken::of('store', 'admin', 'epoch', 2, 2, 'fp')],
        [0, StateToken::of('store', 'admin', 'epoch', 1, 3, 'fp')],
        [0, StateToken::of('store', 'admin', 'epoch', 1, 2, 'other')],
        [0, CodeStateToken::of('admin', 'build', 'fp')],
        [1, SubjectRef::of('vendor', '1')], [1, SubjectRef::of('user', '01')],
        [2, TenantRef::of('company', '007')], [2, TenantRef::of('org', 7)], [2, TenantRef::global()],
        [3, [AccessScope::in($tenant)]], [4, 'other'], [5, 'default'], [6, 'other'], [7, 3],
    ];
    foreach ($changes as [$position, $value]) {
        $changed = $tuple;
        $changed[$position] = $value;
        expect(PermissionSetCache::key(...$changed))->not->toBe($baseline);
    }
    $code = $tuple;
    $code[0] = CodeStateToken::of('admin', 'build:a', 'fp');
    $otherBuild = $code;
    $otherBuild[0] = CodeStateToken::of('admin', 'build:b', 'fp');
    expect(PermissionSetCache::key(...$code))->not->toBe(PermissionSetCache::key(...$otherBuild));
});
