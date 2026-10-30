<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Authorization\Internal\Pipeline\Stages\AuthorityStage;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tenancy\Internal\TenantMembershipRestriction;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;

class AttributionBrokenCondition implements GrantCondition
{
    public function __construct()
    {
        throw new RuntimeException('constructor');
    }

    public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
    {
        return true;
    }
}

class AttributionBrokenSource extends GeneratedSource {}

it('attributes a failing source after another source was read', function (): void {
    $broken = new AttributionBrokenSource(name: 'broken', read: fn () => throw new RuntimeException('read'));
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]), extra: [$broken]);
    $decision = $engine->decide($panel, $request);
    expect($decision->reason)->toBe(DecisionReason::SourceError)->and($decision->component)->toBe(AttributionBrokenSource::class);
});

it('attributes a condition constructor failure to the current condition across contributions', function (): void {
    $first = new class implements GrantCondition
    {
        public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
        {
            return true;
        }
    };
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant(), AuthorizationWorld::grant()]),
        fn (PanelBuilder $panel) => $panel->grantConditions([$first, AttributionBrokenCondition::class]));
    $decision = $engine->decide($panel, $request);
    expect($decision->reason)->toBe(DecisionReason::ConditionError)->and($decision->component)->toBe(AttributionBrokenCondition::class);
});

it('resolves membership through the scoped authorizer graph', function (): void {
    $count = 0;
    app()->bind(TenantMembershipRestriction::class, function ($app) use (&$count) {
        $count++;

        return new TenantMembershipRestriction($app);
    });
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]));
    $before = $count;
    $stage = app(AuthorityStage::class);
    $property = new ReflectionProperty(AuthorityStage::class, 'membership');
    expect($property->getValue($stage))->toBeInstanceOf(TenantMembershipRestriction::class)->and($count)->toBe($before + 1);
    $before = $count;
    $engine->decide($panel, $request);
    expect($count)->toBe($before);
    app()->forgetScopedInstances();
    app(Authorizer::class);
    expect($count)->toBeGreaterThan($before);
});

it('attributes a later contribution failure to its source', function (): void {
    $broken = new AttributionBrokenSource(name: 'broken', direct: [AuthorizationWorld::grant(panel: 'other')]);
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]), extra: [$broken]);
    $decision = $engine->decide($panel, $request);
    expect($decision->reason)->toBe(DecisionReason::SourceError)->and($decision->component)->toBe(AttributionBrokenSource::class);
});

it('attributes a condition failure on the second contribution', function (): void {
    $condition = new class implements GrantCondition
    {
        private int $calls = 0;

        public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('second');
            }

            return true;
        }
    };
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant(), AuthorizationWorld::grant()]),
        fn (PanelBuilder $panel) => $panel->grantConditions([$condition]));
    $decision = $engine->decide($panel, $request);
    expect($decision->reason)->toBe(DecisionReason::ConditionError)->and($decision->component)->toBe($condition::class);
});
