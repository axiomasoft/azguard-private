<?php

declare(strict_types=1);

namespace AzGuard\Testing;

use AzGuard\AzGuardManager;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\PanelResolver;
use AzGuard\Storage\AuthorityReadBaseline;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use UnitEnum;

/**
 * For a `TestCase` of the application: signs a subject in with real grants. The grants are stored through the change
 * pipeline as the system actor `testing`, so the checks of the test run through the same engine, cache and fence as in
 * production — also under `RefreshDatabase`.
 *
 * A panel with tenants needs its current tenant set before the call, as for any grant.
 *
 * @api
 */
trait InteractsWithAzGuard
{
    /** @internal Laravel invokes this after starting RefreshDatabase's wrapping transaction. */
    protected function setUpInteractsWithAzGuard(): void
    {
        $manager = app('db.transactions');

        if (app()->runningUnitTests() && $manager instanceof DatabaseTransactionsManager) {
            app()->instance(AuthorityReadBaseline::class, new RefreshDatabaseBaseline($manager));
        }
    }

    /**
     * Grants the permissions to the subject, tenant-wide or in the scope `$on`, and signs it in on the guard of the panel.
     *
     * @param  list<string|UnitEnum>  $permissions
     * @return $this
     */
    public function actingAsWithPermissions(Model $subject, array $permissions, Model|AssignmentScopeRef|null $on = null, ?string $panel = null): static
    {
        $manager = app(AzGuardManager::class);
        $selected = app(PanelResolver::class)->select($subject, $permissions, [], $panel === null ? [] : [$panel]);
        $manager->actingAs('testing', static fn () => $manager->panel($selected->id())->for($subject)->grantPermission($permissions, $on));

        return $this->azguardSignIn($subject, $selected->subject($subject)?->guard);
    }

    /**
     * Grants the superadmin role of the panel to the subject and signs it in on the guard of the panel.
     *
     * @return $this
     *
     * @throws UnknownRoleException when the panel has no superadmin role
     */
    public function actingAsSuperAdmin(Model $subject, ?string $panel = null): static
    {
        $manager = app(AzGuardManager::class);
        $selected = app(PanelResolver::class)->select($subject, [], [], $panel === null ? [] : [$panel]);
        $role = null;
        foreach ($manager->panel($selected->id())->roles()->all() as $candidate) {
            if ($candidate->superAdmin) {
                $role = $candidate->key->key();

                break;
            }
        }

        if ($role === null) {
            throw new UnknownRoleException('Panel '.$selected->id().' has no superadmin role to grant.');
        }
        $manager->actingAs('testing', static fn () => $manager->panel($selected->id())->for($subject)->grantRole($role));

        return $this->azguardSignIn($subject, $selected->subject($subject)?->guard);
    }

    /** @return $this */
    private function azguardSignIn(Model $subject, ?string $guard): static
    {
        if (! $subject instanceof Authenticatable) {
            return $this;
        }

        return $this->actingAs($subject, $guard);
    }
}
