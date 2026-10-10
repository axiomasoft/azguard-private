<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Baseline070\Persistence;

use AzGuard\Authorization\Authorizer;
use AzGuard\Changes\PermissionDetails;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Fixtures\Baseline070\Persistence\Panels\Crm\CrmPanelProvider;
use AzGuard\Tests\Fixtures\Baseline070\Persistence\Panels\LedgerRoleGrant;
use AzGuard\Tests\Fixtures\Baseline070\Persistence\Panels\Ops\OpsPanelProvider;
use AzGuard\Tests\Fixtures\Crm\CrmPermissionGrant;
use AzGuard\Tests\Fixtures\Crm\CrmSchema;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\CrmGuardPanelProvider;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Uid\Ulid;

/**
 * The one deterministic dataset of the populated 0.7.0 fixtures (I16), written through the public 0.7.0 API: panel
 * `crm` (tenants, project scopes, Audit plugin) and panel `ops` (no tenants, no Audit plugin) share one storage.
 *
 * Determinism: the clock is fixed (`CLOCK`, then `PRUNE_CLOCK` for the expiry prune) and every ULID the code generates
 * (event, correlation and incarnation ids) comes from one counter, so two runs write the same rows.
 */
final class Dataset
{
    public const string CLOCK = '2026-10-06 12:00:00';

    public const string PRUNE_CLOCK = '2026-10-08 12:00:00';

    /** The application column the host migration adds to `role_grants` after the storage is created. */
    public const string APPLICATION_COLUMN = 'ticket';

    /** The change event types the dataset journals on panel `crm`: every journaled type of 0.7.0. */
    public const array JOURNALED = ['grant.expired', 'panel.touched', 'permission.created', 'permission.deleted', 'permission.grant_updated',
        'permission.granted', 'permission.revoked', 'permission.updated', 'role.grant_updated', 'role.granted', 'role.revoked'];

    private static string $storage = 'default';

    public static function source(): DatabaseSource
    {
        return DatabaseSource::make()->storage(self::$storage)->dynamicPermissions()
            ->models(roleGrant: LedgerRoleGrant::class, permissionGrant: CrmPermissionGrant::class)
            ->decisionFields(roleGrant: ['region', 'eligible'], permissionGrant: ['region', 'eligible']);
    }

    /** The folder of the CRM stand definitions both panels discover. */
    public static function crmGuards(): string
    {
        return dirname((string) (new ReflectionClass(CrmGuardPanelProvider::class))->getFileName());
    }

    /** Every ULID generated after this call is `01k7` followed by the zero-padded counter: valid, ordered, fixed. */
    public static function fixUlids(): void
    {
        $next = 0;
        Str::createUlidsUsing(static function () use (&$next): Ulid {
            return Ulid::fromString(strtoupper(sprintf('01k7%022d', ++$next)));
        });
    }

    public static function releaseUlids(): void
    {
        Str::createUlidsNormally();
    }

    /** The host migration of the application: one application grant column, added after the AzGuard storage exists. */
    public static function addApplicationColumns(Connection $connection, string $prefix): void
    {
        $connection->getSchemaBuilder()->table($prefix.'role_grants', static function (Blueprint $table): void {
            $table->string(self::APPLICATION_COLUMN, 32)->nullable();
        });
    }

    /** Writes the dataset into the storage `$storage`, which a 0.7.0 migration already created at schema 2. */
    public static function seed(string $storage): void
    {
        self::hostWorld();
        self::$storage = $storage;
        self::fixUlids();
        Carbon::setTestNow(Carbon::parse(self::CLOCK, 'UTC'));

        try {
            self::compile();
            self::write();
        } finally {
            self::releaseUlids();
            Carbon::setTestNow();
            self::$storage = 'default';
        }
    }

    private static function compile(): void
    {
        $registry = new PanelRegistry(app());
        $registry->register(CrmPanelProvider::class);
        $registry->register(OpsPanelProvider::class);
        $registry->freeze();
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetScopedInstances();
        app()->forgetInstance(Authorizer::class);
    }

