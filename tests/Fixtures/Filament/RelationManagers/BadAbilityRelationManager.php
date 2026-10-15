<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\RelationManagers;

use AzGuard\Filament\Concerns\AuthorizesRelationManager;
use AzGuard\Tests\Fixtures\Filament\Resources\ProductResource;
use Filament\Resources\RelationManagers\RelationManager;

/** Declares a relation ability that is not snake_case. */
final class BadAbilityRelationManager extends RelationManager
{
    use AuthorizesRelationManager;

    protected static string $relationship = 'products';

    protected static ?string $azguardResource = ProductResource::class;

    protected static ?string $azguardRelationAbility = 'Manage Products';
}
