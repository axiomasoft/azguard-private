<?php

declare(strict_types=1);

namespace AzGuard\Sources\Database;

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Changes\GrantRecord;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Schema\FieldTarget;
use AzGuard\Storage\GrantFields;
use AzGuard\Storage\Models\Permission;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;
use AzGuard\Storage\PanelState;
use AzGuard\Storage\Schema\HostKeyColumns;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageMutation;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use stdClass;

/**
 * @internal Reads of the writer under the panel lock, on the write handle of the active mutation, with explicit
 * panel, tenant, context and origin predicates. Neither the public read session nor a memo is used.
 */
final class LockedReads
{
    /** @var array<string, GrantFields> */
    private array $fields = [];

    /** @param array<string, class-string<Permission|PermissionGrant|RoleGrant>> $models */
    public function __construct(
        private readonly Storage $storage,
        private readonly StorageMutation $mutation,
        private readonly Panel $panel,
        private readonly PanelRegistry $registry,
        private readonly array $models,
        private readonly bool $dynamic,
    ) {}

    public function panel(): Panel
    {
        return $this->panel;
    }

    public function registry(): PanelRegistry
    {
        return $this->registry;
    }

    /** The panel state as the lock read it, before this mutation's effects. */
    public function state(): PanelState
    {
        return $this->mutation->state($this->panel->id());
    }

    public function token(int $effects = 0): StateToken
    {
        $state = $this->state();

        return StateToken::of($this->storage->id(), $this->panel->id(), $state->incarnation, $state->version + ($effects > 0 ? 1 : 0),
            $this->panel->settings()->cacheGeneration(), $this->registry->fingerprint($this->panel->id()));
    }

    /** The static catalog with the dynamic permissions of the tenant read now under the lock. */
    public function catalog(TenantRef $tenant): PanelCatalog
    {
        $catalog = $this->registry->catalog($this->panel->id());

        if (! $this->dynamic) {
            return $catalog;
        }
        $model = $this->storage->model('permission', $this->models['permission'] ?? null);
        $definitions = [];
        foreach ($this->mutation->table('permissions')->where('panel', $this->panel->id())->where('tenant_key', $tenant->key())->get() as $row) {
            $permission = $model->newFromBuilder((array) $row);

            if (! $permission instanceof Permission || $permission->panel() !== $this->panel->id() || ! $permission->tenantRef()->equals($tenant)) {
                throw new InvalidSourceContributionException('Dynamic permission identity differs from its query.');
            }
            $definitions[] = new PermissionDefinition(local: $permission->permissionKey()->local(), authority: PermissionAuthority::Grants,
                label: $permission->getAttribute('label'), group: $permission->getAttribute('group'), description: $permission->getAttribute('description'));
        }

        return $catalog->withDynamic($definitions);
    }

    /**
     * Stored grants of one subject and key in one tenant and origin; `context` null reads every context of the tenant.
     *
     * @param  'role'|'permission'  $kind
     * @return list<GrantRecord>
     */
    public function grants(string $kind, TenantRef $tenant, SubjectRef $subject, ?string $key, ?AssignmentScopeRef $context, string $origin): array
    {
        $query = $this->partition($kind, $tenant, $origin)->where('subject_type', $subject->type())
            ->where('subject_id', HostKeyColumns::canonical($this->storage->hostKeys(), $subject->id()));

        if ($key !== null) {
            $query->where($kind, $key);
        }

        if ($context !== null) {
            $query->where('context_key', $context->key());
        }
        $records = [];
        foreach ($query->orderBy('id')->get() as $row) {
            $records[] = $this->record($kind, $row);
        }

        return $records;
    }

    /** @param 'role'|'permission' $kind */
    public function exact(string $kind, AccessScope $scope, SubjectRef $subject, string $key, string $origin): ?GrantRecord
    {
        return $this->grants($kind, $scope->tenant, $subject, $key, $scope->context, $origin)[0] ?? null;
    }

    /** A stored grant by id inside the panel, tenant and origin; a foreign id is not found. */
    public function find(string $id, TenantRef $tenant, string $origin): ?GrantRecord
    {
        if (preg_match('/\A(role|permission):([1-9][0-9]{0,18})\z/', $id, $parts) !== 1) {
            return null;
        }
        /** @var 'role'|'permission' $kind */
        $kind = $parts[1];
        $row = $this->partition($kind, $tenant, $origin)->where('id', $parts[2])->first();

        return $row === null ? null : $this->record($kind, $row);
    }

    /**
     * @internal the row with every grant column, for the writer
     *
     * @param  'role'|'permission'  $kind
     */
    public function row(string $kind, int $id): ?stdClass
    {
        $row = $this->mutation->table($kind.'_grants')->where('panel', $this->panel->id())->where('id', $id)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /** @param 'role'|'permission' $kind */
    public function fields(string $kind): GrantFields
    {
        $target = $kind === 'role' ? FieldTarget::RoleGrant : FieldTarget::PermissionGrant;

        return $this->fields[$kind] ??= GrantFields::for($this->storage, $target, $this->storage->model($target->value, $this->models[$target->value] ?? null)::class,
            $this->panel->fields($target));
    }

    /** @param 'role'|'permission' $kind */
    public function record(string $kind, stdClass $row): GrantRecord
    {
        $target = $kind === 'role' ? 'role_grant' : 'permission_grant';
        $model = $this->storage->model($target, $this->models[$target] ?? null)->newFromBuilder((array) $row);

        if ((! $model instanceof RoleGrant && ! $model instanceof PermissionGrant) || $model->panel() !== $this->panel->id()) {
            throw new InvalidSourceContributionException('Stored grant identity differs from its query.');
        }
        $key = $model instanceof RoleGrant ? $model->roleKey() : $model->permissionKey();
        $pattern = $key instanceof RoleKey ? null : PermissionPattern::of($key->panel(), $key->local());
        $fields = $this->fields($kind)->stored($model);
        $until = $model->expiresAt()?->toDateTimeImmutable();
        $updated = self::utc($row->updated_at ?? null);
        $id = $kind.':'.$row->id;

        return new GrantRecord(
            id: $id, panel: $model->panel(), scope: AccessScope::in($model->tenantRef(), $model->assignmentScopeRef()),
            subject: $model->subjectRef(), role: $key instanceof RoleKey ? $key : null, permission: $pattern,
            origin: $model->origin(), until: $until, fields: $fields, actor: $model->actorRef(),
            createdAt: self::utc($row->created_at ?? null), updatedAt: $updated,
            fingerprint: IdentityCodec::digest([$this->registry->fingerprint($this->panel->id()), $id, $key->full(),
                $until?->format('Y-m-d H:i:s'), json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $updated?->format('Y-m-d H:i:s')]),
        );
    }

    /** @param 'role'|'permission' $kind */
    private function partition(string $kind, TenantRef $tenant, string $origin): Builder
    {
        return $this->mutation->table($kind.'_grants')->where('panel', $this->panel->id())
            ->where('tenant_key', $tenant->key())->where('origin', $origin);
    }

    private static function utc(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) ? new DateTimeImmutable($value, new DateTimeZone('UTC')) : null;
    }
}
