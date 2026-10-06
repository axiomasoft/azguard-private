<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\RestrictionResult;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheSource;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Authorization\ScenarioGenerator;
use AzGuard\Tests\Fixtures\Authorization\WeekdaysCondition;
use AzGuard\Tests\Fixtures\Scopes\EligibilityExternalAdapter;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Carbon;

it('explains the identical decision on 200 seeded scenarios without a second source poll', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        $source = new GeneratedSource(direct: $s->flag() ? [$s->grant(expires: $s->expiry(($seed % 3) - 1))] : [], roles: $s->flag() ? [$s->role()] : []);
        $restriction = new RecordingRestriction(deny: $s->flag(), exempt: $s->flag());
        [$engine, $panel, $request] = $s->world([$source], fn (PanelBuilder $p) => $p->restrictions([$restriction]));
        $decision = $engine->decide($panel, $request);
        $reads = $source->grantReads;
        $explanation = $engine->explain($panel, $request);
        $this->assertEquals($decision, $explanation->decision(), "explain seed=$seed");
        expect($source->grantReads)->toBe($reads + 1)->and($explanation->now())->toEqual($s->now)
            ->and($explanation->scope())->toEqual($decision->scope)->and($explanation->state())->toEqual($decision->state);
    }
})->group('properties');

it('marks assignments and superadmin skipped for PolicyOnly and preserves Response details', function (): void {
    $source = new GeneratedSource(read: fn () => throw new RuntimeException('must not read'));
    [$engine, $panel, $request] = AuthorizationWorld::compile($source);
    RuntimePolicy::$result = Response::denyAsNotFound('hidden', 'policy-code');
    $explanation = $engine->explain($panel, AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy')));
    expect($explanation->decision()->reason)->toBe(DecisionReason::Policy)->and($source->grantReads)->toBe(0);
    $steps = collect($explanation->steps())->keyBy('stage');
    expect($steps['sources']['outcome'])->toBe('skipped')->and($steps['superadmin']['outcome'])->toBe('skipped')
        ->and($steps['policy']['detail'])->toBe(['message' => 'hidden', 'status' => 404, 'code' => 'policy-code'])
        ->and($steps['restriction']['outcome'])->toBe('skipped');
});

it('reports contribution origins expiry and the condition class that rejected a grant', function (): void {
    $source = new GeneratedSource(direct: [AuthorizationWorld::grant(expires: Carbon::now()->toDateTimeImmutable()), AuthorizationWorld::grant(fields: ['weekdays' => [0]])]);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $p) => $p->grantConditions([WeekdaysCondition::class]));
    $steps = $engine->explain($panel, $request)->steps();
    expect(array_column($steps, 'result'))->toContain('expired', 'condition_false');
    $condition = collect($steps)->firstWhere('stage', 'condition');
    expect($condition['component'])->toBe(WeekdaysCondition::class)->and($condition['outcome'])->toBe('deny');
    $contribution = collect($steps)->firstWhere('outcome', 'contribution');
    expect($contribution['detail']['origin'])->toBe('manual')->and($contribution['detail']['source'])->toBe('generated');
});

it('observes hooks once and marks later stages skipped after before denial', function (): void {
    $before = $after = 0;
    $source = new GeneratedSource;
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, function (PanelBuilder $p) use (&$before, &$after): void {
        $p->before(function () use (&$before): BeforeResult {
            $before++;

            return BeforeResult::Deny;
        })
            ->after(function () use (&$after): void {
                $after++;
            });
    });
    $explanation = $engine->explain($panel, $request);
    expect($explanation->decision()->reason)->toBe(DecisionReason::Hook)->and($before)->toBe(1)->and($after)->toBe(1)->and($source->grantReads)->toBe(0);
    foreach (['sources', 'superadmin', 'policy', 'authority', 'restriction'] as $stage) {
        expect(collect($explanation->steps())->firstWhere('stage', $stage)['outcome'])->toBe('skipped');
    }
});

