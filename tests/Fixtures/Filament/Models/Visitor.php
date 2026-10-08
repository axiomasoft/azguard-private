<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** A row of the users table as a user model that holds no roles: it is not an AzGuardSubject. */
final class Visitor extends Authenticatable implements FilamentUser
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    public function getMorphClass(): string
    {
        return 'user';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
