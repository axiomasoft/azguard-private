<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Authorization\ScenarioGenerator;
use AzGuard\Tests\Fixtures\Authorization\WeekdaysCondition;
use Illuminate\Support\Carbon;

uses()->group('batch');

it('matches scalar decisions for 200 seeded ordered batches at frozen time', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        $mode = [null, 'inherit', 'isolated', 'required'][$seed % 4];
        $scope = $mode === null ? $s->scope(global: true) : $s->scope(true);
        $source = new GeneratedSource(
            direct: $s->flag() ? [$s->grant($scope, expires: $s->expiry(($seed % 3) - 1), fields: ['weekdays' => [(int) $s->now->format('N')]])] : [],
            roles: $s->flag() ? [$s->role($scope, key: 'reader', expires: $s->expiry(($seed % 5) - 2))] : [],
        );
        $restriction = new RecordingRestriction(deny: $s->flag());
        [$engine, $panel, $request, , $registry] = $s->world([$source], function (PanelBuilder $panel) use ($restriction): void {
            $panel->restrictions([$restriction])->grantConditions([WeekdaysCondition::class]);
        }, mode: $mode);
        $other = $s->panel === 'alpha' ? 'beta' : 'alpha';
        $requests = [
            $request->inScope($scope)->traced(),
            AccessRequest::for($s->subject, PermissionKey::of($s->panel, 'orders.policy'))->inScope($scope),
            AccessRequest::for($s->subject, PermissionKey::of($other, 'orders.view'))->inScope($scope)->traced(),
            AccessRequest::for($s->subject, PermissionKey::of($s->panel, 'orders.edit'))->inScope($scope),
            $request->inScope($scope)->traced(),
        ];
        RuntimePolicy::$result = $seed % 3 === 0 ? null : $seed % 3 === 1;
        $scalar = array_map(fn (AccessRequest $r): Decision => $engine->decide($registry->get($r->permission()->panel()), $r), $requests);
        $batch = $engine->decideMany($requests);
        expect($batch)->toHaveCount(count($requests));

        foreach ($scalar as $i => $expected) {
            $this->assertEquals($expected, $batch->get($i), "batch parity seed=$seed index=$i");
        }
    }
})->group('properties');

it('runs hooks policy and restrictions for every resource instance and captures one now', function (): void {
    $s = new ScenarioGenerator(2);
    $before = $after = 0;
    $times = [];
    $restriction = new RecordingRestriction;
    [$engine, , $request] = $s->world([new GeneratedSource], function (PanelBuilder $panel) use (&$before, &$after, $restriction): void {
        $panel->before(function () use (&$before): BeforeResult {
            $before++;
            Carbon::setTestNow(Carbon::now('UTC')->addDay());

            return BeforeResult::Continue;
        })->after(function () use (&$after): void {
            $after++;
        })->restrictions([$restriction]);
    });
    RuntimePolicy::$callback = function ($subject, $resource, $context) use (&$times): bool {
        $times[] = $context->now()->format('c');

        return $resource->allow;
    };
    $requests = [];

    foreach ([true, false, true] as $allow) {
        // Same identity and permission, different prospective resource state.
        $resource = (object) ['id' => 17, 'allow' => $allow];
        $requests[] = AccessRequest::for($request->subject(), PermissionKey::of($s->panel, 'orders.policy'))->on(null, $resource);
    }
    $captured = Carbon::now('UTC')->format('c');
    $batch = $engine->decideMany($requests);
    expect(array_map(fn (Decision $d): bool => $d->allowed(), iterator_to_array($batch)))->toBe([true, false, true])
        ->and($before)->toBe(3)->and($after)->toBe(3)->and(RuntimePolicy::$calls)->toBe(3)
        ->and($restriction->checks)->toBe(2)->and($times)->toBe([$captured, $captured, $captured]);
});

it('qualifies the grant condition separately for each prospective resource state', function (): void {
    $condition = new class implements GrantCondition
    {
        public int $calls = 0;

        public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
        {
            $this->calls++;

            return $request->resource()->allow;
        }
    };
    [$engine, , $request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]),
        fn (PanelBuilder $panel) => $panel->grantConditions([$condition]));
    $set = $engine->decideMany(array_map(fn (bool $allow) => $request->on(null, (object) ['id' => 19, 'allow' => $allow]), [true, false, true]));
    expect(array_map(fn (Decision $d): bool => $d->allowed(), iterator_to_array($set)))->toBe([true, false, true])
        ->and($condition->calls)->toBe(3);
});

it('maps a per-resource condition exception without suppressing adjacent successful checks', function (): void {
    $condition = new class implements GrantCondition
    {
        public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
        {
            if ($request->resource()->fail) {
                throw new RuntimeException('this resource condition failed');
            }

            return true;
        }
    };
    [$engine, , $request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]),
        fn (PanelBuilder $panel) => $panel->grantConditions([$condition]));
    $set = $engine->decideMany(array_map(fn (bool $fail) => $request->on(null, (object) ['id' => 19, 'fail' => $fail]), [false, true, false]));
    expect(array_map(fn (Decision $d): DecisionReason => $d->reason, iterator_to_array($set)))->toBe([
        DecisionReason::Granted, DecisionReason::ConditionError, DecisionReason::Granted,
    ]);
});
