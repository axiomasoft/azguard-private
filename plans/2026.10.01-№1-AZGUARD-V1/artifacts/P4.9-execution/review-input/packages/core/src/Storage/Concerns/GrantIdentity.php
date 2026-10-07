<?php

declare(strict_types=1);

namespace AzGuard\Storage\Concerns;

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;
use Carbon\CarbonImmutable;

trait GrantIdentity
{
    final public function subjectRef(): SubjectRef
    {
        return SubjectRef::of($this->identityString('subject_type'), $this->hostId('subject_id'));
    }

    final public function assignmentScopeRef(): AssignmentScopeRef
    {
        $type = $this->identityString('context_type', true);
        $id = $this->hostId('context_id', true);
        $context = $type === null && $id === null ? AssignmentScopeRef::global() : AssignmentScopeRef::of(
            $type ?? throw new InvalidIdentityException('Missing context type.'),
            $id ?? throw new InvalidIdentityException('Missing context id.'),
        );

        if ($context->key() !== $this->identityString('context_key')) {
            throw new InvalidIdentityException('Context key does not match its reference.');
        }

        return $context;
    }

    final public function origin(): string
    {
        $origin = $this->identityString('origin');
        IdentityCodec::assertSourceLabel($origin);

        return $origin;
    }

    final public function actorRef(): ?ActorRef
    {
        $type = $this->identityString('actor_type', true);
        $id = $this->hostId('actor_id', true);
        $reason = $this->identityString('actor_reason', true);

        if ($type === null && $id === null) {
            return null;
        }

        $actor = IdentityCodec::decode(['actor', $type, $id, $reason]);

        return $actor instanceof ActorRef ? $actor : throw new InvalidIdentityException('Invalid actor reference.');
    }

    final public function expiresAt(): ?CarbonImmutable
    {
        $value = $this->identityString('expires_at', true);

        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc();
    }

    /** @return array<string, string> */
    final protected function grantCasts(): array
    {
        return [...$this->storageCasts(), 'subject_type' => 'string', 'subject_id' => 'string',
            'context_key' => 'string', 'context_type' => 'string', 'context_id' => 'string',
            'origin' => 'string', 'actor_type' => 'string', 'actor_id' => 'string', 'actor_reason' => 'string',
            'expires_at' => 'immutable_datetime'];
    }
}
