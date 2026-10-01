<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs;

use AzGuard\Concerns\HasAzGuard;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * ULID-keyed user stub for the morph_type=ulid coverage.
 */
class UlidUser extends Authenticatable
{
    use HasAzGuard;
    use HasUlids;

    protected $table = 'ulid_users';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];
}
