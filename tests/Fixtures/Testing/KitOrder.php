<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Testing;

use Illuminate\Database\Eloquent\Model;

/** An order of the application under test: a resource that belongs to a store. It has attributes and no table. */
final class KitOrder extends Model
{
    protected $table = 'orders';

    protected $guarded = [];

    public static function make(int $id, int $store): self
    {
        $order = new self(['store_id' => $store]);
        $order->setAttribute($order->getKeyName(), $id);
        $order->exists = true;

        return $order;
    }
}
