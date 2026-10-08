<?php

declare(strict_types=1);

namespace AzGuard\Catalog;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Sources\ProvidesRoles;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicateRoleException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Exceptions\InvalidRoleKeyException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\ScopeConfiguration;
use AzGuard\Sources\PanelSources;
use Illuminate\Contracts\Container\Container;
use UnitEnum;

/**
 * Turns the code roles of a panel into scalar records: key, former keys, permissions as local names or patterns, and
 * the assignment scopes the role may be granted in.
 *
 * A role key is the one the role declares; a plugin does not change it. Methods a role overrides are checked like
 * attributes.
 *
 * @phpstan-type CompiledScope array{type: string, class: class-string<AssignmentScopeDefinition>, filter_configuration?: list<string>}
 * @phpstan-type CompiledRole array{class: class-string<BaseRole>, key: string, former_keys: list<string>, permissions: list<string>, scopes: list<CompiledScope>, scope_required: bool, super_admin: bool, grantable: bool, source: string, origin: string}
 */
final readonly class RoleCompiler
{
    public function __construct(private Container $container) {}

    /**
     * @return array<string, CompiledRole> by role key, in the order the roles were contributed
     *
     * @throws DuplicateRoleException when two classes share a key, or a former key of one is a key of another
     * @throws InvalidRoleKeyException
     * @throws UnknownPermissionException when a role names a permission the panel does not have
     * @throws InvalidPermissionKeyException
     * @throws InvalidSourceContributionException when a source returns something that is not a role
     * @throws DefinitionException
     */
    public function compile(Panel $panel, PanelCatalog $catalog, PanelSources $sources): array
    {
        $compiled = $claimed = $seen = $scopeTypes = [];

        foreach ($sources->with(ProvidesRoles::class) as $attached) {
            $source = $attached['source'];
            foreach (PanelCatalog::untrusted($source->roles($panel)) as $role) {
                if (! $role instanceof BaseRole) {
                    throw new InvalidSourceContributionException(
                        'Source "'.$source->id().'" of panel "'.$panel->id().'" returned '.get_debug_type($role)
                        .' from roles(); a source returns '.BaseRole::class.' objects.',
                    );
                }

                $origin = $attached['role_origins'][$role::class] ?? $attached['origin'];

                if (($seen[$role::class] ?? null) === $origin) {
                    continue;
                }

                $owner = 'source "'.$source->id().'"'.($origin === 'provider' ? '' : ' ('.$origin.')');
                $key = $role->key();
                PermissionGrammar::assertRoleKey($key);
                $formerKeys = $this->formerKeys($role, $key);

                foreach ([$key, ...$formerKeys] as $name) {
                    $holder = $claimed[$name] ?? null;

                    if ($holder !== null) {
                        throw new DuplicateRoleException(
                            'Role key "'.$name.'" of panel "'.$panel->id().'" belongs to '.$holder['class'].' from '.$holder['owner'].' and to '
                            .$role::class.' from '.$owner.': a key or former key names one role of a panel.',
                        );
                    }
                }

                foreach ([$key, ...$formerKeys] as $name) {
                    $claimed[$name] = ['class' => $role::class, 'owner' => $owner];
                }

                $seen[$role::class] = $origin;
                $scopes = $this->scopes($panel, $role);

                foreach ($scopes as $scope) {
                    $known = $scopeTypes[$scope['type']] ?? $scope['class'];

                    if ($known !== $scope['class']) {
                        throw new DefinitionException(
                            'Roles of panel "'.$panel->id().'" use two assignment scopes with the type "'.$scope['type'].'": '.$known.' and '
                            .$scope['class'].'. A scope type names one class.',
                        );
                    }

                    $scopeTypes[$scope['type']] = $scope['class'];
                }

                $compiled[$key] = [
                    'class' => $role::class,
                    'key' => $key,
                    'former_keys' => $formerKeys,
                    'permissions' => $this->permissions($panel, $catalog, $role),
                    'scopes' => $scopes,
                    'scope_required' => $role->scopeRequired(),
                    'super_admin' => $role->superAdmin(),
                    'grantable' => $role->grantable(),
                    'source' => $source->id(),
                    'origin' => $origin,
                ];
            }
        }

        return $compiled;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidRoleKeyException
     */
    private function formerKeys(BaseRole $role, string $key): array
    {
        $formerKeys = [];

        foreach (PanelCatalog::untrusted($role->formerKeys()) as $former) {
            if (! is_string($former)) {
                throw new InvalidRoleKeyException('Role '.$role::class.' lists '.get_debug_type($former).' among former keys.');
            }

            PermissionGrammar::assertRoleKey($former);

            if ($former === $key) {
                throw new InvalidRoleKeyException('Role '.$role::class.' lists its current key "'.$key.'" among former keys.');
            }

            $formerKeys[] = $former;
        }

        return array_values(array_unique($formerKeys));
    }

    /**
     * Permissions of a role as local names and patterns of the panel.
     *
     * @return list<string>
     *
     * @throws UnknownPermissionException
     * @throws InvalidPermissionKeyException
     * @throws DefinitionException when the role lists a policy-only permission or an item of another type
     */
    private function permissions(Panel $panel, PanelCatalog $catalog, BaseRole $role): array
    {
        $permissions = [];

        foreach (PanelCatalog::untrusted($role->permissions()) as $permission) {
            if ($permission instanceof UnitEnum) {
                $local = $catalog->keyOf($permission)->local();
            } elseif (! is_string($permission)) {
                throw new DefinitionException(
                    'Role '.$role::class.' lists '.get_debug_type($permission).' in permissions(); a role lists enum cases, local names '
                    .'and patterns.',
                );
            } elseif (str_contains($permission, PermissionGrammar::WILDCARD_ONE)) {
                $local = PermissionPattern::of($panel->id(), $permission)->local();
            } else {
                PermissionGrammar::assertLocalKey($permission);

                if (! $catalog->has($permission) && ! $catalog->isDynamic()) {
                    throw new UnknownPermissionException(
                        'Role '.$role::class.' lists "'.$permission.'", which is not a permission of panel "'.$panel->id().'".',
                    );
                }

                $local = $permission;
            }

            if ($catalog->find($local)?->authority === PermissionAuthority::Policy) {
                throw new DefinitionException(
                    'Role '.$role::class.' lists "'.$local.'", which panel "'.$panel->id().'" decides by its policy alone: a policy-only '
                    .'permission is never assigned.',
                );
            }

            $permissions[] = $local;
        }

        return array_values(array_unique($permissions));
    }

    /**
     * @return list<CompiledScope>
     *
     * @throws DefinitionException
     */
    private function scopes(Panel $panel, BaseRole $role): array
    {
        $scopes = [];

        foreach (PanelCatalog::untrusted($role->scopes()) as $scope) {
            if (is_string($scope) && is_subclass_of($scope, AssignmentScopeDefinition::class)) {
                $scope = $this->container->make($scope);
            }

            if (! $scope instanceof AssignmentScopeDefinition) {
                throw new DefinitionException(
                    'Role '.$role::class.' of panel "'.$panel->id().'" lists '.(is_string($scope) ? $scope : get_debug_type($scope))
                    .' in scopes(); a scope is an '.AssignmentScopeDefinition::class.' object or class.',
                );
            }

            ScopeConfiguration::filters($scope, $this->container);
            $registered = $panel->scopeDefinition($scope->type());

            if ($registered === null || $registered::class !== $scope::class || $registered->model() !== $scope->model()) {
                throw InvalidConfigurationException::failing('tenant_scope', 'Role '.$role::class.' of panel "'.$panel->id().'" declares an unregistered or conflicting assignment scope '.$scope->type().'.');
            }

            if (! ScopeConfiguration::sameStructure($registered, $scope)) {
                throw new DefinitionException('Role '.$role::class.' of panel "'.$panel->id().'" changes the registered structural assignment scope '.$scope->type().'.');
            }

            $compiled = ['type' => $scope->type(), 'class' => $scope::class];
            $filters = ScopeConfiguration::filterMetadata($scope);

            if ($filters !== []) {
                $compiled['filter_configuration'] = $filters;
            }
            $scopes[] = $compiled;
        }

        if ($scopes === [] && $role->scopeRequired()) {
            throw new DefinitionException(
                'Role '.$role::class.' of panel "'.$panel->id().'" requires a scope on every assignment but lists no scopes: add scopes() '
                .'or drop scopeRequired().',
            );
        }

        return $scopes;
    }
}
