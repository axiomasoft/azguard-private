<?php

declare(strict_types=1);

use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\ReaderRole;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class);

beforeEach(function (): void {
    Relation::morphMap(['user' => User::class], false);
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
    });
    User::query()->insert(['id' => 1]);
});
afterEach(function (): void {
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('A03 decideMany reuses one external-adapter verdict for different contributions', function (): void {
    $tenant = TenantRef::of('org', 'A');
    $definition = new StoreScope(fn (AssignmentScopeRef $ref): ResolvedAssignmentScope => new ResolvedAssignmentScope($ref, $tenant));
    app()->instance(StoreScope::class, $definition);
    // Live external eligibility that depends on the contribution witness (here: its pattern).
    $adapter = new class implements AssignmentScopeAccessAdapter
    {
        public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool
        {
            return ! $runtime->grant instanceof Grant || $runtime->grant->pattern->local() === 'orders.view';
        }

        public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array
        {
            $out = [];
            foreach ($refs as $ref) {
                $out[$ref->key()] = $this->allows($ref, $runtime);
            }

            return $out;
        }

        public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder
        {
            throw new RuntimeException('external');
        }
    };
    $one = AccessScope::in($tenant, AssignmentScopeRef::of('store', 1));
    $source = new GeneratedSource(direct: [
        Grant::of(PermissionPattern::of('admin', 'orders.edit'), 'generated', $one),
        Grant::of(PermissionPattern::of('admin', 'orders.view'), 'generated', $one),
    ]);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel
        ->roles([ReaderRole::class])->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership))
        ->scopes(AssignmentScopePolicy::inherit($definition)->accessAdapter('store', $adapter)));
    $view = $request->inScope($one);
    $edit = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.edit'))->inScope($one);

    $scalar = [$engine->decide($panel, $view), $engine->decide($panel, $edit)];
    $set = $engine->decideMany([$view, $edit]);
    dump(['scalar' => array_map(fn ($d) => [$d->allowed(), $d->reason->value], $scalar),
        'batch' => array_map(fn ($d) => [$d->allowed(), $d->reason->value], iterator_to_array($set))]);

    expect($set->get(0)->allowed())->toBe($scalar[0]->allowed())
        ->and($set->get(1)->allowed())->toBe($scalar[1]->allowed());
});
