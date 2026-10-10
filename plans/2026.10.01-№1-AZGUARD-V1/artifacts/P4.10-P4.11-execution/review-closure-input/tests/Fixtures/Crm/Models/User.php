<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Models;

use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function getMorphClass(): string
    {
        return 'crm.user';
    }
}
