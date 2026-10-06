<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheSource;
use AzGuard\Tests\Fixtures\Authorization\DepartmentCondition;
use AzGuard\Tests\Fixtures\Authorization\FailingSource;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Authorization\ScenarioGenerator;
use AzGuard\Tests\Fixtures\Authorization\ScenarioPermission;
use AzGuard\Tests\Fixtures\Authorization\WeekdaysCondition;
use Illuminate\Support\Carbon;

uses()->group('properties');

it('P1 adding unconditional authority never reduces an unchanged decision including admin exemptions', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        $admin = $s->flag();
        $exempt = $s->flag();
        $source = new GeneratedSource(direct: $s->flag() ? [$s->grant()] : [], roles: $admin ? [$s->role()] : []);
        $restriction = new RecordingRestriction(deny: $s->flag(), exempt: $exempt);
        [$engine, $panel, $request] = $s->world([$source], fn (PanelBuilder $p) => $p->restrictions([$restriction]));
        $before = $engine->decide($panel, $request)->allowed();
        $source->direct[] = $s->grant(fields: ['nonce' => $seed]);
        $after = $engine->decide($panel, $request)->allowed();
        expect(! $before || $after)->toBeTrue("P1 seed=$seed");
        expect($after)->toBe(! $restriction->deny || ($admin && $exempt), "P1 positive control seed=$seed");
    }
});

it('P3 isolates panels and rejects a foreign RoleKey before local role lookup', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        $other = $s->panel === 'alpha' ? 'beta' : 'alpha';
        $own = new GeneratedSource(direct: $s->flag() ? [$s->grant()] : []);
        $foreign = new GeneratedSource(name: 'foreign');
        [$engine, $panel, $request, , $registry] = $s->world([$own], otherSource: $foreign);
        $first = $engine->decide($panel, $request);
        $foreign->direct = [$s->grant(panel: $other, source: 'foreign')];
        $foreign->roles = [$s->role(panel: $other, source: 'foreign')];
        $otherRequest = AccessRequest::for($s->subject, PermissionKey::of($other, 'orders.view'));
        expect($engine->decide($registry->get($other), $otherRequest)->allowed())->toBeTrue("P3 other seed=$seed");
        $second = $engine->decide($panel, $request);
        expect($second->allowed())->toBe($first->allowed(), "P3 isolation seed=$seed");
        expect($second->state->equals($first->state))->toBeTrue("P3 state seed=$seed");
        $own->roles = [$s->role(panel: $other)];
        expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SourceError, "P3 forged role seed=$seed");
    }
});

it('P5 identical inputs state now and source responses produce the same decision', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        $offset = ($seed % 3) - 1;
        $source = new GeneratedSource(direct: [$s->grant(expires: $s->expiry($offset), fields: ['weekdays' => [(int) $s->now->format('N')]])]);
        [$engine, $panel, $request] = $s->world([$source], fn (PanelBuilder $p) => $p->grantConditions([WeekdaysCondition::class]));
        $first = $engine->decide($panel, $request);
        $second = $engine->decide($panel, $request);
        expect($second->allowed())->toBe($offset > 0, "P5 seed=$seed")->toBe($first->allowed());
        expect($second->reason)->toBe($first->reason)->and($second->grants)->toEqual($first->grants);
        expect($second->state->equals($first->state))->toBeTrue();
    }
});

it('P7 expiry at now denies direct and role contributions including repeated reads', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        $role = $s->flag();
        $expires = $s->expiry($seed % 7);
        $source = new CacheSource(name: 'expiry-'.$seed, direct: $role ? [] : [$s->grant(source: 'expiry-'.$seed, expires: $expires)], roles: $role ? [$s->role(source: 'expiry-'.$seed, expires: $expires)] : []);
        [$engine, $panel, $request] = $s->world([$source], fn (PanelBuilder $p) => $p->cache('array'));
        Carbon::setTestNow($expires->modify('-1 second'));
        $this->assertTrue($engine->decide($panel, $request)->allowed(), "P7 before seed=$seed");
        $this->assertTrue($engine->decide($panel, $request)->allowed(), "P7 warm seed=$seed");
        expect($source->grantReads)->toBe(1)->and($source->roleReads)->toBe(1);
        foreach ([$expires, $expires->modify('+1 second')] as $now) {
            Carbon::setTestNow($now);
            $this->assertFalse($engine->decide($panel, $request)->allowed(), "P7 at/after seed=$seed");
        }
    }
});

