<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\PermissionAuthority;

// mode × policy {true, null, false} × qualified contribution {true, false}; the boundary is ANDed outside.
dataset('authority table', [
    'policy / true / qualified' => [PermissionAuthority::Policy, true, true, true],
    'policy / true / unqualified' => [PermissionAuthority::Policy, true, false, true],
    'policy / null / qualified' => [PermissionAuthority::Policy, null, true, false],
    'policy / null / unqualified' => [PermissionAuthority::Policy, null, false, false],
    'policy / false / qualified' => [PermissionAuthority::Policy, false, true, false],
    'policy / false / unqualified' => [PermissionAuthority::Policy, false, false, false],
    'grants / true / qualified' => [PermissionAuthority::Grants, true, true, true],
    'grants / true / unqualified' => [PermissionAuthority::Grants, true, false, false],
    'grants / null / qualified' => [PermissionAuthority::Grants, null, true, true],
    'grants / null / unqualified' => [PermissionAuthority::Grants, null, false, false],
    'grants / false / qualified' => [PermissionAuthority::Grants, false, true, false],
    'grants / false / unqualified' => [PermissionAuthority::Grants, false, false, false],
]);

it('decides authority by the mode table', function (PermissionAuthority $mode, ?bool $policy, bool $qualified, bool $allows): void {
    expect($mode->allows($policy, $qualified))->toBe($allows);
})->with('authority table');

it('covers every mode in the table', function (): void {
    expect(array_map(static fn (PermissionAuthority $m): string => $m->value, PermissionAuthority::cases()))
        ->toBe(['policy', 'grants']);
});

it('reads and accepts assignments and trusts a super admin only in grants mode', function (PermissionAuthority $mode, bool $grants): void {
    expect($mode->readsAssignments())->toBe($grants)
        ->and($mode->acceptsAssignments())->toBe($grants)
        ->and($mode->superAdminIsAuthority())->toBe($grants);
})->with([
    'policy' => [PermissionAuthority::Policy, false],
    'grants' => [PermissionAuthority::Grants, true],
]);
