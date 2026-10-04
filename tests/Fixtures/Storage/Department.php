<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Storage;

use Illuminate\Database\Eloquent\Model;

final class Department extends Model
{
    protected $connection = 'testbench';

    public $timestamps = false;
}
