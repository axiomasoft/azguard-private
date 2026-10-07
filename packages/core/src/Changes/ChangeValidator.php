<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Authorization\ModelSubjectResolver;
use AzGuard\Authorization\ScopeEligibility;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeMembership;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Contracts\Scopes\ResourceScopeResolver;
use AzGuard\Contracts\Scopes\TenantMembership;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\AssignmentScopeRequiredException;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\PermissionNotGrantableException;
use AzGuard\Exceptions\RoleNotGrantableException;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Exceptions\TenantRequiredException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Grammar\PatternMatcher;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Sources\Database\LockedReads;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use PDOException;
use Throwable;

/**
 * @internal Checks of one pipeline attempt under the panel lock, after all pipes, over the final change.
 *
 * Order: identity → origin → structural and partition checks (subject, role, permission, tenant) → expiry and
 * fields normalized → assignment eligibility over the validated proposal → fingerprint of an update. A revocation
 * checks only identity, origin and its stored partition: an orphan, expired or inactive grant stays removable, and a
 * touch of the panel state is panel-wide and checks nothing but its identity. A role key migration checks its stored
 * row and the code catalog, not the live eligibility of a new grant. A
 * change of a dynamic permission checks the opt-in, the tenant, the details and the name against the catalog read
 * under the lock; it needs neither a subject nor an eligibility check.
 */
final class ChangeValidator
{
    /** @var array<string, ?Model> */
    private array $targets = [];

    public function __construct(
        private readonly Container $container,
        private readonly Panel $panel,
        private readonly TenantRef $tenant,
        private readonly LockedReads $reads,
        private readonly DateTimeImmutable $now,
        private readonly StateToken $locked,
        private readonly ?ActorRef $actor,
        private readonly ?Model $actorModel,
        private readonly bool $rolesOnly,
        private readonly bool $dynamic = false,
    ) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /** The static catalog with the tenant's current dynamic permissions; a nested change may have changed them. */
    public function catalog(): PanelCatalog
    {
        return $this->reads->catalog($this->tenant);
    }

    /** Expiry and fields checked on the raw values of the change; the context never carries an unchecked proposal. */
    public function context(Change $change): ChangeContext
    {
        $proposed = [];

        if ($change->type->proposes()) {
            $proposed = ['until' => $this->until($change->until), 'fields' => $this->fields($change)];
        } elseif ($change->type->proposesFields()) {
            $proposed = ['fields' => $this->actionFields($change)];
        }

        return new ChangeContext(
            panel: $this->panel, scope: $change->scope, actor: $change->actor,
            actorModel: $this->actorModel($change->actor),
            subject: $change->subject, user: $change->subject === null ? null : $this->target($change->subject),
            role: $change->role === null ? null : $this->role($change->role->key()), operation: $change->type,
            phase: $change->type->phase(), proposed: $proposed, now: $this->now, state: $this->locked,
        );
    }

    /**
     * Final validation of the change a pipe passed on; returns the change the writer may apply.
     *
     * @throws AzGuardException
     */
    public function finalize(Change $planned, mixed $final): Change
    {
        if (! $final instanceof Change || ! $planned->sameIdentity($final)) {
            throw InvalidConfigurationException::failing('changing', 'A changing pipe may change only until and fields of a change.');
        }
        IdentityCodec::assertSourceLabel($final->origin);

        if (! $final->scope->tenant->equals($this->tenant)) {
            throw new TenantMismatchException('A change leaves the tenant of its operation.');
        }

        if ($final->type->isRevocation() || $final->type->isTouch()) {
            return $final->finalized();
        }

        if ($final->type->isMigration()) {
            $this->migration($final);

            return $final->finalized();
        }

        if ($final->type->isAction()) {
            $this->action($final);

            return $final->finalized();
        }
        $subject = $final->subject ?? throw new SubjectNotAcceptedException('A grant change needs a target subject.');
        $this->subject($subject);
        $definition = $final->role === null ? null : $this->grantableRole($final->role->key());

        if ($final->permission !== null) {
            $this->grantablePermission($final->permission);
        }
        $this->tenancy($final, $subject, $definition);
        $context = $final->context();
        $this->eligibility($final, $context, $definition);

        if ($final->type === ChangeType::UpdateGrant) {
            $stored = $this->reads->find($final->grantId ?? '', $this->tenant, $final->origin);

            if ($stored === null || ($final->expectedFingerprint !== null && ! hash_equals($stored->fingerprint, $final->expectedFingerprint))) {
                throw new StaleSelectionException('The selected grant changed or left this panel, tenant and origin after it was read.');
            }
        }

        return $final->finalized();
    }

