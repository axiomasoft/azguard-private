<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns;

use AzGuard\Concerns\HasAzGuard;
use Illuminate\Database\Eloquent\Model;

/** Stands for `Vendor` of the panel selection matrix, with the trait. */
final class TraitVendor extends Model
{
    use HasAzGuard;

    public function getMorphClass(): string
    {
        return 'trait-vendor';
    }
}
