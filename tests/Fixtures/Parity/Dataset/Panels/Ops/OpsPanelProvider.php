<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Parity\Dataset\Panels\Ops;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Parity\Dataset\Dataset;

/** Panel `ops` of the baseline dataset: no tenants, no Audit plugin, on the same storage as `crm`; its own folder. */
final class OpsPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'ops';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(model: User::class, guard: 'web')->permissions([Dataset::source()]);
    }
}
