<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Tests\Fixtures\Visibility\VisibilityBefore;
use AzGuard\Tests\Fixtures\Visibility\VisibilityCondition;
use AzGuard\Tests\Fixtures\Visibility\VisibilityPolicy;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope;
use AzGuard\Tests\Fixtures\Visibility\VisibilityRestriction;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Eloquent\Model;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';

class ParityVetoPolicy extends VisibilityPolicy
{
    #[Decides('orders.view')]
    public function view(?Model $user, ?Model $resource): ?bool
    {
        return parent::view($user, $resource);
    }
}

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('P14 matches every scalar allow id and count on 100 reproducible seeds under one snapshot and decision clock', function (): void {
    $projects = VisibilityProject::query()->orderBy('id')->get();
    for ($seed = 1; $seed <= 100; $seed++) {
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
        $source = new VisibilitySource(direct: $direct, roles: $roles);
        [$visibility, $panel, $authorizer] = W::compile($source, function (PanelBuilder $p) use ($seed): void {
            $p->policies([PolicyBinding::for('orders.view', ParityVetoPolicy::class)])
                ->grantConditions([VisibilityCondition::class]);

            if ($seed % 2 === 0) {
                $p->before([VisibilityBefore::class]);
            }

            if ($seed % 3 === 0) {
                $p->restrictions([VisibilityRestriction::class]);
            }
        });
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
            $this->assertSame($ids, (clone $query)->orderBy('id')->pluck('id')->all(), "seed=$seed action=$permission");
            $this->assertSame(count($ids), $query->count(), "count seed=$seed action=$permission");
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
    [$visibility, $panel] = W::compile($source, function (PanelBuilder $p) use ($layer): void {
        match ($layer) {
            'before' => $p->before([VisibilityBefore::class]),
            'common' => $p->scopes(AssignmentScopePolicy::inherit((new VisibilityProjectScope)->filter(fn ($q) => $q->where('active', true)))),
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
