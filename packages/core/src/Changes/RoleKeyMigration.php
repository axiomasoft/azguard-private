<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Roles\BaseRole;
use AzGuard\Sources\Database\DatabaseSource;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;

/**
 * @internal The explicit migration of stored grants from a former key of a code role to its current key.
 *
 * A former key is never an alias: until this migration runs, a grant of it gives no authority. The migration works
 * tenant by tenant, each tenant one mutation of the change pipeline, so a tenant is moved completely or not at all and
 * a repeated run continues where an interrupted one stopped. `plan()` shows the same targets and collisions without
 * writing anything.
 */
final readonly class RoleKeyMigration
{
    public function __construct(private Container $container, private ChangePipeline $pipeline) {}

    /**
     * What a migration would do now, tenant by tenant.
     *
     * @param  string|class-string<BaseRole>|RoleKey  $to
     * @return array<string, list<array{source: GrantRecord, target: ?GrantRecord, until: ?DateTimeImmutable}>> by tenant key
     */
    public function plan(Panel $panel, string $from, string|RoleKey $to, ?TenantRef $tenant = null): array
    {
        $role = $this->pipeline->role($panel, $to);
        $plan = [];
        foreach ($this->tenants($panel, $from, $role, $tenant) as $each) {
            $plan[$each->key()] = $this->pipeline->planRoleKeyMigration($panel, $each, $from, $role);
        }

        return $plan;
    }

    /**
     * Migrates the grants of `$from` in one tenant or in every tenant that holds one; the result of each tenant.
     *
     * @param  string|class-string<BaseRole>|RoleKey  $to
     * @return array<string, ChangeResult> by tenant key
     */
    public function run(Panel $panel, string $from, string|RoleKey $to, ?TenantRef $tenant = null, ?ActorRef $actor = null): array
    {
        $role = $this->pipeline->role($panel, $to);
        $results = [];
        foreach ($this->tenants($panel, $from, $role, $tenant) as $each) {
            $results[$each->key()] = $this->pipeline->migrateRoleKey($panel, $each, $from, $role, $actor);
        }

        return $results;
    }

    /**
     * The tenants to migrate, after the pair of keys is checked against the code catalog even when no grant exists.
     *
     * @return list<TenantRef>
     */
    private function tenants(Panel $panel, string $from, RoleKey $to, ?TenantRef $tenant): array
    {
        ChangeValidator::assertMigration($this->container->make(PanelRegistry::class)->catalog($panel->id())->roles(), $panel->id(), $from, $to->key());

        if ($tenant !== null) {
            return [$tenant];
        }
        $writer = $panel->writer();

        if (! $writer instanceof DatabaseSource) {
            throw new PanelNotWritableException('Panel '.$panel->id().' has no database writer.');
        }

        return $writer->tenantsHolding($panel, $from);
    }
}
