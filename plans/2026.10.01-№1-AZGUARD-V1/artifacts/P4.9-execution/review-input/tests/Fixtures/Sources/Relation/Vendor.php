<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Relation;

use Illuminate\Database\Eloquent\Model;

final class Vendor extends Model
{
    public $timestamps = false;

    protected $table = 'relation_vendors';
}
