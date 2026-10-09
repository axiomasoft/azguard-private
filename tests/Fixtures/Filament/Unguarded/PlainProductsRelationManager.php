<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Unguarded;

use Filament\Resources\RelationManagers\RelationManager;

final class PlainProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static bool $shouldSkipAuthorization = true;
}
