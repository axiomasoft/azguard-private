<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\PoliciesGate;

use AzGuard\Authorization\Authorizer;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Sources\StaticSource;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class GateWorld
{
    public static function seed(): void
    {
        Relation::morphMap(['user' => User::class], false);
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('department')->nullable();
            $table->boolean('is_root')->default(false);
        });
        User::query()->insert(['id' => 1]);
        NativePolicy::$before = null;
        NativePolicy::$result = true;
        NativePolicy::$calls = 0;
        NativePolicy::$seen = [];
    }

    public static function permissions(): StaticSource
    {
        return new StaticSource('beta', array_map(static fn (GatePermission $case): PermissionDefinition => new PermissionDefinition(
            local: $case->value,
            authority: $case === GatePermission::Veto ? PermissionAuthority::Grants : PermissionAuthority::Policy,
            case: $case,
            resourceModel: GateRecord::class,
        ), GatePermission::cases()));
    }

    /** @return array{Authorizer, Panel, PanelRegistry} */
    public static function compile(array $sources, array $bindings = []): array
    {
        [,,$registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->for(User::class)->resourcePrefix(false)->permissions([self::permissions(), ...$sources])
            ->policies([PolicyBinding::for(GatePermission::Php, PhpPolicy::class), ...$bindings])]);
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetInstance(Authorizer::class);

        return [app(Authorizer::class), $registry->get('admin'), $registry];
    }

    public static function request(GatePermission $permission = GatePermission::Access): AccessRequest
    {
        return AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', $permission->value));
    }
}
