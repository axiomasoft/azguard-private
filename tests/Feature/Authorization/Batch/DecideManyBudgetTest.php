<?php

declare(strict_types=1);

use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\StateRefresh;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\BatchPolicy;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

uses()->group('batch');

it('fences all 250 contexts and reuses raw sets with the configured refresh mode', function (StateRefresh $refresh, int $warmReads): void {
    app(StorageSchema::class)->create('default');
    $tenant = TenantRef::of('org', 'A');
    $definition = new StoreScope(fn (AssignmentScopeRef $ref): ResolvedAssignmentScope => new ResolvedAssignmentScope($ref, $tenant));
    $requests = $rows = [];

    for ($id = 1; $id <= 250; $id++) {
        $scope = AccessScope::in($tenant, AssignmentScopeRef::of('store', $id));
        $requests[] = DatabaseWorld::request()->inScope($scope);
        $rows[] = DatabaseWorld::row('permission', $scope);
    }
    DatabaseWorld::insert('permission', $rows);
    BatchPolicy::$calls = 0;
    [$engine] = CacheWorld::database(DatabaseSource::make(), $refresh, function (PanelBuilder $panel) use ($definition): void {
        $panel->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership))
            ->scopes(AssignmentScopePolicy::inherit($definition))->policies([PolicyBinding::for('documents.view', BatchPolicy::class)]);
    });
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    $set = $engine->decideMany($requests);
    expect($set)->toHaveCount(250)->and(BatchPolicy::$calls)->toBe(250)
        ->and($budget['state'])->toBe(1)->and($budget['grants'])->toBeLessThanOrEqual(6)
        // One snapshot for the set: the observed state keys the cache lookups, then the chunks of grants follow.
        ->and($budget['grants'])->toBeGreaterThan(0)->and(array_slice($budget['sequence'], 0, 2))->toBe(['panel_state', 'permission_grants'])
        ->and($budget['sequence'][array_key_last($budget['sequence'])])->toBe('role_grants');

    foreach ($set as $i => $decision) {
        expect($decision->allowed())->toBeTrue()->and($decision->scope->context->id())->toBe((string) ($i + 1));
    }
    $budget = CacheWorld::emptyBudget();
    $warm = $engine->decideMany($requests);
    expect($budget['state'])->toBe($warmReads)->and($budget['grants'])->toBe(0)->and(BatchPolicy::$calls)->toBe(500);

    foreach ($warm as $i => $decision) {
        expect($decision->allowed())->toBeTrue()->and($decision->state->equals($set->get($i)->state))->toBeTrue();
    }
    app()->forgetScopedInstances();
    $budget = CacheWorld::emptyBudget();
    expect($engine->decideMany($requests))->toHaveCount(250);
    expect($budget['state'])->toBe(1)->and($budget['grants'])->toBe(0)->and(BatchPolicy::$calls)->toBe(750);
})->with(['Check refresh' => [StateRefresh::Check, 1], 'Request refresh' => [StateRefresh::Request, 0]]);
