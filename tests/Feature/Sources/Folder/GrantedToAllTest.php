<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Permissions\GrantedToAll;
use AzGuard\Permissions\PolicyOnly;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Sources\PanelSources;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Guards\Shop\ShopWorld;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Eloquent\Relations\Relation;

#[RequiresGrant]
enum ExtraEveryonePermission: string
{
    #[GrantedToAll] case Read = 'everyone.read';
}
#[PolicyOnly]
enum InvalidEveryonePermission: string
{
    #[GrantedToAll] case Read = 'everyone.policy';
}

beforeEach(fn () => ShopWorld::seed());
afterEach(fn () => Relation::morphMap([], false));

it('grants exactly its declared permission without making all actions public', function (): void {
    $registry = ShopWorld::compile();
    $panel = $registry->get('shop');
    expect(app(Authorizer::class)->decide($panel, ShopWorld::request('shop.browse'))->allowed())->toBeTrue()
        ->and(app(Authorizer::class)->decide($panel, ShopWorld::request('shop.edit'))->reason)->toBe(DecisionReason::NotGranted)
        ->and(app(Authorizer::class)->decide($panel, ShopWorld::request('shop.browse', 99))->allowed())->toBeFalse();
});

it('grants discovered and explicitly registered enums at each requested scope only to accepted existing subjects', function (): void {
    $registry = ShopWorld::compile([ExtraEveryonePermission::class]);
    $panel = $registry->get('shop');
    $folder = PanelSources::of($registry->recipe('shop'), app())->all()[0]['source'];
    $scopes = [AccessScope::in(TenantRef::global()), AccessScope::in(TenantRef::of('org', 2), AssignmentScopeRef::of('store', 3))];
    $frame = new EvaluationFrame($panel, $scopes[0], CodeStateToken::of('shop', 'build', 'fingerprint'), new DateTimeImmutable, ActorRef::of('user', 1), subject: User::query()->find(1));
    $grants = iterator_to_array($folder->grants(SubjectRef::of('user', 1), $scopes, $frame), false);
    expect($grants)->toHaveCount(4)->and($folder->volatility())->toBe(Volatility::Request);
    foreach ($grants as $grant) {
        expect($grant->origin)->toBe('granted_to_all')->and($grant->role)->toBeNull()->and($grant->expiresAt)->toBeNull()
            ->and(in_array($grant->scope, $scopes, true))->toBeTrue();
    }
    expect(iterator_to_array($folder->grants(SubjectRef::of('foreign', 1), $scopes, $frame)))->toBe([]);
    $missing = new EvaluationFrame($panel, $scopes[0], $frame->state(), $frame->now(), $frame->actor());
    expect(iterator_to_array($folder->grants(SubjectRef::of('user', 99), $scopes, $missing)))->toBe([]);
});

it('rejects GrantedToAll on PolicyOnly at compilation', function (): void {
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn ($panel) => $panel->permissions([InvalidEveryonePermission::class])]))
        ->toThrow(DefinitionException::class, 'GrantedToAll');
});

it('gives the same declared right as an equivalent generated contribution', function (): void {
    $folder = ShopWorld::compile();
    $before = app(Authorizer::class)->decide($folder->get('shop'), ShopWorld::request('shop.browse'));
    $manual = Grant::of(PermissionPattern::of('shop', 'orders.view'), 'generated', AccessScope::in(TenantRef::global()));
    $registry = ShopWorld::compile([new GeneratedSource(direct: [$manual])]);
    $after = app(Authorizer::class)->decide($registry->get('shop'), ShopWorld::request('orders.view'));
    expect($after->effect)->toBe($before->effect)->and($after->reason)->toBe($before->reason)
        ->and(app(Authorizer::class)->decide($registry->get('shop'), ShopWorld::request('shop.edit'))->allowed())->toBeFalse();
});
