<?php

declare(strict_types=1);

namespace AzGuard\Sources\Database;

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Changes\GrantFilter;
use AzGuard\Changes\GrantRecord;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Grammar\PatternMatcher;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Storage\Schema\HostKeyColumns;
use AzGuard\Storage\StorageReadSession;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;

/**
 * @internal Stored grants of one panel, tenant and origin as an administrator inspects them: expired, inactive and
 * orphaned rows are read too, and nothing here qualifies a grant as access.
 *
 * A grant is known when the current code can still give it authority: a registered role granted through storage, in
 * an assignment scope type the role is bound to (or tenant-wide unless the role requires a scope), on a tenant
 * that may hold it; an exact permission of the catalog of the tenant decided by grants, or a pattern that covers one,
 * tenant-wide or in a registered scope type. Access reads tenant-wide grants whatever the scope mode of the panel, so
 * the mode does not make them orphaned. Every other stored grant is orphaned. The classification is SQL over the partition, so a
 * page is exact under its limit.
 */
final readonly class GrantInspection
{
    public function __construct(
        private StorageReadSession $session,
        private string $hostKeys,
        private Panel $panel,
        private PanelCatalog $catalog,
        private bool $rolesOnly,
        private GrantRows $rows,
    ) {}

    /** A stored grant by id inside the panel, tenant and origin, whatever its state; a foreign id is not found. */
    public function find(TenantRef $tenant, string $origin, string $id): ?GrantRecord
    {
        if (preg_match('/\A(role|permission):([1-9][0-9]{0,18})\z/', $id, $parts) !== 1) {
            return null;
        }
        /** @var 'role'|'permission' $kind */
        $kind = $parts[1];
        $row = $this->partition($kind, $tenant, $origin)->where('id', $parts[2])->first();

        return $row === null ? null : $this->rows->record($kind, $row);
    }

    /**
     * At most `$limit` grants matching the filter after `$after`, role grants before permission grants, each by id.
     *
     * @param  array{'role'|'permission', int}|null  $after
     * @return list<GrantRecord>
     */
    public function page(TenantRef $tenant, string $origin, GrantFilter $filter, ?array $after, DateTimeImmutable $now, int $limit): array
    {
        $records = [];
        foreach ($filter->kinds() as $kind) {
            if ($after !== null && $after[0] === 'permission' && $kind === 'role') {
                continue;
            }
            $query = $this->partition($kind, $tenant, $origin);

            if ($after !== null && $after[0] === $kind) {
                $query->where('id', '>', $after[1]);
            }
            $this->filter($query, $kind, $filter);
            $this->state($query, $kind, $tenant, $origin, $filter->state, $now);

            foreach ($query->orderBy('id')->limit($limit - count($records))->get() as $row) {
                $records[] = $this->rows->record($kind, $row);
            }

            if (count($records) >= $limit) {
                break;
            }
        }

        return $records;
    }

    /** @param 'role'|'permission' $kind */
    private function filter(Builder $query, string $kind, GrantFilter $filter): void
    {
        if ($filter->subject !== null) {
            $query->where('subject_type', $filter->subject->type())
                ->where('subject_id', HostKeyColumns::canonical($this->hostKeys, $filter->subject->id()));
        }

        if ($filter->context instanceof AssignmentScopeRef) {
            $query->where('context_key', $filter->context->key());
        }

        if ($kind === 'role' && $filter->role !== null) {
            $query->where('role', $filter->role->key());
        }

        if ($kind === 'permission' && $filter->permission !== null) {
            $query->where('permission', $filter->permission->local());
        }
    }

    /** @param 'role'|'permission' $kind */
    private function state(Builder $query, string $kind, TenantRef $tenant, string $origin, string $state, DateTimeImmutable $now): void
    {
        if ($state === GrantFilter::ANY) {
            return;
        }
        $known = $kind === 'role' ? $this->knownRoles($tenant) : $this->knownPermissions($tenant, $origin);

        if ($state === GrantFilter::ORPHANED) {
            $query->whereNot($known);

            return;
        }
        $moment = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $query->where($known);

        if ($state === GrantFilter::EXPIRED) {
            $query->whereNotNull('expires_at')->where('expires_at', '<=', $moment);

            return;
        }
        $query->where(static fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhere('expires_at', '>', $moment));
    }

    /**
     * Rows of a registered grantable role, in a context type the role is bound to or in no context where allowed.
     *
     * @return Closure(Builder): void
     */
    private function knownRoles(TenantRef $tenant): Closure
    {
        $types = $this->scopeTypes();
        $policy = $this->panel->tenants();
        $roles = [];
        foreach ($this->catalog->roles() as $key => $role) {
            if (! $role['grantable'] || $tenant->isGlobal() && $policy->mode() === 'required' && ! in_array($role['class'], $policy->globalRoles(), true)) {
                continue;
            }
            $roles[$key] = [
                // Access reads the tenant-wide context whatever the scope mode of the panel; only the role can require a scope.
                'global' => ! $role['scope_required'],
                'types' => array_values(array_intersect(array_column($role['scopes'], 'type'), $types)),
            ];
        }

        return static function (Builder $query) use ($roles): void {
            // A false start keeps the group non-empty, so its negation is never an empty condition.
            $query->whereIn('id', []);
            foreach ($roles as $key => $role) {
                $query->orWhere(static function (Builder $query) use ($key, $role): void {
                    $query->where('role', $key)->where(static function (Builder $query) use ($role): void {
                        $query->whereIn('id', []);

                        if ($role['global']) {
                            $query->orWhereNull('context_type');
                        }
                        $query->orWhere(static fn (Builder $query): Builder => $query->whereNotNull('context_type')->whereIn('context_type', $role['types']));
                    });
                });
            }
        };
    }

    /**
     * Rows of an exact Grants permission of the catalog of the tenant or of a pattern that covers one, in no context
     * where the panel allows that or in a registered context type. A roles-only writer gives no permission grant.
     *
     * @return Closure(Builder): void
     */
    private function knownPermissions(TenantRef $tenant, string $origin): Closure
    {
        $names = [];

        if (! $this->rolesOnly) {
            $grants = [];
            foreach ($this->catalog->all() as $local => $definition) {
                if ($definition->authority === PermissionAuthority::Grants) {
                    $grants[] = $names[] = (string) $local;
                }
            }
            $patterns = $this->partition('permission', $tenant, $origin)->where('permission', 'like', '%*%')->distinct()->pluck('permission');
            foreach ($patterns as $pattern) {
                if (self::coversAny((string) $pattern, $grants)) {
                    $names[] = (string) $pattern;
                }
            }
        }
        $types = $this->scopeTypes();

        return static function (Builder $query) use ($names, $types): void {
            $query->whereIn('permission', $names)->where(static function (Builder $query) use ($types): void {
                $query->whereNull('context_type')
                    ->orWhere(static fn (Builder $query): Builder => $query->whereNotNull('context_type')->whereIn('context_type', $types));
            });
        };
    }

    /**
     * A stored pattern that is not a valid pattern covers nothing.
     *
     * @param  list<string>  $locals
     */
    private static function coversAny(string $pattern, array $locals): bool
    {
        try {
            foreach ($locals as $local) {
                if (PatternMatcher::covers($pattern, $local)) {
                    return true;
                }
            }
        } catch (InvalidPermissionKeyException) {
            return false;
        }

        return false;
    }

    /** @return list<string> */
    private function scopeTypes(): array
    {
        return array_map(strval(...), array_keys($this->panel->scopeDefinitions()));
    }

    /** @param 'role'|'permission' $kind */
    private function partition(string $kind, TenantRef $tenant, string $origin): Builder
    {
        return $this->session->table($kind.'_grants')->where('panel', $this->panel->id())
            ->where('tenant_key', $tenant->key())->where('origin', $origin);
    }
}
