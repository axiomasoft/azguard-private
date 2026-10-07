<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Exceptions\UnknownRoleException;
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
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
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
 */
final readonly class ChangePipeline
{
    public function __construct(private Container $container, private ActingActor $actors) {}

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
        $outcome = $writer->transaction(function () use ($writer, $panel, $tenant, $plan, $actor, $actorModel, $build, $correlation): array {
            $reads = $writer->lockedReads($panel);
            $this->assertBuild($panel, ...$build);
            $now = CarbonImmutable::now('UTC')->startOfSecond()->toDateTimeImmutable();
            $validator = new ChangeValidator($this->container, $panel, $tenant, $reads, $now, $reads->token(), $actor, $actorModel, $writer->isRolesOnly());
            $frame = new ChangeFrame($validator->context(...), $correlation);
            $results = [];
            $effects = 0;
            foreach ($plan($reads, $actor) as $change) {
                if ($change->panel !== $panel->id() || ! $change->scope->tenant->equals($tenant)) {
                    throw InvalidConfigurationException::failing('changing', 'A planned change leaves the panel or tenant of its operation.');
                }
                $planned = $change->bind($frame);
                $result = OnceTerminal::run($this->container, $planned, $panel->changing(),
                    static fn (mixed $final): ChangeResult => $writer->apply($validator->finalize($planned, $final)));
                $effects += count($result->effects);
                $results[] = $result;
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
