<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Tests\Fixtures\Authorization\ScenarioGenerator;
use AzGuard\Tests\Fixtures\Visibility\ParityGenerator;
use AzGuard\Tests\Fixtures\Visibility\ParityScenario;
use AzGuard\Tests\Fixtures\Visibility\VisibilityBefore;
use AzGuard\Tests\Fixtures\Visibility\VisibilityCondition;
use AzGuard\Tests\Fixtures\Visibility\VisibilityPolicy;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope;
use AzGuard\Tests\Fixtures\Visibility\VisibilityRestriction;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';
require_once __DIR__.'/../../Fixtures/Visibility/ParityGenerator.php';

class ParityVetoPolicy extends VisibilityPolicy
{
    #[Decides('orders.view')]
    public function view(?Model $user, ?Model $resource): ?bool
    {
        return parent::view($user, $resource);
    }
}

class ParityResourceRestriction extends VisibilityRestriction
{
    public function key(): string
    {
        return 'parity-resource';
    }

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
    {
        return $request->resource() !== null;
    }
}

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('P14 matches every scalar allow id and count on 100 reproducible seeds under one snapshot and decision clock', function (): void {
    $projects = VisibilityProject::query()->orderBy('id')->get();
    for ($seed = 1; $seed <= ScenarioGenerator::parityCount(100); $seed++) {
        $next = $seed;
        $draw = static function (int $max) use (&$next): int {
            $next = ($next * 1664525 + 1013904223) & 0xFFFFFFFF;

            return ($next >> 8) % $max;
        };
        $direct = $roles = [];
        for ($i = 0; $i < 12; $i++) {
            $scope = $draw(5) ?: null;
            $city = [[], ['city' => 'Paris'], ['city' => 'Rome'], ['city' => null]][$draw(4)];
            $expiry = $draw(3) === 0 ? new DateTimeImmutable('2026-10-06 12:00:00 UTC') : null;

            if ($draw(2) === 0) {
                $direct[] = W::grant($scope, $city, $expiry);
            } else {
                $roles[] = W::role($scope, $city, expiry: $expiry);
            }
        }
        $scenario = new ParityScenario($seed, records: $projects->all(), sources: ['generated'], contributions: $direct, roles: $roles);
        $compileScenario = static function (ParityScenario $candidate) use ($seed): array {
            $candidateSource = new VisibilitySource(
                direct: $candidate->sources === [] ? [] : $candidate->contributions,
                roles: $candidate->sources === [] ? [] : $candidate->roles,
            );

            return W::compile($candidateSource, function (PanelBuilder $p) use ($seed): void {
                $p->policies([PolicyBinding::for('orders.view', ParityVetoPolicy::class)])
                    ->grantConditions([VisibilityCondition::class]);

                if ($seed % 2 === 0) {
                    $p->before([VisibilityBefore::class]);
                }

                if ($seed % 3 === 0) {
                    $p->restrictions([VisibilityRestriction::class]);
                }
            });
        };
        [$visibility, $panel, $authorizer] = $compileScenario($scenario);
        foreach (['orders.view', 'orders.policy'] as $permission) {
            $ids = [];
            $states = [];
            foreach ($projects as $resource) {
                $decision = $authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', $permission))->on(null, $resource));
                $states[] = $decision->state;

                if ($decision->allowed()) {
                    $ids[] = $resource->id;
                }
            }
            $query = $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), $permission);
            $actualIds = (clone $query)->orderBy('id')->pluck('id')->all();
            $actualCount = $query->count();
            $summary = "seed=$seed action=$permission; reproduce: AZGUARD_PROPERTY_SEED=$seed";

            if ($actualIds !== $ids || $actualCount !== count($ids)) {
                $shrunk = ParityGenerator::shrink($scenario, static function (ParityScenario $candidate) use ($compileScenario, $permission): bool {
                    [$vis, $pan, $auth] = $compileScenario($candidate);
                    $expectedIds = [];
                    $recordIds = [];
                    foreach ($candidate->records as $rec) {
                        $recordIds[] = $rec->id;

                        if ($auth->decide($pan, AccessRequest::for(W::subject(), PermissionKey::of('admin', $permission))->on(null, $rec))->allowed()) {
                            $expectedIds[] = $rec->id;
                        }
                    }
                    $q = $vis->visibleTo($pan, VisibilityProject::query()->whereKey($recordIds), W::subject(), $permission);

                    return (clone $q)->orderBy('id')->pluck('id')->all() !== $expectedIds || $q->count() !== count($expectedIds);
                });
                $summary = sprintf(
                    'seed=%d action=%s shrunk(records=%d,sources=%d,contributions=%d,roles=%d); reproduce: AZGUARD_PROPERTY_SEED=%d',
                    $seed,
                    $permission,
                    count($shrunk->records),
                    count($shrunk->sources),
                    count($shrunk->contributions),
                    count($shrunk->roles),
                    $seed,
                );
            }

            $this->assertSame($ids, $actualIds, $summary);
            $this->assertSame(count($ids), $actualCount, 'count '.$summary);
            foreach ($states as $state) {
                expect($state->equals($states[0]))->toBeTrue();
            }
        }
    }
});

