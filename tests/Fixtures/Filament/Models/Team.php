<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Models;

use Illuminate\Database\Eloquent\Model;

final class Team extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];
}