    /**
     * A role key migration rests on the stored row it was planned from and on the current code catalog alone: the
     * destination is a registered grantable role that lists the previous key among its former keys, and the stored
     * grant is still the one that was read. Live assignment checks of a new grant do not apply to a historical row.
     *
     * @throws AzGuardException
     */
    private function migration(Change $change): void
    {
        $to = $change->role ?? throw new UnknownRoleException('A role key migration names the current role.');
        $from = $change->previousRole ?? throw new UnknownRoleException('A role key migration names the former key.');
        self::assertMigration($this->catalog()->roles(), $this->panel->id(), $from->key(), $to->key());
        $stored = $this->reads->find($change->grantId ?? '', $this->tenant, $change->origin);

        if ($stored === null || $stored->role === null || ! $stored->role->equals($from) || ! $stored->scope->equals($change->scope)
            || ! hash_equals($stored->fingerprint, (string) $change->expectedFingerprint)) {
            throw new StaleSelectionException('The stored grant of the former key changed or left this panel, tenant and origin after it was read.');
        }
    }

    /**
     * `$to` is a registered role that may be granted through storage and names `$from` among its former keys.
     *
     * @param  array<string, array{class: class-string<BaseRole>, key: string, former_keys: list<string>, grantable: bool}>  $roles
     *
     * @throws UnknownRoleException
     * @throws RoleNotGrantableException
     */
    public static function assertMigration(array $roles, string $panel, string $from, string $to): void
    {
        $definition = $roles[$to] ?? throw new UnknownRoleException('Panel '.$panel.' has no role "'.$to.'" to migrate grants to.');

        if (! in_array($from, $definition['former_keys'], true)) {
            throw new UnknownRoleException('"'.$from.'" is not a former key of role "'.$to.'" of panel '.$panel.'.');
        }

        if (! $definition['grantable']) {
            throw new RoleNotGrantableException('Role "'.$to.'" of panel '.$panel.' is not granted through storage; its former grants are not migrated.');
        }
    }

    /**
     * A dynamic permission change: the opt-in writer, a tenant that can own it, valid details and a name that is
     * free (create) or stored as a dynamic permission of this tenant (update, delete). The catalog overlay of a new
     * name is built by the catalog itself, which refuses a static shadow and the panel prefix.
     *
     * @throws AzGuardException
     */
    private function action(Change $change): void
    {
        if (! $this->dynamic) {
            throw new PanelNotWritableException('Panel '.$this->panel->id().' does not declare dynamic permissions.');
        }
        $tenant = $change->scope->tenant;
        $this->actionTenancy($tenant);
        $name = $change->name ?? throw new UnknownPermissionException('A dynamic permission change names the permission.');

        if ($change->type === ChangeType::DeletePermission) {
            $this->stored($tenant, $name);

            if ($this->reads->grantsNamed($tenant, $name) !== []) {
                throw new StaleSelectionException('Exact grants of permission "'.$name.'" changed after its deletion cascade was planned.');
            }

            return;
        }
        $change->context();
        $details = $change->details ?? throw new InvalidChangeFieldsException(['details' => ['A dynamic permission change carries its details.']]);
        $this->actionDetails($details);

        if ($change->type === ChangeType::UpdatePermission) {
            $this->stored($tenant, $name);

            return;
        }

        if ($this->catalog()->find($name) !== null) {
            throw new DuplicatePermissionException('Permission "'.$name.'" of panel '.$this->panel->id().' already exists in this tenant or is a static permission.');
        }
        $this->reads->catalog($tenant, new PermissionDefinition(local: $name, authority: PermissionAuthority::Grants,
            label: $details->label, group: $details->group, description: $details->description));
    }

    /** Only dynamic permission rows of this tenant are updated or deleted; a static name is not stored and never is. */
    private function stored(TenantRef $tenant, string $name): PermissionRecord
    {
        return $this->reads->action($tenant, $name)
            ?? throw new UnknownPermissionException('Panel '.$this->panel->id().' has no dynamic permission "'.$name.'" in this tenant.');
    }

