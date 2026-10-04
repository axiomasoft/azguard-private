<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use Closure;
use DateTimeImmutable;

final class AuthorizationWorld
{
    /** @return array{Authorizer, Panel, AccessRequest} */
    public static function compile(GeneratedSource $source, ?Closure $configure = null, array $extra = [], string $rootRole = GrantableRootRole::class): array
    {
        [,,$registry] = PanelWorld::compile([AdminPanel::class => static function (PanelBuilder $panel) use ($source, $configure, $extra, $rootRole): void {
            $panel->for(User::class)->permissions([$source, ...$extra])->roles([$rootRole])->policies([PolicyBinding::for('orders.policy', RuntimePolicy::class)]);

            if ($configure !== null) {
                $configure($panel);
            }
        }]);
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetInstance(Authorizer::class);

        return [app(Authorizer::class), $registry->get('admin'), AccessRequest::for(SubjectRef::of((new User)->getMorphClass(), 1), PermissionKey::of('admin', 'orders.view'))];
    }

    public static function grant(array $fields = [], ?DateTimeImmutable $expires = null, string $panel = 'admin'): Grant
    {
        return Grant::of(PermissionPattern::of($panel, 'orders.view'), 'generated', AccessScope::in(TenantRef::global()), expiresAt: $expires, fields: $fields);
    }
}
