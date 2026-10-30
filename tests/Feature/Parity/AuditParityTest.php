<?php

declare(strict_types=1);

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\Contracts\AssignmentScopeAccessAdapter;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Tests\Fixtures\Visibility\AuditAutomaticRole;
use AzGuard\Tests\Fixtures\Visibility\AuditGlobalAutomaticRole;
use AzGuard\Tests\Fixtures\Visibility\ParityGenerator;
use AzGuard\Tests\Fixtures\Visibility\VisibilityBefore;
use AzGuard\Tests\Fixtures\Visibility\VisibilityCondition;
use AzGuard\Tests\Fixtures\Visibility\VisibilityPolicy;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope;
use AzGuard\Tests\Fixtures\Visibility\VisibilityRestriction;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use AzGuard\Tests\Modules\Scopes\Fixtures\Membership;
use AzGuard\Tests\Modules\Scopes\Fixtures\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';
require_once __DIR__.'/../../Fixtures/Visibility/ParityGenerator.php';

class AuditParityVeto extends VisibilityPolicy
{
    #[Decides('orders.view')]
    public function view(?Model $user, ?Model $resource): ?bool
    {
        return parent::view($user, $resource);
    }
}

beforeEach(fn () => W::seed());
afterEach(function (): void {
    AuditAutomaticRole::$enabled = false;
    W::reset();
});

it('compares scalar batch and exact visibility against generated fixture facts', function (): void {
    foreach (ParityGenerator::seeds() as $seed) {
        try {
            $facts = new ParityGenerator($seed);
            VisibilityProject::query()->delete();
            VisibilityProject::query()->insert($facts->rows);
            W::$tenanted = $facts->tenant;
            $source = $facts->source();
            AuditAutomaticRole::$enabled = $facts->automatic;
            [$visibility, $panel, $engine] = W::compile($source, function (PanelBuilder $p) use ($facts): void {
                $p->grantConditions([VisibilityCondition::class])->roles([AuditAutomaticRole::class]);

                if ($facts->tenant && ! $facts->member) {
                    $p->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership([])));
                }

                if ($facts->before) {
                    $p->before([VisibilityBefore::class]);
                }

                if ($facts->restriction) {
                    $p->restrictions([VisibilityRestriction::class]);
                }

                if ($facts->veto) {
                    $p->policies([PolicyBinding::for('orders.view', AuditParityVeto::class)]);
                }
            }, tenant: $facts->tenant);
            $records = VisibilityProject::query()->orderBy('id')->get();
            $requests = [];
            $expected = [];
            foreach ($records as $i => $record) {
                // Exact list is requested for tenant A; its scalar and batch inputs use that same tenant.
                if ($facts->tenant && $facts->rows[$i]['org'] !== 'A') {
                    continue;
                }
                $request = AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $record);
                $requests[] = $request;

                if ($facts->allows($facts->rows[$i])) {
                    $expected[] = $record->id;
                }
            }
            for ($round = 0; $round < 2; $round++) {
                $batch = $engine->decideMany($requests);
                foreach ($requests as $i => $request) {
                    $id = $request->resource()->getKey();
                    $want = in_array($id, $expected, true);
                    $scalar = $engine->decide($panel, $request);
                    $many = $batch->get($i);
                    $message = "seed=$seed row=$id round=$round; reproduce: AZGUARD_PROPERTY_SEED=$seed vendor/bin/pest tests/Feature/Parity/AuditParityTest.php";
                    $this->assertNull($scalar->failure(), $message.' scalar failure: '.$scalar->reason->value);
                    $this->assertNull($many->failure(), $message.' batch failure: '.$many->reason->value);
                    $this->assertSame($want, $scalar->allowed(), $message.' scalar');
                    $this->assertSame($want, $many->allowed(), $message.' batch');
                }
                $query = $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view', $facts->tenant ? W::scope() : null);
                $this->assertSame($expected, $query->orderBy('id')->pluck('id')->all(), "seed=$seed round=$round exact visibility; reproduce: AZGUARD_PROPERTY_SEED=$seed vendor/bin/pest tests/Feature/Parity/AuditParityTest.php");
            }
        } catch (Throwable $error) {
            fwrite(STDERR, "Parity seed=$seed; reproduce: AZGUARD_PROPERTY_SEED=$seed vendor/bin/pest tests/Feature/Parity/AuditParityTest.php\n");

            throw $error;
        }
    }
});

it('refuses an unsupported sibling without changing the caller query even alongside a grant', function (): void {
    $bad = new VisibilitySource(name: 'unsupported');
    $bad->selection = static fn () => null;
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant()]), extra: [$bad]);
    $query = VisibilityProject::query()->where('id', '>', 1);
    $before = [$query->toSql(), $query->getBindings()];

    try {
        $visibility->visibleTo($panel, $query, W::subject(), 'orders.view');
        $this->fail('Unsupported selection must not return a list.');
    } catch (VisibilityNotSupportedException $error) {
        $this->assertStringContainsString('unsupported_selection', $error->getMessage());
        $this->assertSame($before, [$query->toSql(), $query->getBindings()]);
    }
});

