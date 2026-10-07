<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\Cache\CachePolicy;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
    CachePolicy::$allow = true;
    CachePolicy::$calls = 0;
});

it('reevaluates policy subject and resource on a raw cache hit with the same DB token', function (string $change): void {
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $panel) => $panel->policies([PolicyBinding::for('documents.view', CachePolicy::class)]));
    $resource = (object) ['active' => true];
    $request = DatabaseWorld::request()->on(null, $resource);
    $first = $engine->decide($panel, $request);
    expect($first->allowed())->toBeTrue();
    match ($change) {
        'policy' => CachePolicy::$allow = false,
        'subject' => User::query()->whereKey(1)->toBase()->update(['department' => 'support']),
        'resource' => $resource->active = false,
    };
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    $second = $engine->decide($panel, $request);
    expect($second->reason)->toBe(DecisionReason::Policy)->and($second->allowed())->toBeFalse()
        ->and($second->state->equals($first->state))->toBeTrue()->and(CachePolicy::$calls)->toBe(2)
        ->and($budget['grants'])->toBe(0);
})->with(['policy', 'subject', 'resource']);

it('reevaluates grant conditions and restrictions after raw authority is cached', function (string $change): void {
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    $condition = new class implements GrantCondition
    {
        public bool $allow = true;

        public int $calls = 0;

        public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
        {
            $this->calls++;

            return $this->allow;
        }
    };
    $restriction = new RecordingRestriction;
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $panel) => $panel->grantConditions([$condition])->restrictions([$restriction]));
    $first = $engine->decide($panel, DatabaseWorld::request());
    expect($first->allowed())->toBeTrue();

    if ($change === 'condition') {
        $condition->allow = false;
    } else {
        $restriction->deny = true;
    }
    $second = $engine->decide($panel, DatabaseWorld::request());
    expect($second->reason)->toBe($change === 'condition' ? DecisionReason::NotGranted : DecisionReason::Restricted)
        ->and($second->state->equals($first->state))->toBeTrue()->and($condition->calls)->toBe(2);
})->with(['condition', 'restriction']);

it('reevaluates active membership while preserving the cached raw tenant grant and DB token', function (): void {
    $tenant = TenantRef::of('org', 'A');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', AccessScope::in($tenant))]);
    $membership = new Membership;
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $panel) => $panel->tenants(TenantPolicy::required(Organization::class)->requireMembership($membership)));
    $request = DatabaseWorld::request()->inTenant($tenant);
    $first = $engine->decide($panel, $request);
    expect($first->allowed())->toBeTrue();
    $membership->members = [];
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    $second = $engine->decide($panel, $request);
    expect($second->reason)->toBe(DecisionReason::Restricted)->and($second->component)->toBe('membership')
        ->and($second->state->equals($first->state))->toBeTrue()->and($membership->checks)->toBe(2)
        ->and($budget['grants'])->toBe(0);
});
