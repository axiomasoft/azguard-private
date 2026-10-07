<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Orders;

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Tests\Fixtures\Guards\Orders\Permissions\OrderPermission;
use AzGuard\Tests\Fixtures\Guards\Orders\Policies\OrderPolicy;
use AzGuard\Tests\Fixtures\Guards\Orders\Policies\WorkingHoursPolicy;
use AzGuard\Tests\Fixtures\Panels\User;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class OrderWorld
{
    public static function seed(): void
    {
        Relation::morphMap(['user' => User::class, 'service' => ServicePrincipal::class], false);
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });
        Schema::create('service_principals', function (Blueprint $table): void {
            $table->id();
        });
        User::query()->insert([['id' => 1], ['id' => 2]]);
        Order::query()->insert([['id' => 1, 'user_id' => 1], ['id' => 2, 'user_id' => 2]]);
        ServicePrincipal::query()->insert(['id' => 1]);
        OrderPolicy::reset();
        WorkingHoursPolicy::$result = true;
        WorkingHoursPolicy::$beforeResult = null;
        WorkingHoursPolicy::$calls = 0;
        app()->instance(Clock::class, new Clock);
    }

    public static function compile(array $sources = [], ?Closure $configure = null, bool $discoverPolicies = true): PanelRegistry
    {
        $registry = new PanelRegistry(app());
        $registry->register(OrdersPanel::class);
        $registry->configure('orders', function ($panel) use ($sources, $configure, $discoverPolicies): void {
            $panel->permissions([$discoverPolicies ? FolderSource::make() : FolderSource::make()->folders(policies: 'AbsentPolicies'), ...$sources]);

            if ($configure !== null) {
                $configure($panel);
            }
        });
        $registry->freeze();
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetInstance(Authorizer::class);

        return $registry;
    }

    public static function request(OrderPermission|string $permission = OrderPermission::Refund, int $id = 1, ?object $resource = null): AccessRequest
    {
        $request = AccessRequest::for(SubjectRef::of('user', $id), PermissionKey::of('orders', $permission instanceof OrderPermission ? $permission->value : $permission));

        return $resource === null ? $request : $request->on(null, $resource);
    }

    public static function decide(AccessRequest $request): Decision
    {
        return app(Authorizer::class)->decide(app(PanelRegistry::class)->get('orders'), $request);
    }

    public static function grant(OrderPermission $permission = OrderPermission::Refund): Grant
    {
        return Grant::of(PermissionPattern::of('orders', $permission->value), 'orders-grants', AccessScope::in(TenantRef::global()));
    }
}
