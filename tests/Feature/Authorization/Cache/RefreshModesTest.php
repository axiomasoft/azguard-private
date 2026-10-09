<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\StateRefresh;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheSource;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
});

it('a cold read encloses the state and both capabilities in one snapshot', function (): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make());
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue()
        ->and($budget['sequence'])->toBe(['panel_state', 'panel_state', 'permission_grants', 'role_grants']);
});

it('refreshes a warm revision once per request or once per check', function (StateRefresh $refresh, int $reads): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), $refresh);
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    app()->forgetScopedInstances();
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    for ($check = 0; $check < 10; $check++) {
        expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    }
    expect($budget['state'])->toBe($reads)->and($budget['grants'])->toBe(0);
})->with([[StateRefresh::Request, 1], [StateRefresh::Check, 10]]);

it('V18 observes a committed revoke in a new Primary request or the next Primary check', function (StateRefresh $refresh): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), $refresh);
    $request = DatabaseWorld::request();
    $before = $engine->decide($panel, $request);
    expect($before->allowed())->toBeTrue();

    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
        $mutation->table('permission_grants')->where('panel', 'admin')->delete();
        $mutation->touch('admin');
    });

    if ($refresh === StateRefresh::Request) {
        app()->forgetScopedInstances();
    }

    $after = $engine->decide($panel, $request);
    expect($after->reason)->toBe(DecisionReason::NotGranted)->and($after->allowed())->toBeFalse()
        ->and($after->state->version)->toBeGreaterThan($before->state->version);
})->with([StateRefresh::Request, StateRefresh::Check]);

it('promotes a newer revision discovered by a request-mode cold miss and clears old raw sets', function (): void {
    User::query()->insert(['id' => 2, 'department' => 'sales']);
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make());
    $request = DatabaseWorld::request();
    $before = $engine->decide($panel, $request);
    expect($before->allowed())->toBeTrue();

    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
        $mutation->table('permission_grants')->where('panel', 'admin')->delete();
        $mutation->touch('admin');
    });
    $otherSubject = AccessRequest::for(SubjectRef::of('user', 2), $request->permission());
    $miss = $engine->decide($panel, $otherSubject);
    expect($miss->reason)->toBe(DecisionReason::NotGranted)
        ->and($miss->state->version)->toBeGreaterThan($before->state->version);

    $after = $engine->decide($panel, $request);
    expect($after->reason)->toBe(DecisionReason::NotGranted)->and($after->allowed())->toBeFalse()
        ->and($after->state->equals($miss->state))->toBeTrue();

    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    expect($engine->decide($panel, $request)->allowed())->toBeFalse()
        ->and($budget['state'])->toBe(0)->and($budget['grants'])->toBe(0);
});

it('promotes a panel-wide DB revision discovered by a cold tenant miss before reusing another tenant set', function (): void {
    $tenantA = TenantRef::of('org', 'A');
    $tenantB = TenantRef::of('org', 'B');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', AccessScope::in($tenantA))]);
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $panel) => $panel->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership)));
    $requestA = DatabaseWorld::request()->inTenant($tenantA);
    $requestB = DatabaseWorld::request()->inTenant($tenantB);
    $before = $engine->decide($panel, $requestA);
    expect($before->allowed())->toBeTrue();

    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation) use ($tenantA): void {
        $mutation->table('permission_grants')->where('panel', 'admin')->where('tenant_key', $tenantA->key())->delete();
        $mutation->touch('admin');
    });
    $miss = $engine->decide($panel, $requestB);
    expect($miss->reason)->toBe(DecisionReason::NotGranted)
        ->and($miss->state->version)->toBeGreaterThan($before->state->version);
    $after = $engine->decide($panel, $requestA);
    expect($after->reason)->toBe(DecisionReason::NotGranted)->and($after->allowed())->toBeFalse()
        ->and($after->state->equals($miss->state))->toBeTrue();

    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    expect($engine->decide($panel, $requestA)->allowed())->toBeFalse()
        ->and($budget['state'])->toBe(0)->and($budget['grants'])->toBe(0);
});

it('keeps generic fenced source revisions partitioned by tenant despite another tenant sharing an old version', function (): void {
    $source = new class extends CacheSource implements FencesReads
    {
        public array $revisions = ['A' => 7, 'B' => 7];

        public array $stateReads = ['A' => 0, 'B' => 0];

        public function state(Panel $panel, TenantRef $tenant): StateToken
        {
            $this->stateReads[$tenant->id()]++;

            return StateToken::of('partitioned', $panel->id(), 'epoch', $this->revisions[$tenant->id()], 1, 'fixture');
        }
    };
    $source->reuse = Volatility::Stable;
    $source->read = static function (SubjectRef $subject, array $scopes, EvaluationContext $context) use ($source): iterable {
        if ($context->scope()->tenant->id() === 'A' && $source->revisions['A'] === 7) {
            yield Grant::of(PermissionPattern::of('admin', 'orders.view'), 'generated', $context->scope());
        }
    };
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel->cache('array')->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership)));
    $requestA = $request->inTenant(TenantRef::of('org', 'A'));
    $requestB = $request->inTenant(TenantRef::of('org', 'B'));
    expect($engine->decide($panel, $requestA)->allowed())->toBeTrue();

    // A's former version 7 set remains persistent while its new partition reports version 3.
    app()->forgetScopedInstances();
    $source->revisions['A'] = 3;
    expect($engine->decide($panel, $requestA)->reason)->toBe(DecisionReason::NotGranted)
        ->and($engine->decide($panel, $requestB)->reason)->toBe(DecisionReason::NotGranted);
    $reads = $source->grantReads;
    expect($engine->decide($panel, $requestA)->reason)->toBe(DecisionReason::NotGranted)
        ->and($source->grantReads)->toBe($reads)->and($source->stateReads)->toBe(['A' => 4, 'B' => 2]);
});
