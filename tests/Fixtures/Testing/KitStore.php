<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Testing;

use Illuminate\Database\Eloquent\Model;

/** A store of the application under test: the assignment scope of the seller panel. It has a key and no table. */
final class KitStore extends Model
{
    protected $table = 'stores';

    public static function make(int $id): self
    {
        $store = new self;
        $store->setAttribute($store->getKeyName(), $id);
        $store->exists = true;

        return $store;
    }
}
