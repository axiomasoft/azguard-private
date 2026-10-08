<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Order extends Model
{
    protected $table = 'orders';

    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
