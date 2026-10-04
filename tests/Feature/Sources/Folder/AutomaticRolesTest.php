<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Sources\PanelSources;
use AzGuard\Tests\Fixtures\Authorization\ExternalAutomaticRole;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Guards\Shop\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Guards\Shop\ShopWorld;
use AzGuard\Tests\Fixtures\Guards\Shop\Store;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Sources\StaticSource;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => ShopWorld::seed());
afterEach(fn () => Relation::morphMap([], false));

it('assigns automatic seller by target stores and root by is_root on each operation', function (): void {
    $registry = ShopWorld::compile();
    $engine = app(Authorizer::class);
    $panel = $registry->get('shop');
    expect($engine->decide($panel, ShopWorld::request())->reason)->toBe(DecisionReason::Granted)
        ->and($engine->decide($panel, ShopWorld::request(id: 2))->reason)->toBe(DecisionReason::SuperAdmin);
    Store::query()->delete();
    expect($engine->decide($panel, ShopWorld::request())->reason)->toBe(DecisionReason::NotGranted);
    User::query()->whereKey(2)->toBase()->update(['is_root' => false]);
    expect($engine->decide($panel, ShopWorld::request(id: 2))->reason)->toBe(DecisionReason::NotGranted);
});

it('adds manual and automatic contributions of one role without conflict', function (): void {
    $manual = RoleContribution::of(RoleKey::of('shop', 'seller'), AccessScope::in(TenantRef::global()), 'generated');
    $registry = ShopWorld::compile([new GeneratedSource(roles: [$manual])]);
    expect(app(Authorizer::class)->decide($registry->get('shop'), ShopWorld::request())->allowed())->toBeTrue();
    Store::query()->delete();
    expect(app(Authorizer::class)->decide($registry->get('shop'), ShopWorld::request())->allowed())->toBeTrue();
});

it('evaluates automatic roles from the compiled catalogue including other definition sources', function (): void {
    $registry = ShopWorld::compile([new StaticSource(roles: [new ExternalAutomaticRole])]);
    expect(app(Authorizer::class)->decide($registry->get('shop'), ShopWorld::request('shop.edit'))->allowed())->toBeTrue();
    User::query()->whereKey(2)->toBase()->update(['is_root' => false]);
    expect(app(Authorizer::class)->decide($registry->get('shop'), ShopWorld::request('shop.edit', 2))->reason)->toBe(DecisionReason::NotGranted);
});

it('creates automatic roles per operation and converts source failures into SourceError', function (): void {
    $registry = ShopWorld::compile();
    app()->bind(SellerRole::class, fn () => throw new RuntimeException('sensitive detail'));
    expect(app(Authorizer::class)->decide($registry->get('shop'), ShopWorld::request())->reason)->toBe(DecisionReason::SourceError);
});

it('fails closed when the automatic predicate throws', function (): void {
    $registry = ShopWorld::compile();
    Schema::drop('stores');
    expect(app(Authorizer::class)->decide($registry->get('shop'), ShopWorld::request('shop.browse'))->reason)->toBe(DecisionReason::SourceError);
});

it('emits one contribution per requested scope and no role for a missing subject model', function (): void {
    $registry = ShopWorld::compile();
    $panel = $registry->get('shop');
    $folder = PanelSources::of($registry->recipe('shop'), app())->all()[0]['source'];
    $scopes = [AccessScope::in(TenantRef::global()), AccessScope::in(TenantRef::of('org', 3), AssignmentScopeRef::of('store', 1))];
    $frame = new EvaluationFrame($panel, $scopes[0], CodeStateToken::of('shop', 'build', 'fingerprint'), new DateTimeImmutable, ActorRef::of('user', 1), subject: User::query()->find(1));
    $roles = iterator_to_array($folder->roleGrants(SubjectRef::of('user', 1), $scopes, $frame), false);
    expect($roles)->toHaveCount(2)->and($roles[0]->scope)->toBe($scopes[0])->and($roles[1]->scope)->toBe($scopes[1])
        ->and($roles[0]->origin)->toBe('automatic')->and($roles[0]->expiresAt)->toBeNull();
    $missing = new EvaluationFrame($panel, $scopes[0], $frame->state(), $frame->now(), $frame->actor());
    expect(iterator_to_array($folder->roleGrants(SubjectRef::of('user', 99), $scopes, $missing)))->toBe([]);
});
