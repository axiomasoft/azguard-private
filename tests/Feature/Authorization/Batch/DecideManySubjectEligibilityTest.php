<?php

declare(strict_types=1);

use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Crm\Models\Organization as CrmOrganization;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\ConfiguredProjectScope;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses()->group('batch');

it('qualifies native and external scopes against each supplied subject instance', function (bool $native, bool $allowedFirst): void {
    $tenant = TenantRef::of($native ? 'crm.organization' : 'org', $native ? '1' : 'A');
    $ref = AssignmentScopeRef::of($native ? 'crm.project' : 'store', 1);
    $scope = AccessScope::in($tenant, $ref);

    if ($native) {
        Schema::create('projects', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
        });
        app('db')->table('projects')->insert(['id' => 1, 'organization_id' => 1]);
        $definition = ConfiguredProjectScope::make()->filter(new class implements AssignmentScopeFilter
        {
            public function apply(Builder $query, AssignmentScopeRuntime $runtime): void
            {
                if ($runtime->user?->getAttribute('department') !== 'allow') {
                    $query->whereRaw('1 = 0');
                }
            }
        });
        $policy = AssignmentScopePolicy::inherit($definition);
    } else {
        $definition = new StoreScope(static fn (AssignmentScopeRef $ref): ResolvedAssignmentScope => new ResolvedAssignmentScope($ref, $tenant));
        $adapter = new class implements AssignmentScopeAccessAdapter
        {
            public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool
            {
                return $runtime->user?->getAttribute('department') === 'allow';
            }

            public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array
            {
                $results = [];
                foreach ($refs as $ref) {
                    $results[$ref->key()] = $this->allows($ref, $runtime);
                }

                return $results;
            }

            public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder
            {
                throw new RuntimeException('External access does not constrain a native query.');
            }
        };
        $policy = AssignmentScopePolicy::inherit($definition)->accessAdapter('store', $adapter);
    }
    [$engine, $panel, $request] = AuthorizationWorld::compile(
        new GeneratedSource(direct: [Grant::of(PermissionPattern::of('admin', 'orders.view'), 'generated', $scope)]),
        static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->tenants(TenantPolicy::required($native ? CrmOrganization::class : Organization::class)->requireMembership(new Membership(['1'])))
            ->scopes($policy),
    );
    $allowed = User::query()->findOrFail(1);
    $allowed->setAttribute('department', 'allow');
    $denied = clone $allowed;
    $denied->setAttribute('department', 'deny');
    $request = $request->inScope($scope);
    $requests = [$request->withSubjectModel($allowed), $request->withSubjectModel($denied)];

    if (! $allowedFirst) {
        $requests = array_reverse($requests);
    }
    $scalar = array_map(static fn ($item): bool => $engine->decide($panel, $item)->allowed(), $requests);
    $batch = $engine->decideMany($requests);

    expect($scalar)->toBe($allowedFirst ? [true, false] : [false, true])
        ->and(array_map(static fn (Decision $decision): bool => $decision->allowed(), iterator_to_array($batch)))->toBe($scalar);
})->with(['native' => [true], 'external' => [false]])->with(['allow then deny' => [true], 'deny then allow' => [false]]);
