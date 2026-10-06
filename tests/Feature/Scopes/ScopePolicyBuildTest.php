<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\ModelAssignmentScopeDefinition;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\ActiveProjects;
use AzGuard\Tests\Fixtures\Scopes\ConfiguredProjectScope;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\Project;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Relations\Relation;

afterEach(fn () => Relation::morphMap([], false));

it('requires a tenant membership adapter at panel compilation', function (): void {
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->tenants(TenantPolicy::required(Organization::class))]))
        ->toThrow(DefinitionException::class);
});

it('rejects unsupported configured common filters at compilation', function (): void {
    $definition = ConfiguredProjectScope::make()->filter(new ActiveProjects);
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->scopes(AssignmentScopePolicy::inherit($definition))]))
        ->toThrow(DefinitionException::class, 'filter');
});

it('rejects unsupported configured role filters at compilation', function (): void {
    $role = new class extends BaseRole
    {
        public function key(): string
        {
            return 'filtered';
        }

        public function permissions(): array
        {
            return [];
        }

        public function scopes(): array
        {
            return [ConfiguredProjectScope::make()->filter(new ActiveProjects)];
        }
    };
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->scopes(AssignmentScopePolicy::inherit(ConfiguredProjectScope::class))->roles([$role::class])]))
        ->toThrow(DefinitionException::class, 'filter');
});

it('rejects a role binding that changes registered scope identity', function (): void {
    $role = new class extends BaseRole
    {
        public function key(): string
        {
            return 'forged';
        }

        public function permissions(): array
        {
            return [];
        }

        public function scopes(): array
        {
            return [new class extends StoreScope
            {
                public function model(): ?string
                {
                    return Project::class;
                }
            }];
        }
    };
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->scopes(AssignmentScopePolicy::inherit(StoreScope::class))->roles([$role::class])]))
        ->toThrow(DefinitionException::class, 'conflicting');
});

it('accepts model shortcuts only with a structural owner in tenant panels', function (): void {
    Relation::morphMap(['project' => Project::class], false);
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)
        ->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership))
        ->scopes(AssignmentScopePolicy::inherit(Project::class))]))->toThrow(DefinitionException::class);
    [, , $registry] = PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->scopes(AssignmentScopePolicy::inherit(Project::class))]);
    expect(array_values($registry->get('admin')->scopeDefinitions())[0])->toBeInstanceOf(ModelAssignmentScopeDefinition::class);
});
