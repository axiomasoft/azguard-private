<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Filament;

final class BypassForceDeleteAnyCanResource extends CatalogProjectResource
{
    public static function canForceDeleteAny(): bool
    {
        return true;
    }
}
