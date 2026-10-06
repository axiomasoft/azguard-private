<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\ActiveProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\AnalystProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission as Action;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Illuminate\Database\Eloquent\Builder;

it('R09 common active filter vetoes direct role policy before Continue and scoped admin', function (string $authority): void {
    World::assign(match ($authority) {
        'direct' => 'clients.view', 'admin' => 'tenant-admin', default => 'analyst'
    }, 1, 3, kind: $authority === 'direct' ? 'permission' : 'role');
    $panel = World::compile(fn (PanelBuilder $p) => $p->before(fn () => BeforeResult::Continue));
    $action = $authority === 'policy' ? Action::ViewOwnProfile : Action::View;
    World::assertDecision(World::decide($panel, 4, $action), false, DecisionReason::AssignmentScopeIneligible);
    Project::query()->whereKey(3)->update(['is_active' => true]);
    World::assertDecision(World::decide($panel, 4, $action), true, match ($authority) {
        'policy' => DecisionReason::Policy, 'admin' => DecisionReason::SuperAdmin, default => DecisionReason::Granted,
    });
})->with(['direct', 'role', 'policy', 'admin']);

it('R10 seller city binding qualifies both view and update instead of granting foreign city', function (): void {
    World::clear();
    World::assign('seller', 1, 2);
    $panel = World::compile();
    foreach ([Action::View, Action::Update] as $action) {
        World::assertDecision(World::decide($panel, 3, $action), false, DecisionReason::NotGranted);
    }
    User::query()->whereKey(1)->update(['city_id' => 2]);
    foreach ([Action::View, Action::Update] as $action) {
        World::assertDecision(World::decide($panel, 3, $action), true, DecisionReason::Granted);
    }
});

it('R11 seller city never vetoes an independent analyst contribution', function (): void {
    World::assign('seller', 1, 2);
    $panel = World::compile();
    World::assertDecision(World::decide($panel, 1), true, DecisionReason::Granted);
    World::assertDecision(World::decide($panel, 3), true, DecisionReason::Granted);
    World::assertDecision(World::decide($panel, 3, Action::Update), false, DecisionReason::NotGranted);
    World::assertDecision(World::decide($panel, 1, Action::Update), true, DecisionReason::Granted);
});

it('R12 independent witnesses survive DB relation source and assignment order permutations', function (bool $reverseSources, bool $reverseGrants): void {
    World::clear();
    $roles = $reverseGrants ? ['analyst', 'seller'] : ['seller', 'analyst'];
    foreach ($roles as $role) {
        World::assign($role, 1, 2);
    }
    Project::query()->findOrFail(2)->members()->attach(1, ['role' => 'seller']);
    $sources = [World::database(), RelationSource::make(ProjectScope::make(), 'members', 'pivot.role')];
    $panel = World::compile(sources: $reverseSources ? array_reverse($sources) : $sources);
    World::assertDecision(World::decide($panel, 3), true, DecisionReason::Granted);
    World::assertDecision(World::decide($panel, 3, Action::Update), false, DecisionReason::NotGranted);
    World::assertDecision(World::decide($panel, 6), false, DecisionReason::NotGranted);
})->with([false, true])->with([false, true]);

it('R13 each contribution must satisfy city region and all its own fields as one witness', function (): void {
    World::clear();
    World::assign('seller', 1, 2, fields: ['region' => 'R1', 'eligible' => true]);
    World::assign('analyst', 1, 2, fields: ['region' => 'R2', 'eligible' => true]);
    World::assign('analyst', 1, 2, fields: ['region' => 'R1', 'eligible' => false], origin: 'import');
    $panel = World::compile();
    World::assertDecision(World::decide($panel, 3), false, DecisionReason::NotGranted);
    World::assign('analyst', 1, 2, fields: ['region' => 'R1', 'eligible' => true], origin: 'approved');
    World::assertDecision(World::decide($panel, 3), true, DecisionReason::Granted);
    World::assertDecision(World::decide($panel, 3, Action::Update), false, DecisionReason::NotGranted);
});

