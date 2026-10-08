<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Models;

use AzGuard\Concerns\HasAzGuard;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable implements FilamentUser
{
    use HasAzGuard;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
