<?php

declare(strict_types=1);

use AzGuard\Authorization\Visibility;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission as Action;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Policies\Clients\ClientPolicy;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Queries\Clients\ClientVisibility as Lists;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Resolvers\ClientScopeResolver;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

it('R31 R32 returns independent literal CRM ids and complete scalar parity for every action and tenant', function (Action $action, int $tenant, array $ids): void {
    $panel = Lists::panel();
    $scalarIds = Client::query()->orderBy('id')->get()->filter(fn (Client $client) => World::decide($panel, $client, $action, tenant: $tenant)->allowed())->values()->modelKeys();
    expect($scalarIds)->toBe($ids)->and(Lists::query($panel, $action, tenant: $tenant)->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(Lists::query($panel, $action, tenant: $tenant)->count())->toBe(count($ids));
})->with([
    'A view' => [Action::View, 1, [1, 2, 3]],
    'B view' => [Action::View, 2, [5]],
    'A update' => [Action::Update, 1, [1]],
    'B update' => [Action::Update, 2, []],
    'A policy only' => [Action::ViewOwnProfile, 1, [1, 2, 3]],
    'B policy only' => [Action::ViewOwnProfile, 2, [5]],
]);

it('R33 constrains order search pages and totals before pagination even when forbidden rows come first', function (): void {
    $panel = Lists::panel();
    $host = Client::query()->where(fn (Builder $q) => $q->where('owner_user_id', 1)->orWhere('id', 6))->orderByDesc('id');
    $query = Lists::query($panel, host: $host);
    $first = (clone $query)->paginate(2, page: 1);
    $second = (clone $query)->paginate(2, page: 2);
    expect($first->total())->toBe(3)->and($first->pluck('id')->all())->toBe([3, 2])
        ->and($second->total())->toBe(3)->and($second->pluck('id')->all())->toBe([1]);
    expect(Lists::query($panel, host: Client::query()->where('id', '>=', 2))->orderBy('id')->pluck('id')->all())->toBe([2, 3]);
    expect(Lists::query($panel, user: null)->count())->toBe(0);
    World::clear();
    expect(Lists::query($panel)->count())->toBe(0);
});

it('R15 R50 R57 recomputes live city common eligibility and deployment filters without changing the authority token', function (): void {
    $panel = Lists::panel();
    $token = World::storage()->state('crm');
    expect(Lists::query($panel)->orderBy('id')->pluck('id')->all())->toBe([1, 2, 3]);
    User::query()->whereKey(1)->update(['city_id' => 2]);
    expect(Lists::query($panel)->pluck('id')->all())->toBe([3]);
    Project::query()->whereKey(2)->update(['is_active' => false]);
    expect(Lists::query($panel)->count())->toBe(0)->and(World::decide($panel, 3)->allowed())->toBeFalse()
        ->and(World::storage()->state('crm'))->toEqual($token);
    Project::query()->whereKey(2)->update(['is_active' => true]);
    $panel = Lists::panel(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter(fn (Builder $q) => $q->whereKey(1)))));
    expect(Lists::query($panel)->count())->toBe(0)->and(World::storage()->state('crm'))->toEqual($token);
});

it('keeps region conditions on their own complete witness and policy vetoes outside the role OR', function (): void {
    World::clear();
    World::assign('seller', 1, 1, fields: ['region' => 'R2']);
    World::assign('analyst', 1, 2, fields: ['region' => 'R1']);
    $panel = Lists::panel();
    expect(Lists::query($panel)->pluck('id')->all())->toBe([3]);
    World::assign('seller', 1, 1, fields: ['region' => 'R1'], origin: 'second');
    expect(Lists::query($panel)->orderBy('id')->pluck('id')->all())->toBe([1, 2, 3]);
    ClientPolicy::$override = true;
    ClientPolicy::$result = null;
    expect(Lists::query($panel, Action::Update)->pluck('id')->all())->toBe([1, 2]);
    ClientPolicy::$result = false;
    expect(Lists::query($panel)->count())->toBe(0);
    ClientPolicy::$result = true;
    User::query()->whereKey(1)->update(['locked_at' => '2026-10-06 12:00:00']);
    expect(Lists::query($panel)->count())->toBe(0);
});

it('R35 throws before querying resources for scalar-only components and bounds the full policy universe outside grants', function (string $component): void {
    $panel = Lists::panel(function (PanelBuilder $p) use ($component): void {
        match ($component) {
            'policy' => ClientPolicy::$unsupported = true,
            'before' => $p->before([fn () => BeforeResult::Continue]),
            'condition' => $p->grantConditions([new class implements GrantCondition
            {
                public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
                {
                    return true;
                }
            }]),
            default => $p->resourceScopes([Client::class => new ClientScopeResolver]),
        };
    });
    $action = $component === 'policy' ? Action::ViewOwnProfile : Action::View;
    $host = Client::query()->orderBy('id');
    $sql = $host->toSql();
    DB::flushQueryLog();
    DB::enableQueryLog();
    expect(fn () => Lists::query($panel, $action, host: $host)->paginate(1))->toThrow(VisibilityNotSupportedException::class);
    expect($host->toSql())->toBe($sql);
    expect(array_filter(DB::getQueryLog(), fn (array $row) => preg_match('/\bfrom ["`]clients["`]/', $row['query'])))->toBe([]);
    DB::disableQueryLog();
})->with(['policy', 'before', 'condition', 'resolver']);

it('R35 R64 evaluates every bounded policy-only row while assignment storage is unavailable', function (): void {
    World::clear();
    $panel = Lists::panel();
    $storage = World::storage();
    $storage->connection()->getSchemaBuilder()->drop($storage->table('role_grants')->from);
    $storage->connection()->getSchemaBuilder()->drop($storage->table('permission_grants')->from);
    expect(Lists::query($panel, Action::ViewOwnProfile)->orderBy('id')->pluck('id')->all())->toBe([1, 2, 3]);
    expect(fn () => Lists::query($panel, Action::Update))->toThrow(VisibilityNotSupportedException::class);
    $page = app(Visibility::class)->bounded($panel, Client::query()->orderByDesc('id'), SubjectRef::of('crm.user', 1), Action::ViewOwnProfile, World::scope(), limit: 5, perPage: 2, page: 2);
    expect($page->total())->toBe(3)->and($page->pluck('id')->all())->toBe([1]);
    expect(fn () => app(Visibility::class)->bounded($panel, Client::query(), SubjectRef::of('crm.user', 1), Action::ViewOwnProfile, World::scope(), limit: 4))->toThrow(VisibilityNotSupportedException::class, 'bounded_universe_limit');
});

it('R35 bounded fallback checks all policy rows even when the policy exact adapter is unsupported', function (): void {
    World::clear();
    ClientPolicy::$unsupported = true;
    $panel = Lists::panel();
    expect(fn () => Lists::query($panel, Action::ViewOwnProfile))->toThrow(VisibilityNotSupportedException::class);
    $page = app(Visibility::class)->bounded($panel, Client::query()->orderBy('id'), SubjectRef::of('crm.user', 1), Action::ViewOwnProfile, World::scope(), limit: 5, perPage: 2, page: 2);
    expect($page->total())->toBe(3)->and($page->pluck('id')->all())->toBe([3]);
});
