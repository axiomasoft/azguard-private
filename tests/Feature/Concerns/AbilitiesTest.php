<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Tests\Fixtures\Concerns\SubjectWorld;

/*
 * abilities() answers each permission as a single check would: the authority stage looks exact role patterns up and
 * matches only wildcards, as permissionSet() does, and every contribution is still qualified.
 */

beforeEach(fn () => SubjectWorld::seed());

const ABILITY_NAMES = ['orders.view', 'orders.update', 'orders.refund', 'users.delete', 'reports.export'];

it('answers abilities like single checks for exact role patterns, wildcard roles and wildcard grants', function (array $roles, array $grants, array $held): void {
    SubjectWorld::compile();
    $member = SubjectWorld::member();
    foreach ($roles as $role) {
        $member->grantRole($role);
    }
    foreach ($grants as $grant) {
        $member->grantPermission($grant);
    }

    $abilities = AzGuard::panel('admin')->for($member)->abilities(ABILITY_NAMES);
    $single = array_combine(ABILITY_NAMES, array_map(static fn (string $name): bool => $member->hasPermission($name), ABILITY_NAMES));

    expect($abilities)->toBe($single)
        ->and(array_keys(array_filter($abilities)))->toBe($held);
})->with([
    'exact role patterns' => [['manager'], [], ['orders.view', 'orders.update']],
    'a wildcard role' => [['support'], [], ['orders.view', 'orders.update', 'orders.refund']],
    'a wildcard grant beside an exact role' => [['manager'], ['users.*'], ['orders.view', 'orders.update', 'users.delete']],
    'nothing' => [[], [], []],
]);
