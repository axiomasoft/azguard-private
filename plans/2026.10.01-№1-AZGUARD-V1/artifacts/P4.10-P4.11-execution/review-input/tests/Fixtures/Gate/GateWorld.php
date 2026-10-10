<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Gate;

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelResolver;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\GrantableRootRole;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\PoliciesGate\IndexedPermission;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class GateWorld
{
    public static function seed(): void
    {
        Relation::morphMap(['user' => User::class], false);
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));
        RuntimePolicy::$result = true;
        RuntimePolicy::$callback = null;
        RuntimePolicy::$calls = 0;
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('department')->nullable();
            $table->boolean('is_root')->default(false);
        });
        User::query()->insert(['id' => 1, 'department' => 'sales']);
    }

    /** @return array{Authorizer, Panel, AccessRequest, CurrentPanel, PanelRegistry} */
    public static function compile(GeneratedSource $source, ?Closure $configure = null, ?GeneratedSource $other = null, array $extra = []): array
    {
        [$resolver, $current, $registry] = PanelWorld::compile([
            AdminPanel::class => function (PanelBuilder $p) use ($source, $configure, $extra): void {
                $p->for(User::class)->default()->permissions([$source, IndexedPermission::class, ...$extra])->roles([GrantableRootRole::class])
                    ->policies([PolicyBinding::for('orders.policy', RuntimePolicy::class)]);
                $configure?->__invoke($p);
            },
            CabinetPanel::class => fn (PanelBuilder $p) => $p->for(User::class)->permissions([$other ?? new GeneratedSource(name: 'cabinet')])
                ->policies([PolicyBinding::for('orders.policy', RuntimePolicy::class)]),
        ]);
        app()->instance(PanelRegistry::class, $registry);
        app()->instance(PanelResolver::class, $resolver);
        app()->instance(CurrentPanel::class, $current);
        app()->forgetInstance(Authorizer::class);

        return [app(Authorizer::class), $registry->get('admin'), AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'orders.view')), $current, $registry];
    }
}
