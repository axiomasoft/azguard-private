<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Relation;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    public $timestamps = false;

    protected $table = 'relation_members';

    protected $guarded = [];
}
