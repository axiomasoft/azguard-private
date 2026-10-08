<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Product extends Model
{
    protected $table = 'products';

    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsToMany<Order, $this> */
    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class);
    }
}
