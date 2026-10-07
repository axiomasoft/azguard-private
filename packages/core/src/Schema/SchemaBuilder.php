<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ConfigurableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Directories\DirectoryResolver;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Permissions\GrantedToAll;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Scopes\RoleBindings;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Sources\PanelSources;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionEnumUnitCase;

/**
 * Builds the schema of a panel for one tenant from the compiled panel, its catalog and the descriptions of its sources.
 *
 * The dynamic permissions of the tenant are read once from each dynamic source; a tenant panel asked without a tenant
 * shows its static permissions only and never gathers the dynamic ones of every tenant.
 *
 * @internal
 *
 * @phpstan-import-type CompiledRole from \AzGuard\Catalog\RoleCompiler
 */
final readonly class SchemaBuilder
{
    public function __construct(private PanelRegistry $registry, private Container $container) {}

    /**
     * @throws TenantMismatchException when the tenant is not of the tenant type of the panel
     * @throws DefinitionException when a source contributes something that is not a definition or a field
     */
    public function for(Panel $panel, TenantRef $tenant): PanelSchema
    {
        $tenantType = $panel->tenants()->definition()?->type();

        if (! $tenant->isGlobal() && $tenant->type() !== $tenantType) {
            throw new TenantMismatchException(
                'Panel "'.$panel->id().'" has '.($tenantType === null ? 'no tenants' : 'tenants of the type "'.$tenantType.'"')
                .', not "'.$tenant->type().'".',
            );
        }

        $static = $this->registry->catalog($panel->id());
        $descriptions = $panel->sources();
        $owners = [];

        foreach ($static->snapshot()['permissions'] as $row) {
            $owners[$row['local']] = $row['source'];
        }
        $catalog = $static->withDynamic($this->dynamic($panel, $tenant, $descriptions, $owners));
        $resolver = DirectoryResolver::for($panel, $this->container);

        return new PanelSchema(
            panel: $panel->id(),
            label: $panel->label(),
            writable: $panel->isWritable(),
            tenant: $tenant,
            permissions: $this->permissions($panel, $catalog, $descriptions, $owners),
            roles: $this->roles($panel, $catalog),
            fields: $this->fields($panel, $descriptions),
            tenants: $this->tenants($panel, $resolver),
            scopes: $this->scopes($panel, $resolver),
            subjects: $this->subjects($panel, $resolver),
        );
    }

    /**
     * The dynamic definitions of the tenant, one read per source its description marks dynamic.
     *
     * @param  list<SourceDescription>  $descriptions
     * @param  array<string, string>  $owners  filled with the source id of each dynamic name
     * @return list<mixed>
     */
    private function dynamic(Panel $panel, TenantRef $tenant, array $descriptions, array &$owners): array
    {
        if ($tenant->isGlobal() && $panel->tenants()->definition() !== null) {
            return [];
        }
        $dynamic = [];

        foreach ($descriptions as $description) {
            if ($description->dynamic) {
                $dynamic[$description->id] = true;
            }
        }

        if ($dynamic === []) {
            return [];
        }
        $definitions = [];

        foreach (PanelSources::of($this->registry->recipe($panel->id()), $this->container)->with(ProvidesPermissions::class) as ['source' => $source]) {
            if (! isset($dynamic[$source->id()])) {
                continue;
            }

            foreach (PanelCatalog::untrusted($source->permissions($panel, $tenant)) as $definition) {
                if ($definition instanceof PermissionDefinition) {
                    $owners[$definition->local] ??= $source->id();
                }
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @param  list<SourceDescription>  $descriptions
     * @param  array<string, string>  $owners
     * @return list<PermissionSchema>
     */
    private function permissions(Panel $panel, PanelCatalog $catalog, array $descriptions, array $owners): array
    {
        $types = array_keys($panel->scopeDefinitions());
        sort($types, SORT_STRING);
        $bindings = $catalog->policyBindings();
        $roles = $catalog->roles();
        $static = $this->registry->catalog($panel->id());
        $permissions = [];

        foreach ($catalog->all() as $local => $definition) {
            $key = PermissionKey::of($panel->id(), $local);
            $binding = $bindings[$local] ?? null;
            $grants = $definition->authority === PermissionAuthority::Grants;
            $permissions[$key->full()] = new PermissionSchema(
                key: $key,
                label: $definition->label ?? $local,
                resourceGroup: $definition->group,
                description: $definition->description,
                authority: $definition->authority,
                dynamic: ! $static->has($local),
                name: $panel->prefix() === null ? $local : $panel->prefix().'.'.$local,
                owner: $owners[$local] ?? 'dynamic',
                decidedBy: match ($binding?->kind) {
                    null => null,
                    'gate' => 'gate:'.$binding->ability,
                    default => 'policy:'.$binding->policy.'@'.$binding->method,
                },
                sources: $grants ? $this->givers($key, $definition, $descriptions, $roles) : [],
                contextTypes: $grants ? $types : [],
            );
        }
        ksort($permissions, SORT_STRING);

        return array_values($permissions);
    }

    /**
     * Sources that can give a Grants permission: a source of direct grants always, a source of role grants when a
     * code role covers the permission, the folder for `GrantedToAll` and for an automatic role that covers it.
     *
     * @param  list<SourceDescription>  $descriptions
     * @param  array<string, CompiledRole>  $roles
     * @return list<string>
     */
    private function givers(PermissionKey $key, PermissionDefinition $definition, array $descriptions, array $roles): array
    {
        $byRole = $byAutomaticRole = false;

        foreach ($roles as $role) {
            $covers = $role['super_admin'];

            foreach ($role['permissions'] as $permission) {
                $covers = $covers || PermissionPattern::of($key->panel(), $permission)->covers($key);
            }

            if ($covers) {
                $byRole = true;
                $byAutomaticRole = $byAutomaticRole || is_subclass_of($role['class'], GrantedAutomatically::class);
            }
        }
        $grantedToAll = $definition->case !== null
            && (new ReflectionEnumUnitCase($definition->case::class, $definition->case->name))->getAttributes(GrantedToAll::class) !== [];
        $sources = [];

        foreach ($descriptions as $description) {
            $gives = is_a($description->class, FolderSource::class, true)
                ? $grantedToAll || $byAutomaticRole
                : in_array(ProvidesGrants::class, $description->capabilities, true)
                    || ($byRole && in_array(ProvidesRoleGrants::class, $description->capabilities, true));

            if ($gives) {
                $sources[] = $description->id;
            }
        }

        return $sources;
    }

    /**
     * @return list<RoleSchema>
     */
    private function roles(Panel $panel, PanelCatalog $catalog): array
    {
        $roles = [];

        foreach ($catalog->roles() as $key => $compiled) {
            $role = $this->container->make($compiled['class']);

            if (! $role instanceof BaseRole) {
                throw new DefinitionException('Role '.$compiled['class'].' of panel "'.$panel->id().'" resolved '.get_debug_type($role).'.');
            }
            $types = array_values(array_unique(array_column($compiled['scopes'], 'type')));
            $bindings = [];

            foreach ($types as $type) {
                $registered = $panel->scopeDefinition($type)
                    ?? throw new DefinitionException('Role '.$compiled['class'].' of panel "'.$panel->id().'" names the unregistered scope '.$type.'.');

                foreach (RoleBindings::of($role, $registered, $this->container) as $binding) {
                    $bindings[] = $this->binding($panel, $registered, $binding);
                }
            }
            sort($types, SORT_STRING);
            $roles[$key] = new RoleSchema(
                key: RoleKey::of($panel->id(), $key),
                label: $role->label(),
                class: $compiled['class'],
                grantable: $compiled['grantable'],
                automatic: is_subclass_of($compiled['class'], GrantedAutomatically::class),
                scopeRequired: $compiled['scope_required'],
                contextTypes: $types,
                contextBindings: $bindings,
                superAdmin: $compiled['super_admin'],
                permissions: array_map(static fn (string $local): PermissionPattern => PermissionPattern::of($panel->id(), $local), $compiled['permissions']),
            );
        }
        ksort($roles, SORT_STRING);

        return array_values($roles);
    }

    private function binding(Panel $panel, AssignmentScopeDefinition $registered, AssignmentScopeDefinition $binding): AssignmentScopeBindingSchema
    {
        $settings = $binding instanceof ConfigurableAssignmentScopeDefinition ? $binding->settings() : null;
        $type = $registered->type();

        return new AssignmentScopeBindingSchema(
            contextType: $type,
            filters: array_map(
                static fn (mixed $filter): string => $filter instanceof Closure ? Closure::class : (is_object($filter) ? $filter::class : $filter),
                $settings === null ? [] : $settings->filters,
            ),
            label: $settings->label ?? self::scopeLabel($registered),
            directory: $settings?->directory,
            exactSupport: ($binding instanceof QueryableAssignmentScopeDefinition && $binding->model() !== null)
                || isset($panel->scopes()->adapters()[$type]),
        );
    }

    /**
     * Fields of the grant models of the panel sources first, then the fields of the panel; each list in name order.
     *
     * @param  list<SourceDescription>  $descriptions
     * @return array<string, list<FieldSchema>>
     */
    private function fields(Panel $panel, array $descriptions): array
    {
        $fields = [];

        foreach (FieldTarget::cases() as $target) {
            $declared = [];

            if ($panel->isWritable()) {
                foreach ($descriptions as $description) {
                    foreach ($description->fields[$target->value] ?? [] as $field) {
                        $declared[] = $field;
                    }
                }
            }
            $byName = [];

            foreach (PanelCatalog::untrusted([...$declared, ...$panel->fields($target)]) as $field) {
                if (! $field instanceof Field) {
                    throw new DefinitionException('Panel "'.$panel->id().'" describes a '.$target->value.' field that is '.get_debug_type($field).'.');
                }
                $schema = FieldSchema::of($field);

                if (isset($byName[$schema->name])) {
                    throw new DefinitionException('Panel "'.$panel->id().'" has the '.$target->value.' field '.$schema->name.' from '
                        .$byName[$schema->name]->contributedBy.' and '.$schema->contributedBy.'.');
                }
                $byName[$schema->name] = $schema;
            }
            ksort($byName, SORT_STRING);
            $fields[$target->value] = array_values($byName);
        }

        return $fields;
    }

    /** @return list<TenantTypeSchema> */
    private function tenants(Panel $panel, DirectoryResolver $resolver): array
    {
        $definition = $panel->tenants()->definition();

        if ($definition === null) {
            return [];
        }

        return [new TenantTypeSchema($definition->type(), self::modelLabel($definition->model()), $definition->model(), $resolver->tenants()::class)];
    }

    /** @return list<AssignmentScopeTypeSchema> */
    private function scopes(Panel $panel, DirectoryResolver $resolver): array
    {
        $scopes = [];

        foreach ($panel->scopeDefinitions() as $type => $definition) {
            $scopes[$type] = new AssignmentScopeTypeSchema($type, self::scopeLabel($definition), $definition->model(), $resolver->scopes($type)::class);
        }
        ksort($scopes, SORT_STRING);

        return array_values($scopes);
    }

    /** @return list<SubjectTypeSchema> */
    private function subjects(Panel $panel, DirectoryResolver $resolver): array
    {
        $subjects = [];

        foreach ($panel->subjects() as $descriptor) {
            $type = (new $descriptor->model)->getMorphClass();
            $subjects[] = new SubjectTypeSchema($descriptor->model, $type, self::modelLabel($descriptor->model), $descriptor->guard, $resolver->subjects($type)::class);
        }

        return $subjects;
    }

    private static function scopeLabel(AssignmentScopeDefinition $definition): string
    {
        return ($definition instanceof ConfigurableAssignmentScopeDefinition ? $definition->settings()->label : null) ?? $definition->type();
    }

    /** @param class-string<Model> $model */
    private static function modelLabel(string $model): string
    {
        return Str::headline(class_basename($model));
    }
}