it('shows exempt restrictions and retains a source error after an allowing source', function (): void {
    $s = new ScenarioGenerator(2);
    $source = new GeneratedSource(roles: [$s->role()]);
    $restriction = new RecordingRestriction(deny: true, exempt: true);
    [$engine, $panel, $request] = $s->world([$source], fn (PanelBuilder $p) => $p->restrictions([$restriction]));
    $explanation = $engine->explain($panel, $request);
    expect($explanation->decision()->allowed())->toBeTrue()->and(collect($explanation->steps())->firstWhere('result', 'exempt')['outcome'])->toBe('skipped');
    [$engine, $panel, $request] = $s->world([$source, new GeneratedSource(name: 'broken', read: fn () => throw new RuntimeException('source failure'))]);
    $explanation = $engine->explain($panel, $request);
    expect($explanation->decision()->reason)->toBe(DecisionReason::SourceError);
    $error = collect($explanation->steps())->firstWhere('outcome', 'error');
    expect($error['detail'])->toBe(['exception' => RuntimeException::class, 'message' => 'source failure'])->not->toHaveKey('trace');
});

it('redacts nested secret fields and their occurrences in exception messages without serializing the subject model', function (): void {
    $source = new GeneratedSource(direct: [AuthorizationWorld::grant(fields: ['api_TOKEN' => 'sensitive-value', 'nested' => ['password' => 'nested-pass'], 'department' => 'sales'])]);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $p) => $p->after(fn () => throw new RuntimeException('sensitive-value nested-pass')));
    $json = json_encode($engine->explain($panel, $request)->toArray(), JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('sensitive-value', 'nested-pass', 'department":"sales","is_root')
        ->toContain('[redacted]', 'department', 'sales');
});

it('does not publish cold explain reads into the permission cache but reuses a warm ordinary read', function (): void {
    $source = new CacheSource(direct: [AuthorizationWorld::grant()]);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $p) => $p->cache('array'));
    $engine->explain($panel, $request);
    $engine->explain($panel, $request);
    expect($source->grantReads)->toBe(2);
    $engine->decide($panel, $request);
    expect($source->grantReads)->toBe(3);
    $engine->explain($panel, $request);
    expect($source->grantReads)->toBe(3);
});

it('names a denied common context filter instead of reporting it skipped', function (): void {
    $s = new ScenarioGenerator(2);
    $adapter = new EligibilityExternalAdapter(fn () => false);
    [$engine, $panel, $request] = $s->world([new GeneratedSource(direct: [$s->grant($s->scope(true))])],
        fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(app(StoreScope::class))->accessAdapter('store', $adapter)), mode: 'inherit');
    $explanation = $engine->explain($panel, $request->inScope($s->scope(true)));
    expect($explanation->decision()->reason)->toBe(DecisionReason::AssignmentScopeIneligible);
    $filter = collect($explanation->steps())->firstWhere('stage', 'filter');
    expect($filter['component'])->toBe($adapter::class)->and($filter['outcome'])->toBe('deny')
        ->and($adapter->observed)->toHaveCount(1);
});

it('redacts a restriction reason containing a declared secret value', function (): void {
    $restriction = new class implements Restriction
    {
        public function key(): string
        {
            return 'secret-restriction';
        }

        public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
        {
            return true;
        }

        public function exemptsSuperAdmin(): bool
        {
            return false;
        }

        public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
        {
            return RestrictionResult::deny('blocked_sensitive_value');
        }
    };
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant(fields: ['api_token' => 'sensitive_value'])]), fn (PanelBuilder $p) => $p->restrictions([$restriction]));
    $explanation = $engine->explain($panel, $request);
    expect($explanation->decision()->reason)->toBe(DecisionReason::Restricted)
        ->and(json_encode($explanation->toArray(), JSON_THROW_ON_ERROR))->not->toContain('sensitive_value')->toContain('[redacted]');
});

