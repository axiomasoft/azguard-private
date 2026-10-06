<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\PluginConflictException;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Tests\Fixtures\Crm\CrmAccessPlugin;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission as Action;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Crm\PublicPanel;
use AzGuard\Tests\Fixtures\Crm\RenamedClientPolicy;
use AzGuard\Tests\Fixtures\Crm\UnattributedClientPolicy;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use Illuminate\Database\Eloquent\Builder;

it('R45 R46 local typed plugin inputs are independent in CRM and backoffice', function (): void {
    CrmAccessPlugin::$boots = [];
    World::assign('analyst', 1, 2, panel: 'backoffice');
    $crm = World::compile(fn (PanelBuilder $p) => $p->plugins([CrmAccessPlugin::make(city: 1)]),
        backoffice: fn (PanelBuilder $p) => $p->plugins([CrmAccessPlugin::make(city: 2)]));
    $backoffice = app(PanelRegistry::class)->get('backoffice');
    World::assertDecision(World::decide($crm), true, DecisionReason::Granted);
    World::assertDecision(World::decide($crm, 3), false, DecisionReason::AssignmentScopeIneligible);
    World::assertDecision(World::decide($backoffice, 3), true, DecisionReason::Granted);
    World::assertDecision(World::decide($backoffice), false, DecisionReason::AssignmentScopeIneligible);
    expect(CrmAccessPlugin::$boots)->toBe(['crm', 'backoffice']);
});

it('R48 local duplicate plugin is rejected and worker rebuild boots once per new registry', function (): void {
    expect(fn () => World::compile(fn (PanelBuilder $p) => $p->plugins([CrmAccessPlugin::make(1), CrmAccessPlugin::make(1)])))
        ->toThrow(PluginConflictException::class);
    CrmAccessPlugin::$boots = [];
    for ($i = 0; $i < 2; $i++) {
        $panel = World::compile(fn (PanelBuilder $p) => $p->plugins([CrmAccessPlugin::make(1)]));
        World::assertDecision(World::decide($panel), true, DecisionReason::Granted);
    }
    expect(CrmAccessPlugin::$boots)->toBe(['crm', 'crm']);
});

it('R57 local filter build fingerprint changes while equal configuration remains deterministic', function (): void {
    $build = function (int $city): string {
        World::compile(fn (PanelBuilder $p) => $p->plugins([CrmAccessPlugin::make($city)]));

        return app(PanelRegistry::class)->fingerprint('crm');
    };
    expect($build(1))->toBe($build(1))->not->toBe($build(2));
});

it('R57 build rejects a captured live host model instead of retaining request inputs', function (): void {
    $project = Project::query()->findOrFail(1);
    $definition = ProjectScope::make()->filter(static function (Builder $query) use ($project): void {
        $query->whereKey($project->getKey());
    });
    expect(fn () => World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit($definition))))
        ->toThrow(DefinitionException::class, 'live model');
    World::assertDecision(World::decide(World::compile()), true, DecisionReason::Granted);
});

it('R67 typed filter class removal and incorrect factory inputs are actionable failures', function (): void {
    expect(fn () => ProjectScope::make()->filter('AzGuard\\Tests\\Fixtures\\Crm\\RemovedFilter'))->toThrow(DefinitionException::class)
        ->and(fn () => CrmAccessPlugin::make(city: 'Kazan'))->toThrow(TypeError::class)
        ->and(fn () => CrmAccessPlugin::make(unknown: 1))->toThrow(Error::class);
    World::assertDecision(World::decide(World::compile()), true, DecisionReason::Granted);
});

it('R67 removed declared policy or Decides attribute is a compile error instead of optional pass', function (string $policy): void {
    expect(fn () => PanelWorld::compile([PublicPanel::class => fn (PanelBuilder $p) => $p->for(User::class)
        ->permissions([Action::class])->policies([PolicyBinding::for(Action::ViewOwnProfile, $policy)])]))
        ->toThrow(DefinitionException::class);
    expect(fn () => PanelWorld::compile([PublicPanel::class => fn (PanelBuilder $p) => $p->for(User::class)
        ->permissions([Action::class])->policies([PolicyBinding::for(Action::ViewOwnProfile, RenamedClientPolicy::class)])]))
        ->not->toThrow(DefinitionException::class);
})->with([UnattributedClientPolicy::class, 'AzGuard\\Tests\\Fixtures\\Crm\\RemovedPolicy']);
