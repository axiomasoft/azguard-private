<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Context\Internal\ContextIdentity;
use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Contracts\Subjects\SubjectDirectory;
use AzGuard\Contracts\Tenancy\TenantDirectory;
use AzGuard\Contracts\Tenancy\TenantMembership;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\AssignmentScopeRequiredException;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Exceptions\TenantRequiredException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;
use AzGuard\Support\ModelKey;
use AzGuard\Tenancy\TenantMode;
use Illuminate\Contracts\Container\Container;
use PDOException;
use Throwable;

/**
 * The one place that picks the directory of a panel: the class declared in code (`for(..., directory:)`, the
 * directory the scope declaration names for a type) when there is one, otherwise the default directory of that kind.
 * A scope type without a declared directory has an empty one: nothing to offer, nothing to describe.
 *
 * @internal
 */
final readonly class DirectoryResolver
{
    private function __construct(private Panel $panel, private Container $container) {}

    public static function for(Panel $panel, Container $container): self
    {
        return new self($panel, $container);
    }

    /**
     * The directory of the subjects of one morph type, or of every subject model of the panel without a type.
     * An unknown type has no subjects.
     */
    public function subjects(?string $type = null): SubjectDirectory
    {
        $byType = [];
        $default = [];

        foreach ($this->panel->subjects() as $descriptor) {
            $alias = (new $descriptor->model)->getMorphClass();

            if ($type !== null && $alias !== $type) {
                continue;
            }

            if ($descriptor->directory === null) {
                $default[$alias] = $descriptor->model;
                $byType[$alias] = null;

                continue;
            }
            $directory = $this->container->make($descriptor->directory);

            if (! $directory instanceof SubjectDirectory) {
                throw new DefinitionException('Subject directory '.$descriptor->directory.' must implement '.SubjectDirectory::class.'.');
            }
            $byType[$alias] = $directory;
        }
        $models = new ModelSubjectDirectory(array_values($default));

        foreach ($byType as $alias => $directory) {
            $byType[$alias] = $directory ?? $models;
        }

        return $type !== null && count($byType) === 1 ? $byType[$type] : new PanelSubjectDirectory($byType);
    }

    public function scopes(string $type): AssignmentScopeDirectory
    {
        $class = $this->scopeDirectory($type);

        if ($class === null) {
            return new class implements AssignmentScopeDirectory
            {
                public function search(string $type, string $term, LookupContext $lookup, int $limit): array
                {
                    return [];
                }

                public function describe(AssignmentScopeRef $context, LookupContext $lookup): ?AssignmentScopeOption
                {
                    return null;
                }
            };
        }
        $directory = $this->container->make($class);

        if (! $directory instanceof AssignmentScopeDirectory) {
            throw new DefinitionException('Assignment scope directory '.$class.' must implement '.AssignmentScopeDirectory::class.'.');
        }

        return $directory;
    }

    /** @return class-string<AssignmentScopeDirectory>|null the directory the scope declaration names for a type */
    public function scopeDirectory(string $type): ?string
    {
        return $this->panel->scopeDeclaration()->directories[$type] ?? null;
    }

    public function tenants(): TenantDirectory
    {
        return new ModelTenantDirectory($this->panel->tenants()->definition());
    }

    /**
     * The tenant and scope-mode part of write admission for one target, shared by final validation and the scope
     * directories: no implicit all-tenants, global roles only where allowed, the target is a member of the tenant,
     * and the scope mode accepts a global or a non-global context.
     *
     * @param  class-string<BaseRole>|null  $role  the role of a role grant
     *
     * @throws AzGuardException the refusal of the write
     */
    public static function admitTenancy(Panel $panel, Container $container, TenantRef $tenant, bool $global, SubjectRef $subject, ?string $role): void
    {
        $policy = $panel->tenants();

        if ($policy->mode() === TenantMode::None && ! $tenant->isGlobal()) {
            throw new TenantMismatchException('Panel '.$panel->id().' has no tenants.');
        }

        if ($policy->mode() === TenantMode::Required) {
            if ($tenant->isGlobal()) {
                if ($role === null || ! in_array($role, $policy->globalRoles(), true)) {
                    throw new TenantRequiredException('Panel '.$panel->id().' grants this only inside a tenant.');
                }
            } elseif ($tenant->type() !== $policy->definition()?->type()) {
                throw new TenantMismatchException('Tenant type '.$tenant->type().' is not the tenant of panel '.$panel->id().'.');
            } elseif (! self::tenantMember($panel, $container, $subject, $tenant)) {
                throw new TenantMismatchException('Subject '.$subject->key().' is not a member of tenant '.$tenant->key().'.');
            }
        }
        $mode = $panel->scopeDeclaration()->mode ?? AssignmentScopeMode::None;

        if ($mode === AssignmentScopeMode::Required && $global) {
            throw new AssignmentScopeRequiredException('Panel '.$panel->id().' grants only inside an assignment scope.');
        }

        if ($mode === AssignmentScopeMode::None && ! $global) {
            throw new AssignmentScopeNotAcceptedException('Panel '.$panel->id().' has no assignment scopes.');
        }
    }

    /**
     * The context part of write admission, shared by final validation and the scope directories: a declared type
     * the role is granted in, `resolve()` with the reference, owner tenant and record verified, then the evaluator of
     * the panel in the runtime's phase.
     *
     * @param  list<string>|null  $roleTypes  the scope types of the role of a role grant
     *
     * @throws AzGuardException the refusal of the write
     */
    public static function admitContext(AssignmentScopeRuntime $runtime, ?array $roleTypes, ?string $roleKey, Container $container): void
    {
        $panel = $runtime->panel;
        $ref = $runtime->scope->context;
        $type = $ref->type() ?? '';
        $definition = $panel->scopeDefinition($type)
            ?? throw new AssignmentScopeNotAcceptedException('Panel '.$panel->id().' has no assignment scope type "'.$type.'".');

        if ($roleTypes !== null && ! in_array($type, $roleTypes, true)) {
            throw new AssignmentScopeNotAcceptedException('Role "'.$roleKey.'" is not granted in assignment scope type "'.$type.'".');
        }

        try {
            $resolved = $definition->resolve($ref);

            if ($resolved === null || ! $resolved->ref->equals($ref)) {
                throw new AssignmentScopeNotAcceptedException('Assignment scope '.$ref->key().' does not exist.');
            }

            if (! $resolved->tenant->equals($runtime->scope->tenant)) {
                throw new TenantMismatchException('Assignment scope '.$ref->key().' belongs to another tenant.');
            }
            $model = $definition->model();

            if ($resolved->record !== null && (($model !== null && ! $resolved->record instanceof $model) || ! ModelKey::matches($resolved->record, $ref->id() ?? ''))) {
                throw new AssignmentScopeNotAcceptedException('Assignment scope '.$ref->key().' resolved to a record of another model or key.');
            }
            $evaluator = ContextIdentity::evaluator($panel, $container);
            $allowed = $evaluator === null || $evaluator->allows($runtime, $resolved);
        } catch (AzGuardException|PDOException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new AssignmentScopeNotAcceptedException('Assignment scope '.$ref->key().' was refused by its filters: '.$error->getMessage(), 0, $error);
        }

        if (! $allowed) {
            throw new AssignmentScopeNotAcceptedException('Assignment scope '.$ref->key().' is not accepted for this target and role.');
        }
    }

    private static function tenantMember(Panel $panel, Container $container, SubjectRef $subject, TenantRef $tenant): bool
    {
        $declared = $panel->tenants()->membership();

        if ($declared === null) {
            return true;
        }
        $adapter = is_string($declared) ? $container->make($declared) : $declared;

        if (! $adapter instanceof TenantMembership) {
            throw new DefinitionException('Tenant membership resolver did not return '.TenantMembership::class.'.');
        }

        return $adapter->isMember($subject, $tenant);
    }
}
