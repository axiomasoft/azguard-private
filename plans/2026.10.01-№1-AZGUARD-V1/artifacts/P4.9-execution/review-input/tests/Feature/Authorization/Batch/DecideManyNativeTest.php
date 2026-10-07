<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\BatchNativeFilter;
use AzGuard\Tests\Fixtures\Authorization\BatchNativeRole;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\ConfiguredProjectScope;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses()->group('batch');

it('keeps native contribution fields and a failing sibling subject witness independent', function (): void {
    Schema::create('projects', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('organization_id');
        $table->boolean('is_active');
    });
    app('db')->table('projects')->insert(['id' => 1, 'organization_id' => 1, 'is_active' => true]);
    User::query()->insert(['id' => 2]);
    $scope = AccessScope::in(TenantRef::of('crm.organization', 1), AssignmentScopeRef::of('crm.project', 1));
    $no = RoleContribution::of(RoleKey::of('admin', 'batch-native'), $scope, 'generated', fields: ['eligible' => false]);
    $yes = RoleContribution::of(RoleKey::of('admin', 'batch-native'), $scope, 'generated', fields: ['eligible' => true]);
    $source = new GeneratedSource(roles: [$no]);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel
        ->roles([BatchNativeRole::class])->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership(['1', '2'])))
        ->scopes(AssignmentScopePolicy::inherit(ConfiguredProjectScope::make()->filter(new BatchNativeFilter))));
    $request = $request->inScope($scope);
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted);
    $source->roles[] = $yes;
    expect($engine->decide($panel, $request)->allowed())->toBeTrue();
    $bad = AccessRequest::for(SubjectRef::of('user', 2), $request->permission())->inScope($scope);
    expect($engine->decide($panel, $bad)->reason)->toBe(DecisionReason::AssignmentScopeFilterError);
    BatchNativeFilter::$fields = [];
    $set = $engine->decideMany([$bad, $request, $request]);
    expect($set->get(0)->reason)->toBe(DecisionReason::AssignmentScopeFilterError)->and($set->get(1)->allowed())->toBeTrue()
        ->and($set->get(2)->allowed())->toBeTrue()->and(BatchNativeFilter::$fields)->toContain(false, true);
});
