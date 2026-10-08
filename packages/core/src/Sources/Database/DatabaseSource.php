<?php

declare(strict_types=1);

namespace AzGuard\Sources\Database;

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeEffect;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\EffectKind;
use AzGuard\Changes\GrantFilter;
use AzGuard\Changes\GrantRecord;
use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\AssignmentScopeSelection;
use AzGuard\Contracts\Sources\DescribesSchema;
use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Contracts\Sources\FiltersQueries;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Contracts\Sources\StoresGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Exceptions\ConsistencyException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\Reads;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;
use AzGuard\Storage\GrantFields;
use AzGuard\Storage\Models\Permission;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;
use AzGuard\Storage\Schema\HostKeyColumns;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageReadSession;
use AzGuard\Storage\StorageRegistry;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Raw database assignments. Role expansion and qualification belong to the engine.
 *
 * @api
 */
final class DatabaseSource implements DescribesSchema, FencesReads, FiltersQueries, ProvidesGrants, ProvidesPermissions, ProvidesRoleGrants, StoresGrants
{
    private bool $onlyRoles = false;

    private bool $dynamic = false;

    private string|Storage $selectedStorage = 'default';

    /** @var array<string, class-string<Permission|PermissionGrant|RoleGrant>> */
    private array $selectedModels = [];

    /** @var array<string, list<string>> */
    private array $fields = ['role_grant' => [], 'permission_grant' => []];

    private ?string $panelId = null;

    public static function make(): static
    {
        return new self;
    }

    public function rolesOnly(): static
    {
        $copy = clone $this;
        $copy->onlyRoles = true;

        return $copy;
    }

    public function dynamicPermissions(): static
    {
        $copy = clone $this;
        $copy->dynamic = true;

        return $copy;
    }

    public function storage(string|Storage $storage): static
    {
        if ($storage === '') {
            throw new DefinitionException('DatabaseSource storage needs a name.');
        }
        $copy = clone $this;
        $copy->selectedStorage = $storage;

        return $copy;
    }

    /** @param class-string<RoleGrant>|null $roleGrant
     * @param  class-string<PermissionGrant>|null  $permissionGrant
     * @param  class-string<Permission>|null  $permission
     */
    public function models(?string $roleGrant = null, ?string $permissionGrant = null, ?string $permission = null): static
    {
        $copy = clone $this;
        foreach (['role_grant' => [$roleGrant, RoleGrant::class], 'permission_grant' => [$permissionGrant, PermissionGrant::class], 'permission' => [$permission, Permission::class]] as $kind => [$class, $base]) {
            if ($class === null) {
                continue;
            }
            self::validateModel($class, $base);
            $copy->selectedModels[$kind] = $class;
        }

        return $copy;
    }

    /** @param list<string> $roleGrant
     * @param  list<string>  $permissionGrant
     */
    public function decisionFields(array $roleGrant = [], array $permissionGrant = []): static
    {
        $copy = clone $this;
        foreach (['role_grant' => $roleGrant, 'permission_grant' => $permissionGrant] as $kind => $names) {
            $names = self::validatedFieldNames($names);
            $copy->fields[$kind] = array_values(array_unique($names));
        }

        return $copy;
    }

