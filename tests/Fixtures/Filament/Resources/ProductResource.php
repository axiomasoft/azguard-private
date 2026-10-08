<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Resources;

use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Tests\Fixtures\Filament\Models\Product;
use Filament\Resources\Resource;

/** The permissions of the products of an order; the relation manager of orders names it. */
final class ProductResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $model = Product::class;

    protected static ?string $slug = 'products';
}
