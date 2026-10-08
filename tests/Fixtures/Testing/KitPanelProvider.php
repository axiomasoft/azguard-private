<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Testing;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Roles\SuperAdminRole;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Testing\FakeSubject;
use Closure;

/** The seller panel of the application under test: fake subjects, two orders permissions, stored grants. */
final class KitPanelProvider extends PanelProvider
{
    public static ?Closure $configure = null;

    public static bool $superAdmin = true;

    public static function getId(): string
    {
        return 'seller';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        $panel->for(FakeSubject::class, guard: 'web')->default()
            ->permissions([OrdersPermission::class, DatabaseSource::make()])
            ->scopes(AssignmentScopePolicy::inherit(new KitStoreScope))
            ->resourceScopes([KitOrder::class => KitOrderScope::class]);

        if (self::$superAdmin) {
            $panel->roles([SuperAdminRole::class]);
        }

        if (self::$configure !== null) {
            (self::$configure)($panel);
        }

        return $panel;
    }
}
