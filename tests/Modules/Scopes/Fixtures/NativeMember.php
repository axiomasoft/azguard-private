<?php

declare(strict_types=1);

namespace AzGuard\Tests\Modules\Scopes\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class NativeMember extends Model
{
    protected $table = 'native_members';

    public $timestamps = false;
}