it('keeps binary and numeric contribution fields distinct in scalar batch and exact scope eligibility', function (mixed $first, mixed $second): void {
    $adapter = new class implements AssignmentScopeAccessAdapter
    {
        private function accepted(AssignmentScopeRuntime $runtime): bool
        {
            return $runtime->grant === null || ($runtime->grant->fields()['marker'] ?? null) === 1.0;
        }

        public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool
        {
            return $this->accepted($runtime);
        }

        public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array
        {
            return array_fill_keys(array_map(static fn (AssignmentScopeRef $ref): string => $ref->key(), $refs), $this->accepted($runtime));
        }

        public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder
        {
            return $this->accepted($runtime) ? $contextQuery : $contextQuery->whereRaw('1 = 0');
        }
    };
    W::$projectScope = new VisibilityProjectScope;
    $rejected = $first === 1.0 ? $second : $first;
    $source = new VisibilitySource(direct: [W::grant(1, ['marker' => $rejected])]);
    [$visibility, $panel, $engine] = W::compile($source, fn (PanelBuilder $p) => $p->plugins([ScopesPlugin::inherit(W::$projectScope)->accessAdapter('project', $adapter)]));
    $record = VisibilityProject::query()->findOrFail(1);
    $request = AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $record);
    expect($engine->decide($panel, $request)->allowed())->toBeFalse()
        ->and($engine->decideMany([$request])->get(0)->allowed())->toBeFalse()
        ->and($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->count())->toBe(0);
    $source->direct = [W::grant(1, ['marker' => $first]), W::grant(1, ['marker' => $second])];
    for ($round = 0; $round < 2; $round++) {
        $scalar = $engine->decide($panel, $request);
        $batch = $engine->decideMany([$request, $request]);
        expect($scalar->failure())->toBeNull()->and($scalar->allowed())->toBeTrue()
            ->and($batch->get(0)->failure())->toBeNull()->and($batch->get(0)->allowed())->toBeTrue()
            ->and($batch->get(1)->failure())->toBeNull()->and($batch->get(1)->allowed())->toBeTrue()
            ->and($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->pluck('id')->all())->toBe([1]);
    }
})->with([[1, 1.0], ["\xFF\x00", 1.0], [1.0, 1], [1.0, "\xFF\x00"]]);

it('tracks authority generations across all entry points with a shared cache', function (): void {
    ParityGenerator::cacheGenerations('array');
});

it('checks generated facts independently in cold memo and shared array cache lanes', function (): void {
    ParityGenerator::cachedOracle('array');
});

it('validates and selects inclusive reproducible seed ranges', function (): void {
    $original = [];
    foreach (['AZGUARD_PROPERTY_SEED', 'AZGUARD_PARITY_SEEDS', 'AZGUARD_FULL_SEEDS'] as $name) {
        $original[$name] = getenv($name);
        putenv($name);
    }

    try {
        expect(ParityGenerator::seeds())->toBe([1, 2]);
        putenv('AZGUARD_PARITY_SEEDS=1-50');
        expect(ParityGenerator::seeds())->toBe(range(1, 50));
        putenv('AZGUARD_PARITY_SEEDS=3-5');
        expect(ParityGenerator::seeds())->toBe([3, 4, 5]);
        putenv('AZGUARD_PARITY_SEEDS=1,2');
        expect(ParityGenerator::seeds())->toBe([1, 2]);
        putenv('AZGUARD_PARITY_SEEDS=2,4-5');
        expect(ParityGenerator::seeds())->toBe([2, 4, 5]);
        putenv('AZGUARD_PROPERTY_SEED=7');
        expect(ParityGenerator::seeds())->toBe([7]);
        putenv('AZGUARD_PROPERTY_SEED');
        foreach (['0', '51', '5-3', '1-51', '1-2x', '', '1,', ',2', '1,,2', '1,51'] as $invalid) {
            putenv('AZGUARD_PARITY_SEEDS='.$invalid);
            expect(fn () => ParityGenerator::seeds())->toThrow(InvalidArgumentException::class);
        }
    } finally {
        foreach ($original as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
    }
});

it('applies global automatic roles only when the tenant allowlist admits them', function (bool $allowGlobal): void {
    AuditAutomaticRole::$enabled = true;
    [$visibility, $panel, $engine] = W::compile(configure: fn (PanelBuilder $p) => $p
        ->roles([AuditGlobalAutomaticRole::class])
        ->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership)
            ->allowGlobalRoles($allowGlobal ? [AuditGlobalAutomaticRole::class] : [])), tenant: true);
    $requests = [];
    foreach (VisibilityProject::query()->where('org', 'A')->orderBy('id')->get() as $record) {
        $requests[] = AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $record);
    }
    $set = $engine->decideMany($requests);
    foreach ($requests as $index => $request) {
        $expected = $allowGlobal && in_array($request->resource()->getKey(), [1, 2, 3], true);
        $scalar = $engine->decide($panel, $request);
        expect($scalar->failure())->toBeNull()->and($set->get($index)->failure())->toBeNull()
            ->and($scalar->allowed())->toBe($expected)->and($set->get($index)->allowed())->toBe($expected);
    }
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view', W::scope())->orderBy('id')->pluck('id')->all())
        ->toBe($allowGlobal ? [1, 2, 3] : []);
})->with([true, false]);
