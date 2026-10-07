<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Changes;

use AzGuard\Changes\ChangePipeline;
use AzGuard\Changes\ChangeResult;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AnyAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Changes\Roles\AuditorRole;
use AzGuard\Tests\Fixtures\Changes\Roles\RootRole;
use AzGuard\Tests\Fixtures\Changes\Roles\SupportRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Closure;
use DateTimeImmutable;

/** Writes through the real change pipeline over the CRM stand: panel crm, tenants A=1/B=2, projects P1–P5. */
final class ChangeWorld
{
    /** @param list<mixed> $pipes */
    public static function panel(array $pipes = [], ?Closure $configure = null): Panel
    {
        return CrmWorld::compile(static function (PanelBuilder $panel) use ($pipes, $configure): void {
            $panel->roles([RootRole::class, AuditorRole::class, SupportRole::class]);

            if ($pipes !== []) {
                $panel->changing($pipes);
            }

            if ($configure !== null) {
                $configure($panel);
            }
        });
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
            CrmWorld::storage()->table($kind.'_grants')->orderBy('id')->get()->all());
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
