<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Events\AccessEvent;
use AzGuard\Events\GrantExpired;
use AzGuard\Events\PanelStateTouched;
use AzGuard\Events\PermissionCreated;
use AzGuard\Events\PermissionDeleted;
use AzGuard\Events\PermissionGranted;
use AzGuard\Events\PermissionGrantUpdated;
use AzGuard\Events\PermissionRevoked;
use AzGuard\Events\PermissionUpdated;
use AzGuard\Events\RoleGranted;
use AzGuard\Events\RoleGrantUpdated;
use AzGuard\Events\RoleRevoked;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\TenantRef;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * @internal Turns the effects of one change into events and delivers them after the root commit.
 *
 * `events()` only builds values: it is called inside the mutation, from the values the writer returned, so nothing
 * after the commit reads a closed handle or a later state. `dispatch()` runs from the commit callback: one event at a
 * time, a listener that throws is reported and the rest are still delivered.
 */
final readonly class ChangeEventPublisher
{
    public function __construct(private Container $container) {}

    /**
     * One event per effect of the result, in order; a result without effects has none.
     *
     * @return list<AccessEvent>
     */
    public function events(Change $change, ChangeResult $result): array
    {
        $events = [];
        foreach ($result->effects as $effect) {
            $events[] = $this->event($change, $effect, $result->state);
        }

        return $events;
    }

    /** @param list<AccessEvent> $events */
    public function dispatch(array $events): void
    {
        $dispatcher = $this->container->make(Dispatcher::class);
        foreach ($events as $event) {
            try {
                $dispatcher->dispatch($event);
            } catch (Throwable $error) {
                report($error);
            }
        }
    }

    private function event(Change $change, ChangeEffect $effect, StateToken $state): AccessEvent
    {
        if ($change->type->isTouch()) {
            return new PanelStateTouched(...$this->envelope($change, $effect, $state, $change->scope->tenant),
                previousVersion: $effect->previousVersion ?? 0, reason: $effect->reason ?? '');
        }
        $record = $effect->after ?? $effect->before ?? throw new UnsupportedDirectWriteException('An effect names the record it changed.');

        return $record instanceof PermissionRecord
            ? $this->permissionEvent($change, $effect, $record, $state)
            : $this->grantEvent($change, $effect, $record, $state);
    }

    private function permissionEvent(Change $change, ChangeEffect $effect, PermissionRecord $record, StateToken $state): AccessEvent
    {
        $head = $this->envelope($change, $effect, $state, $record->tenant);

        return match ($effect->kind) {
            EffectKind::Created => new PermissionCreated(...$head, permission: $record->key, label: $record->label, group: $record->group,
                description: $record->description, fields: $record->fields),
            EffectKind::Updated => new PermissionUpdated(...$head, permission: $record->key, label: $record->label, group: $record->group,
                description: $record->description, fields: $record->fields),
            EffectKind::Deleted => new PermissionDeleted(...$head, permission: $record->key, label: $record->label, group: $record->group,
                description: $record->description, fields: $record->fields, removedGrantIds: $change->removedGrantIds()),
        };
    }

    private function grantEvent(Change $change, ChangeEffect $effect, GrantRecord $record, StateToken $state): AccessEvent
    {
        $head = $this->envelope($change, $effect, $state, $record->scope->tenant);

        if ($effect->expired) {
            return new GrantExpired(...$head, subject: $record->subject, kind: $record->role === null ? 'permission' : 'role', role: $record->role,
                permission: $record->permission, context: $record->scope->context, origin: $record->origin, expiredAt: $record->until);
        }

        if ($record->role !== null) {
            return match ($effect->kind) {
                EffectKind::Created => new RoleGranted(...$head, subject: $record->subject, role: $record->role, context: $record->scope->context,
                    origin: $record->origin, expiresAt: $record->until, fields: $record->fields),
                EffectKind::Updated => new RoleGrantUpdated(...$head, subject: $record->subject, role: $record->role, context: $record->scope->context,
                    origin: $record->origin, expiresAt: $record->until, fields: $record->fields),
                EffectKind::Deleted => new RoleRevoked(...$head, subject: $record->subject, role: $record->role, context: $record->scope->context,
                    origin: $record->origin, expiresAt: $record->until, fields: $record->fields),
            };
        }
        $permission = $record->permission ?? throw new UnsupportedDirectWriteException('A grant record names a role or a permission.');

        return match ($effect->kind) {
            EffectKind::Created => new PermissionGranted(...$head, subject: $record->subject, permission: $permission, context: $record->scope->context,
                origin: $record->origin, expiresAt: $record->until, fields: $record->fields),
            EffectKind::Updated => new PermissionGrantUpdated(...$head, subject: $record->subject, permission: $permission, context: $record->scope->context,
                origin: $record->origin, expiresAt: $record->until, fields: $record->fields),
            EffectKind::Deleted => new PermissionRevoked(...$head, subject: $record->subject, permission: $permission, context: $record->scope->context,
                origin: $record->origin, expiresAt: $record->until, fields: $record->fields),
        };
    }

    /**
     * The seven values every event begins with, in the order of the constructor of `AccessEvent`.
     *
     * @return array{string, DateTimeImmutable, string, TenantRef, ?ActorRef, string, StateToken}
     */
    private function envelope(Change $change, ChangeEffect $effect, StateToken $state, TenantRef $tenant): array
    {
        return [$effect->eventId, $change->occurredAt(), $change->panel, $tenant, $change->actor,
            $change->correlationId() ?? throw new UnsupportedDirectWriteException('An event comes from a change of the pipeline.'), $state];
    }
}