    /** A permission belongs to one tenant: the global tenant only on a panel without tenants. */
    private function actionTenancy(TenantRef $tenant): void
    {
        $policy = $this->panel->tenants();

        if ($policy->mode() === 'none' && ! $tenant->isGlobal()) {
            throw new TenantMismatchException('Panel '.$this->panel->id().' has no tenants.');
        }

        if ($policy->mode() === 'required') {
            if ($tenant->isGlobal()) {
                throw new TenantRequiredException('Panel '.$this->panel->id().' keeps dynamic permissions only inside a tenant.');
            }

            if ($tenant->type() !== $policy->definition()?->type()) {
                throw new TenantMismatchException('Tenant type '.$tenant->type().' is not the tenant of panel '.$this->panel->id().'.');
            }
        }
    }

    /** Label and group fit their columns; the description is free text. */
    private function actionDetails(PermissionDetails $details): void
    {
        $errors = [];
        foreach (['label' => $details->label, 'group' => $details->group] as $name => $value) {
            if ($value !== null && mb_strlen($value) > 191) {
                $errors[$name] = ['The '.$name.' is longer than 191 characters.'];
            }
        }

        if ($errors !== []) {
            throw new InvalidChangeFieldsException($errors);
        }
    }

    /**
     * A dynamic permission declares no field schema: any field is refused.
     *
     * @return array<string, mixed>
     */
    private function actionFields(Change $change): array
    {
        if ($change->fields !== []) {
            throw new InvalidChangeFieldsException(array_map(static fn (): array => ['A dynamic permission declares no fields.'], $change->fields));
        }

        return [];
    }

    /** The target is accepted by the panel and present; a missing target is never replaced by an empty model. */
    private function subject(SubjectRef $subject): void
    {
        if (! $this->panel->accepts($subject)) {
            throw new SubjectNotAcceptedException('Subject type '.$subject->type().' is not a subject of panel '.$this->panel->id().'.');
        }

        if ($this->target($subject) === null) {
            throw new SubjectNotAcceptedException('Subject '.$subject->key().' does not exist.');
        }
    }

    /**
     * A code role of the panel that is granted through storage; a former key is not an alias.
     *
     * @return array{class: class-string<BaseRole>, key: string, former_keys: list<string>, scopes: list<array{type: string}>, scope_required: bool, grantable: bool}
     */
    private function grantableRole(string $key): array
    {
        $roles = $this->catalog()->roles();
        $definition = $roles[$key] ?? null;

        if ($definition === null) {
            foreach ($roles as $role) {
                if (in_array($key, $role['former_keys'], true)) {
                    throw new UnknownRoleException('Role "'.$key.'" of panel '.$this->panel->id().' was renamed to "'.$role['key']
                        .'"; a former key is not granted, migrate it explicitly.');
                }
            }

            throw new UnknownRoleException('Panel '.$this->panel->id().' has no role "'.$key.'".');
        }

        if (! $definition['grantable']) {
            throw new RoleNotGrantableException('Role "'.$key.'" of panel '.$this->panel->id().' is not granted through storage.');
        }

        return $definition;
    }

    /** An exact Grants permission of the locked catalog, or a pattern that covers one; never a bare wildcard. */
    private function grantablePermission(PermissionPattern $pattern): void
    {
        if ($this->rolesOnly) {
            throw new PanelNotWritableException('Panel '.$this->panel->id().' stores role grants only.');
        }
        $catalog = $this->catalog();

        if ($pattern->isExact()) {
            $definition = $catalog->find($pattern->local())
                ?? throw new UnknownPermissionException('Panel '.$this->panel->id().' has no permission "'.$pattern->local().'" in this tenant.');

            if ($definition->authority !== PermissionAuthority::Grants) {
                throw new PermissionNotGrantableException('Permission "'.$pattern->local().'" of panel '.$this->panel->id()
                    .' is decided by its policy alone and is never assigned.');
            }

            return;
        }
        foreach ($catalog->all() as $definition) {
            if ($definition->authority === PermissionAuthority::Grants && PatternMatcher::covers($pattern->local(), $definition->local)) {
                return;
            }
        }

        throw new UnknownPermissionException('Pattern "'.$pattern->local().'" covers no assignable permission of panel '.$this->panel->id().'.');
    }