it('marks undeclared condition and filter stages skipped and ignored role qualification denied', function (): void {
    $s = new ScenarioGenerator(2);
    [$engine, $panel, $request] = $s->world([new GeneratedSource(direct: [$s->grant()], roles: [$s->role(key: 'removed-role')])]);
    $explanation = $engine->explain($panel, $request);
    expect($explanation->decision()->allowed())->toBeTrue();
    $steps = collect($explanation->steps());
    expect($steps->firstWhere('stage', 'condition')['outcome'])->toBe('skipped')
        ->and($steps->firstWhere('stage', 'filter')['outcome'])->toBe('skipped')
        ->and($steps->firstWhere('result', 'unknown_role')['outcome'])->toBe('deny');
});

it('redacts secrets observed before a lazy direct or role contribution iterator fails', function (bool $role): void {
    $s = new ScenarioGenerator(2);
    $source = new class($role, $s) extends GeneratedSource
    {
        public function __construct(private bool $roleFailure, private ScenarioGenerator $scenario)
        {
            parent::__construct();
        }

        public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
        {
            if (! $this->roleFailure) {
                yield $this->scenario->grant(fields: ['api_token' => 'lazy-confidential-value']);

                throw new RuntimeException('Rejected value lazy-confidential-value');
            }
        }

        public function roleGrants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
        {
            if ($this->roleFailure) {
                yield $this->scenario->role(fields: ['credential' => 'lazy-confidential-value']);

                throw new RuntimeException('Rejected value lazy-confidential-value');
            }
        }
    };
    [$engine, $panel, $request] = $s->world([$source]);
    $explanation = $engine->explain($panel, $request);
    expect($explanation->decision()->reason)->toBe(DecisionReason::SourceError)
        ->and(json_encode($explanation->toArray(), JSON_THROW_ON_ERROR))->not->toContain('lazy-confidential-value')->toContain('[redacted]')
        ->and(collect($explanation->steps())->where('outcome', 'contribution'))->toHaveCount(1);
})->with([false, true]);

it('reports membership pass only when membership was evaluated', function (string $mode, string $outcome): void {
    $s = new ScenarioGenerator(2);
    [$engine, $panel, $request] = $s->world([new GeneratedSource(direct: $mode === 'granted' ? [$s->grant($s->scope(true))] : [])], mode: 'inherit');

    if ($mode === 'policy') {
        $request = AccessRequest::for($s->subject, PermissionKey::of($s->panel, 'orders.policy'));
    }
    $explanation = $engine->explain($panel, $request->inScope($s->scope(true)));
    expect(collect($explanation->steps())->firstWhere('stage', 'membership')['outcome'])->toBe($outcome);
})->with([['granted', 'pass'], ['policy', 'pass'], ['unqualified', 'skipped']]);

it('reports common and contribution filter exceptions as filter errors without a skipped backfill', function (bool $contributionOnly): void {
    $s = new ScenarioGenerator(2);
    $adapter = new EligibilityExternalAdapter(function ($ref, $runtime) use ($contributionOnly): bool {
        if (! $contributionOnly || $runtime->grant !== null) {
            throw new RuntimeException('filter failed');
        }

        return true;
    });
    [$engine, $panel, $request] = $s->world([new GeneratedSource(direct: [$s->grant($s->scope(true))])],
        fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(app(StoreScope::class))->accessAdapter('store', $adapter)), mode: 'inherit');
    $explanation = $engine->explain($panel, $request->inScope($s->scope(true)));
    expect($explanation->decision()->reason)->toBe(DecisionReason::AssignmentScopeFilterError);
    $filters = collect($explanation->steps())->where('stage', 'filter');
    expect($filters->where('outcome', 'error'))->toHaveCount(1)
        ->and($filters->where('outcome', 'skipped'))->toHaveCount(0)
        ->and($filters->firstWhere('outcome', 'error')['detail']['exception'])->toBe(RuntimeException::class)
        ->and($filters->firstWhere('outcome', 'error')['detail']['message'])->toBe('filter failed');
})->with([false, true]);
