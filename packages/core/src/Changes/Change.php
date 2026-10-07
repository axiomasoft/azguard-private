<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use DateTimeImmutable;

/**
 * One planned change of one stored grant or one dynamic permission, as `changing` pipes see it.
 *
 * Identity — type, panel, scope, subject, key, origin, actor, grant id, expected fingerprint and, for a dynamic
 * permission, its name and label, group and description — is fixed when the change is planned under the panel lock.
 * A role key migration also names the `previousRole` its stored grant leaves; its expiry and fields are the stored ones
 * and no pipe replaces them.
 * A pipe may only replace `until` and `fields` through `withUntil()` and `withFields()` (the fields of the details for
 * a dynamic permission), cancel with `cancel()`, and must return the result it receives from `$next`. The engine
 * validates the final change again after all pipes.
 *
 * @api
 */
final readonly class Change
{
    /** The origin recorded on a change of a dynamic permission, which has no grant origin. */
    public const string ACTION_ORIGIN = 'dynamic';

    /** The origin recorded on a touch of the panel state, which has no grant origin. */
    public const string TOUCH_ORIGIN = 'panel';

    /** @param array<string, mixed> $fields */
    private function __construct(
        public ChangeType $type,
        public string $panel,
        public AccessScope $scope,
        public ?SubjectRef $subject,
        public ?RoleKey $role,
        public ?PermissionPattern $permission,
        public string $origin,
        public ?ActorRef $actor,
        public ?DateTimeImmutable $until,
        public array $fields,
        public ?string $grantId,
        public ?string $expectedFingerprint,
        public ?string $name = null,
        public ?PermissionDetails $details = null,
        public ?string $reason = null,
        public bool $expired = false,
        public ?RoleKey $previousRole = null,
        private ?ChangeFrame $frame = null,
        private bool $final = false,
    ) {
        PermissionGrammar::assertPanelId($panel);

        if ($type->isTouch()) {
            self::assertTouch($scope, $subject, $role, $permission, $until, $fields, $grantId, $expectedFingerprint, $name, $details, $reason);

            return;
        }

        if ($reason !== null) {
            throw new InvalidIdentityException('Only a touch of the panel state carries a reason.');
        }

        if ($expired && ! $type->isRevocation()) {
            throw new InvalidIdentityException('Only a revocation can be an expiry.');
        }

        if (($previousRole !== null) !== $type->isMigration()) {
            throw new InvalidIdentityException('Only a role key migration names a previous role, and it always does.');
        }

        if ($type->isAction()) {
            self::assertAction($type, $panel, $scope, $subject, $role, $permission, $until, $fields, $grantId, $expectedFingerprint, $name, $details);

            return;
        }

        if ($name !== null || $details !== null) {
            throw new InvalidIdentityException('Only a dynamic permission change carries a name and details.');
        }

        if (($role === null) === ($permission === null)) {
            throw new InvalidIdentityException('A change names exactly one role or permission.');
        }

        if (($role?->panel() ?? $permission?->panel()) !== $panel) {
            throw new InvalidIdentityException('A change key belongs to another panel than '.$panel.'.');
        }

        if ($subject === null) {
            throw new InvalidIdentityException('A grant change needs a subject.');
        }

        if (($type === ChangeType::UpdateGrant || $type->isMigration()) && $grantId === null) {
            throw new InvalidIdentityException('An update or a migration names the stored grant.');
        }

        if ($type->isMigration() && ($role === null || $previousRole === null || $previousRole->panel() !== $panel
            || $previousRole->key() === $role->key() || $expectedFingerprint === null)) {
            throw new InvalidIdentityException('A role key migration moves one stored grant of the panel from a former key to another key.');
        }

        if (in_array($type, [ChangeType::GrantRole, ChangeType::RevokeRole, ChangeType::MigrateRoleGrant], true) && $role === null
            || in_array($type, [ChangeType::GrantPermission, ChangeType::RevokePermission], true) && $permission === null) {
            throw new InvalidIdentityException('Change type '.$type->value.' does not match its key.');
        }
        self::assertFieldShape($fields);
    }

    /**
     * @internal planned by the change pipeline
     *
     * @param  array<string, mixed>  $fields
     */
    public static function grant(string $panel, AccessScope $scope, SubjectRef $subject, RoleKey|PermissionPattern $key, string $origin,
        ?ActorRef $actor, ?DateTimeImmutable $until = null, array $fields = []): self
    {
        return $key instanceof RoleKey
            ? new self(ChangeType::GrantRole, $panel, $scope, $subject, $key, null, $origin, $actor, $until, $fields, null, null)
            : new self(ChangeType::GrantPermission, $panel, $scope, $subject, null, $key, $origin, $actor, $until, $fields, null, null);
    }

    /** @internal planned by the change pipeline */
    public static function revoke(string $panel, AccessScope $scope, SubjectRef $subject, RoleKey|PermissionPattern $key, string $origin,
        ?ActorRef $actor, ?string $grantId = null): self
    {
        return $key instanceof RoleKey
            ? new self(ChangeType::RevokeRole, $panel, $scope, $subject, $key, null, $origin, $actor, null, [], $grantId, null)
            : new self(ChangeType::RevokePermission, $panel, $scope, $subject, null, $key, $origin, $actor, null, [], $grantId, null);
    }

    /** @internal planned by the change pipeline from the stored grant read under the lock */
    public static function update(GrantRecord $stored, ?ActorRef $actor, GrantDetails $details, ?string $expectedFingerprint): self
    {
        return new self(ChangeType::UpdateGrant, $stored->panel, $stored->scope, $stored->subject, $stored->role, $stored->permission,
            $stored->origin, $actor, $details->until, $details->fields, $stored->id, $expectedFingerprint);
    }

    /** @internal planned by the change pipeline */
    public static function createPermission(string $panel, TenantRef $tenant, string $name, PermissionDetails $details, ?ActorRef $actor): self
    {
        return new self(ChangeType::CreatePermission, $panel, AccessScope::in($tenant), null, null, null, self::ACTION_ORIGIN, $actor, null,
            $details->fields, null, null, $name, $details);
    }

    /** @internal planned by the change pipeline from the stored dynamic permission read under the lock */
    public static function updatePermission(string $panel, TenantRef $tenant, string $name, PermissionDetails $details, ?ActorRef $actor): self
    {
        return new self(ChangeType::UpdatePermission, $panel, AccessScope::in($tenant), null, null, null, self::ACTION_ORIGIN, $actor, null,
            $details->fields, null, null, $name, $details);
    }

    /** @internal planned by the change pipeline after the revocations of the grants of this name */
    public static function deletePermission(string $panel, TenantRef $tenant, string $name, ?ActorRef $actor): self
    {
        return new self(ChangeType::DeletePermission, $panel, AccessScope::in($tenant), null, null, null, self::ACTION_ORIGIN, $actor, null,
            [], null, null, $name);
    }

    /** @internal planned by the change pipeline: a stored grant whose expiry has passed is removed */
    public static function expire(GrantRecord $stored, ?ActorRef $actor): self
    {
        $key = $stored->role ?? $stored->permission ?? throw new InvalidIdentityException('A stored grant has no key.');

        return $key instanceof RoleKey
            ? new self(ChangeType::RevokeRole, $stored->panel, $stored->scope, $stored->subject, $key, null, $stored->origin, $actor, null, [], $stored->id, null, expired: true)
            : new self(ChangeType::RevokePermission, $stored->panel, $stored->scope, $stored->subject, null, $key, $stored->origin, $actor, null, [], $stored->id, null, expired: true);
    }

    /**
     * @internal planned by the change pipeline from a stored grant of a former key read under the lock: the grant moves
     * to `$to` with its scope, subject, origin, expiry and fields; the fingerprint pins the row that was read
     */
    public static function migrate(GrantRecord $stored, RoleKey $to, ?ActorRef $actor): self
    {
        return new self(ChangeType::MigrateRoleGrant, $stored->panel, $stored->scope, $stored->subject, $to, null, $stored->origin, $actor,
            $stored->until, $stored->fields, $stored->id, $stored->fingerprint, previousRole: $stored->role
                ?? throw new InvalidIdentityException('Only a stored role grant is migrated to another role key.'));
    }

    /** @internal planned by the change pipeline: the state version of the whole panel is raised for `$reason` */
    public static function touchPanel(string $panel, string $reason, ?ActorRef $actor): self
    {
        return new self(ChangeType::TouchPanel, $panel, AccessScope::in(TenantRef::global()), null, null, null, self::TOUCH_ORIGIN, $actor,
            null, [], null, null, reason: $reason);
    }

    /** The same change with another expiry; identity does not change. */
    public function withUntil(?DateTimeImmutable $until): self
    {
        $this->assertProposes('withUntil');

        return $this->copy(until: $until, fields: $this->fields);
    }

    /**
     * The same change with other fields; identity does not change. Values are validated against the field schema. A
     * dynamic permission declares no field schema, so any field is refused when the change is validated.
     *
     * @param  array<string, mixed>  $fields
     */
    public function withFields(array $fields): self
    {
        if (! $this->type->proposesFields()) {
            throw InvalidConfigurationException::failing('changing', 'withFields() applies to a grant, an update or a dynamic permission to store, not to '.$this->type->value.'.');
        }

        return $this->copy(until: $this->until, fields: $fields);
    }

    /**
     * Cancels the whole operation; nothing is written.
     *
     * @throws ChangeCancelledException
     */
    public function cancel(string $reason): never
    {
        throw ChangeCancelledException::because($reason);
    }

    /**
     * Checked inputs derived from this value: actor, target user, code role and schema-validated proposed values.
     *
     * @throws InvalidConfigurationException outside the change pipeline
     * @throws InvalidChangeFieldsException when the proposed expiry or fields are invalid
     */
    public function context(): ChangeContext
    {
        $frame = $this->frame ?? throw InvalidConfigurationException::failing('changing', 'A change context exists only inside the change pipeline.');

        return $frame->contextFor($this);
    }

    public function isRole(): bool
    {
        return $this->role !== null;
    }

    /** Whether this change creates, updates or deletes a dynamic permission rather than a grant. */
    public function isAction(): bool
    {
        return $this->type->isAction();
    }

    /** @internal */
    public function bind(ChangeFrame $frame): self
    {
        return new self($this->type, $this->panel, $this->scope, $this->subject, $this->role, $this->permission, $this->origin,
            $this->actor, $this->until, $this->fields, $this->grantId, $this->expectedFingerprint, $this->name, $this->details, $this->reason, $this->expired,
            $this->previousRole, $frame);
    }

    /** @internal the change after final validation; only such a change is applied by the writer */
    public function finalized(): self
    {
        return new self($this->type, $this->panel, $this->scope, $this->subject, $this->role, $this->permission, $this->origin,
            $this->actor, $this->until, $this->fields, $this->grantId, $this->expectedFingerprint, $this->name, $this->details, $this->reason, $this->expired,
            $this->previousRole, $this->frame, true);
    }

    /** @internal */
    public function isFinal(): bool
    {
        return $this->final && $this->frame !== null;
    }

    /** @internal */
    public function belongsTo(ChangeFrame $frame): bool
    {
        return $this->frame === $frame;
    }

    /** @internal one correlation id per operation */
    public function correlationId(): ?string
    {
        return $this->frame?->correlationId;
    }

    /**
     * @internal the grants removed by the changes of this operation that already ran, in order
     *
     * @return list<string>
     */
    public function removedGrantIds(): array
    {
        return $this->frame?->removed() ?? [];
    }

    /** @internal the clock of the operation under the panel lock */
    public function occurredAt(): DateTimeImmutable
    {
        return ($this->frame ?? throw InvalidConfigurationException::failing('changing', 'A change has a clock only inside the change pipeline.'))->now;
    }

    /** @internal everything except `until` and `fields`, including the attempt frame */
    public function sameIdentity(self $other): bool
    {
        return $this->type === $other->type && $this->panel === $other->panel && $this->scope->equals($other->scope)
            && self::same($this->subject, $other->subject) && self::same($this->role, $other->role)
            && self::same($this->permission, $other->permission) && $this->origin === $other->origin
            && $this->actor == $other->actor && $this->grantId === $other->grantId
            && $this->expectedFingerprint === $other->expectedFingerprint && $this->name === $other->name
            && $this->reason === $other->reason && $this->expired === $other->expired && self::same($this->previousRole, $other->previousRole)
            && $this->details?->label === $other->details?->label && $this->details?->group === $other->details?->group
            && $this->details?->description === $other->details?->description && $this->frame === $other->frame;
    }

    /**
     * @internal shape only: fields are keyed by name
     *
     * @param  array<mixed>  $fields
     *
     * @throws InvalidChangeFieldsException
     */
    public static function assertFieldShape(array $fields): void
    {
        foreach (array_keys($fields) as $name) {
            if (! is_string($name) || $name === '') {
                throw new InvalidChangeFieldsException(['fields' => ['Grant fields are keyed by field name.']]);
            }
        }
    }

    /** @param array<string, mixed> $fields */
    private function copy(?DateTimeImmutable $until, array $fields): self
    {
        $details = $this->details === null ? null
            : new PermissionDetails($this->details->label, $this->details->group, $this->details->description, $fields);

        return new self($this->type, $this->panel, $this->scope, $this->subject, $this->role, $this->permission, $this->origin,
            $this->actor, $until, $fields, $this->grantId, $this->expectedFingerprint, $this->name, $details, $this->reason, $this->expired,
            $this->previousRole, $this->frame);
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws InvalidIdentityException
     */
    private static function assertAction(ChangeType $type, string $panel, AccessScope $scope, ?SubjectRef $subject, ?RoleKey $role,
        ?PermissionPattern $permission, ?DateTimeImmutable $until, array $fields, ?string $grantId, ?string $expectedFingerprint,
        ?string $name, ?PermissionDetails $details): void
    {
        if ($subject !== null || $role !== null || $permission !== null || $until !== null || $grantId !== null || $expectedFingerprint !== null) {
            throw new InvalidIdentityException('A dynamic permission change names neither a subject, a role, a grant nor an expiry.');
        }

        if (! $scope->context->isGlobal()) {
            throw new InvalidIdentityException('A dynamic permission belongs to a tenant, not to an assignment scope.');
        }

        if ($name === null) {
            throw new InvalidIdentityException('A dynamic permission change names the permission.');
        }
        PermissionGrammar::assertLocalKey($name);

        if ($type === ChangeType::DeletePermission ? $details !== null || $fields !== [] : $details === null || $details->fields !== $fields) {
            throw new InvalidIdentityException('Change type '.$type->value.' does not match its details.');
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws InvalidIdentityException
     */
    private static function assertTouch(AccessScope $scope, ?SubjectRef $subject, ?RoleKey $role, ?PermissionPattern $permission,
        ?DateTimeImmutable $until, array $fields, ?string $grantId, ?string $expectedFingerprint, ?string $name, ?PermissionDetails $details,
        ?string $reason): void
    {
        if ($subject !== null || $role !== null || $permission !== null || $until !== null || $fields !== [] || $grantId !== null
            || $expectedFingerprint !== null || $name !== null || $details !== null) {
            throw new InvalidIdentityException('A touch of the panel state names neither a subject, a key, a grant nor a permission.');
        }

        if (! $scope->tenant->isGlobal() || ! $scope->context->isGlobal()) {
            throw new InvalidIdentityException('A touch of the panel state is panel-wide: its scope is global.');
        }

        if ($reason === null || trim($reason) === '' || strlen($reason) > 255) {
            throw new InvalidIdentityException('A touch of the panel state carries a reason of 1 to 255 bytes.');
        }
    }

    private function assertProposes(string $method): void
    {
        if (! $this->type->proposes()) {
            throw InvalidConfigurationException::failing('changing', $method.'() applies to a grant or an update, not to '.$this->type->value.'.');
        }
    }

    private static function same(SubjectRef|RoleKey|PermissionPattern|null $one, SubjectRef|RoleKey|PermissionPattern|null $other): bool
    {
        if ($one === null || $other === null) {
            return $one === $other;
        }

        return $one::class === $other::class && $one->equals($other);
    }
}