    /** The CRM host tables and rows the panels resolve subjects, tenants, membership and projects from. */
    private static function hostWorld(): void
    {
        Relation::morphMap(['crm.user' => User::class, 'crm.organization' => Organization::class], false);
        (new CrmSchema)->up();
        $db = app('db')->connection();
        $db->table('organizations')->insert([['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]);
        $db->table('cities')->insert([['id' => 1, 'name' => 'Kazan'], ['id' => 2, 'name' => 'Samara']]);
        $db->table('users')->insert([
            ['id' => 1, 'name' => 'Anna', 'city_id' => 1, 'is_root' => false],
            ['id' => 2, 'name' => 'Boris', 'city_id' => 2, 'is_root' => false],
            ['id' => 3, 'name' => 'Darya', 'city_id' => 1, 'is_root' => true],
        ]);
        $db->table('organization_user')->insert([
            ['organization_id' => 1, 'user_id' => 1], ['organization_id' => 2, 'user_id' => 1],
            ['organization_id' => 1, 'user_id' => 2], ['organization_id' => 1, 'user_id' => 3],
        ]);
        $db->table('projects')->insert([
            ['id' => 1, 'organization_id' => 1, 'city_id' => 1, 'region' => 'R1', 'is_active' => true],
            ['id' => 2, 'organization_id' => 1, 'city_id' => 2, 'region' => 'R1', 'is_active' => true],
            ['id' => 4, 'organization_id' => 2, 'city_id' => 1, 'region' => 'R1', 'is_active' => true],
            ['id' => 5, 'organization_id' => 1, 'city_id' => 1, 'region' => 'R2', 'is_active' => true],
        ]);
    }

    private static function write(): void
    {
        $a = TenantRef::of('crm.organization', 1);
        $b = TenantRef::of('crm.organization', 2);
        $project = static fn (int $id): AssignmentScopeRef => AssignmentScopeRef::of('crm.project', $id);
        $user = static fn (int $id): User => User::query()->findOrFail($id);
        $crm = AzGuard::panel('crm')->inTenant($a);
        $until = static fn (string $at): DateTimeImmutable => new DateTimeImmutable($at);

        AzGuard::actingAs($user(3), static function () use ($crm, $user, $project, $until, $b): void {
            // Dynamic permissions with metadata: one kept, one updated, one deleted.
            $crm->permissions()->create('campaigns.launch', 'Launch', 'Marketing');
            $crm->permissions()->update('campaigns.launch', new PermissionDetails('Launch now', 'Marketing', 'Starts a campaign', []));
            $crm->permissions()->create('campaigns.pause', 'Pause', 'Marketing');
            $crm->permissions()->delete('campaigns.pause');

            // Role grants: tenant-wide with meta and the application column, scoped with expiry, updated, revoked.
            $crm->for($user(2))->grantRole('auditor', fields: ['region' => 'R1', 'ticket' => 'T-100']);
            $crm->for($user(2))->grantRole('analyst', $project(1), $until('2026-11-01T00:00:00Z'), ['region' => 'R1', 'eligible' => true]);
            $crm->for($user(2))->grantRole('analyst', $project(1), $until('2026-12-01T00:00:00Z'), ['region' => 'R1', 'eligible' => true]);
            $crm->for($user(1))->grantRole('seller', $project(1));
            $crm->for($user(1))->revokeRole('seller', $project(1));
            $crm->for($user(1))->grantRole('seller', $project(5));
            $crm->fromOrigin('import')->for($user(3))->syncRoles(['support']);

            // Permission grants: scoped, updated, revoked, tenant-wide.
            $crm->for($user(2))->grantPermission('clients.view', $project(1));
            $crm->for($user(2))->grantPermission('clients.view', $project(1), $until('2027-01-01T00:00:00Z'));
            $crm->for($user(1))->grantPermission('clients.update', $project(2));
            $crm->for($user(1))->revokePermission('clients.update', $project(2));
            $crm->for($user(1))->grantPermission('campaigns.launch');

            // Tenant B: grants that expire before the prune.
            AzGuard::panel('crm')->inTenant($b)->for($user(1))->grantRole('analyst', $project(4), $until('2026-10-07T00:00:00Z'));
            AzGuard::panel('crm')->inTenant($b)->for($user(1))->grantPermission('clients.view', $project(4), $until('2026-10-07T00:00:00Z'));
        });

        // Panel ops: no tenant, no journal; a system actor.
        AzGuard::actingAs('baseline import', static function () use ($user, $until): void {
            $ops = AzGuard::panel('ops');
            $ops->for($user(1))->grantRole('reader');
            $ops->for($user(2))->grantRole('operator', until: $until('2026-10-07T00:00:00Z'));
            $ops->for($user(1))->grantPermission('reports.export', until: $until('2027-01-01T00:00:00Z'));
            $ops->touch();
        });

        AzGuard::actingAs($user(3), static fn () => AzGuard::panel('crm')->touch());

        Carbon::setTestNow(Carbon::parse(self::PRUNE_CLOCK, 'UTC'));
        $exit = Artisan::call('azguard:grants:prune');

        if ($exit !== 0) {
            throw new RuntimeException('azguard:grants:prune failed: '.Artisan::output());
        }
    }
}
