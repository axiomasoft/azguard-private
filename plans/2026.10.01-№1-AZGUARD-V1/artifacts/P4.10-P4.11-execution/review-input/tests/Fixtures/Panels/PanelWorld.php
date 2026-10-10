<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelResolver;
use Closure;

/**
 * Compiles fixture panels into a frozen registry with a resolver on top, without booting an application.
 */
final class PanelWorld
{
    /**
     * @param  array<class-string<FixturePanel>, Closure(PanelBuilder): mixed>  $panels  description per provider,
     *                                                                                   in registration order
     * @return array{0: PanelResolver, 1: CurrentPanel, 2: PanelRegistry}
     */
    public static function compile(array $panels): array
    {
        FixturePanel::reset();
        $registry = new PanelRegistry(app());

        foreach ($panels as $provider => $description) {
            $provider::describe($description);
            $registry->register($provider);
        }

        $registry->freeze();
        $current = new CurrentPanel;

        return [new PanelResolver($registry, $current), $current, $registry];
    }

    /**
     * Two panels sharing the `User` model: `admin` also takes `Vendor`, `cabinet` also takes `Seller`.
     *
     * @return array{0: PanelResolver, 1: CurrentPanel, 2: PanelRegistry}
     */
    public static function adminAndCabinet(bool $adminIsDefault = false): array
    {
        return self::compile([
            AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
                ->for([User::class, Vendor::class])
                ->default($adminIsDefault)
                ->permissions([OrderPermission::class, SharedPermission::class]),
            CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
                ->for([User::class, Seller::class])
                ->permissions([InvoicePermission::class, SharedPermission::class]),
        ]);
    }
}
