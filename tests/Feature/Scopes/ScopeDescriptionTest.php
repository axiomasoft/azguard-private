<?php

declare(strict_types=1);

use AzGuard\Context\AssignmentScopeDescription;
use AzGuard\Context\Internal\ContextIdentity;
use AzGuard\Contracts\Scopes\DescribesAssignmentScope;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\BaseAssignmentScope;
use AzGuard\Scopes\ModelAssignmentScopeDefinition;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\ActiveProjects;
use AzGuard\Tests\Fixtures\Scopes\ConfiguredProjectScope;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\Project;
use AzGuard\Tests\Fixtures\Scopes\ProjectDirectory;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** An external definition that describes itself instead of extending the module base. */
function describedStore(AssignmentScopeDescription $description): DescribesAssignmentScope
{
    return new class($description) extends StoreScope implements DescribesAssignmentScope
    {
        public function __construct(private readonly AssignmentScopeDescription $facts)
        {
            parent::__construct();
        }

        public function description(): AssignmentScopeDescription
        {
            return $this->facts;
        }
    };
}

it('describes a module definition by its filters, label, directory, owner and base resolver', function (): void {
    $filter = new ActiveProjects;
    $configured = ConfiguredProjectScope::make()->filter($filter)->filter(ActiveProjects::class)->label('Projects')->directory(ProjectDirectory::class);
    $custom = new class extends BaseAssignmentScope
    {
        public function type(): string
        {
            return 'crm.project';
        }

        public function query(): Builder
        {
            return Project::query();
        }

        public function tenantOf(Model $record): TenantRef
        {
            return TenantRef::global();
        }

        public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
        {
            return null;
        }
    };

    expect(ContextIdentity::describe($configured))->toEqual(new AssignmentScopeDescription([$filter, ActiveProjects::class], 'Projects', ProjectDirectory::class, true, true))
        ->and(ContextIdentity::describe($custom)->resolvesThroughQuery)->toBeFalse()
        ->and(ContextIdentity::describe(ModelAssignmentScopeDefinition::make(Project::class, type: 'project'))->tenantOwner)->toBeFalse()
        ->and(ContextIdentity::describe(ModelAssignmentScopeDefinition::make(Project::class, owner: static fn () => TenantRef::global(), type: 'project'))->tenantOwner)->toBeTrue();
});

it('describes a definition that gives no description with the defaults: no components, an owner, a custom resolver', function (): void {
    expect(ContextIdentity::describe(new StoreScope))->toEqual(new AssignmentScopeDescription);
});

it('takes the tenant owner and the default directory of a custom definition from its description', function (): void {
    $tenantPanel = static fn (DescribesAssignmentScope|StoreScope $definition) => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)
        ->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership))
        ->plugins([ScopesPlugin::inherit($definition)])]);

    expect(fn () => $tenantPanel(describedStore(new AssignmentScopeDescription(tenantOwner: false))))
        ->toThrow(InvalidConfigurationException::class, 'requires an authoritative tenant owner for assignment scope store')
        ->and(fn () => $tenantPanel(new StoreScope))->not->toThrow(InvalidConfigurationException::class);

    [, , $registry] = PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)
        ->plugins([ScopesPlugin::inherit(describedStore(new AssignmentScopeDescription(label: 'Stores', directory: ProjectDirectory::class)))])]);
    expect($registry->get('admin')->scopeDeclaration()?->directories)->toBe(['store' => ProjectDirectory::class]);
});