    /**
     * Tenant policy and scope policy of the panel: no implicit all-tenants, global roles only where allowed.
     *
     * @param  array{class: class-string<BaseRole>, scopes: list<array{type: string}>, scope_required: bool}|null  $role
     */
    private function tenancy(Change $change, SubjectRef $subject, ?array $role): void
    {
        $policy = $this->panel->tenants();
        $tenant = $change->scope->tenant;

        if ($policy->mode() === 'none' && ! $tenant->isGlobal()) {
            throw new TenantMismatchException('Panel '.$this->panel->id().' has no tenants.');
        }

        if ($policy->mode() === 'required') {
            if ($tenant->isGlobal()) {
                if ($role === null || ! in_array($role['class'], $policy->globalRoles(), true)) {
                    throw new TenantRequiredException('Panel '.$this->panel->id().' grants this only inside a tenant.');
                }
            } elseif ($tenant->type() !== $policy->definition()?->type()) {
                throw new TenantMismatchException('Tenant type '.$tenant->type().' is not the tenant of panel '.$this->panel->id().'.');
            } elseif (! $this->tenantMember($subject, $tenant)) {
                throw new TenantMismatchException('Subject '.$subject->key().' is not a member of tenant '.$tenant->key().'.');
            }
        }
        $mode = $this->panel->scopes()->mode();

        if ($mode === 'required' && $change->scope->context->isGlobal()) {
            throw new AssignmentScopeRequiredException('Panel '.$this->panel->id().' grants only inside an assignment scope.');
        }

        if ($mode === 'none' && ! $change->scope->context->isGlobal()) {
            throw new AssignmentScopeNotAcceptedException('Panel '.$this->panel->id().' has no assignment scopes.');
        }
    }

    /**
     * Assignment eligibility with the validated proposal: context type and role binding, structure and owner tenant,
     * membership, then common and role-binding Assignment filters for the target.
     *
     * @param  array{class: class-string<BaseRole>, scopes: list<array{type: string}>, scope_required: bool}|null  $role
     */
    private function eligibility(Change $change, ChangeContext $context, ?array $role): void
    {
        $ref = $change->scope->context;

        if ($ref->isGlobal()) {
            if ($role !== null && $role['scope_required']) {
                throw new AssignmentScopeRequiredException('Role "'.$change->role?->key().'" is granted only inside an assignment scope.');
            }

            return;
        }
        $type = $ref->type() ?? '';
        $definition = $this->panel->scopeDefinition($type)
            ?? throw new AssignmentScopeNotAcceptedException('Panel '.$this->panel->id().' has no assignment scope type "'.$type.'".');

        if ($role !== null && ! in_array($type, array_column($role['scopes'], 'type'), true)) {
            throw new AssignmentScopeNotAcceptedException('Role "'.$change->role?->key().'" is not granted in assignment scope type "'.$type.'".');
        }
        $subject = $change->subject ?? throw new SubjectNotAcceptedException('A grant change needs a target subject.');

        try {
            $resolved = $definition->resolve($ref);

            if ($resolved === null || ! $resolved->ref->equals($ref)) {
                throw new AssignmentScopeNotAcceptedException('Assignment scope '.$ref->key().' does not exist.');
            }

            if (! $resolved->tenant->equals($change->scope->tenant)) {
                throw new TenantMismatchException('Assignment scope '.$ref->key().' belongs to another tenant.');
            }

            if (! $this->scopeMember($subject, $resolved)) {
                throw new AssignmentScopeNotAcceptedException('Subject '.$subject->key().' is not a member of assignment scope '.$ref->key().'.');
            }
            $runtime = new AssignmentScopeRuntime(panel: $this->panel, scope: $change->scope, subject: $subject, user: $context->user,
                role: $context->role, grant: null, actor: $context->actor, actorModel: $context->actorModel, now: $this->now,
                phase: AssignmentScopePhase::Assignment, proposed: $context->proposed);
            $allowed = (new ScopeEligibility($this->container))->assignment($runtime, $resolved);
        } catch (AzGuardException|PDOException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new AssignmentScopeNotAcceptedException('Assignment scope '.$ref->key().' was refused by its filters: '.$error->getMessage(), 0, $error);
        }

        if (! $allowed) {
            throw new AssignmentScopeNotAcceptedException('Assignment scope '.$ref->key().' is not accepted for this target and role.');
        }
    }

