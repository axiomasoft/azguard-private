<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Filament;

use AzGuard\Concerns\HasAzGuard;
use AzGuard\Contracts\AzGuardSubject;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;

/**
 * A user of the CRM stand as the authenticated user of its Filament panel: the same row and subject (`crm.user`), with
 * the organizations it belongs to as the tenants Filament offers.
 */
final class CrmFilamentUser extends Authenticatable implements AzGuardSubject, FilamentUser, HasTenants
{
    use HasAzGuard;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    public function getMorphClass(): string
    {
        return 'crm.user';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /** @return Collection<int, Organization> */
    public function getTenants(Panel $panel): Collection
    {
        return Organization::query()->whereIn('id', $this->getConnection()->table('organization_user')->where('user_id', $this->getKey())->select('organization_id'))->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->getConnection()->table('organization_user')->where('user_id', $this->getKey())->where('organization_id', $tenant->getKey())->exists();
    }
}
