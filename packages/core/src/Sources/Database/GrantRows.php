<?php

declare(strict_types=1);

namespace AzGuard\Sources\Database;

use AzGuard\Changes\GrantRecord;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Schema\FieldTarget;
use AzGuard\Storage\GrantFields;
use AzGuard\Storage\Models\Permission;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageReadSession;
use DateTimeImmutable;
use DateTimeZone;
use stdClass;

/**
 * @internal One stored grant row as a `GrantRecord`, the same on the write handle of a mutation and on a read session:
 * a fingerprint read for a form equals the one the writer checks under the lock.
 */
final class GrantRows
{
    /** @var array<string, GrantFields> */
    private array $fields = [];

    /** @param array<string, class-string<Permission|PermissionGrant|RoleGrant>> $models */
    public function __construct(
        private readonly Storage|StorageReadSession $storage,
        private readonly Panel $panel,
        private readonly PanelRegistry $registry,
        private readonly array $models,
    ) {}

    /** @param 'role'|'permission' $kind */
    public function fields(string $kind): GrantFields
    {
        $target = $kind === 'role' ? FieldTarget::RoleGrant : FieldTarget::PermissionGrant;

        return $this->fields[$kind] ??= GrantFields::for($this->storage, $target, $this->storage->model($target->value, $this->models[$target->value] ?? null)::class,
            $this->panel->fields($target));
    }

    /**
     * The fingerprint covers the build of the panel, the id, the key, the expiry, the fields and the last update.
     *
     * @param  'role'|'permission'  $kind
     */
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

    public static function utc(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) ? new DateTimeImmutable($value, new DateTimeZone('UTC')) : null;
    }
}