it('P8 errors at every evaluated step fail closed despite valid direct and admin authority', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        // Exercise all five error boundaries for every generated scenario, with passing controls.
        foreach (['before', 'source', 'policy', 'condition', 'restriction'] as $step) {
            RuntimePolicy::$callback = null;
            $condition = new class implements GrantCondition
            {
                public bool $throws = false;

                public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
                {
                    if ($this->throws) {
                        throw new RuntimeException('generated condition failure');
                    }

                    return true;
                }
            };
            $restriction = new RecordingRestriction;
            $source = new GeneratedSource(direct: [$s->grant()], roles: [$s->role()]);
            $fail = false;
            [$engine, $panel, $request] = $s->world([$source], function (PanelBuilder $p) use ($step, &$fail, $condition, $restriction): void {
                $p->policies([PolicyBinding::for('orders.view', RuntimePolicy::class)])
                    ->grantConditions([$condition])->restrictions([$restriction]);
                $p->before(function () use ($step, &$fail): BeforeResult {
                    if ($step === 'before' && $fail) {
                        throw new RuntimeException('generated before failure');
                    }

                    return BeforeResult::Continue;
                });
            });
            expect($engine->decide($panel, $request)->allowed())->toBeTrue("P8 control $step seed=$seed");
            $fail = true;
            match ($step) {
                'source' => $source->read = fn () => throw new RuntimeException('generated source failure'),
                'policy' => RuntimePolicy::$result = 'invalid policy response',
                'condition' => $condition->throws = true,
                'restriction' => $restriction->error = ['key', 'applies', 'check'][$seed % 3],
                default => null,
            };
            $expected = match ($step) {
                'before' => DecisionReason::HookError,
                'source' => DecisionReason::SourceError,
                'policy' => DecisionReason::PolicyError,
                'condition' => DecisionReason::ConditionError,
                'restriction' => DecisionReason::RestrictionError,
            };
            $decision = $engine->decide($panel, $request);
            $this->assertFalse($decision->allowed(), "P8 $step seed=$seed");
            expect($decision->reason)->toBe($expected);

            if ($step === 'policy') {
                RuntimePolicy::$callback = fn () => throw new RuntimeException('generated policy failure');
                $policyRequest = AccessRequest::for($s->subject, PermissionKey::of($s->panel, 'orders.policy'));
                expect($engine->decide($panel, $policyRequest)->reason)->toBe(DecisionReason::PolicyError);
            }
            RuntimePolicy::$result = true;
        }
        RuntimePolicy::$callback = null;
    }
});

it('P10 moving raw grants and roles between sources preserves qualification', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        $fields = ['weekdays' => [$s->flag() ? (int) $s->now->format('N') : 0]];
        $expires = $s->expiry(($seed % 3) - 1);
        $role = $s->flag();
        $mode = [null, 'inherit', 'isolated', 'required'][$seed % 4];
        $scope = $mode === null ? $s->scope(global: true) : $s->scope(true);
        $outcomes = [];
        foreach (['left', 'right'] as $name) {
            $source = new GeneratedSource(name: $name,
                direct: $role ? [] : [$s->grant($scope, source: $name, expires: $expires, fields: $fields)],
                roles: $role ? [$s->role($scope, source: $name, key: 'reader', expires: $expires, fields: $fields)] : []);
            [$engine, $panel, $request] = $s->world([$source], fn (PanelBuilder $p) => $p->grantConditions([WeekdaysCondition::class]), mode: $mode);
            $request = $request->inScope($scope);
            $decision = $engine->decide($panel, $request);
            expect($decision->allowed())->toBe($expires > $s->now && $fields['weekdays'][0] !== 0, "P10 control seed=$seed");
            $outcomes[] = [$decision->allowed(), $decision->reason];
        }
        expect($outcomes[1])->toBe($outcomes[0], "P10 seed=$seed");
    }
});

