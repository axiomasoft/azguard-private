<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Models;

use Illuminate\Database\Eloquent\Model;

final class Organization extends Model
{
    protected $table = 'organizations';

    protected $guarded = [];

    public $timestamps = false;

    public function getMorphClass(): string
    {
        return 'crm.organization';
    }
}
