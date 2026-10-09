<?php

declare(strict_types=1);

namespace AzGuard\Sources\Database;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeContext;
use AzGuard\Changes\ChangeEffect;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\ChangeType;
use AzGuard\Changes\EffectKind;
use AzGuard\Changes\GrantRecord;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Storage\Schema\HostKeyColumns;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageMutation;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * @internal Writes one validated change inside the active mutation of the panel: insert, update, delete or nothing.
 *
 * A repeat whose expiry (UTC, seconds) and canonical fields equal the stored grant is `Unchanged`: no write, no touch,
 * no effect. A revocation planned as an expiry marks its effect `expired`. The actor is stored but never compared. A
 * role key migration carries a historical row to the current key and is the only change that writes `role`.
 * Writes go through the query builder of the mutation only.
 */
final readonly class GrantWriter
{
    public function __construct(private Storage $storage, private StorageMutation $mutation, private LockedReads $reads) {}

    public function apply(Change $change): ChangeResult
    {
        $context = $change->context();
        $kind = $change->isRole() ? 'role' : 'permission';
        $key = $change->role?->key() ?? $change->permission?->local() ?? throw new UnsupportedDirectWriteException('A grant change names a key.');
        $subject = $change->subject ?? throw new UnsupportedDirectWriteException('A grant change names a subject.');

        [$record, $effects] = match ($change->type) {
            ChangeType::GrantRole, ChangeType::GrantPermission => self::one($this->grant($change, $context, $kind,
                $this->reads->exact($kind, $change->scope, $subject, $key, $change->origin))),
            ChangeType::RevokeRole, ChangeType::RevokePermission => self::one($this->revoke($change, $kind,
                $this->reads->exact($kind, $change->scope, $subject, $key, $change->origin))),
            ChangeType::UpdateGrant => self::one($this->update($change, $context, $kind)),
            ChangeType::MigrateRoleGrant => $this->migrate($change, $context, $subject),
            ChangeType::CreatePermission, ChangeType::UpdatePermission, ChangeType::DeletePermission => throw new UnsupportedDirectWriteException('A dynamic permission change is not written as a grant.'),
            ChangeType::TouchPanel => throw new UnsupportedDirectWriteException('A touch of the panel state is not written as a grant.'),
        };

        if ($effects !== []) {
            // A grant change is one subject's: its revision and the panel version move, the epoch does not.
            $this->mutation->touchSubject($this->reads->panel()->id(), $subject);
        }

        return ChangeResult::written($record, $effects, $this->reads->token($effects === [] ? 0 : 1),
            $change->correlationId() ?? throw new UnsupportedDirectWriteException('A change is applied by the change pipeline.'));
    }

    /**
     * The stored grant of a former key moves to the current key with its id, scope, subject, origin, expiry, fields
     * and stored actor. When the current key already holds the same identity, that grant stays: its expiry becomes the
     * later of both (no expiry wins), its fields stay, and the former grant is deleted.
     *
     * @return array{?GrantRecord, list<ChangeEffect>}
     */
    private function migrate(Change $change, ChangeContext $context, SubjectRef $subject): array
    {
        $from = $change->previousRole ?? throw new UnsupportedDirectWriteException('A role key migration names the former key.');
        $to = $change->role ?? throw new UnsupportedDirectWriteException('A role key migration names the current role.');
        $source = $this->reads->find($change->grantId ?? '', $change->scope->tenant, $change->origin);

        if ($source === null || $source->role === null || ! $source->role->equals($from) || ! $source->scope->equals($change->scope)
            || ! $source->subject->equals($subject)) {
            throw new StaleSelectionException('The stored grant of the former key no longer exists in this panel, tenant and origin.');
        }
        $now = self::stamp($context->now);
        $target = $this->reads->exact('role', $change->scope, $subject, $to->key(), $change->origin);

        if ($target === null) {
            $this->mutation->table('role_grants')->where('panel', $change->panel)->where('id', self::number($source->id))
                ->update(['role' => $to->key(), 'updated_at' => $now]);
            $after = $this->reload('role', self::number($source->id));

            return [$after, [new ChangeEffect(EffectKind::Updated, $change->type, $source, $after, self::eventId())]];
        }
        $effects = [];
        $kept = $target;
        $until = $source->until === null || $target->until === null ? null : max($source->until, $target->until);

        if (! self::sameMoment($target->until, $until)) {
            $this->mutation->table('role_grants')->where('panel', $change->panel)->where('id', self::number($target->id))
                ->update(['expires_at' => $until === null ? null : self::stamp($until), 'updated_at' => $now]);
            $kept = $this->reload('role', self::number($target->id));
            $effects[] = new ChangeEffect(EffectKind::Updated, $change->type, $target, $kept, self::eventId());
        }
        $this->mutation->table('role_grants')->where('panel', $change->panel)->where('id', self::number($source->id))->delete();
        $effects[] = new ChangeEffect(EffectKind::Deleted, $change->type, $source, null, self::eventId());

        return [$kept, $effects];
    }

    /**
     * @param  array{?GrantRecord, ?ChangeEffect}  $written
     * @return array{?GrantRecord, list<ChangeEffect>}
     */
    private static function one(array $written): array
    {
        return [$written[0], $written[1] === null ? [] : [$written[1]]];
    }

    /**
     * @param  'role'|'permission'  $kind
     * @return array{?GrantRecord, ?ChangeEffect}
     */
    private function grant(Change $change, ChangeContext $context, string $kind, ?GrantRecord $existing): array
    {
        if ($existing !== null) {
            return $this->replace($change, $context, $kind, $existing);
        }
        [$until, $row] = $this->proposed($context, $kind);
        $subject = $change->subject ?? throw new UnsupportedDirectWriteException('A grant change names a subject.');
        $now = self::stamp($context->now);
        $values = [
            'panel' => $change->panel,
            'tenant_key' => $change->scope->tenant->key(), 'tenant_type' => $change->scope->tenant->type(), 'tenant_id' => $change->scope->tenant->id(),
            $kind => $change->role?->key() ?? $change->permission?->local(),
            'subject_type' => $subject->type(), 'subject_id' => HostKeyColumns::canonical($this->storage->hostKeys(), $subject->id()),
            'context_key' => $change->scope->context->key(), 'context_type' => $change->scope->context->type(), 'context_id' => $change->scope->context->id(),
            'origin' => $change->origin, 'expires_at' => $until === null ? null : self::stamp($until),
            ...$this->actor($change->actor), ...$row['columns'],
            'meta' => $row['meta'] === [] ? null : json_encode($row['meta'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => $now, 'updated_at' => $now,
        ];
        $id = (int) $this->mutation->table($kind.'_grants')->insertGetId($values);
        $after = $this->reload($kind, $id);

        return [$after, new ChangeEffect(EffectKind::Created, $change->type, null, $after, self::eventId())];
    }

    /**
     * @param  'role'|'permission'  $kind
     * @return array{?GrantRecord, ?ChangeEffect}
     */
    private function revoke(Change $change, string $kind, ?GrantRecord $existing): array
    {
        if ($existing === null) {
            return [null, null];
        }
        $this->mutation->table($kind.'_grants')->where('panel', $change->panel)->where('id', self::number($existing->id))->delete();

        return [$existing, new ChangeEffect(EffectKind::Deleted, $change->type, $existing, null, self::eventId(), $change->expired)];
    }

    /**
     * @param  'role'|'permission'  $kind
     * @return array{?GrantRecord, ?ChangeEffect}
     */
    private function update(Change $change, ChangeContext $context, string $kind): array
    {
        $existing = $this->reads->find($change->grantId ?? '', $change->scope->tenant, $change->origin);

        if ($existing === null || ! $existing->scope->equals($change->scope) || $existing->subject != $change->subject
            || $existing->role != $change->role || $existing->permission != $change->permission) {
            throw new StaleSelectionException('The selected grant no longer exists in this panel, tenant and origin.');
        }

        if ($change->expectedFingerprint !== null && ! hash_equals($existing->fingerprint, $change->expectedFingerprint)) {
            throw new StaleSelectionException('The selected grant changed after it was read.');
        }

        return $this->replace($change, $context, $kind, $existing);
    }

    /**
     * @param  'role'|'permission'  $kind
     * @return array{?GrantRecord, ?ChangeEffect}
     */
    private function replace(Change $change, ChangeContext $context, string $kind, GrantRecord $existing): array
    {
        [$until, $row] = $this->proposed($context, $kind);
        $fields = $this->reads->fields($kind);

        if (self::sameMoment($existing->until, $until) && $existing->fields === $fields->canonical($row)) {
            return [$existing, null];
        }
        $columns = [];
        foreach ($fields->declared() as $field) {
            if (! $field->isInMeta()) {
                $columns[$field->name()] = $row['columns'][$field->name()] ?? null;
            }
        }
        $this->mutation->table($kind.'_grants')->where('panel', $change->panel)->where('id', self::number($existing->id))->update([
            'expires_at' => $until === null ? null : self::stamp($until), ...$this->actor($change->actor), ...$columns,
            'meta' => $row['meta'] === [] ? null : json_encode($row['meta'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => self::stamp($context->now),
        ]);
        $after = $this->reload($kind, self::number($existing->id));

        return [$after, new ChangeEffect(EffectKind::Updated, $change->type, $existing, $after, self::eventId())];
    }

    /**
     * Validated values of the context again turned into a row; the writer does not trust a value it did not check.
     *
     * @param  'role'|'permission'  $kind
     * @return array{?DateTimeImmutable, array{columns: array<string, mixed>, meta: array<string, mixed>}}
     */
    private function proposed(ChangeContext $context, string $kind): array
    {
        $until = $context->proposed['until'] ?? null;
        $fields = $context->proposed['fields'] ?? [];

        if (($until !== null && ! $until instanceof DateTimeImmutable) || ! is_array($fields)) {
            throw new UnsupportedDirectWriteException('A grant change carries validated expiry and fields.');
        }

        return [$until, $this->reads->fields($kind)->toRow($fields)];
    }

    /** @return array{actor_type: ?string, actor_id: ?string, actor_reason: ?string} */
    private function actor(?ActorRef $actor): array
    {
        return ['actor_type' => $actor?->type,
            'actor_id' => $actor?->id === null ? null : HostKeyColumns::canonical($this->storage->hostKeys(), $actor->id),
            'actor_reason' => $actor?->reason];
    }

    /** @param 'role'|'permission' $kind */
    private function reload(string $kind, int $id): GrantRecord
    {
        return $this->reads->record($kind, $this->reads->row($kind, $id)
            ?? throw new UnsupportedDirectWriteException('The written grant cannot be read back inside its mutation.'));
    }

    private static function sameMoment(?DateTimeImmutable $one, ?DateTimeImmutable $other): bool
    {
        return ($one === null || $other === null) ? $one === $other : self::stamp($one) === self::stamp($other);
    }

    private static function stamp(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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