it('P11 presentation prefixes do not change enum or full name decisions', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        $allowed = $s->flag();
        $source = new GeneratedSource(direct: $allowed ? [Grant::of(PermissionPattern::of($s->panel, 'orders.present'), 'generated', $s->scope(global: true))] : []);
        $results = [];
        foreach ([false, 'front'.$seed, 'back'.$seed] as $prefix) {
            $panelNumber = 0;
            [$engine, , , $resolver] = $s->world([$source], function (PanelBuilder $p) use ($prefix, &$panelNumber): void {
                $p->resourcePrefix($prefix === false ? false : $prefix.(++$panelNumber));
            });
            foreach ([ScenarioPermission::View, $s->panel.':orders.present'] as $permission) {
                $resolved = $resolver->resolve($s->subject, $permission, $s->panel);
                $decision = $engine->decide($resolved['panel'], AccessRequest::for($s->subject, $resolved['key']));
                expect($decision->allowed())->toBe($allowed, "P11 seed=$seed");
                $results[] = $decision->reason;
            }
        }
        expect(array_unique(array_map(fn (DecisionReason $reason): string => $reason->value, $results)))->toHaveCount(1, "P11 seed=$seed");
    }
});

it('P12 all source permutations preserve denial errors and admin exemption', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        $error = $s->flag();
        $exempt = $s->flag();
        $scope = $s->scope($s->flag());
        $sources = [
            new GeneratedSource(name: 'ordinary', direct: [$s->grant($scope, source: 'ordinary')]),
            new GeneratedSource(name: 'admin-source', roles: [$s->role($scope, source: 'admin-source', key: 'tenant-admin')]),
            $error ? new FailingSource(name: 'third') : new GeneratedSource(name: 'third'),
        ];
        $outcomes = [];
        foreach ($s->permutations($sources) as $order) {
            [$engine, $panel, $request] = $s->world($order, fn (PanelBuilder $p) => $p->restrictions([new RecordingRestriction(deny: true, exempt: $exempt)]), mode: 'inherit');
            $request = $request->inScope($scope);
            $decision = $engine->decide($panel, $request);
            expect($decision->allowed())->toBe(! $error && $exempt, "P12 seed=$seed");

            if ($error) {
                expect($decision->reason)->toBe(DecisionReason::SourceError);
            }
            $outcomes[] = $decision->reason->value;
        }
        expect(array_unique($outcomes))->toHaveCount(1, "P12 seed=$seed");
    }
});

it('P15 two partial grants cannot combine their condition fields into a witness', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        $day = (int) $s->now->format('N');
        $source = new GeneratedSource(direct: [
            $s->grant(fields: ['weekdays' => [$day], 'department' => 'other-'.$seed]),
            $s->grant(fields: ['weekdays' => [0], 'department' => 'sales']),
        ]);

        if ($s->flag()) {
            $source->direct = array_reverse($source->direct);
        }
        [$engine, $panel, $request] = $s->world([$source], fn (PanelBuilder $p) => $p->grantConditions([WeekdaysCondition::class, DepartmentCondition::class]));
        $request = AccessRequest::for(SubjectRef::of('user', 1), $request->permission());
        expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted, "P15 seed=$seed");
        $source->direct[] = $s->grant(fields: ['weekdays' => [$day], 'department' => 'sales']);
        expect($engine->decide($panel, $request)->allowed())->toBeTrue("P15 whole witness seed=$seed");
    }
});
