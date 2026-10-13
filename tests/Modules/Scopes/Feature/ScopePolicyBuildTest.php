<?php

declare(strict_types=1);

use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\Internal\Checks\ScopeMembershipConfigured;
use AzGuard\Scopes\ModelAssignmentScopeDefinition;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Modules\Scopes\Fixtures\ActiveProjects;
use AzGuard\Tests\Modules\Scopes\Fixtures\ConfiguredProjectScope;
use AzGuard\Tests\Modules\Scopes\Fixtures\Membership;
use AzGuard\Tests\Modules\Scopes\Fixtures\Organization;
use AzGuard\Tests\Modules\Scopes\Fixtures\Project;
use AzGuard\Tests\Modules\Scopes\Fixtures\StoreScope;
use Illuminate\Database\Eloquent\Relations\Relation;

afterEach(fn () => Relation::morphMap([], false));

it('requires a tenant membership adapter at panel compilation', function (): void {
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->tenants(TenantPolicy::required(Organization::class))]))
        ->toThrow(InvalidConfigurationException::class, 'membership adapter');
});

it('accepts configured common filters at compilation', function (): void {
    $definition = ConfiguredProjectScope::make()->filter(new ActiveProjects);
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([ScopesPlugin::inherit($definition)])]))
        ->not->toThrow(DefinitionException::class);
});

it('accepts configured role filters at compilation', function (): void {
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
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([ScopesPlugin::inherit(ConfiguredProjectScope::class)])->roles([$role::class])]))
        ->not->toThrow(DefinitionException::class);
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
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([ScopesPlugin::inherit(StoreScope::class)])->roles([$role::class])]))
        ->toThrow(InvalidConfigurationException::class, 'conflicting');
});

it('accepts model shortcuts only with a structural owner in tenant panels', function (): void {
    Relation::morphMap(['project' => Project::class], false);
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)
        ->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership))
        ->plugins([ScopesPlugin::inherit(Project::class)])]))->toThrow(InvalidConfigurationException::class, 'authoritative tenant owner');
    [, , $registry] = PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([ScopesPlugin::inherit(Project::class)])]);
    expect(array_values($registry->get('admin')->scopeDefinitions())[0])->toBeInstanceOf(ModelAssignmentScopeDefinition::class);
});

it('rejects role filters when the panel declares scopes but names no evaluator', function (): void {
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

    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)
        ->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [ConfiguredProjectScope::make()]))
        ->roles([$role::class])]))
        ->toThrow(DefinitionException::class, 'without an evaluator that applies them');
});

it('reports a scope membership that cannot be created as a doctor error, not a compile failure', function (): void {
    Relation::morphMap(['project' => Project::class], false);
    [, , $registry] = PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)
        ->plugins([ScopesPlugin::inherit(Project::class)->requireMembership()])]);

    $findings = ScopeMembershipConfigured::check($registry->get('admin'), app());

    expect(array_map(static fn ($finding): string => $finding->key, $findings))->toContain('membership.configured')
        ->and(implode("\n", array_map(static fn ($finding): string => $finding->message, $findings)))->toContain('AssignmentScopeMembership');
});
