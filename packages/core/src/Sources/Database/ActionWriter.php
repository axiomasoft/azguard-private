<?php

declare(strict_types=1);

namespace AzGuard\Sources\Database;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeContext;
use AzGuard\Changes\ChangeEffect;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\ChangeType;
use AzGuard\Changes\EffectKind;
use AzGuard\Changes\PermissionRecord;
use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Storage\StorageMutation;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * @internal Writes one validated change of a dynamic permission inside the active mutation of the panel: insert,
 * update, delete or nothing.
 *
 * Deleting removes the permission row only. The exact grants of its name were revoked by the changes planned before
 * it, each through the pipes; this writer never deletes a grant. An update equal to the stored row is `Unchanged`:
 * no write, no touch, no effect. Writes go through the query builder of the mutation only.
 */
final readonly class ActionWriter
{
    public function __construct(private StorageMutation $mutation, private LockedReads $reads) {}

    public function apply(Change $change): ChangeResult
    {
        $context = $change->context();
        $name = $change->name ?? throw new UnsupportedDirectWriteException('A dynamic permission change names the permission.');
        $tenant = $change->scope->tenant;
        $existing = $this->reads->action($tenant, $name);

        [$record, $effect] = match ($change->type) {
            ChangeType::CreatePermission => $this->create($change, $context, $tenant, $name, $existing),
            ChangeType::UpdatePermission => $this->update($change, $context, $name, $existing),
            ChangeType::DeletePermission => $this->delete($change, $name, $existing),
            default => throw new UnsupportedDirectWriteException('Change type '.$change->type->value.' is not a dynamic permission change.'),
        };

        if ($effect !== null) {
            $this->mutation->touch($this->reads->panel()->id());
        }

        return ChangeResult::written($record, $effect === null ? [] : [$effect], $this->reads->token($effect === null ? 0 : 1),
            $change->correlationId() ?? throw new UnsupportedDirectWriteException('A change is applied by the change pipeline.'));
    }

    /** @return array{?PermissionRecord, ?ChangeEffect} */
    private function create(Change $change, ChangeContext $context, TenantRef $tenant, string $name, ?PermissionRecord $existing): array
    {
        if ($existing !== null) {
            throw new DuplicatePermissionException('Permission "'.$name.'" of panel '.$change->panel.' already exists in this tenant.');
        }
        $details = $change->details ?? throw new UnsupportedDirectWriteException('A dynamic permission change carries its details.');
        $now = $context->now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $id = (int) $this->mutation->table('permissions')->insertGetId([
            'panel' => $change->panel,
            'tenant_key' => $tenant->key(), 'tenant_type' => $tenant->type(), 'tenant_id' => $tenant->id(),
            'name' => $name, 'label' => $details->label, 'group' => $details->group, 'description' => $details->description,
            'meta' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $after = $this->reload($id);

        return [$after, new ChangeEffect(EffectKind::Created, $change->type, null, $after, self::eventId())];
    }

    /** @return array{?PermissionRecord, ?ChangeEffect} */
    private function update(Change $change, ChangeContext $context, string $name, ?PermissionRecord $existing): array
    {
        if ($existing === null) {
            throw new UnknownPermissionException('Panel '.$change->panel.' has no dynamic permission "'.$name.'" in this tenant.');
        }
        $details = $change->details ?? throw new UnsupportedDirectWriteException('A dynamic permission change carries its details.');
        $fields = $context->proposed['fields'] ?? [];

        if ($existing->label === $details->label && $existing->group === $details->group && $existing->description === $details->description
            && $existing->fields === $fields) {
            return [$existing, null];
        }
        $this->mutation->table('permissions')->where('panel', $change->panel)->where('id', self::number($existing->id))->update([
            'label' => $details->label, 'group' => $details->group, 'description' => $details->description, 'meta' => null,
            'updated_at' => $context->now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
        $after = $this->reload(self::number($existing->id));

        return [$after, new ChangeEffect(EffectKind::Updated, $change->type, $existing, $after, self::eventId())];
    }

    /** @return array{?PermissionRecord, ?ChangeEffect} */
    private function delete(Change $change, string $name, ?PermissionRecord $existing): array
    {
        if ($existing === null) {
            throw new UnknownPermissionException('Panel '.$change->panel.' has no dynamic permission "'.$name.'" in this tenant.');
        }
        $this->mutation->table('permissions')->where('panel', $change->panel)->where('id', self::number($existing->id))->delete();

        return [$existing, new ChangeEffect(EffectKind::Deleted, $change->type, $existing, null, self::eventId())];
    }

    private function reload(int $id): PermissionRecord
    {
        return $this->reads->actionById($id) ?? throw new UnsupportedDirectWriteException('The written dynamic permission cannot be read back inside its mutation.');
    }

    private static function number(string $id): int
    {
        return (int) substr($id, (int) strpos($id, ':') + 1);
    }

    private static function eventId(): string
    {
        return strtolower((string) Str::ulid());
    }
}
