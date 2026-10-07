<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Changes\ChangeType;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission as Action;

/** The decision of the real authorizer for a permission with no resource, in one tenant and project. */
function dynamicDecision(Panel $panel, string $name, int $user, int $tenant, ?int $project): Decision
{
    $request = AccessRequest::for(SubjectRef::of('crm.user', $user), PermissionKey::of('crm', $name))
        ->inScope(World::scope($tenant, $project))->traced();

    return app(Authorizer::class)->decide($panel, $request);
}

it('R23 keeps an opt-in dynamic action and its direct grant inside tenant A and creates no role', function (): void {
    $panel = W::dynamicPanel();
    $roles = app(PanelRegistry::class)->catalog('crm')->roles();
    $roleRows = W::rows('role');

    expect(fn () => W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('campaigns.view'), W::project(2)))
        ->toThrow(UnknownPermissionException::class);

    $created = W::createAction($panel, 'campaigns.view', label: 'Просмотр кампаний', group: 'Кампании');
    $grant = W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('campaigns.view'), W::project(2));

    expect($created->effects[0]->type)->toBe(ChangeType::CreatePermission)
        ->and($grant->effects[0]->type)->toBe(ChangeType::GrantPermission)
        ->and(W::actionNames())->toBe(['crm.organization:1|campaigns.view'])
        ->and(W::keys('permission'))->toBe(['crm.organization:1|campaigns.view|2|crm.project:2|manual'])
        ->and(W::rows('role'))->toBe($roleRows)
        ->and(app(PanelRegistry::class)->catalog('crm')->roles())->toBe($roles);

    World::assertDecision(dynamicDecision($panel, 'campaigns.view', 2, 1, 2), true, DecisionReason::Granted);
    World::assertDecision(dynamicDecision($panel, 'campaigns.view', 2, 1, 1), false, DecisionReason::NotGranted);

    // Tenant B: the same code roles, no such action and no grant; Anna is a member of both organizations.
    expect(fn () => W::pipeline()->grant($panel, W::tenant(2), W::user(1), W::permission('campaigns.view'), W::project(4)))
        ->toThrow(UnknownPermissionException::class)
        ->and(fn () => dynamicDecision($panel, 'campaigns.view', 1, 2, 4))->toThrow(UnknownPermissionException::class);
});

it('R23 refuses to create a dynamic action when the panel did not opt in', function (): void {
    $panel = W::panel();
    $rows = [W::actions(), W::version()];

    expect(fn () => W::createAction($panel, 'campaigns.view'))->toThrow(PanelNotWritableException::class)
        ->and([W::actions(), W::version()])->toBe($rows);
});

it('R23 writes with a roles-only writer that keeps the action definition but never a direct grant', function (): void {
    $panel = W::dynamicPanel(rolesOnly: true);

    expect(W::createAction($panel, 'campaigns.view')->applied())->toBeTrue()
        ->and(fn () => W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('campaigns.view'), W::project(2)))
        ->toThrow(PanelNotWritableException::class)
        ->and(W::keys('permission'))->toBe([]);
});

it('R59 removes the exact grants of a deleted action in the tenant and keeps the independent grants and the other tenant', function (): void {
    $panel = W::dynamicPanel();
    W::createAction($panel, 'campaigns.view');
    W::createAction($panel, 'campaigns.view', tenant: 2);
    W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('campaigns.view'), W::project(2));
    W::pipeline()->grant($panel, W::tenant(), W::user(1), W::permission('campaigns.*'), W::project(null));
    W::pipeline()->grant($panel, W::tenant(2), W::user(1), W::permission('campaigns.view'), W::project(4));
    World::assertDecision(dynamicDecision($panel, 'campaigns.view', 2, 1, 2), true, DecisionReason::Granted);
    World::assertDecision(dynamicDecision($panel, 'campaigns.view', 1, 1, 1), true, DecisionReason::Granted);

    $deleted = W::deleteAction($panel, 'campaigns.view');

    expect($deleted->removedGrantIds())->toHaveCount(1)
        ->and(W::keys('permission'))->toBe(['crm.organization:1|campaigns.*|1|global|manual', 'crm.organization:2|campaigns.view|1|crm.project:4|manual']);

    // The action is gone in tenant A, so neither Boris's exact grant nor Anna's pattern gives any authority for the
    // name (the catalog no longer knows it); tenant B keeps its action and its grant.
    expect(fn () => dynamicDecision($panel, 'campaigns.view', 2, 1, 2))->toThrow(UnknownPermissionException::class)
        ->and(fn () => dynamicDecision($panel, 'campaigns.view', 1, 1, 1))->toThrow(UnknownPermissionException::class);
    World::assertDecision(dynamicDecision($panel, 'campaigns.view', 1, 2, 4), true, DecisionReason::Granted);
});

it('R59 never resurrects a deleted grant: a grant after the delete is refused and a recreated action starts empty', function (): void {
    $panel = W::dynamicPanel();
    W::createAction($panel, 'campaigns.view');
    W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('campaigns.view'), W::project(2));
    W::deleteAction($panel, 'campaigns.view');

    expect(fn () => W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('campaigns.view'), W::project(2)))
        ->toThrow(UnknownPermissionException::class);

    W::createAction($panel, 'campaigns.view', label: 'Снова');

    expect(W::keys('permission'))->toBe([]);
    World::assertDecision(dynamicDecision($panel, 'campaigns.view', 2, 1, 2), false, DecisionReason::NotGranted);
});

it('R65 grants an enum permission without any copy in permissions, refuses a runtime action before the opt-in and keeps Grants mode', function (): void {
    $plain = W::panel();
    W::pipeline()->grant($plain, W::tenant(), W::user(2), W::permission('clients.update'), W::project(2));

    World::assertDecision(World::decide($plain, 3, Action::Update, 2), true, DecisionReason::Granted);

    expect(W::actions())->toBe([])
        ->and(fn () => W::createAction($plain, 'campaigns.view'))->toThrow(PanelNotWritableException::class)
        ->and(W::actions())->toBe([]);

    $dynamic = W::dynamicPanel();
    W::createAction($dynamic, 'campaigns.view');
    $catalog = app(PanelRegistry::class)->catalog('crm');

    expect($catalog->get('clients.update')->authority)->toBe(PermissionAuthority::Grants)
        ->and($catalog->has('campaigns.view'))->toBeFalse()
        ->and(W::actionNames())->toBe(['crm.organization:1|campaigns.view']);

    World::assertDecision(World::decide($dynamic, 3, Action::Update, 2), true, DecisionReason::Granted);
    World::assertDecision(dynamicDecision($dynamic, 'campaigns.view', 2, 1, 2), false, DecisionReason::NotGranted);
});