    /** @param array<mixed> $names
     * @return list<string>
     */
    private static function validatedFieldNames(array $names): array
    {
        if (! array_is_list($names)) {
            throw new DefinitionException('Decision fields must be a list of field names.');
        }
        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                throw new DefinitionException('Decision fields must be non-empty names.');
            }
        }

        return $names;
    }

    private static function validateModel(string $class, string $base): void
    {
        if (! is_a($class, $base, true) || ! (new ReflectionClass($class))->isInstantiable()) {
            throw new StorageMismatchException("DatabaseSource model must be a concrete subclass of {$base}.");
        }
    }

    public function id(): string
    {
        return 'database';
    }

    public function isRolesOnly(): bool
    {
        return $this->onlyRoles;
    }

    public function isDynamic(): bool
    {
        return $this->dynamic;
    }

    public function volatility(): Volatility
    {
        return Volatility::Stable;
    }

    /** @internal panel attachment binds the writer without reading authority. */
    public function bindPanel(string $panel): void
    {
        if ($this->panelId !== null && $this->panelId !== $panel) {
            throw new DefinitionException('A DatabaseSource instance belongs to one panel.');
        }
        $this->panelId = $panel;
    }

    /**
     * Resolves the storage the source names and checks its models against it, so a misspelled storage or a model of
     * another table stops the boot instead of the first check.
     *
     * @internal
     *
     * @throws InvalidConfigurationException when the storage is not registered
     * @throws StorageMismatchException when a model does not fit the storage
     */
    public function validateStorage(): void
    {
        $storage = $this->resolvedStorage();

        foreach (['role_grant', 'permission_grant', 'permission'] as $kind) {
            $storage->assertModel($kind, $this->selectedModels[$kind] ?? null);
        }
    }

    public function describe(Panel $panel, ?TenantRef $tenant = null): SourceDescription
    {
        $this->bindPanel($panel->id());

        return new SourceDescription(
            $this->id(),
            self::class,
            [ProvidesGrants::class, ProvidesRoleGrants::class, ProvidesPermissions::class, StoresGrants::class, FencesReads::class, FiltersQueries::class, DescribesSchema::class],
            $this->dynamic,
            label: 'Database',
            fields: [
                FieldTarget::RoleGrant->value => $this->modelFields('role_grant'),
                FieldTarget::PermissionGrant->value => $this->onlyRoles ? [] : $this->modelFields('permission_grant'),
            ],
        );
    }

    /**
     * Fields the grant model of a kind declares, marked with the model as `GrantFields` marks them; no database read.
     *
     * @return list<Field>
     */
    private function modelFields(string $kind): array
    {
        $class = $this->selectedModels[$kind] ?? app(AzGuardConfig::class)->defaultModels()[$kind];

        if (! is_a($class, RoleGrant::class, true) && ! is_a($class, PermissionGrant::class, true)) {
            throw new StorageMismatchException('Storage model '.$class.' must be a grant model.');
        }
        $fields = [];

        foreach ($class::azguardFields() as $field) {
            $fields[] = $field->withContribution('model:'.$class);
        }

        return $fields;
    }

    /** @return iterable<PermissionDefinition> */
    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
    {
        if (! $this->dynamic) {
            return [];
        }

        if ($tenant === null) {
            throw new DefinitionException('Dynamic permissions require an explicit tenant.');
        }

        $this->bindPanel($panel->id());
        $session = $this->resolvedStorage()->readSession($panel->settings()->reads());
        $fingerprint = app(PanelRegistry::class)->fingerprint($panel->id());
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $before = $this->token($session, $panel, $fingerprint);
            $definitions = $this->readPermissions($session, $panel, $tenant);

            if ($before->equals($this->token($session, $panel, $fingerprint))) {
                return $definitions;
            }
        }

        throw new ConsistencyException('DatabaseSource definitions changed during all three read attempts.');
    }

    /** @internal The engine retains this pinned handle for the complete Prepare/authority attempt. */
    public function openReadSession(EvaluationContext $context): StorageReadSession
    {
        $this->bindPanel($context->panel()->id());

        return $this->resolvedStorage()->readSession($context->panel()->settings()->reads());
    }

    /** @internal Read both edges on the same attempt handle. */
    public function readState(StorageReadSession $session, EvaluationContext $context): StateToken
    {
        return $this->token($session, $context->panel(), $context->state()->fingerprint);
    }

    /** @internal Unfenced capability; the engine owns the surrounding whole-attempt fence.
     * @return list<PermissionDefinition>
     */
    public function readPermissions(StorageReadSession $session, Panel $panel, TenantRef $tenant): array
    {
        if (! $this->dynamic) {
            return [];
        }
        $model = $session->model('permission', $this->selectedModels['permission'] ?? null);
        $definitions = [];
        foreach ($session->table('permissions')->where('panel', $panel->id())->where('tenant_key', $tenant->key())->get() as $row) {
            $permission = $model->newFromBuilder((array) $row);

            if (! $permission instanceof Permission || $permission->panel() !== $panel->id() || ! $permission->tenantRef()->equals($tenant)) {
                throw new InvalidSourceContributionException('Dynamic permission identity differs from its query.');
            }
            $definitions[] = new PermissionDefinition(
                local: $permission->permissionKey()->local(), authority: PermissionAuthority::Grants,
                label: $permission->getAttribute('label'), group: $permission->getAttribute('group'),
                description: $permission->getAttribute('description'),
            );
        }

        return $definitions;
    }

    /** @internal Joint root helper; the public host facade is defined separately.
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withinAuthorityTransaction(Panel $panel, Closure $callback): mixed
    {
        $this->bindPanel($panel->id());

        return $this->resolvedStorage()->withinAuthorityTransaction($panel->id(), $callback);
    }

    /**
     * Applies one change the change pipeline validated, inside the active mutation of the bound panel.
     *
     * @throws UnsupportedDirectWriteException outside the pipeline or the mutation, or for a foreign panel
     * @throws PanelNotWritableException when a roles-only writer receives a permission grant or update, or a writer without
     *                                   `dynamicPermissions()` receives a change of a dynamic permission
     */
    public function apply(Change $change): ChangeResult
    {
        $panel = $this->panelId ?? throw new DefinitionException('Attach DatabaseSource to a panel before applying changes.');

        if (! $change->isFinal() || $change->panel !== $panel) {
            throw new UnsupportedDirectWriteException('DatabaseSource applies only changes the change pipeline validated for panel '.$panel.'.');
        }

        if ($change->type->isTouch()) {
            return $this->touch($change, $panel);
        }

        if ($change->isAction() && ! $this->dynamic) {
            throw new PanelNotWritableException('Panel '.$panel.' does not declare dynamic permissions.');
        }

        // A roles-only writer still removes a permission grant stored before it became roles-only: cleanup stays possible.
        if ($this->onlyRoles && ! $change->isAction() && ! $change->isRole() && ! $change->type->isRevocation()) {
            throw new PanelNotWritableException('Panel '.$panel.' stores role grants only.');
        }
        $storage = $this->resolvedStorage();
        $reads = $this->lockedReads(app(PanelRegistry::class)->get($panel));

        return $change->isAction()
            ? (new ActionWriter($storage->mutation($panel), $reads))->apply($change)
            : (new GrantWriter($storage, $storage->mutation($panel), $reads))->apply($change);
    }

    /**
     * Raises the state version of the panel inside its active mutation: the only effect is an `Updated` one without a
     * record, which carries the version before the touch and the reason.
     */
    private function touch(Change $change, string $panel): ChangeResult
    {
        $reads = $this->lockedReads(app(PanelRegistry::class)->get($panel));
        $previous = $reads->state()->version;
        $this->resolvedStorage()->mutation($panel)->touch($panel);
        $effect = new ChangeEffect(EffectKind::Updated, $change->type, null, null, strtolower((string) Str::ulid()), previousVersion: $previous, reason: $change->reason);

        return ChangeResult::written(null, [$effect], $reads->token(1),
            $change->correlationId() ?? throw new UnsupportedDirectWriteException('A change is applied by the change pipeline.'));
    }

    /**
     * @internal Runs `$callback` after the root commit that makes the active mutation of the panel durable; a rollback
     * of that commit, a retry or a failed mutation drops it.
     *
     * @param  Closure(): void  $callback
     *
     * @throws UnsupportedDirectWriteException outside the active mutation of the panel
     */
    public function afterCommit(Panel $panel, Closure $callback): void
    {
        $this->bindPanel($panel->id());
        $this->resolvedStorage()->mutation($panel->id())->afterCommit($callback);
    }

    /**
     * @internal Runs `$callback` after the commit of the transaction the package itself holds open on the storage of
     * the panel and returns true; returns false, running nothing, when no such transaction is active.
     *
     * @param  Closure(): void  $callback
     */
    public function afterAuthorityCommit(Closure $callback): bool
    {
        $storage = $this->resolvedStorage();

        if ($storage->authorityTransaction() === null) {
            return false;
        }
        $storage->connection()->afterCommit($callback);

        return true;
    }

    /**
     * @internal Appends one row to the journal table in the active mutation of the panel, so a rollback removes it.
     *
     * @param  array<string, mixed>  $row  the columns of the journal, `payload` as an array
     *
     * @throws UnsupportedDirectWriteException outside the active mutation of the panel
     */
    public function appendJournal(Panel $panel, array $row): void
    {
        $this->bindPanel($panel->id());
        $storage = $this->resolvedStorage();
        $mutation = $storage->mutation($panel->id());
        $keys = $storage->hostKeys();

        foreach (['subject_id', 'actor_id'] as $column) {
            if (is_int($row[$column] ?? null) || is_string($row[$column] ?? null)) {
                $row[$column] = HostKeyColumns::canonical($keys, $row[$column]);
            }
        }
        $row['payload'] = json_encode($row['payload'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $mutation->table('audit_log')->insert($row);
    }

    /**
     * @internal Tenants of the panel that still hold a grant whose expiry has passed; `$tenant` narrows to one.
     *
     * @return list<TenantRef>
     */
    public function tenantsWithExpired(Panel $panel, ?TenantRef $tenant, DateTimeImmutable $now): array
    {
        $this->bindPanel($panel->id());
        $storage = $this->resolvedStorage();
        $found = [];
        foreach (['role_grants', 'permission_grants'] as $table) {
            $query = $storage->table($table)->where('panel', $panel->id())->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

            if ($tenant !== null) {
                $query->where('tenant_key', $tenant->key());
            }
            foreach ($query->select('tenant_key', 'tenant_type', 'tenant_id')->distinct()->orderBy('tenant_key')->get() as $row) {
                $found[(string) $row->tenant_key] = $row->tenant_type === null ? TenantRef::global() : TenantRef::of((string) $row->tenant_type, (string) $row->tenant_id);
            }
        }
        ksort($found, SORT_STRING);

        return array_values($found);
    }

    /**
     * @internal Tenants of the panel that hold a stored grant of the role key `$role`, in every origin.
     *
     * @return list<TenantRef>
     */
    public function tenantsHolding(Panel $panel, string $role): array
    {
        $this->bindPanel($panel->id());
        $found = [];
        foreach ($this->resolvedStorage()->table('role_grants')->where('panel', $panel->id())->where('role', $role)
            ->select('tenant_key', 'tenant_type', 'tenant_id')->distinct()->orderBy('tenant_key')->get() as $row) {
            $found[(string) $row->tenant_key] = $row->tenant_type === null ? TenantRef::global() : TenantRef::of((string) $row->tenant_type, (string) $row->tenant_id);
        }

        return array_values($found);
    }

    /**
     * @internal One stored grant by id inside the panel, tenant and origin, whatever its state, read on the primary
     * outside any mutation; a foreign or malformed id is null.
     */
    public function inspectGrant(Panel $panel, TenantRef $tenant, string $origin, string $id): ?GrantRecord
    {
        return $this->inspecting($panel, $tenant, static fn (GrantInspection $inspection): ?GrantRecord => $inspection->find($tenant, $origin, $id));
    }

    /**
     * @internal At most `$limit` stored grants of the panel, tenant and origin that match the filter after `$after`,
     * expired and orphaned grants included as the filter asks.
     *
     * @param  array{'role'|'permission', int}|null  $after
     * @return list<GrantRecord>
     */
    public function inspectGrants(Panel $panel, TenantRef $tenant, string $origin, GrantFilter $filter, ?array $after, DateTimeImmutable $now, int $limit): array
    {
        return $this->inspecting($panel, $tenant, static fn (GrantInspection $inspection): array => $inspection->page($tenant, $origin, $filter, $after, $now, $limit));
    }

    /**
     * @internal The stored grants of one kind of the subject inside the panel, tenant and origin as write-guarded models
     * of the panel, in one context or in all contexts of the tenant, whatever their state.
     *
     * @param  'role'|'permission'  $kind
     * @return list<RoleGrant|PermissionGrant>
     */
    public function grantModels(Panel $panel, TenantRef $tenant, string $origin, string $kind, SubjectRef $subject, ?AssignmentScopeRef $context): array
    {
        return $this->inspecting($panel, $tenant, static fn (GrantInspection $inspection): array => $inspection->models($kind, $tenant, $origin, $subject, $context));
    }

    /**
     * One read of stored grants on a pinned primary session between two equal state reads, with the catalog of the
     * tenant read on the same session; up to three attempts.
     *
     * @template T
     *
     * @param  Closure(GrantInspection): T  $read
     * @return T
     */
    private function inspecting(Panel $panel, TenantRef $tenant, Closure $read): mixed
    {
        $this->bindPanel($panel->id());
        $storage = $this->resolvedStorage();
        $session = $storage->readSession(Reads::Primary);
        $registry = app(PanelRegistry::class);
        $fingerprint = $registry->fingerprint($panel->id());
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $before = $this->token($session, $panel, $fingerprint);
            $catalog = $registry->catalog($panel->id())->withDynamic($this->readPermissions($session, $panel, $tenant));
            $result = $read(new GrantInspection($session, $storage->hostKeys(), $panel, $catalog, $this->onlyRoles,
                new GrantRows($session, $panel, $registry, $this->selectedModels)));

            if ($before->equals($this->token($session, $panel, $fingerprint))) {
                return $result;
            }
        }

        throw new ConsistencyException('Stored grants changed during all three inspection attempts.');
    }

    /**
     * @internal Reads of the change pipeline under the panel lock of the active mutation.
     *
     * @throws UnsupportedDirectWriteException outside the active mutation of the panel
     */
    public function lockedReads(Panel $panel): LockedReads
    {
        $this->bindPanel($panel->id());
        $storage = $this->resolvedStorage();

        return new LockedReads($storage, $storage->mutation($panel->id()), $panel, app(PanelRegistry::class), $this->selectedModels, $this->dynamic);
    }

    /** @internal Whether a transaction is already open on the writer connection; its commit is then not ours. */
    public function inTransaction(): bool
    {
        return $this->resolvedStorage()->connection()->transactionLevel() > 0;
    }

    /** @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        $panel = $this->panelId ?? throw new DefinitionException('Attach DatabaseSource to a panel before opening its transaction.');

        return $this->resolvedStorage()->mutate($panel, static fn (): mixed => $callback());
    }

    public function state(Panel $panel, TenantRef $tenant): StateToken
    {
        $this->bindPanel($panel->id());
        $session = $this->resolvedStorage()->readSession($panel->settings()->reads());

        return $this->token($session, $panel, app(PanelRegistry::class)->fingerprint($panel->id()));
    }

    /** @param list<AccessScope> $scopes
     * @return iterable<Grant>
     */
    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        if ($this->onlyRoles || $scopes === []) {
            return [];
        }

        return $this->fenced($context, fn (StorageReadSession $session): array => $this->read($session, 'permission_grant', $subject, $scopes, $context));
    }

    /** @param list<AccessScope> $scopes
     * @return iterable<RoleContribution>
     */
    public function roleGrants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        if ($scopes === []) {
            return [];
        }

        return $this->fenced($context, fn (StorageReadSession $session): array => $this->read($session, 'role_grant', $subject, $scopes, $context));
    }

    /** @internal one whole-set fence for both capabilities.
     * @param  list<AccessScope>  $scopes
     * @return array{state: StateToken, grants: list<Grant>, roles: list<RoleContribution>}
     */
    public function readContributions(SubjectRef $subject, array $scopes, EvaluationContext $context): array
    {
        return $this->fenced($context, fn (StorageReadSession $session, StateToken $before): array => [
            'state' => $before,
            ...$this->readAssignments($session, $subject, $scopes, $context),
        ]);
    }

    /** @internal Unfenced capabilities on the engine's pinned attempt handle.
     * @param  list<AccessScope>  $scopes
     * @return array{grants: list<Grant>, roles: list<RoleContribution>}
     */
    public function readAssignments(StorageReadSession $session, SubjectRef $subject, array $scopes, EvaluationContext $context): array
    {
        return [
            'grants' => $this->onlyRoles || $scopes === [] ? [] : $this->read($session, 'permission_grant', $subject, $scopes, $context),
            'roles' => $scopes === [] ? [] : $this->read($session, 'role_grant', $subject, $scopes, $context),
        ];
    }

    public function contextsCovering(SubjectRef $subject, PermissionKey $key, string $contextType, EvaluationContext $context): AssignmentScopeSelection
    {
        IdentityCodec::assertTypeAlias($contextType);

        if ($key->panel() !== $context->panel()->id()) {
            throw new InvalidSourceContributionException('Selection permission belongs to another panel.');
        }

        return $this->fenced($context, fn (StorageReadSession $session): AssignmentScopeSelection => $this->readSelection($session, $subject, $key, $contextType, $context));
    }

    /** @internal Selection on the operation's pinned whole-source fence. */
    public function readSelection(StorageReadSession $session, SubjectRef $subject, PermissionKey $key, string $contextType, EvaluationContext $context): AssignmentScopeSelection
    {
        IdentityCodec::assertTypeAlias($contextType);

        if ($key->panel() !== $context->panel()->id()) {
            throw new InvalidSourceContributionException('Selection permission belongs to another panel.');
        }

        return (function () use ($session, $subject, $contextType, $context): AssignmentScopeSelection {
            $contributions = $this->read($session, 'role_grant', $subject, [], $context, $contextType);

            if (! $this->onlyRoles) {
                $contributions = [...$contributions, ...$this->read($session, 'permission_grant', $subject, [], $context, $contextType)];
            }

            if (count($contributions) > 10000) {
                throw new InvalidSourceContributionException('Database selection exceeds the 10000 assignment witness budget.');
            }
            $refs = [];
            $everywhere = false;
            foreach ($contributions as $item) {
                $ref = $item->scope->context;

                if ($ref->isGlobal()) {
                    $everywhere = true;

                    continue;
                }
                $refs[$ref->key()] = $ref;
            }

            if ($contributions === []) {
                return AssignmentScopeSelection::nowhere();
            }

            return $everywhere ? AssignmentScopeSelection::everywhere($contributions) : AssignmentScopeSelection::in(array_values($refs), $contributions);
        })();
    }

    /** @param array<mixed> $scopes
     * @return list<AccessScope>
     */
    private static function validatedScopes(array $scopes): array
    {
        if (! array_is_list($scopes)) {
            throw new InvalidSourceContributionException('Assignment scopes must be a list.');
        }
        foreach ($scopes as $scope) {
            if (! $scope instanceof AccessScope) {
                throw new InvalidSourceContributionException('Assignment scope must be AccessScope.');
            }
        }

        return $scopes;
    }

    private function resolvedStorage(): Storage
    {
        return $this->selectedStorage instanceof Storage ? $this->selectedStorage : app(StorageRegistry::class)->get($this->selectedStorage);
    }

    /** @template T
     * @param  Closure(StorageReadSession, StateToken): T  $callback
     * @return T
     */
    private function fenced(EvaluationContext $context, Closure $callback): mixed
    {
        $this->bindPanel($context->panel()->id());
        $session = $this->resolvedStorage()->readSession($context->panel()->settings()->reads());
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $before = $this->token($session, $context->panel(), $context->state()->fingerprint);
            $result = $callback($session, $before);
            $after = $this->token($session, $context->panel(), $context->state()->fingerprint);

            if ($before->equals($after)) {
                return $result;
            }
        }

        throw new ConsistencyException('DatabaseSource authority changed during all three read attempts.');
    }

    private function token(StorageReadSession $session, Panel $panel, string $fingerprint): StateToken
    {
        $state = $session->state($panel->id());

        return StateToken::of($this->resolvedStorage()->id(), $panel->id(), $state->incarnation ?? 'uninitialized', $state->version ?? 0, $panel->settings()->cacheGeneration(), $fingerprint);
    }

    /** @param list<AccessScope> $scopes
     * @return ($kind is 'role_grant' ? list<RoleContribution> : list<Grant>)
     */
    private function read(StorageReadSession $session, string $kind, SubjectRef $subject, array $scopes, EvaluationContext $context, ?string $contextType = null): array
    {
        $scopes = self::validatedScopes($scopes);
        $storage = $this->resolvedStorage();
        $query = $session->table($kind === 'role_grant' ? 'role_grants' : 'permission_grants')
            ->where('panel', $context->panel()->id())->where('subject_type', $subject->type())
            ->where('subject_id', HostKeyColumns::canonical($storage->hostKeys(), $subject->id()));

        if ($contextType !== null) {
            $tenants = [$context->scope()->tenant->key()];

            if ($context->panel()->tenants()->globalRoles() !== []) {
                $tenants[] = TenantRef::global()->key();
            }
            $query->whereIn('tenant_key', array_unique($tenants))->where(function (Builder $query) use ($contextType): void {
                $query->whereNull('context_type')->orWhere('context_type', $contextType);
            });
        } else {
            $query->where(function (Builder $query) use ($scopes): void {
                foreach ($scopes as $scope) {
                    $query->orWhere(fn (Builder $pair): Builder => $pair->where('tenant_key', $scope->tenant->key())->where('context_key', $scope->context->key()));
                }
            });
        }
        $model = $session->model($kind, $this->selectedModels[$kind] ?? null);
        $target = $kind === 'role_grant' ? FieldTarget::RoleGrant : FieldTarget::PermissionGrant;
        $fields = GrantFields::for($session, $target, $model::class, $context->panel()->fields($target), $this->fields[$kind]);
        $items = [];
        $consume = function (object $row) use ($model, $subject, $context, $fields, &$items): void {
            $assignment = $model->newFromBuilder((array) $row);

            if (! $assignment instanceof RoleGrant && ! $assignment instanceof PermissionGrant) {
                throw new InvalidSourceContributionException('Invalid assignment model.');
            }

            if (! $assignment->subjectRef()->equals($subject) || $assignment->panel() !== $context->panel()->id()) {
                throw new InvalidSourceContributionException('Assignment identity differs from its query.');
            }
            $scope = AccessScope::in($assignment->tenantRef(), $assignment->assignmentScopeRef());
            $items[] = $assignment instanceof RoleGrant
                ? RoleContribution::of($assignment->roleKey(), $scope, $this->id(), $assignment->origin(), $assignment->expiresAt(), $fields->decisionValues($assignment))
                : Grant::of(PermissionPattern::of($assignment->panel(), $assignment->permissionKey()->local()), $this->id(), $scope, origin: $assignment->origin(), expiresAt: $assignment->expiresAt(), fields: $fields->decisionValues($assignment));
        };

        if ($contextType === null) {
            foreach ($query->get() as $row) {
                $consume($row);
            }

            return $items;
        }
        // Visibility enumeration has an explicit budget; every raw witness survives ref deduplication.
        $query->orderBy('id')->chunkById(500, function ($rows) use ($consume, &$items): void {
            foreach ($rows as $row) {
                if (count($items) >= 10000) {
                    throw new InvalidSourceContributionException('Database selection exceeds the 10000 assignment witness budget.');
                }
                $consume($row);
            }
        });

        return $items;
    }
}