    /** Absolute UTC expiry in the future, kept to the second as storage keeps it. */
    private function until(?DateTimeImmutable $until): ?DateTimeImmutable
    {
        if ($until === null) {
            return null;
        }
        $until = $until->setTimezone(new DateTimeZone('UTC'));
        $until = $until->setTime((int) $until->format('H'), (int) $until->format('i'), (int) $until->format('s'));

        if ($until <= $this->now) {
            throw new InvalidChangeFieldsException(['until' => ['The expiry must be later than now.']]);
        }

        return $until;
    }

    /**
     * The field schema of the grant model and the panel, and the tenant of every model field on a tenant panel.
     *
     * @return array<string, mixed>
     */
    private function fields(Change $change): array
    {
        $grantFields = $this->reads->fields($change->isRole() ? 'role' : 'permission');
        $values = $grantFields->validate($change->fields);

        if ($this->panel->tenants()->mode() !== 'required') {
            return $values;
        }
        foreach ($grantFields->declared() as $field) {
            $value = $values[$field->name()] ?? null;
            $class = $field->valueClass();

            if ($field->type() !== 'model' || $value === null || $class === null) {
                continue;
            }
            foreach ($field->isMultiple() && is_array($value) ? $value : [$value] as $id) {
                $this->fieldTenant($field->name(), $class, $id, $change->scope);
            }
        }

        return $values;
    }

    private function fieldTenant(string $name, string $class, mixed $id, AccessScope $scope): void
    {
        $resolver = null;
        foreach ($this->panel->resourceScopes() as $model => $declared) {
            if (is_a($class, $model, true)) {
                $resolver = is_string($declared) ? $this->container->make($declared) : $declared;

                break;
            }
        }

        if (! $resolver instanceof ResourceScopeResolver) {
            throw new InvalidChangeFieldsException([$name => ['The panel cannot tell the tenant of '.$class.'.']]);
        }
        $record = is_a($class, Model::class, true) && (is_int($id) || is_string($id)) ? $class::query()->find($id) : null;

        if (! $record instanceof Model || ! $resolver->resolve($record, AccessScope::in($scope->tenant))->tenant->equals($scope->tenant)) {
            throw new InvalidChangeFieldsException([$name => ['The selected record does not belong to tenant '.$scope->tenant->key().'.']]);
        }
    }

    private function role(string $key): ?BaseRole
    {
        $definition = $this->catalog()->roles()[$key] ?? null;

        if ($definition === null) {
            return null;
        }
        $role = $this->container->make($definition['class']);

        return $role instanceof BaseRole ? $role : throw new DefinitionException('Role resolver did not return '.BaseRole::class.'.');
    }

    /** The actor's model as Access resolves it: the guard user, or the panel subject of the reference; never invented. */
    private function actorModel(?ActorRef $actor): ?Model
    {
        if ($actor === null || $actor->id === null) {
            return null;
        }

        if ($this->actorModel !== null && $actor == $this->actor) {
            return $this->actorModel;
        }
        $subject = SubjectRef::of($actor->type, $actor->id);

        return $this->panel->accepts($subject) ? $this->target($subject) : null;
    }

    private function target(SubjectRef $subject): ?Model
    {
        if (! array_key_exists($subject->key(), $this->targets)) {
            $this->targets[$subject->key()] = (new ModelSubjectResolver)->resolve($this->panel, $subject);
        }

        return $this->targets[$subject->key()];
    }

    private function tenantMember(SubjectRef $subject, TenantRef $tenant): bool
    {
        $declared = $this->panel->tenants()->membership();

        if ($declared === null) {
            return true;
        }
        $adapter = is_string($declared) ? $this->container->make($declared) : $declared;

        if (! $adapter instanceof TenantMembership) {
            throw new DefinitionException('Tenant membership resolver did not return '.TenantMembership::class.'.');
        }

        return $adapter->isMember(subject: $subject, tenant: $tenant);
    }

    private function scopeMember(SubjectRef $subject, ResolvedAssignmentScope $resolved): bool
    {
        $declared = $this->panel->scopes()->membership();

        if ($declared === null) {
            return true;
        }
        $adapter = is_string($declared) ? $this->container->make($declared) : $declared;

        if (! $adapter instanceof AssignmentScopeMembership) {
            throw new DefinitionException('Scope membership resolver did not return '.AssignmentScopeMembership::class.'.');
        }

        return $adapter->isMember(subject: $subject, context: $resolved->ref);
    }
}
