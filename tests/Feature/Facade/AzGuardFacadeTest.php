<?php

declare(strict_types=1);

use AzGuard\Exceptions\AmbiguousPanelException;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\InvalidAssignmentScopeException;
use AzGuard\Exceptions\PanelNotResolvedException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/*
 * 05 §3: check/authorize pick the panel by the rule of the panel resolver; withinScope/currentScope work on the
 * panel of the request or the default panel; actingAs names who issues the changes made inside it.
 */

beforeEach(function (): void {
    CrmWorld::seed();
    ChangeWorld::panel();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

/** The request panel: crm with tenant A as the current scope, as the middleware of a request would leave it. */
function facadeRequestOf(int $tenant = 1): void
{
    $panel = AzGuard::panels()['crm'];
    app(CurrentPanel::class)->set($panel);
    app(CurrentContext::class)->set($panel, CrmWorld::scope($tenant));
}

it('checks a permission in the panel the resolver picks and answers as the subject wrapper does', function (): void {
    $anna = User::query()->findOrFail(1);
    $boris = User::query()->findOrFail(2);
    $client = Client::query()->findOrFail(1);

    expect(AzGuard::check($anna, ClientPermission::Update, $client, 'crm'))->toBeTrue()
        ->and(AzGuard::check($anna, 'crm:clients.update', $client))->toBeTrue()
        ->and(AzGuard::check(SubjectRef::of('crm.user', 1), 'crm:clients.update', $client))->toBeTrue()
        ->and(AzGuard::check($boris, ClientPermission::Update, $client, 'crm'))->toBeFalse()
        ->and(AzGuard::check($anna, 'clients.update', $client, 'backoffice'))->toBeFalse()
        ->and(AzGuard::check($anna, 'backoffice:clients.update', $client))->toBeFalse()
        ->and(AzGuard::check($anna, 'crm:clients.update', $client, 'crm'))->toBeTrue()
        ->and(fn () => AzGuard::check($anna, 'crm:clients.update', $client, 'backoffice'))->toThrow(ConflictingPanelException::class)
        ->and(fn () => AzGuard::check($anna, ClientPermission::Update, $client))->toThrow(AmbiguousPanelException::class)
        ->and(fn () => AzGuard::check($anna, 'crm:clients.nope', $client))->toThrow(UnknownPermissionException::class);
});

it('takes the panel of the request when nothing else names one, and an explicit name over it', function (): void {
    $anna = User::query()->findOrFail(1);
    $client = Client::query()->findOrFail(1);
    facadeRequestOf();

    expect(AzGuard::check($anna, 'clients.update', $client))->toBeTrue()
        ->and(AzGuard::check($anna, ClientPermission::Update, $client, 'crm'))->toBeTrue()
        ->and(AzGuard::check($anna, 'backoffice:clients.update', $client))->toBeFalse()
        ->and(app(CurrentPanel::class)->get()?->id())->toBe('crm');
});

it('refuses a subject that is not a model or a reference, and one the picked panel does not accept', function (): void {
    expect(fn () => AzGuard::check(new GenericUser(['id' => 1]), 'crm:clients.update'))->toThrow(SubjectNotAcceptedException::class)
        ->and(fn () => AzGuard::check('anna', 'crm:clients.update'))->toThrow(SubjectNotAcceptedException::class)
        ->and(fn () => AzGuard::check(null, 'crm:clients.update'))->toThrow(SubjectNotAcceptedException::class)
        ->and(fn () => AzGuard::check(SubjectRef::of('crm.organization', 1), 'crm:clients.update'))->toThrow(SubjectNotAcceptedException::class);
});

it('authorizes with the reason code and no explanation, and passes silently when allowed', function (): void {
    $anna = User::query()->findOrFail(1);
    $boris = User::query()->findOrFail(2);
    $client = Client::query()->findOrFail(1);

    AzGuard::authorize($anna, 'crm:clients.update', $client);

    try {
        AzGuard::authorize($boris, 'crm:clients.update', $client);
        $thrown = null;
    } catch (AuthorizationException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(AuthorizationException::class)
        ->and($thrown->response()?->code())->toBe('not_granted')
        ->and($thrown->response()?->allowed())->toBeFalse()
        ->and($thrown->getMessage())->toBe('This action is unauthorized.')
        ->and(fn () => AzGuard::authorize($boris, 'backoffice:clients.update', $client))->toThrow(AuthorizationException::class);
});

it('runs a callback in a context of the request panel and restores the previous one, also after an exception', function (): void {
    facadeRequestOf();
    $panel = AzGuard::currentPanel() ?? throw new RuntimeException('No current panel.');
    $p1 = AssignmentScopeRef::of('crm.project', 1);
    $p2 = AssignmentScopeRef::of('crm.project', 2);

    expect(AzGuard::currentScope())->toBeNull();

    $result = AzGuard::withinScope($p1, function () use ($p2, $panel): array {
        $outer = AzGuard::currentScope();
        $inner = AzGuard::withinScope($p2, static fn (): ?AssignmentScopeRef => AzGuard::currentScope());

        return [$outer?->key(), $inner?->key(), AzGuard::currentScope()?->key(), app(CurrentContext::class)->get($panel)?->tenant->key()];
    });

    expect($result)->toBe(['crm.project:1', 'crm.project:2', 'crm.project:1', 'crm.organization:1'])
        ->and(AzGuard::currentScope())->toBeNull()
        ->and(fn () => AzGuard::withinScope($p1, static fn () => throw new LogicException('inside')))->toThrow(LogicException::class)
        ->and(AzGuard::currentScope())->toBeNull()
        ->and(app(CurrentContext::class)->get($panel)?->context->isGlobal())->toBeTrue();
});

it('takes a model of an assignment scope type as the context and refuses one that is not', function (): void {
    facadeRequestOf();

    expect(AzGuard::withinScope(Project::query()->findOrFail(1), static fn (): ?string => AzGuard::currentScope()?->key()))->toBe('crm.project:1')
        ->and(fn () => AzGuard::withinScope(Client::query()->findOrFail(1), static fn () => 1))->toThrow(AssignmentScopeNotAcceptedException::class)
        ->and(fn () => AzGuard::withinScope(AssignmentScopeRef::of('crm.project', 4), static fn () => 1))->toThrow(InvalidAssignmentScopeException::class)
        ->and(AzGuard::currentScope())->toBeNull();
});

it('narrows a check inside withinScope to the scope the callback runs in', function (): void {
    $anna = User::query()->findOrFail(1);
    $client = Client::query()->findOrFail(1);
    facadeRequestOf();

    $inside = AzGuard::withinScope(AssignmentScopeRef::of('crm.project', 1), static fn (): array => [
        AzGuard::currentScope()?->key(),
        AzGuard::check($anna, 'crm:clients.update', $client),
    ]);

    expect($inside)->toBe(['crm.project:1', true]);
});

it('has no scope to work in when the request has no panel and none is the default', function (): void {
    expect(AzGuard::currentScope())->toBeNull()
        ->and(fn () => AzGuard::withinScope(AssignmentScopeRef::of('crm.project', 1), static fn () => 1))->toThrow(PanelNotResolvedException::class);
});

it('issues the changes of the callback in the name of an explicit actor', function (): void {
    $anna = User::query()->findOrFail(1);
    $boris = User::query()->findOrFail(2);
    $p5 = AssignmentScopeRef::of('crm.project', 5);
    $subject = AzGuard::panel('crm')->inTenant(Organization::query()->findOrFail(1))->for($anna);
    $actorOf = static fn ($result): ?ActorRef => $result->record?->actor;

    $system = AzGuard::actingAs('import: crm', static fn () => $subject->grantRole('caller', on: $p5));
    $subject->revokeRole('caller', on: $p5);
    $model = AzGuard::actingAs($boris, static fn () => $subject->grantRole('caller', on: $p5));
    $subject->revokeRole('caller', on: $p5);
    $reference = AzGuard::actingAs(SubjectRef::of('crm.user', 3), static fn () => $subject->grantRole('caller', on: $p5));
    $subject->revokeRole('caller', on: $p5);
    $nested = AzGuard::actingAs($boris, static fn () => [
        AzGuard::actingAs('inner', static fn () => $subject->grantRole('caller', on: $p5)),
    ]);

    expect($actorOf($system))->toEqual(ActorRef::system('import: crm'))
        ->and($actorOf($model))->toEqual(ActorRef::of('crm.user', 2))
        ->and($actorOf($reference))->toEqual(ActorRef::of('crm.user', 3))
        ->and($actorOf($nested[0]))->toEqual(ActorRef::system('inner'));
});

it('returns the result of the actingAs callback, restores the actor after it and refuses an actor that is no identity', function (): void {
    $anna = User::query()->findOrFail(1);
    $p5 = AssignmentScopeRef::of('crm.project', 5);
    $subject = AzGuard::panel('crm')->inTenant(Organization::query()->findOrFail(1))->for($anna);

    expect(AzGuard::actingAs('seed', static fn (): int => 42))->toBe(42)
        ->and(fn () => AzGuard::actingAs('seed', static fn () => throw new LogicException('inside')))->toThrow(LogicException::class)
        ->and($subject->grantRole('caller', on: $p5)->record?->actor)->toEqual(ActorRef::system('console'))
        ->and(fn () => AzGuard::actingAs('', static fn () => 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => AzGuard::actingAs('   ', static fn () => 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => AzGuard::actingAs(new GenericUser(['id' => 1]), static fn () => 1))->toThrow(InvalidArgumentException::class);
});

it('never reads the tenant of a request from the actor or the callback of the facade', function (): void {
    $anna = User::query()->findOrFail(1);
    $tenantA = Organization::query()->findOrFail(1);
    $a = AzGuard::panel('crm')->inTenant($tenantA)->for($anna);
    $p5 = AssignmentScopeRef::of('crm.project', 5);

    AzGuard::actingAs('import: crm', static fn () => $a->grantRole('caller', on: $p5));

    expect(AzGuard::panel('crm')->inTenant(TenantRef::of('crm.organization', 2))->for($anna)->roleNames(on: $p5)->all())->toBe([])
        ->and($a->roleNames(on: $p5)->all())->toBe(['caller']);
});
