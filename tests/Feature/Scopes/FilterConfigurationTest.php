<?php

declare(strict_types=1);

use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelFingerprint;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Scopes\ModelAssignmentScopeDefinition;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\ActiveProjects;
use AzGuard\Tests\Fixtures\Scopes\ConfigCountingFilter;
use AzGuard\Tests\Fixtures\Scopes\ConfigGenericScope;
use AzGuard\Tests\Fixtures\Scopes\ConfiguredProjectScope;
use AzGuard\Tests\Fixtures\Scopes\Project;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Builder;

it('registers typed access adapters on an immutable policy', function (): void {
    $adapter = new class implements AssignmentScopeAccessAdapter
    {
        public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool
        {
            return true;
        }

        public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array
        {
            return [];
        }

        public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder
        {
            return $contextQuery;
        }
    };
    $policy = AssignmentScopePolicy::inherit(StoreScope::class);
    $configured = $policy->accessAdapter('store', $adapter);
    [, , $registry] = PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->scopes($configured)]);

    expect($policy->adapters())->toBe([])
        ->and($registry->get('admin')->scopes()->adapters())->toBe(['store' => $adapter]);
});

it('rejects invalid access adapter type aliases and removed classes', function (): void {
    expect(fn () => AssignmentScopePolicy::none()->accessAdapter('invalid:type', AssignmentScopeAccessAdapter::class))
        ->toThrow(DefinitionException::class, 'type alias');
    expect(fn () => AssignmentScopePolicy::none()->accessAdapter('store', 'RemovedAdapter'))
        ->toThrow(DefinitionException::class, 'implement');
});

it('requires a binding for access adapter interfaces and known scope types', function (): void {
    $compile = fn (AssignmentScopePolicy $policy) => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->scopes($policy)]);
    expect(fn () => $compile(AssignmentScopePolicy::inherit(StoreScope::class)->accessAdapter('store', AssignmentScopeAccessAdapter::class)))
        ->toThrow(DefinitionException::class, 'container binding');
    expect(fn () => $compile(AssignmentScopePolicy::inherit(StoreScope::class)->accessAdapter('other', new class implements AssignmentScopeAccessAdapter
    {
        public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool
        {
            return true;
        }

        public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array
        {
            return [];
        }

        public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder
        {
            return $contextQuery;
        }
    })))
        ->toThrow(DefinitionException::class, 'unknown assignment scope');
});

it('accepts class object and closure filters without resolving typed classes at build', function (): void {
    ConfigCountingFilter::$instances = 0;
    $definition = ConfiguredProjectScope::make()->filter(ConfigCountingFilter::class)->filter(new ActiveProjects)
        ->filter(static function (Builder $query, AssignmentScopeRuntime $runtime): void {
            $query->where('is_active', true);
        });
    [, , $registry] = PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->scopes(AssignmentScopePolicy::inherit($definition))]);
    expect(ConfigCountingFilter::$instances)->toBe(0)
        ->and($registry->get('admin')->scopeDefinition('crm.project')->settings()->filters)->toHaveCount(3);
});

it('rejects an unbound filter interface at build and accepts its bound deployment', function (): void {
    $definition = ConfiguredProjectScope::make()->filter(AssignmentScopeFilter::class);
    $compile = fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->scopes(AssignmentScopePolicy::inherit($definition))]);
    expect($compile)->toThrow(DefinitionException::class, 'container binding');
    app()->bind(AssignmentScopeFilter::class, ConfigCountingFilter::class);
    ConfigCountingFilter::$instances = 0;
    expect($compile)->not->toThrow(DefinitionException::class)
        ->and(ConfigCountingFilter::$instances)->toBe(0);
});

it('rejects native filters for generic definitions and reports removed filter classes', function (): void {
    $generic = (new ConfigGenericScope)->filter(new ActiveProjects);
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->scopes(AssignmentScopePolicy::inherit($generic))]))
        ->toThrow(DefinitionException::class, 'queryable model');
    expect(fn () => ConfiguredProjectScope::make()->filter('RemovedFilter'))->toThrow(DefinitionException::class, 'filter()');
});

it('rejects filter constructor dependencies that cannot be resolved without a binding', function (): void {
    $filter = new class('active') implements AssignmentScopeFilter
    {
        public function __construct(public string $column) {}

        public function apply(Builder $query, AssignmentScopeRuntime $runtime): void {}
    };
    $definition = ConfiguredProjectScope::make()->filter($filter::class);
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->scopes(AssignmentScopePolicy::inherit($definition))]))
        ->toThrow(DefinitionException::class, 'constructor parameter');
});

it('merges common filters across configure plugin and provider with identity intact', function (): void {
    $recipe = new PanelRecipe('admin');
    foreach ([PanelRecipe::configure(), PanelRecipe::plugin('filters', 0), PanelRecipe::provider()] as $i => $origin) {
        $recipe->during($origin, fn () => $recipe->record(PanelRecipe::SCOPES, AssignmentScopePolicy::inherit(
            ConfiguredProjectScope::make()->label('layer'.$i)->filter(static function (Builder $query) use ($i): void {
                $query->where('layer', $i);
            }),
        )));
    }
    $panel = (new PanelCompiler)->compile($recipe, container: app());
    $definition = $panel->scopeDefinition('crm.project');
    expect($definition->settings()->filters)->toHaveCount(3)->and($definition->settings()->label)->toBe('layer2');
});

it('rejects a changed owner despite identical model class and scope type', function (): void {
    $recipe = new PanelRecipe('admin');
    $recipe->record(PanelRecipe::SCOPES, AssignmentScopePolicy::inherit(ModelAssignmentScopeDefinition::make(Project::class, type: 'project')));
    $recipe->record(PanelRecipe::SCOPES, AssignmentScopePolicy::inherit(ModelAssignmentScopeDefinition::make(Project::class, owner: static fn () => TenantRef::global(), type: 'project')));
    expect(fn () => (new PanelCompiler)->compile($recipe, container: app()))->toThrow(DefinitionException::class, 'conflicting');
});

it('fingerprints scalar closure configuration and rejects live runtime captures', function (): void {
    $fingerprint = function (int $city): string {
        $recipe = new PanelRecipe('admin');
        $recipe->record(PanelRecipe::SCOPES, AssignmentScopePolicy::inherit(ConfiguredProjectScope::make()
            ->filter(static function (Builder $query) use ($city): void {
                $query->where('city_id', $city);
            })));
        $panel = (new PanelCompiler)->compile($recipe, container: app());

        return PanelFingerprint::of($panel, $recipe);
    };
    expect($fingerprint(1))->toBe($fingerprint(1))->not->toBe($fingerprint(2));
    $project = new Project;
    $definition = ConfiguredProjectScope::make()->filter(static function (Builder $query) use ($project): void {
        $query->whereKey($project->getKey());
    });
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->scopes(AssignmentScopePolicy::inherit($definition))]))
        ->toThrow(DefinitionException::class, 'live model');
});
