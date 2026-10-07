<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Shop;

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class ShopWorld
{
    public static function seed(): void
    {
        Relation::morphMap(['user' => User::class], false);
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_root')->default(false);
        });
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });
        User::query()->insert([['id' => 1, 'is_root' => false], ['id' => 2, 'is_root' => true]]);
        Store::query()->insert(['id' => 1, 'user_id' => 1]);
    }

    public static function compile(array $sources = []): PanelRegistry
    {
        $registry = new PanelRegistry(app());
        $registry->register(ShopPanel::class);
        $registry->configure('shop', function ($panel) use ($sources): void {
            $panel->permissions($sources);
            foreach ($sources as $source) {
                if ($source instanceof GeneratedSource) {
                    $panel->policies([PolicyBinding::for('orders.policy', RuntimePolicy::class)]);

                    break;
                }
            }
        });
        $registry->freeze();
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetInstance(Authorizer::class);

        return $registry;
    }

    public static function request(string $permission = 'shop.sell', int $id = 1): AccessRequest
    {
        return AccessRequest::for(SubjectRef::of('user', $id), PermissionKey::of('shop', $permission));
    }
}
