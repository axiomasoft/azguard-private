<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Directories\LookupContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\ActiveProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission as Action;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Crm\ProjectDirectory;
use AzGuard\Tests\Fixtures\Crm\PublicPanel;
use AzGuard\Tests\Fixtures\Crm\RenamedClientPolicy;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;

it('R50 local public definition filter and directory use structural queries before eligibility', function (): void {
    $definition = ProjectScope::make()->filter(new ActiveProjects)->directory(ProjectDirectory::class);
    expect($definition)->toBeInstanceOf(AssignmentScopeDefinition::class)
        ->and(new ActiveProjects)->toBeInstanceOf(AssignmentScopeFilter::class)
        ->and(new ProjectDirectory)->toBeInstanceOf(AssignmentScopeDirectory::class);
    $first = $definition->query();
    $first->whereKey(1);
    expect($definition->query())->not->toBe($first)->and($definition->query()->count())->toBe(5);
    $inactive = $definition->resolve(AssignmentScopeRef::of('crm.project', 3));
    expect($inactive->record->getKey())->toBe(3)->and($inactive->tenant->id())->toBe('1');
    $panel = World::compile();
    $lookup = new LookupContext($panel, World::scope(), ActorRef::system(), null, SubjectRef::of('crm.user', 1),
        User::query()->findOrFail(1), null, [], AssignmentScopePhase::Inspection, new DateTimeImmutable('2026-10-06T12:00:00Z'));
    $directory = new ProjectDirectory;
    expect(array_map(fn ($option) => $option->scope->id(), $directory->search('crm.project', '1', $lookup, 10)))->toBe(['1'])
        ->and($directory->describe(AssignmentScopeRef::of('crm.project', 4), $lookup))->toBeNull();
    World::assertDecision(World::decide($panel), true, DecisionReason::Granted);
    World::assertDecision(World::decide($panel, 4), false, DecisionReason::AssignmentScopeIneligible);
});

it('R67 renamed Decides method still supplies local public consumer policy authority', function (): void {
    [,, $registry] = PanelWorld::compile([PublicPanel::class => fn (PanelBuilder $p) => $p->for(User::class)
        ->permissions([Action::class])->policies([PolicyBinding::for(Action::ViewOwnProfile, RenamedClientPolicy::class)])]);
    app()->instance(PanelRegistry::class, $registry);
    app()->forgetInstance(Authorizer::class);
    $panel = $registry->get('crm-public');
    $request = AccessRequest::for(SubjectRef::of('crm.user', 1), PermissionKey::of('crm-public', Action::ViewOwnProfile->value))
        ->on(null, Client::query()->findOrFail(1));
    World::assertDecision(app(Authorizer::class)->decide($panel, $request), true, DecisionReason::Policy);
    World::assertDecision(app(Authorizer::class)->decide($panel, $request->on(null, Client::query()->findOrFail(6))), false, DecisionReason::Policy);
});
