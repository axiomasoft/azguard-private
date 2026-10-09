<?php

declare(strict_types=1);

use AzGuard\Events\RoleGranted;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * fromOrigin() narrows changes and stored-grant lists to one partition and never authorization. A revocation in
 * one origin leaves an independent witness of another origin in force. A change inside a host transaction is
 * tentative: neither an event nor a later read takes it before the host commits.
 */

beforeEach(function (): void {
    CrmWorld::seed();
    ChangeWorld::panel();
});
afterEach(fn () => CrmWorld::resetRuntime());

it('narrows changes and lists to the origin and reads every origin for checks and roles', function (): void {
    $anna = User::query()->findOrFail(1);
    $manual = $anna->guard('crm')->inTenant(Organization::query()->findOrFail(1));
    $external = $manual->fromOrigin('external');
    $p1 = AssignmentScopeRef::of('crm.project', 5);

    expect($external)->not->toBe($manual)
        ->and($external->grantRole('caller', on: $p1)->applied())->toBeTrue()
        ->and(ChangeWorld::keys())->toContain('crm.organization:1|caller|1|crm.project:5|external')
        ->and($manual->roleGrants(on: $p1)->all())->toBe([])
        ->and($external->roleGrants(on: $p1)->map(static fn ($grant): string => $grant->origin())->all())->toBe(['external'])
        ->and($manual->hasRole('caller', on: $p1))->toBeTrue()
        ->and($manual->hasPermission(ClientPermission::Update, on: Client::query()->findOrFail(6)))->toBeTrue()
        ->and($manual->revokeRole('caller', on: $p1)->applied())->toBeFalse()
        ->and($external->revokeRole('caller', on: $p1)->applied())->toBeTrue()
        ->and($manual->hasRole('caller', on: $p1))->toBeFalse();
});

it('keeps an independent external witness in force when the manual grant is revoked', function (): void {
    $anna = User::query()->findOrFail(1);
    $manual = $anna->guard('crm')->inTenant(Organization::query()->findOrFail(1));
    $p1 = AssignmentScopeRef::of('crm.project', 5);
    $client = Client::query()->findOrFail(6);
    $manual->grantRole('caller', on: $p1);
    $manual->fromOrigin('external')->grantRole('caller', on: $p1);

    expect($manual->revokeRole('caller', on: $p1)->applied())->toBeTrue()
        ->and($manual->hasPermission(ClientPermission::Update, on: $client))->toBeTrue()
        ->and($manual->roleNames(on: $p1)->all())->toBe(['caller'])
        ->and($manual->fromOrigin('external')->revokeRole('caller', on: $p1)->applied())->toBeTrue()
        ->and($manual->hasPermission(ClientPermission::Update, on: $client))->toBeFalse();
});

it('gives no event and no later read of a change inside a host transaction that rolls back', function (): void {
    $anna = User::query()->findOrFail(1);
    $access = $anna->guard('crm')->inTenant(Organization::query()->findOrFail(1));
    $p1 = AssignmentScopeRef::of('crm.project', 5);
    $client = Client::query()->findOrFail(6);
    $events = [];
    Event::listen(RoleGranted::class, function (RoleGranted $event) use (&$events): void {
        $events[] = $event->eventId;
    });
    expect($access->hasPermission(ClientPermission::Update, on: $client))->toBeFalse();
    $connection = DB::connection(CrmWorld::storage()->connectionName());

    $connection->beginTransaction();
    $result = $access->grantRole('caller', on: $p1);
    $connection->rollBack();

    expect($result->applied())->toBeTrue()
        ->and($result->committed)->toBeFalse()
        ->and($events)->toBe([])
        ->and($access->hasPermission(ClientPermission::Update, on: $client))->toBeFalse()
        ->and($access->roleNames(on: $p1)->all())->toBe([])
        ->and($access->roleGrants(on: $p1)->all())->toBe([]);

    $committed = $access->grantRole('caller', on: $p1);

    expect($committed->committed)->toBeTrue()
        ->and($events)->toHaveCount(1)
        ->and($access->hasPermission(ClientPermission::Update, on: $client))->toBeTrue();
});
