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
use DateTimeImmutable;

/**
 * One planned change of one stored grant, as `changing` pipes see it.
 *
 * Identity — type, panel, scope, subject, key, origin, actor, grant id and expected fingerprint — is fixed when the
 * change is planned under the panel lock. A pipe may only replace `until` and `fields` through `withUntil()` and
 * `withFields()`, cancel with `cancel()`, and must return the result it receives from `$next`. The engine validates
 * the final change again after all pipes.
 *
 * @api
 */
final readonly class Change
{
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
        private ?ChangeFrame $frame = null,
        private bool $final = false,
    ) {
        PermissionGrammar::assertPanelId($panel);

        if (($role === null) === ($permission === null)) {
            throw new InvalidIdentityException('A change names exactly one role or permission.');
        }

        if (($role?->panel() ?? $permission?->panel()) !== $panel) {
            throw new InvalidIdentityException('A change key belongs to another panel than '.$panel.'.');
        }

        if ($subject === null) {
            throw new InvalidIdentityException('A grant change needs a subject.');
        }

        if ($type === ChangeType::UpdateGrant && $grantId === null) {
            throw new InvalidIdentityException('An update names the stored grant.');
        }

        if (in_array($type, [ChangeType::GrantRole, ChangeType::RevokeRole], true) && $role === null
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

    /** The same change with another expiry; identity does not change. */
    public function withUntil(?DateTimeImmutable $until): self
    {
        $this->assertProposes('withUntil');

        return $this->copy(until: $until, fields: $this->fields);
    }

    /**
     * The same change with other fields; identity does not change. Values are validated against the field schema.
     *
     * @param  array<string, mixed>  $fields
     */
    public function withFields(array $fields): self
    {
        $this->assertProposes('withFields');

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

    /** @internal */
    public function bind(ChangeFrame $frame): self
    {
        return new self($this->type, $this->panel, $this->scope, $this->subject, $this->role, $this->permission, $this->origin,
            $this->actor, $this->until, $this->fields, $this->grantId, $this->expectedFingerprint, $frame);
    }

    /** @internal the change after final validation; only such a change is applied by the writer */
    public function finalized(): self
    {
        return new self($this->type, $this->panel, $this->scope, $this->subject, $this->role, $this->permission, $this->origin,
            $this->actor, $this->until, $this->fields, $this->grantId, $this->expectedFingerprint, $this->frame, true);
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

    /** @internal everything except `until` and `fields`, including the attempt frame */
    public function sameIdentity(self $other): bool
    {
        return $this->type === $other->type && $this->panel === $other->panel && $this->scope->equals($other->scope)
            && self::same($this->subject, $other->subject) && self::same($this->role, $other->role)
            && self::same($this->permission, $other->permission) && $this->origin === $other->origin
            && $this->actor == $other->actor && $this->grantId === $other->grantId
            && $this->expectedFingerprint === $other->expectedFingerprint && $this->frame === $other->frame;
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
        return new self($this->type, $this->panel, $this->scope, $this->subject, $this->role, $this->permission, $this->origin,
            $this->actor, $until, $fields, $this->grantId, $this->expectedFingerprint, $this->frame);
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