it('R14 two PHP roles use the actual target actor and branch via real DB and Project members', function (string $source): void {
    World::clear();

    if ($source === 'database') {
        World::assign('seller', 1, 2);
    } else {
        Project::query()->findOrFail(2)->members()->attach(1, ['role' => 'seller']);
    }
    $panel = World::compile(sources: [$source === 'database' ? World::database() : RelationSource::make(ProjectScope::make(), 'members', 'pivot.role')]);
    $actor = ActorRef::of('crm.user', 2);
    World::assertDecision(World::decide($panel, 3, actor: $actor), false, DecisionReason::NotGranted);

    if ($source === 'database') {
        World::assign('analyst', 1, 2);
    } else {
        Project::query()->findOrFail(2)->members()->attach(1, ['role' => 'analyst']);
    }
    $panel = World::compile(sources: [$source === 'database' ? World::database() : RelationSource::make(ProjectScope::make(), 'members', 'pivot.role')]);
    SellerProjects::$observed = AnalystProjects::$observed = [];
    World::assertDecision(World::decide($panel, 3, actor: $actor), true, DecisionReason::Granted);
    foreach ([[SellerProjects::$observed, SellerRole::class], [AnalystProjects::$observed, AnalystRole::class]] as [$inputs, $roleClass]) {
        expect($inputs)->not->toBeEmpty();
        $runtime = $inputs[0];
        expect($runtime->role)->toBeInstanceOf($roleClass)->and($runtime->grant->role->key())->toBe($roleClass === SellerRole::class ? 'seller' : 'analyst')
            ->and($runtime->user->getKey())->toBe(1)->and($runtime->actorModel->getKey())->toBe(2)
            ->and($runtime->scope->equals(World::scope(1, 2)))->toBeTrue();
    }
})->with(['database', 'relation']);

it('R16 direct grants expose role null and cannot manufacture a required PHP role', function (): void {
    World::clear();
    World::assign('clients.view', 1, 2, kind: 'permission');
    $panel = World::compile();
    World::assertDecision(World::decide($panel, 3), true, DecisionReason::Granted);
    expect(ActiveProjects::$observed[0]->role)->toBeNull()->and(ActiveProjects::$observed[0]->grant)->toBeNull()
        ->and(SellerProjects::$observed)->toBe([]);
    $panel = World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter(
        function (Builder $query, BaseRole $role): void {
            $query->where('is_active', true);
        },
    ))));
    World::assertDecision(World::decide($panel, 3), false, DecisionReason::AssignmentScopeFilterError);
});

it('R17 empty permission admin still obeys city inactive tenant scope and restriction boundaries', function (): void {
    World::assign('tenant-admin', 3, 2);
    World::assign('tenant-admin', 3, 3);
    World::assign('tenant-admin', 3, 4, 2);
    $panel = World::compile();
    World::assertDecision(World::decide($panel, user: 3), true, DecisionReason::SuperAdmin);
    expect(app(Authorizer::class)->isSuperAdmin($panel, SubjectRef::of('crm.user', 3), World::scope(1, 1)))->toBeTrue()
        ->and(app(Authorizer::class)->isSuperAdmin($panel, SubjectRef::of('crm.user', 3), World::scope(1, 2)))->toBeFalse();
    World::assertDecision(World::decide($panel, 3, user: 3), false, DecisionReason::NotGranted);
    World::assertDecision(World::decide($panel, 4, user: 3), false, DecisionReason::AssignmentScopeIneligible);
    World::assertDecision(World::decide($panel, 5, user: 3, tenant: 2), false, DecisionReason::Restricted, 'membership');
    World::assertDecision(World::decide($panel, 6, user: 3), false, DecisionReason::NotGranted);
    User::query()->whereKey(3)->update(['locked_at' => '2026-10-06 12:00:00']);
    World::assertDecision(World::decide($panel, user: 3), false, DecisionReason::Restricted, 'account_locked');
});
