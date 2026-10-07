<?php

declare(strict_types=1);

namespace AzGuard\Concerns;

use AzGuard\Authorization\Authorizer;
use AzGuard\Authorization\Pipeline\Stages\BoundaryStage;
use AzGuard\Changes\Change;
use AzGuard\Changes\ChangePipeline;
use AzGuard\Changes\ChangeResult;
use AzGuard\Contracts\Scopes\ProvidesAccessScope;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Exceptions\TenantRequiredException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AnyAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Kernel\Permissions\PermissionSet;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelResolver;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Database\LockedReads;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;
use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * One subject in one panel: checks, roles, the super-admin question and changes, all in this panel.
 *
 * The wrapper is immutable: `inTenant()` and `fromOrigin()` return a new wrapper, and neither the model, the
 * authentication manager nor the current panel or scope of the request change. A `guard:` argument may only repeat
 * the panel of the wrapper; another panel is a `ConflictingPanelException`.
 *
 * Checks pass the tenant of the wrapper, or none, so the engine resolves it as for any check. Role lists, permission
 * sets, the super-admin question and changes take the tenant of the wrapper, otherwise the current tenant of the panel;
 * a panel with tenants without either is a `TenantRequiredException`, never an aggregate over tenants.
 *
 * `on:` is a context or a resource. A model of an assignment scope type of the panel is that context; a check also
 * passes it as the resource when the panel can place it. Any other model is a resource: a check hands it to the engine,
 * a role list or permission set takes the scope the panel resolves for it, and a change refuses it.
 *
 * The origin selects the partition of changes and stored-grant lists only; checks read every origin.
 *
 * @api
 */
