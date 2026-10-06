<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Scopes\EligibilityCityFilter;
use AzGuard\Tests\Fixtures\Scopes\EligibilityProjectScope;
use AzGuard\Tests\Fixtures\Scopes\EligibilityWorld;
use AzGuard\Tests\Fixtures\Scopes\WideReaderRole;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    EligibilityWorld::tables();
    EligibilityCityFilter::$observed = [];
});
afterEach(fn () => Relation::morphMap([], false));

it('keeps common filters before grants policy hook and scoped superadmin', function (string $authority): void {
    $source = new GeneratedSource;

    if ($authority === 'direct') {
        $source->direct = [EligibilityWorld::direct(3)];
    }

    if ($authority === 'root') {
        $source->roles = [EligibilityWorld::role('root', 3)];
    }
    [$engine, $panel, $request] = EligibilityWorld::compile($source, $authority === 'hook' ? fn (PanelBuilder $p) => $p->before(fn (): bool => true) : null);
    $request = $request->inScope(EligibilityWorld::scope(3));

    if ($authority === 'policy') {
        $request = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy'))->inScope(EligibilityWorld::scope(3));
    }
    $decision = $engine->decide($panel, $request);
    expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::AssignmentScopeIneligible)
        ->and($source->grantReads)->toBe(0)->and($source->roleReads)->toBe(0)->and(RuntimePolicy::$calls)->toBe(0);
})->with(['direct', 'root', 'hook', 'policy']);

it('keeps a rejected seller independent from an analyst and direct contribution', function (string $alternative): void {
    $source = new GeneratedSource(roles: [EligibilityWorld::role('seller', 4)]);

    if ($alternative === 'analyst') {
        $source->roles[] = EligibilityWorld::role('analyst', 4);
    }

    if ($alternative === 'direct') {
        $source->direct = [EligibilityWorld::direct(4)];
    }
    [$engine, $panel, $request] = EligibilityWorld::compile($source);
    $decision = $engine->decide($panel, $request->inScope(EligibilityWorld::scope(4)));
    expect($decision->allowed())->toBe($alternative !== 'none');
})->with(['none', 'analyst', 'direct']);

it('applies the same role binding to scoped superadmin qualification', function (): void {
    $source = new GeneratedSource(roles: [EligibilityWorld::role('root', 4)]);
    [$engine, $panel, $request] = EligibilityWorld::compile($source);
    expect($engine->isSuperAdmin($panel, $request->subject(), EligibilityWorld::scope(4)))->toBeFalse()
        ->and($engine->decide($panel, $request->inScope(EligibilityWorld::scope(4)))->allowed())->toBeFalse();
    $source->roles = [EligibilityWorld::role('root')];
    expect($engine->isSuperAdmin($panel, $request->subject(), EligibilityWorld::scope()))->toBeTrue();
});

it('requires one whole contribution for filters and its own fields', function (): void {
    $source = new GeneratedSource(roles: [EligibilityWorld::role('seller', fields: ['eligible' => false]), EligibilityWorld::role('seller', 4, ['eligible' => true])]);
    [$engine, $panel, $request] = EligibilityWorld::compile($source);
    // Only the selected context is read by this fixture source.
    $source->roles = [EligibilityWorld::role('seller', fields: ['eligible' => false])];
    expect($engine->decide($panel, $request->inScope(EligibilityWorld::scope()))->allowed())->toBeFalse();
    $source->roles[] = EligibilityWorld::role('seller', fields: ['eligible' => true]);
    expect($engine->decide($panel, $request->inScope(EligibilityWorld::scope()))->allowed())->toBeTrue();
    expect(EligibilityCityFilter::$observed[0]->grant?->fields())->toBe(['eligible' => false]);
});

it('does not promote scope-required or empty-binding roles into selected context contributions', function (string $case): void {
    $global = AccessScope::in(TenantRef::of('org', 'A'));
    $key = $case === 'required' ? 'seller' : 'reader';
    $source = new GeneratedSource(roles: [RoleContribution::of(RoleKey::of('admin', $key), $case === 'required' ? $global : EligibilityWorld::scope(), 'generated')]);
    [$engine, $panel, $request] = EligibilityWorld::compile($source, fn (PanelBuilder $p) => $p->roles([WideReaderRole::class]));
    expect($engine->decide($panel, $request->inScope(EligibilityWorld::scope()))->allowed())->toBeFalse();
})->with(['required', 'empty']);

it('rereads active and city and current grant fields with an unchanged code state', function (): void {
    $source = new GeneratedSource(roles: [EligibilityWorld::role('seller')]);
    [$engine, $panel, $request] = EligibilityWorld::compile($source);
    $request = $request->inScope(EligibilityWorld::scope());
    $first = $engine->decide($panel, $request);
    expect($first->allowed())->toBeTrue();
    DB::table('users')->where('id', 1)->update(['city' => 'east']);
    expect($engine->decide($panel, $request)->allowed())->toBeFalse();
    DB::table('users')->where('id', 1)->update(['city' => 'west']);
    $source->roles = [EligibilityWorld::role('seller', fields: ['eligible' => false])];
    expect($engine->decide($panel, $request)->allowed())->toBeFalse();
    $source->roles = [EligibilityWorld::role('seller')];
    DB::table('projects')->where('id', 1)->update(['is_active' => false]);
    $last = $engine->decide($panel, $request);
    expect($last->reason)->toBe(DecisionReason::AssignmentScopeIneligible)->and($last->state->equals($first->state))->toBeTrue();
});

it('maps a common native filter exception to a fail closed reason', function (): void {
    $scope = EligibilityProjectScope::make()->filter(function (): void {
        throw new RuntimeException('filter failed');
    });
    [$engine, $panel, $request] = EligibilityWorld::compile(new GeneratedSource(direct: [EligibilityWorld::direct()]), fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit($scope)));
    expect($engine->decide($panel, $request->inScope(EligibilityWorld::scope()))->reason)->toBe(DecisionReason::AssignmentScopeFilterError);
});
