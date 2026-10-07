<?php

declare(strict_types=1);

namespace AzGuard\Sources\Database;

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Changes\GrantRecord;
use AzGuard\Changes\PermissionRecord;
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

    public function isDynamic(): bool
    {
        return $this->dynamic;
    }

    /**
     * The static catalog with the dynamic permissions of the tenant read now under the lock, and `$added` as one more
     * dynamic permission: the catalog itself refuses a static shadow and the panel prefix.
     */
    public function catalog(TenantRef $tenant, ?PermissionDefinition $added = null): PanelCatalog
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

        return $catalog->withDynamic($added === null ? $definitions : [...$definitions, $added]);
    }

    /** The stored dynamic permission of this panel and tenant by name; a static name or another tenant is not found. */
    public function action(TenantRef $tenant, string $name): ?PermissionRecord
    {
        if (! $this->dynamic) {
            return null;
        }
        $row = $this->mutation->table('permissions')->where('panel', $this->panel->id())->where('tenant_key', $tenant->key())
            ->where('name', $name)->first();

        return $row instanceof stdClass ? $this->actionRecord($row) : null;
    }

    /** @internal the stored dynamic permission by row id, for the writer */
    public function actionById(int $id): ?PermissionRecord
    {
        $row = $this->mutation->table('permissions')->where('panel', $this->panel->id())->where('id', $id)->first();

        return $row instanceof stdClass ? $this->actionRecord($row) : null;
    }

    /**
     * Every stored grant of exactly this permission name in the tenant, whatever its subject, context or origin. A
     * pattern is a different name and another tenant is another partition: neither is read.
     *
     * @return list<GrantRecord>
     */
    public function grantsNamed(TenantRef $tenant, string $name): array
    {
        $records = [];
        foreach ($this->mutation->table('permission_grants')->where('panel', $this->panel->id())->where('tenant_key', $tenant->key())
            ->where('permission', $name)->orderBy('id')->get() as $row) {
            $records[] = $this->record('permission', $row);
        }

        return $records;
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

    /** @internal */
    public function actionRecord(stdClass $row): PermissionRecord
    {
        $model = $this->storage->model('permission', $this->models['permission'] ?? null)->newFromBuilder((array) $row);

        if (! $model instanceof Permission || $model->panel() !== $this->panel->id()) {
            throw new InvalidSourceContributionException('Stored dynamic permission identity differs from its query.');
        }
        $fields = is_string($row->meta ?? null) ? json_decode($row->meta, true, flags: JSON_THROW_ON_ERROR) : [];

        return new PermissionRecord(
            id: 'action:'.$row->id, panel: $model->panel(), tenant: $model->tenantRef(), key: $model->permissionKey(),
            label: $model->getAttribute('label'), group: $model->getAttribute('group'), description: $model->getAttribute('description'),
            fields: is_array($fields) ? $fields : [], createdAt: self::utc($row->created_at ?? null), updatedAt: self::utc($row->updated_at ?? null),
        );
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
