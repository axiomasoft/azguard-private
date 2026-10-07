<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Guards\Shop\ShopWorld;
use AzGuard\Tests\Fixtures\Guards\Shop\Store;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;

beforeEach(fn () => ShopWorld::seed());
afterEach(fn () => Relation::morphMap([], false));

it('ignores removed former and third party NotGrantable keys with actionable notices', function (string $key, string $reason, ?string $current): void {
    Store::query()->delete();
    $contribution = RoleContribution::of(RoleKey::of('shop', $key), AccessScope::in(TenantRef::global()), 'folder', origin: 'automatic');
    $registry = ShopWorld::compile([new GeneratedSource(roles: [$contribution])]);
    Log::spy();
    expect(app(Authorizer::class)->decide($registry->get('shop'), ShopWorld::request()->traced())->reason)->toBe(DecisionReason::NotGranted);
    Log::shouldHaveReceived('notice')->once()->with('AzGuard ignored role contribution.', Mockery::on(fn (array $context): bool => $context['reason'] === $reason && $context['current_key'] === $current));
})->with([['removed', 'unknown_role', null], ['old-seller', 'former_role_key', 'seller'], ['root', 'not_grantable_role', 'root']]);