final readonly class SubjectAccess
{
    private SubjectRef $ref;

    /**
     * @throws SubjectNotAcceptedException when the panel does not accept the subject
     * @throws TenantMismatchException when the tenant is not of the tenant type of the panel
     */
    public function __construct(
        private Panel $panel,
        private Model|SubjectRef $subject,
        private ?TenantRef $tenant = null,
        private string $origin = IdentityCodec::DEFAULT_ORIGIN,
    ) {
        if (! $panel->accepts($subject)) {
            throw new SubjectNotAcceptedException('The subject is not a subject of panel "'.$panel->id().'".');
        }
        IdentityCodec::assertSourceLabel($origin);

        if ($tenant !== null && ! $tenant->isGlobal() && $tenant->type() !== $panel->tenants()->definition()?->type()) {
            throw new TenantMismatchException('Panel '.$panel->id().' does not take tenants of the type "'.$tenant->type().'".');
        }
        $this->ref = $subject instanceof SubjectRef ? $subject : SubjectRef::of($subject->getMorphClass(), self::key($subject));
    }

    public function panel(): Panel
    {
        return $this->panel;
    }

    /** The same subject, panel and tenant with changes and stored-grant lists in another origin. */
    public function fromOrigin(string $origin): self
    {
        return new self($this->panel, $this->subject, $this->tenant, $origin);
    }

    /** The same subject and panel in another tenant; this wrapper keeps its own tenant. */
    public function inTenant(Model|TenantRef $tenant): self
    {
        return new self($this->panel, $this->subject, $tenant instanceof TenantRef ? $tenant : $this->tenantOf($tenant), $this->origin);
    }

    /**
     * The tenant of the wrapper with the tenant-wide context.
     *
     * @throws TenantRequiredException
     */
    public function scope(): AccessScope
    {
        return AccessScope::in($this->requiredTenant());
    }

    // Checks

    public function decide(string|UnitEnum $permission, Model|AssignmentScopeRef|null $on = null): Decision
    {
        return $this->authorizer()->decide($this->panel, $this->request($permission, $on, null));
    }

    public function hasPermission(string|UnitEnum $permission, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        return $this->authorizer()->decide($this->panel, $this->request($permission, $on, $guard))->allowed();
    }

    /** @param list<string|UnitEnum> $permissions an empty list holds none */
    public function hasAnyPermission(array $permissions, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        return in_array(true, $this->decisions($permissions, $on, $guard), true);
    }

    /** @param list<string|UnitEnum> $permissions an empty list holds none */
    public function hasAllPermissions(array $permissions, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        $decisions = $this->decisions($permissions, $on, $guard);

        return $decisions !== [] && ! in_array(false, $decisions, true);
    }

    /**
     * Each permission by the name it was asked with; an enum case by its name in the panel.
     *
     * @param  list<string|UnitEnum>  $permissions
     * @return array<string, bool>
     */
    public function abilities(array $permissions, Model|AssignmentScopeRef|null $on = null): array
    {
        $abilities = [];
        foreach ($this->decisions($permissions, $on, null) as $i => $allowed) {
            $permission = $permissions[$i];
            $abilities[is_string($permission) ? $permission : $this->name($this->authorizer()->resolve($this->subject, $permission, $this->panel->id())[1]->local())] = $allowed;
        }

        return $abilities;
    }

    /**
     * The Grants permissions the subject holds in the scope; not a decision, `decide()` stays the answer to a check.
     *
     * @throws TenantRequiredException
     */
    public function permissionSet(Model|AssignmentScopeRef|null $on = null, ?string $guard = null): PermissionSet
    {
        $this->select([], [], $guard);

        return $this->authorizer()->permissionSet($this->panel, $this->ref, $this->readScope($on));
    }

    /**
     * Names of the permissions of `permissionSet()`, with the prefix of the panel.
     *
     * @return Collection<int, string>
     */
    public function permissionNames(Model|AssignmentScopeRef|null $on = null, ?string $guard = null): Collection
    {
        return collect(array_map(fn (PermissionPattern $pattern): string => $this->name($pattern->local()), $this->permissionSet($on, $guard)->patterns()));
    }

    // Roles

    /** @param string|UnitEnum|list<string|UnitEnum> $roles a list holds when any of the roles does */
    public function hasRole(string|UnitEnum|array $roles, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        return $this->hasAnyRole(is_array($roles) ? $roles : [$roles], $on, $guard);
    }

    /** @param list<string|UnitEnum> $roles an empty list holds none */
    public function hasAnyRole(array $roles, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        $held = $this->heldRoles($roles, $on, $guard);

        return in_array(true, $held, true);
    }

    /** @param list<string|UnitEnum> $roles an empty list holds none */
    public function hasAllRoles(array $roles, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        $held = $this->heldRoles($roles, $on, $guard);

        return $held !== [] && ! in_array(false, $held, true);
    }

    /**
     * Keys of the roles the subject holds in the scope: stored, related and automatic roles that qualify there.
     *
     * @return Collection<int, string>
     *
     * @throws TenantRequiredException
     */
    public function roleNames(Model|AssignmentScopeRef|null $on = null, ?string $guard = null): Collection
    {
        $this->select([], [], $guard);

        return collect(array_map(static fn (RoleKey $role): string => $role->key(), $this->authorizer()->roles($this->panel, $this->ref, $this->readScope($on))));
    }

    /** @throws TenantRequiredException */
    public function isSuperAdmin(Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        $this->select([], [], $guard);

        return $this->authorizer()->isSuperAdmin($this->panel, $this->ref, $this->readScope($on));
    }

    // Changes

    /**
     * @param  string|UnitEnum|list<string|UnitEnum>  $roles  several roles are granted in one change
     * @param  array<string, mixed>  $fields
     */
    public function grantRole(string|UnitEnum|array $roles, Model|AssignmentScopeRef|null $on = null, ?DateTimeInterface $until = null, array $fields = []): ChangeResult
    {
        $roles = is_array($roles) ? $roles : [$roles];
        $this->select([], $roles, null);

        return $this->grant(array_map($this->roleKey(...), $roles), $on, $until, $fields);
    }

    public function revokeRole(string|UnitEnum $role, Model|AssignmentScopeRef|AnyAssignmentScope|null $on = null): ChangeResult
    {
        $this->select([], [$role], null);

        return $this->pipeline()->revoke($this->panel, $this->requiredTenant(), $this->ref, $this->roleKey($role), $this->writeContext($on), $this->origin);
    }

    /** @param list<string|UnitEnum> $roles */
    public function syncRoles(array $roles, Model|AssignmentScopeRef|null $on = null): ChangeResult
    {
        $this->select([], $roles, null);

        return $this->pipeline()->sync($this->panel, $this->requiredTenant(), $this->ref, 'role', array_map($this->roleKey(...), $roles),
            $this->context($on), $this->origin);
    }

    /**
     * @param  string|UnitEnum|list<string|UnitEnum>  $permissions  names or patterns; several are granted in one change
     * @param  array<string, mixed>  $fields
     */
    public function grantPermission(string|UnitEnum|array $permissions, Model|AssignmentScopeRef|null $on = null, ?DateTimeInterface $until = null, array $fields = []): ChangeResult
    {
        $permissions = is_array($permissions) ? $permissions : [$permissions];
        $this->select($permissions, [], null);

        return $this->grant(array_map($this->pattern(...), $permissions), $on, $until, $fields);
    }

    public function revokePermission(string|UnitEnum $permission, Model|AssignmentScopeRef|AnyAssignmentScope|null $on = null): ChangeResult
    {
        $this->select([$permission], [], null);

        return $this->pipeline()->revoke($this->panel, $this->requiredTenant(), $this->ref, $this->pattern($permission), $this->writeContext($on), $this->origin);
    }

    /** @param list<string|UnitEnum> $permissions */
    public function syncPermissions(array $permissions, Model|AssignmentScopeRef|null $on = null): ChangeResult
    {
        $this->select($permissions, [], null);

        return $this->pipeline()->sync($this->panel, $this->requiredTenant(), $this->ref, 'permission', array_map($this->pattern(...), $permissions),
            $this->context($on), $this->origin);
    }

    /**
     * Stored role grants of the subject in the tenant and origin as models of the panel with their own fields: in the
     * context of `on:`, tenant-wide without it, in every context with `AnyAssignmentScope`.
     *
     * @return Collection<int, RoleGrant>
     */
    public function roleGrants(Model|AssignmentScopeRef|AnyAssignmentScope|null $on = null): Collection
    {
        /** @var Collection<int, RoleGrant> */
        return collect($this->stored('role', $on));
    }

    /**
     * Stored permission grants of the subject, as `roleGrants()`.
     *
     * @return Collection<int, PermissionGrant>
     */
    public function permissionGrants(Model|AssignmentScopeRef|AnyAssignmentScope|null $on = null): Collection
    {
        /** @var Collection<int, PermissionGrant> */
        return collect($this->stored('permission', $on));
    }

    /**
     * @param  list<RoleKey>|list<PermissionPattern>  $keys
     * @param  array<string, mixed>  $fields
     */
    private function grant(array $keys, Model|AssignmentScopeRef|null $on, ?DateTimeInterface $until, array $fields): ChangeResult
    {
        $panel = $this->panel->id();
        $scope = AccessScope::in($this->requiredTenant(), $this->context($on));
        $subject = $this->ref;
        $origin = $this->origin;
        $until = $until === null ? null : DateTimeImmutable::createFromInterface($until);

        return $this->pipeline()->run($this->panel, $scope->tenant, static fn (LockedReads $reads, ?ActorRef $actor): array => array_map(
            static fn (RoleKey|PermissionPattern $key): Change => Change::grant($panel, $scope, $subject, $key, $origin, $actor, $until, $fields),
            $keys,
        ));
    }

    /**
     * @param  'role'|'permission'  $kind
     * @return list<RoleGrant|PermissionGrant>
     */
    private function stored(string $kind, Model|AssignmentScopeRef|AnyAssignmentScope|null $on): array
    {
        $writer = $this->panel->writer();

        if ($writer === null) {
            return [];
        }

        if (! $writer instanceof DatabaseSource) {
            throw new PanelNotWritableException('Panel '.$this->panel->id().' stores grants through '.$writer::class.', which cannot list them.');
        }
        $context = $this->writeContext($on);

        return $writer->grantModels($this->panel, $this->requiredTenant(), $this->origin, $kind, $this->ref, $context instanceof AssignmentScopeRef ? $context : null);
    }

    /**
     * Checks the names against the panel of the wrapper: a name, enum, role class or `guard:` of another panel is a
     * conflict, never a switch to that panel.
     *
     * @param  list<string|UnitEnum>  $permissions
     * @param  list<string|UnitEnum>  $roles
     *
     * @throws ConflictingPanelException
     */
    private function select(array $permissions, array $roles, ?string $guard): void
    {
        $this->resolver()->select($this->subject, $permissions, $roles, $guard === null ? [$this->panel->id()] : [$this->panel->id(), $guard]);
    }

    private function request(string|UnitEnum $permission, Model|AssignmentScopeRef|null $on, ?string $guard): AccessRequest
    {
        $this->select([$permission], [], $guard);
        [, $key] = $this->authorizer()->resolve($this->subject, $permission, $this->panel->id());
        $request = AccessRequest::for($this->ref, $key);

        if ($this->tenant !== null) {
            $request = $request->inTenant($this->tenant);
        }

        if ($on === null || $on instanceof AssignmentScopeRef) {
            return $request->on($on);
        }
        $context = $this->contextOf($on);

        return $request->on($context, $context === null || $this->placesResource($on) ? $on : null);
    }

    /**
     * @param  list<string|UnitEnum>  $permissions
     * @return list<bool>
     */
    private function decisions(array $permissions, Model|AssignmentScopeRef|null $on, ?string $guard): array
    {
        if ($permissions === []) {
            return [];
        }
        $requests = array_map(fn (string|UnitEnum $permission): AccessRequest => $this->request($permission, $on, $guard), $permissions);
        $allowed = [];
        foreach ($this->authorizer()->decideMany($requests) as $decision) {
            $allowed[] = $decision->allowed();
        }

        return $allowed;
    }

    /**
     * @param  list<string|UnitEnum>  $roles
     * @return list<bool>
     */
    private function heldRoles(array $roles, Model|AssignmentScopeRef|null $on, ?string $guard): array
    {
        $this->select([], $roles, $guard);
        $catalog = app(PanelRegistry::class)->catalog($this->panel->id())->roles();
        $wanted = [];
        foreach ($roles as $role) {
            $key = $this->roleKey($role);

            if (! isset($catalog[$key->key()])) {
                throw new UnknownRoleException('Role "'.$key->key().'" is not a code role of panel '.$this->panel->id().'.');
            }
            $wanted[] = $key->key();
        }

        if ($wanted === []) {
            return [];
        }
        $held = array_map(static fn (RoleKey $role): string => $role->key(), $this->authorizer()->roles($this->panel, $this->ref, $this->readScope($on)));

        return array_map(static fn (string $key): bool => in_array($key, $held, true), $wanted);
    }

    /** @throws TenantRequiredException */
    private function requiredTenant(): TenantRef
    {
        $tenant = $this->tenant ?? app(CurrentContext::class)->get($this->panel)?->tenant;

        if ($tenant !== null) {
            return $tenant;
        }

        if ($this->panel->tenants()->mode() === 'none') {
            return TenantRef::global();
        }

        throw new TenantRequiredException('Panel '.$this->panel->id().' has tenants: choose one with inTenant(); nothing is read or changed across tenants.');
    }

    /**
     * The scope of a role list, permission set or super-admin question: a context, or the scope the panel resolves
     * for a resource in the tenant.
     *
     * @throws TenantRequiredException
     * @throws TenantMismatchException
     * @throws AssignmentScopeNotAcceptedException
     */
    private function readScope(Model|AssignmentScopeRef|null $on): AccessScope
    {
        $tenant = $this->requiredTenant();

        if (! $on instanceof Model) {
            return AccessScope::in($tenant, $on);
        }
        $context = $this->contextOf($on);

        if ($context !== null) {
            return AccessScope::in($tenant, $context);
        }
        $placed = app(BoundaryStage::class)->resourceScope($on, $this->panel, AccessScope::in($tenant));

        if ($placed === null) {
            throw new AssignmentScopeNotAcceptedException('Panel '.$this->panel->id().' cannot place '.$on::class.': pass an assignment scope or a resource the panel resolves.');
        }

        if (! $placed->tenant->equals($tenant)) {
            throw new TenantMismatchException('The resource belongs to another tenant than the selected one.');
        }

        return $placed;
    }

    /** @throws AssignmentScopeNotAcceptedException */
    private function writeContext(Model|AssignmentScopeRef|AnyAssignmentScope|null $on): AssignmentScopeRef|AnyAssignmentScope
    {
        return $on instanceof AnyAssignmentScope ? $on : $this->context($on);
    }

    /**
     * The context of a change: a resource in place of a context is refused.
     *
     * @throws AssignmentScopeNotAcceptedException
     */
    private function context(Model|AssignmentScopeRef|null $on): AssignmentScopeRef
    {
        if (! $on instanceof Model) {
            return $on ?? AssignmentScopeRef::global();
        }

        return $this->contextOf($on) ?? throw new AssignmentScopeNotAcceptedException(
            $on::class.' is not an assignment scope type of panel '.$this->panel->id().': a change takes a context, not a resource.',
        );
    }

    /**
     * The context a model is when its class is the model of an assignment scope type of the panel.
     *
     * @throws AssignmentScopeNotAcceptedException when the model fits several types
     */
    private function contextOf(Model $model): ?AssignmentScopeRef
    {
        $types = [];
        foreach ($this->panel->scopeDefinitions() as $definition) {
            $class = $definition->model();

            if ($class !== null && $model instanceof $class) {
                $types[] = $definition->type();
            }
        }

        if (count($types) > 1) {
            throw new AssignmentScopeNotAcceptedException($model::class.' is the model of several assignment scope types of panel '
                .$this->panel->id().': pass an AssignmentScopeRef.');
        }

        return $types === [] ? null : AssignmentScopeRef::of($types[0], self::key($model));
    }

    /** Whether a check may also pass the model as its resource: the panel can place it or has no tenants. */
    private function placesResource(Model $model): bool
    {
        if ($this->panel->tenants()->mode() === 'none' || $model instanceof ProvidesAccessScope) {
            return true;
        }
        foreach (array_keys($this->panel->resourceScopes()) as $class) {
            if ($model instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /** @throws TenantMismatchException */
    private function tenantOf(Model $tenant): TenantRef
    {
        $definition = $this->panel->tenants()->definition();
        $class = $definition?->model();

        if ($definition === null || $class === null || ! $tenant instanceof $class) {
            throw new TenantMismatchException($tenant::class.' is not the tenant model of panel '.$this->panel->id().'.');
        }

        return TenantRef::of($definition->type(), self::key($tenant));
    }

    private function roleKey(string|UnitEnum $role): RoleKey
    {
        return $this->pipeline()->role($this->panel, match (true) {
            $role instanceof BackedEnum => (string) $role->value,
            $role instanceof UnitEnum => $role->name,
            default => $role,
        });
    }

    private function pattern(string|UnitEnum $permission): PermissionPattern
    {
        return $this->pipeline()->permission($this->panel, $this->resolver()->pattern($this->panel, $permission));
    }

    /** A local name with the prefix of the panel, as the schema names permissions. */
    private function name(string $local): string
    {
        $prefix = $this->panel->prefix();

        return $prefix === null ? $local : $prefix.'.'.$local;
    }

    /** @throws InvalidIdentityException when the model has no key yet */
    private static function key(Model $model): int|string
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidIdentityException($model::class.' has no key: save the model first.');
        }

        return $key;
    }

    private function authorizer(): Authorizer
    {
        return app(Authorizer::class);
    }

    private function resolver(): PanelResolver
    {
        return app(PanelResolver::class);
    }

    private function pipeline(): ChangePipeline
    {
        return app(ChangePipeline::class);
    }
}