it('P14 gives each denial layer a positive control and an independent literal denial', function (string $layer): void {
    $source = new VisibilitySource(direct: [W::grant(1), W::grant(2)], roles: [W::role(1)]);
    [$visibility, $panel] = W::compile($source);
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe([1, 2]);
    // One alias has one definition: the stand declares the filtered definition the plugin attaches.
    W::$projectScope = $layer === 'common' ? (new VisibilityProjectScope)->filter(fn ($q) => $q->where('active', true)) : null;
    [$visibility, $panel] = W::compile($source, function (PanelBuilder $p) use ($layer): void {
        match ($layer) {
            'before' => $p->before([VisibilityBefore::class]),
            'common' => $p->plugins([ScopesPlugin::inherit(W::$projectScope)]),
            'restriction' => $p->restrictions([VisibilityRestriction::class]),
            'veto' => $p->policies([PolicyBinding::for('orders.view', ParityVetoPolicy::class)]),
            'condition' => $p->grantConditions([VisibilityCondition::class]),
            default => null,
        };
    });

    if (in_array($layer, ['condition', 'role'], true)) {
        $source->direct = $layer === 'condition' ? [W::grant(1, ['city' => 'Rome']), W::grant(2, ['city' => 'Rome'])] : [];
        $source->roles = $layer === 'role' ? [W::role(1, ['city' => 'Rome']), W::role(2, ['city' => 'Rome'])] : [];
    }
    $expected = in_array($layer, ['condition', 'role'], true) ? [2] : [1];
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe($expected);
})->with(['before', 'common', 'role', 'condition', 'restriction', 'veto']);

it('P14 keeps list equal to scalar for a restriction whose appliesTo depends on the resource', function (): void {
    $projects = VisibilityProject::query()->orderBy('id')->get();
    for ($seed = 1; $seed <= 30; $seed++) {
        $next = $seed;
        $draw = static function (int $max) use (&$next): int {
            $next = ($next * 1664525 + 1013904223) & 0xFFFFFFFF;

            return ($next >> 8) % $max;
        };
        $direct = [];
        for ($i = 0; $i < 6; $i++) {
            $direct[] = W::grant($draw(5) ?: null, [[], ['city' => 'Paris'], ['city' => 'Rome']][$draw(3)]);
        }
        $scenario = new ParityScenario($seed, records: $projects->all(), sources: ['generated'], contributions: $direct, roles: []);
        [$visibility, $panel, $authorizer] = W::compile(new VisibilitySource(direct: $direct), fn (PanelBuilder $p) => $p->grantConditions([VisibilityCondition::class])->restrictions([ParityResourceRestriction::class]));
        $ids = [];
        foreach ($projects as $resource) {
            if ($authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource))->allowed()) {
                $ids[] = $resource->id;
            }
        }
        $query = $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view');
        $actualIds = (clone $query)->orderBy('id')->pluck('id')->all();
        $actualCount = $query->count();
        $summary = "seed=$seed; reproduce: AZGUARD_PROPERTY_SEED=$seed";

        if ($actualIds !== $ids || $actualCount !== count($ids)) {
            $shrunk = ParityGenerator::shrink($scenario, static function (ParityScenario $candidate): bool {
                [$vis, $pan, $auth] = W::compile(
                    new VisibilitySource(direct: $candidate->sources === [] ? [] : $candidate->contributions),
                    fn (PanelBuilder $p) => $p->grantConditions([VisibilityCondition::class])->restrictions([ParityResourceRestriction::class]),
                );
                $expectedIds = [];
                $recordIds = [];
                foreach ($candidate->records as $rec) {
                    $recordIds[] = $rec->id;

                    if ($auth->decide($pan, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $rec))->allowed()) {
                        $expectedIds[] = $rec->id;
                    }
                }
                $q = $vis->visibleTo($pan, VisibilityProject::query()->whereKey($recordIds), W::subject(), 'orders.view');

                return (clone $q)->orderBy('id')->pluck('id')->all() !== $expectedIds || $q->count() !== count($expectedIds);
            });
            $summary = sprintf(
                'seed=%d shrunk(records=%d,sources=%d,contributions=%d,roles=%d); reproduce: AZGUARD_PROPERTY_SEED=%d',
                $seed,
                count($shrunk->records),
                count($shrunk->sources),
                count($shrunk->contributions),
                count($shrunk->roles),
                $seed,
            );
        }

        $this->assertSame($ids, $actualIds, $summary);
        $this->assertSame(count($ids), $actualCount, 'count '.$summary);
    }
});

it('shrinks a failing parity scenario to a minimal counterexample across records sources contributions and roles', function (): void {
    $scenario = new ParityScenario(
        seed: 17,
        records: [1, 2, 3, 4, 5],
        sources: ['folder', 'generated', 'extra'],
        contributions: ['g1', 'g2', 'g3', 'g4', 'g5', 'g6'],
        roles: ['r1', 'r2', 'r3', 'r4'],
    );

    $shrunk = ParityGenerator::shrink($scenario, static function (ParityScenario $candidate): bool {
        return in_array(2, $candidate->records, true)
            && in_array(4, $candidate->records, true)
            && in_array('generated', $candidate->sources, true)
            && in_array('g3', $candidate->contributions, true)
            && in_array('r2', $candidate->roles, true);
    });

    expect($shrunk->seed)->toBe(17)
        ->and($shrunk->records)->toBe([2, 4])
        ->and($shrunk->sources)->toBe(['generated'])
        ->and($shrunk->contributions)->toBe(['g3'])
        ->and($shrunk->roles)->toBe(['r2']);
});

it('keeps decide and visibleTo parity when the host logger throws on an unknown role contribution', function (): void {
    $projects = VisibilityProject::query()->orderBy('id')->get();
    $source = new VisibilitySource(
        direct: [W::grant(1)],
        roles: [W::role(2, key: 'unknown-removed-role')],
    );
    [$visibility, $panel, $authorizer] = W::compile($source);

    Log::shouldReceive('notice')
        ->andThrow(new RuntimeException('Custom host logger transport failed.'));

    $expectedIds = [];
    foreach ($projects as $resource) {
        $decision = $authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource));

        if ($decision->allowed()) {
            $expectedIds[] = $resource->id;
        }
    }

    $actualIds = $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($actualIds)->toBe($expectedIds)
        ->and($actualIds)->not->toBeEmpty();
});
