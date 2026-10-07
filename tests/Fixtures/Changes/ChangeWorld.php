<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Changes;

use AzGuard\Changes\ChangePipeline;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\PermissionDetails;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AnyAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Changes\Roles\AuditorRole;
use AzGuard\Tests\Fixtures\Changes\Roles\RootRole;
use AzGuard\Tests\Fixtures\Changes\Roles\SupportRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Closure;
use DateTimeImmutable;

/** Writes through the real change pipeline over the CRM stand: panel crm, tenants A=1/B=2, projects P1–P5. */
final class ChangeWorld
{
    /** Removes only the stand's tables from the isolated test connection, before or after an engine run. */
    public static function clean(): void
    {
        $schema = CrmWorld::storage()->connection()->getSchemaBuilder();

        foreach (['crm_weight', 'crm_active_build', 'crm_project_revisions', 'clients', 'project_members', 'projects', 'organization_user', 'users', 'cities', 'organizations'] as $table) {
            $schema->dropIfExists($table);
        }
        app(StorageSchema::class)->drop('default');
    }

    /**
     * @param  list<mixed>  $pipes
     * @param  list<mixed>|null  $sources
     */
    public static function panel(array $pipes = [], ?Closure $configure = null, ?array $sources = null): Panel
    {
        return CrmWorld::compile(static function (PanelBuilder $panel) use ($pipes, $configure): void {
            $panel->roles([RootRole::class, AuditorRole::class, SupportRole::class]);

            if ($pipes !== []) {
                $panel->changing($pipes);
            }

            if ($configure !== null) {
                $configure($panel);
            }
        }, $sources);
    }

    /** The panel with the database writer opted in to dynamic permissions, optionally storing role grants only. */
    public static function dynamicPanel(array $pipes = [], bool $rolesOnly = false): Panel
    {
        $source = CrmWorld::database()->dynamicPermissions();

        return self::panel($pipes, null, [$rolesOnly ? $source->rolesOnly() : $source]);
    }

    /** @param array<string, mixed> $fields */
    public static function createAction(Panel $panel, string $name, int $tenant = 1, ?string $label = null, ?string $group = null,
        ?string $description = null, array $fields = [], ?ActorRef $actor = null): ChangeResult
    {
        return self::pipeline()->createPermission($panel, self::tenant($tenant), $name, new PermissionDetails($label, $group, $description, $fields), $actor);
    }

    public static function deleteAction(Panel $panel, string $name, int $tenant = 1): ChangeResult
    {
        return self::pipeline()->deletePermission($panel, self::tenant($tenant), $name);
    }

    /** @return list<array<string, mixed>> */
    public static function actions(): array
    {
        return self::rows('permissions');
    }

    /** @return list<string> tenant and name of each stored dynamic permission */
    public static function actionNames(): array
    {
        return array_map(static fn (array $row): string => $row['tenant_key'].'|'.$row['name'], self::actions());
    }

    public static function pipeline(): ChangePipeline
    {
        return app(ChangePipeline::class);
    }

    public static function tenant(int $id = 1): TenantRef
    {
        return TenantRef::of('crm.organization', $id);
    }

    public static function user(int $id = 1): SubjectRef
    {
        return SubjectRef::of('crm.user', $id);
    }

    public static function project(?int $id = null): AssignmentScopeRef
    {
        return $id === null ? AssignmentScopeRef::global() : AssignmentScopeRef::of('crm.project', $id);
    }

    public static function role(string $key): RoleKey
    {
        return RoleKey::of('crm', $key);
    }

    public static function permission(string $local): PermissionPattern
    {
        return PermissionPattern::of('crm', $local);
    }

    /** @param array<string, mixed> $fields */
    public static function grant(Panel $panel, string $role, int $user, ?int $project, int $tenant = 1, ?DateTimeImmutable $until = null,
        array $fields = [], string $origin = 'manual', ?ActorRef $actor = null): ChangeResult
    {
        return self::pipeline()->grant($panel, self::tenant($tenant), self::user($user), self::role($role), self::project($project),
            $origin, $until, $fields, $actor);
    }

    public static function revoke(Panel $panel, string $role, int $user, ?int $project, int $tenant = 1, string $origin = 'manual'): ChangeResult
    {
        return self::pipeline()->revoke($panel, self::tenant($tenant), self::user($user), self::role($role), self::project($project), $origin);
    }

    public static function revokeEverywhere(Panel $panel, string $role, int $user, int $tenant = 1, string $origin = 'manual'): ChangeResult
    {
        return self::pipeline()->revoke($panel, self::tenant($tenant), self::user($user), self::role($role), AnyAssignmentScope::all(), $origin);
    }

    /** @return list<array<string, mixed>> */
    public static function rows(string $kind = 'role'): array
    {
        return array_map(static fn (object $row): array => (array) $row,
            CrmWorld::storage()->table($kind === 'permissions' ? 'permissions' : $kind.'_grants')->orderBy('id')->get()->all());
    }

    /** @return list<string> panel/tenant/key/subject/context/origin of each stored grant */
    public static function keys(string $kind = 'role'): array
    {
        return array_map(static fn (array $row): string => implode('|', [$row['tenant_key'], $row[$kind], $row['subject_id'], $row['context_key'], $row['origin']]),
            self::rows($kind));
    }

    public static function version(): int
    {
        return CrmWorld::storage()->state('crm')?->version ?? 0;
    }
}
