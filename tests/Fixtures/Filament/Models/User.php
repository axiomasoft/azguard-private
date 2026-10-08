<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Models;

use AzGuard\Concerns\HasAzGuard;
use AzGuard\Contracts\AzGuardSubject;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable implements AzGuardSubject, FilamentUser
{
    use HasAzGuard;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /** How the directories of AzGuard name the user. */
    public function azguardLabel(): ?string
    {
        $name = $this->getAttribute('name');

        return is_string($name) ? $name : null;
    }

    /** @return list<string> */
    public static function azguardSearchColumns(): array
    {
        return ['name'];
    }
}
