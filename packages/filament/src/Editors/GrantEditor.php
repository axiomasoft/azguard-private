<?php

declare(strict_types=1);

namespace AzGuard\Filament\Editors;

use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\GrantDetails;
use AzGuard\Changes\GrantFilter;
use AzGuard\Changes\GrantPage;
use AzGuard\Changes\GrantRecord;
use AzGuard\Contracts\PanelAccess;
use AzGuard\Directories\LookupContext;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Forms\SchemaFields;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Explanation;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Roles\BaseRole;
use AzGuard\Schema\FieldTarget;
use AzGuard\Schema\PanelSchema;
use AzGuard\Schema\PermissionSchema;
use AzGuard\Schema\RoleSchema;
use AzGuard\Scopes\AssignmentScopePhase;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The grants of one kind, role or permission, in the target panel and tenant of an editor, as the user who edits sees
 * and changes them.
 *
 * The editor is made from the panel and the tenant that `TargetSelector` checked; nothing else names the partition. Every
 * value of a form is a value of a Livewire payload, so each one is looked up again here before it is used: a subject
 * and a context must be described by the directory of the target panel, a role or a permission must be in its schema,
 * a type of context must be one the role or the permission is granted in, a grant id must be found by the grant manager
 * of the partition. What is not found is refused with a validation error and nothing is written. The writes run as the
 * user who edits, so the change pipes of the application see who delegates; the writer validates the values again.
 *
 * @internal
 */
