<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Models;

use AzGuard\Concerns\HasAzGuard;
use AzGuard\Contracts\AzGuardSubject;
use Illuminate\Database\Eloquent\Model;

final class User extends Model implements AzGuardSubject
{
    use HasAzGuard;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function getMorphClass(): string
    {
        return 'crm.user';
    }
}
