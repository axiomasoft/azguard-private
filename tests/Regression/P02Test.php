<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Guards\Shop\ShopWorld;
use Illuminate\Database\Eloquent\Relations\Relation;

afterEach(fn () => Relation::morphMap([], false));

it('P02 preserves grants after a role class rename and denies removed keys without throwing', function (): void {
    ShopWorld::seed();
    $before = new #[Role('reader')] class extends BaseRole
    {
        public function permissions(): array
        {
            return ['orders.view'];
        }
    };
    $after = new #[Role('reader')] class extends BaseRole
    {
        public function permissions(): array
        {
            return ['orders.view'];
        }
    };
    expect($before::class)->not->toBe($after::class);
    $source = new GeneratedSource(roles: [RoleContribution::of(RoleKey::of('admin', 'reader'), AccessScope::in(TenantRef::global()), 'generated')]);
    foreach ([$before::class, $after::class] as $class) {
        [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn ($panel) => $panel->roles([$class]));
        expect($engine->decide($panel, $request)->allowed())->toBeTrue();
    }
    [$engine, $panel, $request] = AuthorizationWorld::compile($source);
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted);
});
