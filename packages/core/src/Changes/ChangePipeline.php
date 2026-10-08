<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AnyAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Roles\BaseRole;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Database\LockedReads;
use AzGuard\Storage\StorageMutation;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * @internal The single write path of grants.
 *
 * One operation is one panel, one tenant and one writer connection. The writer transaction locks `panel_state` first;
 * then the catalog and the stored grants are read again on the mutation's write handle, every stored identity becomes
 * its own planned `Change`, each change runs through the `changing` pipes with guarded continuations, the final change
 * is validated under the lock and the writer applies it. Any failure rolls back every sibling. A retry of the root
 * transaction plans again from fresh reads, clock, frames and pipes.
 *
 * Events of the effects are built inside the mutation from the writer's results and handed to the root commit: a
 * rollback, a retry or an operation without effects delivers nothing, and a listener's failure changes nothing.
 */
final readonly class ChangePipeline
{
    public function __construct(private Container $container, private ActingActor $actors, private ChangeJournal $journal, private ChangeEventPublisher $events) {}

    /**
     * Grants a role or a permission to the subject in one assignment scope and origin.
     *
     * @param  array<string, mixed>  $fields
     */
    public function grant(Panel $panel, TenantRef $tenant, SubjectRef $subject, RoleKey|PermissionPattern $key, AssignmentScopeRef $context,
        string $origin = IdentityCodec::DEFAULT_ORIGIN, ?DateTimeImmutable $until = null, array $fields = [], ?ActorRef $actor = null): ChangeResult
    {
        $scope = AccessScope::in($tenant, $context);

        return $this->run($panel, $tenant, static fn (LockedReads $reads, ?ActorRef $actor): array => [
            Change::grant($panel->id(), $scope, $subject, $key, $origin, $actor, $until, $fields),
        ], $actor);
    }

    /**
     * Revokes the stored grants of one key in one scope, or in every context of the tenant with `AnyAssignmentScope`.
     * A stored grant whose role, context or subject no longer exists is still revoked.
     */
    public function revoke(Panel $panel, TenantRef $tenant, SubjectRef $subject, RoleKey|PermissionPattern $key,
        AssignmentScopeRef|AnyAssignmentScope $context, string $origin = IdentityCodec::DEFAULT_ORIGIN, ?ActorRef $actor = null): ChangeResult
    {
        $kind = $key instanceof RoleKey ? 'role' : 'permission';
        $local = $key instanceof RoleKey ? $key->key() : $key->local();

        return $this->run($panel, $tenant, static function (LockedReads $reads, ?ActorRef $actor) use ($panel, $tenant, $subject, $key, $context, $origin, $kind, $local): array {
            $stored = $reads->grants($kind, $tenant, $subject, $local, $context instanceof AssignmentScopeRef ? $context : null, $origin);

            if ($stored === [] && $context instanceof AssignmentScopeRef) {
                return [Change::revoke($panel->id(), AccessScope::in($tenant, $context), $subject, $key, $origin, $actor)];
            }

            return array_map(static fn (GrantRecord $record): Change => Change::revoke($panel->id(), $record->scope, $record->subject,
                $record->role ?? $record->permission ?? $key, $record->origin, $actor, $record->id), $stored);
        }, $actor);
    }

    /**
     * Makes the stored grants of one kind in exactly this tenant, context and origin equal the list: extra grants are
     * revoked, missing ones granted, matching ones untouched.
     *
     * @param  list<RoleKey>|list<PermissionPattern>  $keys
     */
    public function sync(Panel $panel, TenantRef $tenant, SubjectRef $subject, string $kind, array $keys, AssignmentScopeRef $context,
        string $origin = IdentityCodec::DEFAULT_ORIGIN, ?ActorRef $actor = null): ChangeResult
    {
        if ($kind !== 'role' && $kind !== 'permission') {
            throw InvalidConfigurationException::failing('changing', 'Sync kind is role or permission.');
        }
        $wanted = [];
        foreach ($keys as $key) {
            if (($kind === 'role') !== $key instanceof RoleKey) {
                throw InvalidConfigurationException::failing('changing', 'Sync of '.$kind.' grants takes '.$kind.' keys only.');
            }
            $wanted[$key instanceof RoleKey ? $key->key() : $key->local()] = $key;
        }
        $scope = AccessScope::in($tenant, $context);

        return $this->run($panel, $tenant, static function (LockedReads $reads, ?ActorRef $actor) use ($panel, $tenant, $subject, $kind, $wanted, $context, $origin, $scope): array {
            $changes = $present = [];
            foreach ($reads->grants($kind, $tenant, $subject, null, $context, $origin) as $record) {
                $local = $record->role?->key() ?? $record->permission?->local() ?? '';
                $present[$local] = true;

                if (! isset($wanted[$local])) {
                    $changes[] = Change::revoke($panel->id(), $record->scope, $record->subject, $record->role ?? $record->permission
                        ?? throw new StaleSelectionException('A stored grant has no key.'), $record->origin, $actor, $record->id);
                }
            }
            foreach ($wanted as $local => $key) {
                if (! isset($present[$local])) {
                    $changes[] = Change::grant($panel->id(), $scope, $subject, $key, $origin, $actor);
                }
            }

            return $changes;
        }, $actor);
    }

    /**
     * Replaces expiry and fields of one stored grant found by id inside the panel, tenant and origin. A foreign or
     * missing id and a changed fingerprint are `StaleSelectionException`; identity never changes.
     */
    public function update(Panel $panel, TenantRef $tenant, string $origin, string $id, GrantDetails $details, ?string $expectedFingerprint = null,
        ?ActorRef $actor = null): ChangeResult
    {
        return $this->run($panel, $tenant, static fn (LockedReads $reads, ?ActorRef $actor): array => [Change::update(
            $reads->find($id, $tenant, $origin) ?? throw new StaleSelectionException('The selected grant no longer exists in this panel, tenant and origin.'),
            $actor, $details, $expectedFingerprint,
        )], $actor);
    }

    /**
     * Revokes stored grants by id inside the panel, tenant and origin; one foreign or missing id cancels the whole
     * operation before anything is written.
     *
     * @param  list<string>  $ids
     */
    public function revokeIds(Panel $panel, TenantRef $tenant, string $origin, array $ids, ?ActorRef $actor = null): ChangeResult
    {
        return $this->run($panel, $tenant, static function (LockedReads $reads, ?ActorRef $actor) use ($panel, $tenant, $origin, $ids): array {
            $records = [];
            foreach (array_values(array_unique($ids)) as $id) {
                $records[] = $reads->find($id, $tenant, $origin) ?? throw new StaleSelectionException('Grant '.$id.' is not in this panel, tenant and origin.');
            }

            return array_map(static fn (GrantRecord $record): Change => Change::revoke($panel->id(), $record->scope, $record->subject,
                $record->role ?? $record->permission ?? throw new StaleSelectionException('A stored grant has no key.'), $record->origin, $actor, $record->id), $records);
        }, $actor);
    }

    /**
     * Creates a dynamic permission in one tenant. The panel must opt in with `dynamicPermissions()`; the name must be
     * free in the tenant, not a static name and not start with the panel prefix. It is always decided by grants.
     */
    public function createPermission(Panel $panel, TenantRef $tenant, string $name, PermissionDetails $details, ?ActorRef $actor = null): ChangeResult
    {
        return $this->run($panel, $tenant, static fn (LockedReads $reads, ?ActorRef $actor): array => [
            Change::createPermission($panel->id(), $tenant, $name, $details, $actor),
        ], $actor);
    }

    /**
     * Replaces label, group and description of a stored dynamic permission of the tenant; a repeat without a
     * difference is `Unchanged`. A static or missing name is `UnknownPermissionException`.
     */
    public function updatePermission(Panel $panel, TenantRef $tenant, string $name, PermissionDetails $details, ?ActorRef $actor = null): ChangeResult
    {
        return $this->run($panel, $tenant, static fn (LockedReads $reads, ?ActorRef $actor): array => [
            Change::updatePermission($panel->id(), $tenant, $name, $details, $actor),
        ], $actor);
    }

    /**
     * Deletes a stored dynamic permission of the tenant in one mutation: every stored grant of exactly this name in
     * the tenant is revoked first, each as its own change with its stored scope, subject and origin, then the
     * permission row goes. Patterns such as `campaigns.*` and other tenants are not touched; a refusal or a cancelled
     * revocation rolls everything back.
     */
    public function deletePermission(Panel $panel, TenantRef $tenant, string $name, ?ActorRef $actor = null): ChangeResult
    {
        return $this->run($panel, $tenant, static function (LockedReads $reads, ?ActorRef $actor) use ($panel, $tenant, $name): array {
            $changes = [];

            if ($reads->action($tenant, $name) !== null) {
                foreach ($reads->grantsNamed($tenant, $name) as $record) {
                    $changes[] = Change::revoke($panel->id(), $record->scope, $record->subject, $record->permission
                        ?? throw new StaleSelectionException('A stored grant has no key.'), $record->origin, $actor, $record->id);
                }
            }
            $changes[] = Change::deletePermission($panel->id(), $tenant, $name, $actor);

            return $changes;
        }, $actor);
    }

    /**
     * Moves every stored grant of the former key `$from` in the tenant to the code role `$to` that lists it in its
     * former keys, in one mutation: each grant is its own `MigrateRoleGrant` change through the pipes, which may cancel
     * it but not change it. Scope, subject, origin, expiry and fields stay; expired, inactive and orphaned grants move
     * too and gain no authority. Where `$to` already holds the same identity, that grant keeps its fields, takes the
     * later expiry and the former one is deleted. A role that is not a registered grantable role naming `$from` among
     * its former keys is refused before anything is planned.
     */
    public function migrateRoleKey(Panel $panel, TenantRef $tenant, string $from, RoleKey $to, ?ActorRef $actor = null): ChangeResult
    {
        self::own($panel, $to);

        return $this->run($panel, $tenant, static function (LockedReads $reads, ?ActorRef $actor) use ($panel, $tenant, $from, $to): array {
            ChangeValidator::assertMigration($reads->catalog($tenant)->roles(), $panel->id(), $from, $to->key());

            return array_map(static fn (GrantRecord $record): Change => Change::migrate($record, $to, $actor), $reads->grantsOfRole($tenant, $from));
        }, $actor);
    }

    /**
     * The plan `migrateRoleKey()` would carry out now, read under the panel lock without a write, a version bump, a
     * pipe, a journal row or an event: for each stored grant of `$from`, the grant of `$to` it would merge into, if
     * any, and the expiry the kept grant would have.
     *
     * @return list<array{source: GrantRecord, target: ?GrantRecord, until: ?DateTimeImmutable}>
     */
    public function planRoleKeyMigration(Panel $panel, TenantRef $tenant, string $from, RoleKey $to): array
    {
        self::own($panel, $to);
        $writer = $panel->writer();

        if (! $writer instanceof DatabaseSource) {
            throw new PanelNotWritableException('Panel '.$panel->id().' has no database writer.');
        }
        $registry = $this->container->make(PanelRegistry::class);
        $build = [$registry, $registry->buildId(), $registry->fingerprint($panel->id())];

        return $writer->transaction(function () use ($writer, $panel, $tenant, $from, $to, $build): array {
            $reads = $writer->lockedReads($panel);
            $this->assertBuild($panel, ...$build);
            ChangeValidator::assertMigration($reads->catalog($tenant)->roles(), $panel->id(), $from, $to->key());
            $plan = [];
            foreach ($reads->grantsOfRole($tenant, $from) as $source) {
                $target = $reads->exact('role', $source->scope, $source->subject, $to->key(), $source->origin);
                $plan[] = ['source' => $source, 'target' => $target, 'until' => $target === null ? $source->until
                    : ($source->until === null || $target->until === null ? null : max($source->until, $target->until))];
            }

            return $plan;
        });
    }

    /**
     * Raises the state version of the panel by one without changing a grant: every cached decision of the panel is
     * renewed. It runs through the `changing` pipes and the journal like any other change and publishes
     * `PanelStateTouched` after the commit. Returns the state the touch produced.
     */
    public function touch(Panel $panel, string $reason, ?ActorRef $actor = null): StateToken
    {
        return $this->run($panel, TenantRef::global(), static fn (LockedReads $reads, ?ActorRef $actor): array => [
            Change::touchPanel($panel->id(), $reason, $actor),
        ], $actor)->state;
    }

    /**
     * Gives the panel state a new incarnation and raises its version, under the lock of `panel_state` that every write
     * of the panel takes: the reset after a manual change of the tables or a restore of the database. Tokens, cached
     * decisions and permission sets of the old incarnation no longer pass the fence. The reset runs through the
     * `changing` pipes and the journal as a touch with the reason `reset` and publishes `PanelStateTouched` after the
     * commit. It is its own root transaction. Returns the new state.
     *
     * @throws InvalidConfigurationException inside an open transaction of the writer
     */
    public function reset(Panel $panel, ?ActorRef $actor = null): StateToken
    {
        $writer = $panel->writer();

        if (! $writer instanceof DatabaseSource) {
            throw new PanelNotWritableException('Panel '.$panel->id().' has no database writer that keeps a state.');
        }

        if ($writer->inTransaction()) {
            throw InvalidConfigurationException::failing('panel_state', 'A reset of panel '.$panel->id().' runs in its own root transaction.');
        }
        $storage = $writer->boundStorage();

        return $this->operate($panel, TenantRef::global(), static fn (LockedReads $reads, ?ActorRef $actor): array => [
            Change::touchPanel($panel->id(), 'reset', $actor),
        ], $actor, static fn () => $storage->renewIncarnation($panel->id()))->state;
    }

    /**
     * Deletes the rows of the change journal of the panel that occurred before `$before`, in every tenant, `$batch`
     * rows at a time, each batch under the lock of the panel state. The journal is not authority: nothing is touched
     * and no event is published. Returns the number of rows deleted.
     */
    public function pruneJournal(Panel $panel, DateTimeImmutable $before, int $batch = 1000): int
    {
        if ($batch < 1) {
            throw InvalidConfigurationException::failing('changing', 'A prune batch holds at least one row.');
        }
        $writer = $panel->writer();

        if (! $writer instanceof DatabaseSource) {
            throw new PanelNotWritableException('Panel '.$panel->id().' has no database writer, so it keeps no journal.');
        }
        $storage = $writer->boundStorage();
        $cutoff = $before->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $removed = 0;
        do {
            $count = $storage->mutate($panel->id(), static function (StorageMutation $mutation) use ($panel, $cutoff, $batch): int {
                $ids = $mutation->table('audit_log')->where('panel', $panel->id())->where('occurred_at', '<', $cutoff)
                    ->orderBy('id')->limit($batch)->pluck('id')->all();

                return $ids === [] ? 0 : $mutation->table('audit_log')->whereIn('id', $ids)->delete();
            });
            $removed += $count;
        } while ($count >= $batch);

        return $removed;
    }

    /**
     * Removes the stored grants whose expiry is not after `$now`, in every origin, one tenant after the other and `$batch`
     * grants at a time, each batch its own mutation and each grant its own change through the pipes. Every removal
     * publishes `GrantExpired` instead of a revocation event. A tenant narrows the run to one tenant. Returns the
     * number of grants removed.
     */
    public function pruneExpired(Panel $panel, ?TenantRef $tenant, DateTimeImmutable $now, int $batch = 500, ?ActorRef $actor = null): int
    {
        if ($batch < 1) {
            throw InvalidConfigurationException::failing('changing', 'A prune batch holds at least one grant.');
        }
        $writer = $panel->writer();

        if (! $writer instanceof DatabaseSource) {
            throw new PanelNotWritableException('Panel '.$panel->id().' has no database writer.');
        }
        $actor ??= ActorRef::system('prune');
        $removed = 0;
        foreach ($writer->tenantsWithExpired($panel, $tenant, $now) as $each) {
            do {
                $selected = 0;
                $result = $this->run($panel, $each, static function (LockedReads $reads, ?ActorRef $actor) use ($each, $now, $batch, &$selected): array {
                    $records = $reads->expired($each, $now, $batch);
                    $selected = count($records);

                    return array_map(static fn (GrantRecord $record): Change => Change::expire($record, $actor), $records);
                }, $actor);
                $removed += count($result->effects);
                // Nested revocations can turn selected expiries into no-ops without exhausting the remaining rows.
            } while ($selected >= $batch);
        }

        return $removed;
    }

    /**
     * A role of the panel by key, `panel:key` or the class of a registered code role. Only a convenience before the
     * lock; the final check repeats under it.
     *
     * @param  string|class-string<BaseRole>|RoleKey  $role
     */
    public function role(Panel $panel, string|RoleKey $role): RoleKey
    {
        if ($role instanceof RoleKey) {
            return $role;
        }
        foreach ($this->container->make(PanelRegistry::class)->catalog($panel->id())->roles() as $key => $definition) {
            if ($definition['class'] === ltrim($role, '\\')) {
                return RoleKey::of($panel->id(), $key);
            }
        }

        if (class_exists($role)) {
            throw new UnknownRoleException('Class '.$role.' is not a code role of panel '.$panel->id().'.');
        }

        return str_contains($role, ':') ? self::own($panel, RoleKey::parse($role)) : RoleKey::of($panel->id(), $role);
    }

    /** A permission or pattern of the panel by local or full name or enum case; a bare wildcard is invalid. */
    public function permission(Panel $panel, string|UnitEnum|PermissionKey|PermissionPattern $permission): PermissionPattern
    {
        if ($permission instanceof UnitEnum) {
            $permission = $this->container->make(PanelRegistry::class)->catalog($panel->id())->keyOf($permission);
        }

        if ($permission instanceof PermissionKey) {
            $permission = PermissionPattern::of($permission->panel(), $permission->local());
        }

        if (is_string($permission)) {
            $parts = explode(':', $permission, 2);
            $permission = count($parts) === 2 ? PermissionPattern::of($parts[0], $parts[1]) : PermissionPattern::of($panel->id(), $permission);
        }

        if ($permission->panel() !== $panel->id()) {
            throw new UnknownPermissionException('Permission '.$permission->full().' belongs to another panel than '.$panel->id().'.');
        }

        return $permission;
    }

    /**
     * Runs one planned operation; `$plan` builds the changes from reads made under the lock.
     *
     * @param  Closure(LockedReads, ?ActorRef): list<Change>  $plan
     */
    public function run(Panel $panel, TenantRef $tenant, Closure $plan, ?ActorRef $actor = null): ChangeResult
    {
        return $this->operate($panel, $tenant, $plan, $actor);
    }

    /**
     * @param  Closure(LockedReads, ?ActorRef): list<Change>  $plan
     * @param  (Closure(): mixed)|null  $locked  runs first under the lock, before the context of the changes is read
     */
    private function operate(Panel $panel, TenantRef $tenant, Closure $plan, ?ActorRef $actor = null, ?Closure $locked = null): ChangeResult
    {
        $writer = $panel->writer() ?? throw new PanelNotWritableException('Panel '.$panel->id().' has no writer.');

        if (! $writer instanceof DatabaseSource) {
            throw new PanelNotWritableException('Panel '.$panel->id().' writes through '.$writer::class.', which the change pipeline does not support.');
        }
        $registry = $this->container->make(PanelRegistry::class);
        $build = [$registry, $registry->buildId(), $registry->fingerprint($panel->id())];
        $actorModel = null;

        if ($actor === null) {
            [$actor, $actorModel] = $this->actors->resolve($panel);
        }
        $nested = $writer->inTransaction();
        $correlation = strtolower((string) Str::ulid());

        /** @var array{list<ChangeResult>, StateToken} $outcome */
        $outcome = $writer->transaction(function () use ($writer, $panel, $tenant, $plan, $actor, $actorModel, $build, $correlation, $locked): array {
            $reads = $writer->lockedReads($panel);
            $this->assertBuild($panel, ...$build);

            if ($locked !== null) {
                $locked();
            }
            $now = CarbonImmutable::now('UTC')->startOfSecond()->toDateTimeImmutable();
            $validator = new ChangeValidator($this->container, $panel, $tenant, $reads, $now, $reads->token(), $actor, $actorModel, $writer->isRolesOnly(), $writer->isDynamic());
            $frame = new ChangeFrame($validator->context(...), $correlation, $now);
            $append = static function (array $row) use ($writer, $panel): void {
                if (($row['panel'] ?? null) !== $panel->id()) {
                    throw new UnsupportedDirectWriteException('The journal of panel '.$panel->id().' takes only rows of that panel.');
                }
                $writer->appendJournal($panel, $row);
            };
            $results = $events = [];
            $effects = 0;
            foreach ($plan($reads, $actor) as $change) {
                if ($change->panel !== $panel->id() || ! $change->scope->tenant->equals($tenant)) {
                    throw InvalidConfigurationException::failing('changing', 'A planned change leaves the panel or tenant of its operation.');
                }
                $planned = $change->bind($frame);
                $result = $this->journal->within($append, fn (): ChangeResult => OnceTerminal::run($this->container, $planned, $panel->changing(),
                    static fn (mixed $final): ChangeResult => $writer->apply($validator->finalize($planned, $final))));
                array_push($events, ...$this->events->events($planned, $result));
                $frame->record($result);
                $effects += count($result->effects);
                $results[] = $result;
            }

            if ($events !== []) {
                $writer->afterCommit($panel, fn () => $this->events->dispatch($events));
            }

            return [$results, $reads->token($effects)];
        });

        return ChangeResult::combine($outcome[0], $outcome[1], ! $nested, $correlation);
    }

    /** Step 2: the panel of this operation is the panel of the registry build that is current under the lock. */
    private function assertBuild(Panel $panel, PanelRegistry $registry, string $buildId, string $fingerprint): void
    {
        $current = $this->container->make(PanelRegistry::class);

        if ($current !== $registry || $current->buildId() !== $buildId || $current->fingerprint($panel->id()) !== $fingerprint
            || $current->get($panel->id()) !== $panel) {
            throw new StaleSelectionException('Panel '.$panel->id().' was compiled for another build than the current one.');
        }
    }

    private static function own(Panel $panel, RoleKey $key): RoleKey
    {
        if ($key->panel() !== $panel->id()) {
            throw new UnknownRoleException('Role '.$key->full().' belongs to another panel than '.$panel->id().'.');
        }

        return $key;
    }
}