final readonly class GrantEditor
{
    /** How many subjects or contexts a search offers. */
    public const int LIMIT = 50;

    /** The value of the context type that grants in the whole tenant. */
    public const string TENANT_WIDE = '-';

    /**
     * @param  'role'|'permission'  $kind
     */
    private function __construct(public PanelAccess $access, public string $kind, private PanelSchema $schema) {}

    /**
     * @param  'role'|'permission'  $kind
     */
    public static function of(PanelAccess $access, string $kind): self
    {
        return new self($access, $kind, $access->schema());
    }

    public function schema(): PanelSchema
    {
        return $this->schema;
    }

    public function fieldTarget(): FieldTarget
    {
        return $this->kind === 'role' ? FieldTarget::RoleGrant : FieldTarget::PermissionGrant;
    }

    /**
     * The roles or the permissions a grant can give, by key: the grantable roles, or the permissions decided by grants.
     *
     * @return array<string, string>
     */
    public function grantable(): array
    {
        $options = [];

        if ($this->kind === 'role') {
            foreach ($this->schema->roles() as $role) {
                if ($role->grantable) {
                    $options[$role->key->key()] = $role->label;
                }
            }

            return $options;
        }

        foreach ($this->schema->permissions() as $permission) {
            if ($permission->grantable()) {
                $options[$permission->key->local()] = $permission->label;
            }
        }

        return $options;
    }

    /**
     * Every permission of the target panel, by local name, also the ones a policy decides.
     *
     * @return array<string, string>
     */
    public function permissions(): array
    {
        $options = [];

        foreach ($this->schema->permissions() as $permission) {
            $options[$permission->key->local()] = $permission->label;
        }

        return $options;
    }

    /**
     * The types of context a role or a permission is granted in, by type; `TENANT_WIDE` stands for the whole tenant
     * where the definition allows it.
     *
     * @return array<string, string>
     */
    public function contextTypes(?string $key): array
    {
        $definition = $this->definition($key);

        if ($definition === null) {
            return [];
        }
        $labels = [];

        foreach ($this->schema->scopes() as $scope) {
            $labels[$scope->type] = $scope->label;
        }
        $types = $definition instanceof RoleSchema && $definition->scopeRequired ? [] : [self::TENANT_WIDE => 'Whole tenant'];

        foreach ($definition->contextTypes as $type) {
            $types[$type] = $labels[$type] ?? $type;
        }

        return $types;
    }

    /**
     * Every type of context of the target panel, by type.
     *
     * @return array<string, string>
     */
    public function scopeTypes(): array
    {
        $types = [];

        foreach ($this->schema->scopes() as $scope) {
            $types[$scope->type] = $scope->label;
        }

        return $types;
    }

    /**
     * The subjects of the target panel that match the term, by subject key; one search of the directory, at most
     * `LIMIT` of them.
     *
     * @return array<string, string>
     */
    public function searchSubjects(string $term): array
    {
        $options = [];

        foreach ($this->access->directories()->subjects()->search($term, $this->lookup(), self::LIMIT) as $option) {
            $options[$option->subject->key()] = $option->label;
        }

        return $options;
    }

    public function subjectLabel(?string $key): ?string
    {
        $subject = $this->subjectRef($key);

        return $subject === null ? null : $this->access->directories()->subjects()->describe($subject, $this->lookup())?->label;
    }

    /** The subject of a subject key when the directory of the target panel describes it. */
    public function subject(?string $key): ?SubjectRef
    {
        return $this->subjectLabel($key) === null ? null : $this->subjectRef($key);
    }

    /**
     * The contexts of a type that the directory offers for the grant of the role or the permission to the subject, with
     * the proposed values of the declared fields, by id; nothing until the subject is chosen and the type is one of the
     * definition.
     *
     * @param  array<mixed>  $proposed
     * @return array<string, string>
     */
    public function searchContexts(?string $type, string $term, ?string $subject, ?string $key, array $proposed = []): array
    {
        $target = $this->subject($subject);

        if ($target === null || $type === null || $type === self::TENANT_WIDE || ! array_key_exists($type, $this->contextTypes($key))) {
            return [];
        }
        $options = [];
        $lookup = $this->lookup($target, $this->definition($key), SchemaFields::declared($this->schema, $this->fieldTarget(), $proposed));

        foreach ($this->access->directories()->scopes($type)->search($type, $term, $lookup, self::LIMIT) as $option) {
            $options[(string) $option->scope->id()] = $option->label;
        }

        return $options;
    }

    /**
     * Every context of a type in the target tenant that matches the term, by id, without the eligibility of a grant:
     * for a filter or a question about access.
     *
     * @return array<string, string>
     */
    public function inspectContexts(?string $type, string $term): array
    {
        if ($type === null || ! array_key_exists($type, $this->scopeTypes())) {
            return [];
        }
        $options = [];
        $lookup = $this->lookup(phase: AssignmentScopePhase::Inspection);

        foreach ($this->access->directories()->scopes($type)->search($type, $term, $lookup, self::LIMIT) as $option) {
            $options[(string) $option->scope->id()] = $option->label;
        }

        return $options;
    }

    public function contextLabel(?string $type, ?string $id): ?string
    {
        $context = $this->contextRef($type, $id);

        return $context === null ? null : $this->access->directories()->scopes((string) $type)->describe($context, $this->lookup())?->label;
    }

    /** The context of a type and an id when the directory of the target panel describes it in the target tenant. */
    public function context(?string $type, ?string $id): ?AssignmentScopeRef
    {
        return $this->contextLabel($type, $id) === null ? null : $this->contextRef($type, $id);
    }

    /**
     * Grants the role or the permission of the form as the user who edits, after every value is checked again.
     *
     * @param  array<mixed>  $data  `subject`, `key`, `context_type`, `context`, `until`, `fields`
     *
     * @throws ValidationException when a value is not one the editor would offer
     */
    public function grant(array $data, Model $user): ChangeResult
    {
        $subject = $this->subject(self::text($data['subject'] ?? null)) ?? throw self::refused('subject', 'The subject is not known to the panel.');
        $key = self::text($data['key'] ?? null);
        $this->definition($key) ?? throw self::refused('key', 'The '.$this->kind.' is not defined in the panel.');
        $type = self::text($data['context_type'] ?? null) ?? self::TENANT_WIDE;

        if (! array_key_exists($type, $this->contextTypes($key))) {
            throw self::refused('context_type', 'The '.$this->kind.' is not granted in this type of context.');
        }
        $on = $type === self::TENANT_WIDE ? null
            : ($this->context($type, self::text($data['context'] ?? null)) ?? throw self::refused('context', 'The context is not known to the panel in this tenant.'));
        $until = self::moment($data['until'] ?? null);
        $fields = SchemaFields::values($this->schema, $this->fieldTarget(), is_array($data['fields'] ?? null) ? $data['fields'] : null);
        $access = $this->access->for($subject);

        return AzGuard::actingAs($user, fn (): ChangeResult => $this->kind === 'role'
            ? $access->grantRole((string) $key, on: $on, until: $until, fields: $fields)
            : $access->grantPermission((string) $key, on: $on, until: $until, fields: $fields));
    }

    /**
     * Replaces the expiry and the fields of a grant of the partition, while the grant still has the fingerprint the
     * form was filled from.
     *
     * @param  array<mixed>  $data  `until`, `fields`
     *
     * @throws ValidationException when the id is not a grant of this kind in the partition
     */
    public function update(string $id, ?string $fingerprint, array $data, Model $user): ChangeResult
    {
        $this->record($id) ?? throw self::refused('id', 'The grant is not in this panel and tenant.');
        $details = new GrantDetails(self::moment($data['until'] ?? null),
            SchemaFields::values($this->schema, $this->fieldTarget(), is_array($data['fields'] ?? null) ? $data['fields'] : null));

        return AzGuard::actingAs($user, fn (): ChangeResult => $this->access->grants()->update($id, $details, expectedFingerprint: $fingerprint));
    }

    /**
     * Revokes grants of the partition in one change; one id of another kind, panel, tenant or origin refuses all of them.
     *
     * @param  array<mixed>  $ids
     *
     * @throws ValidationException when an id is not a grant id of this kind
     */
    public function revoke(array $ids, Model $user): ChangeResult
    {
        foreach ($ids as $id) {
            if (! is_string($id) || ! str_starts_with($id, $this->kind.':')) {
                throw self::refused('ids', 'Only '.$this->kind.' grants are revoked here.');
            }
        }

        return AzGuard::actingAs($user, fn (): ChangeResult => $this->access->grants()->revokeMany(array_values($ids)));
    }

    /** A grant of this kind in the partition, found by the grant manager; null for any other id. */
    public function record(?string $id): ?GrantRecord
    {
        if ($id === null || ! str_starts_with($id, $this->kind.':')) {
            return null;
        }

        return $this->access->grants()->find($id);
    }

    public function page(GrantFilter $filter): GrantPage
    {
        return $this->access->grants()->page($filter);
    }

    /**
     * A grant as a row of the table, with the labels of the directories and the schema.
     *
     * @return array{id: string, subject: string, subject_label: string, key: string, key_label: string, context_type: ?string, context: ?string, context_label: string, origin: string, until: ?string, granted_by: ?string, fields: array<string, mixed>, fingerprint: string}
     */
    public function row(GrantRecord $record): array
    {
        $key = $record->role?->key() ?? $record->permission?->local() ?? '';
        $context = $record->scope->context;
        $lookup = $this->lookup();

        return [
            'id' => $record->id,
            'subject' => $record->subject->key(),
            'subject_label' => $this->access->directories()->subjects()->describe($record->subject, $lookup)->label ?? $record->subject->key(),
            'key' => $key,
            'key_label' => ($this->kind === 'role' ? $this->role($key)?->label : $this->permission($key)?->label) ?? $key,
            'context_type' => $context->type(),
            'context' => $context->id(),
            'context_label' => $context->isGlobal() ? 'Whole tenant' : $this->describeContext($context, $lookup),
            'origin' => $record->origin,
            'until' => $record->until?->format(DATE_ATOM),
            'granted_by' => $this->actorLabel($record->actor),
            'fields' => $record->fields,
            'fingerprint' => $record->fingerprint,
        ];
    }

    /**
     * Why the subject has the permission, or not, in the target tenant and the context.
     *
     * @throws ValidationException when the subject, the permission or the context is not one of the panel
     */
    public function explain(?string $subject, ?string $permission, ?string $type, ?string $context): Explanation
    {
        $target = $this->subject($subject) ?? throw self::refused('subject', 'The subject is not known to the panel.');
        $permission = self::text($permission);

        if ($permission === null || ! array_key_exists($permission, $this->permissions())) {
            throw self::refused('permission', 'The permission is not defined in the panel.');
        }
        $type = self::text($type);
        $on = $type === null || $type === self::TENANT_WIDE ? null
            : ($this->context($type, self::text($context)) ?? throw self::refused('context', 'The context is not known to the panel in this tenant.'));
        $request = AccessRequest::for($target, PermissionKey::of($this->access->definition()->id(), $permission))
            ->inTenant($this->access->scope()->tenant)->on($on)->traced();

        return $this->access->explain($request);
    }

    /**
     * What a directory search knows: the target panel and tenant, the user who edits and, once chosen, the target
     * subject, its model, the role and the proposed fields. An assignment lookup offers what a grant to that subject
     * would accept; an inspection lookup offers every context of the tenant.
     *
     * @param  array<string, mixed>  $proposed
     */
    public function lookup(
        ?SubjectRef $subject = null,
        RoleSchema|PermissionSchema|null $definition = null,
        array $proposed = [],
        AssignmentScopePhase $phase = AssignmentScopePhase::Assignment,
    ): LookupContext {
        $user = Filament::auth()->user();
        $actor = $user instanceof Model ? $user : null;
        $role = $definition instanceof RoleSchema ? app($definition->class) : null;

        return new LookupContext(
            panel: $this->access->definition(),
            scope: $this->access->scope(),
            actor: $actor === null ? null : ActorRef::of($actor->getMorphClass(), $actor->getKey()),
            actorModel: $actor,
            subject: $subject,
            user: $subject === null ? null : $this->subjectModel($subject),
            role: $role instanceof BaseRole ? $role : null,
            proposed: $proposed,
            phase: $phase,
            now: new DateTimeImmutable,
        );
    }

    private function definition(?string $key): RoleSchema|PermissionSchema|null
    {
        return $key === null ? null : ($this->kind === 'role' ? $this->role($key) : $this->permission($key));
    }

    private function role(string $key): ?RoleSchema
    {
        foreach ($this->schema->roles() as $role) {
            if ($role->key->key() === $key) {
                return $role;
            }
        }

        return null;
    }

    private function permission(string $local): ?PermissionSchema
    {
        foreach ($this->schema->permissions() as $permission) {
            if ($permission->key->local() === $local) {
                return $permission;
            }
        }

        return null;
    }

    /** The model of a subject, by the subject model the schema names for its type. */
    private function subjectModel(SubjectRef $subject): ?Model
    {
        foreach ($this->schema->subjects() as $type) {
            if ($type->type === $subject->type()) {
                return $type->model::query()->whereKey($subject->id())->first();
            }
        }

        return null;
    }

    private function subjectRef(?string $key): ?SubjectRef
    {
        $parts = $key === null ? [] : explode(':', $key, 2);

        if (count($parts) !== 2) {
            return null;
        }

        try {
            return SubjectRef::of($parts[0], $parts[1]);
        } catch (InvalidIdentityException) {
            return null;
        }
    }

    private function contextRef(?string $type, ?string $id): ?AssignmentScopeRef
    {
        if ($type === null || $id === null || $type === self::TENANT_WIDE || ! array_key_exists($type, $this->scopeTypes())) {
            return null;
        }

        try {
            return AssignmentScopeRef::of($type, $id);
        } catch (InvalidIdentityException) {
            return null;
        }
    }

    private function describeContext(AssignmentScopeRef $context, LookupContext $lookup): string
    {
        try {
            return $this->access->directories()->scopes((string) $context->type())->describe($context, $lookup)->label ?? $context->key();
        } catch (Throwable) {
            // The type of an orphaned grant may be gone from the panel; the row is still shown for cleanup.
            return $context->key();
        }
    }

    private function actorLabel(?ActorRef $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        if ($actor->id === null) {
            return 'system'.($actor->reason === null ? '' : ' ('.$actor->reason.')');
        }

        try {
            $subject = SubjectRef::of($actor->type, $actor->id);
        } catch (InvalidIdentityException) {
            return $actor->type.':'.$actor->id;
        }

        return $this->access->directories()->subjects()->describe($subject, $this->lookup())->label ?? $subject->key();
    }

    /**
     * @throws ValidationException when the value is not a moment
     */
    private static function moment(mixed $value): ?DateTimeImmutable
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($text);
        } catch (Throwable) {
            throw self::refused('until', 'The expiry is not a date.');
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function refused(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
