<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources;

use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Filament\Resources\RoleResource\Pages\ListRoles;
use AzGuard\Filament\Resources\RoleResource\Pages\ViewRole;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Schema\AssignmentScopeBindingSchema;
use AzGuard\Schema\PanelSchema;
use AzGuard\Schema\PermissionSchema;
use AzGuard\Schema\RoleSchema;
use Filament\Resources\Resource;
use UnitEnum;

/**
 * The code roles of the panels the plugin manages, read-only: key, label, class, whether the role is granted by hand or
 * by a rule, its assignment scopes and their filters, superadmin and its permissions with who decides each of them.
 *
 * A role is defined in PHP only. The resource has no page, action or property that writes, and nothing of a role can be
 * changed from the interface; roles are assigned in the grant editors. Its permissions are `azguard-roles.view_any`
 * and `azguard-roles.view` in the guard panel.
 *
 * @api
 */
final class RoleResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $slug = 'azguard-roles';

    protected static ?string $modelLabel = 'role';

    protected static ?string $pluralModelLabel = 'roles';

    protected static string|UnitEnum|null $navigationGroup = 'AzGuard';

    protected static bool $isGloballySearchable = false;

    protected static bool $isScopedToTenant = false;

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'view' => ViewRole::route('/{panel}/{role}'),
        ];
    }

    /**
     * A role as a row of the catalogue; the permissions carry the authority of the panel schema when it has them.
     *
     * @param  array<string, PermissionSchema>  $permissions  by local name
     * @return array{key: string, label: string, class: string, grantable: bool, automatic: bool, super_admin: bool, scope_required: bool, contexts: list<string>, filters: list<string>, permissions: list<array{name: string, authority: ?string, grantable: bool}>} the authority is `grants`, `policy` or `pattern`
     */
    public static function row(RoleSchema $role, array $permissions = []): array
    {
        return [
            'key' => $role->key->key(),
            'label' => $role->label,
            'class' => $role->class,
            'grantable' => $role->grantable,
            'automatic' => $role->automatic,
            'super_admin' => $role->superAdmin,
            'scope_required' => $role->scopeRequired,
            'contexts' => $role->contextTypes,
            'filters' => array_map(
                static fn (AssignmentScopeBindingSchema $binding): string => $binding->label.' ('.$binding->contextType.($binding->filters === [] ? '' : ': '.implode(', ', $binding->filters)).')',
                $role->contextBindings,
            ),
            'permissions' => array_map(static function (PermissionPattern $pattern) use ($permissions): array {
                $permission = $permissions[$pattern->local()] ?? null;

                return [
                    'name' => $pattern->local(),
                    'authority' => $permission?->authority->value ?? ($pattern->isExact() ? null : 'pattern'),
                    'grantable' => $permission?->grantable() ?? true,
                ];
            }, $role->permissions),
        ];
    }

    /**
     * The permissions of a schema by local name.
     *
     * @return array<string, PermissionSchema>
     */
    public static function permissionsOf(?PanelSchema $schema): array
    {
        $permissions = [];

        foreach ($schema?->permissions() ?? [] as $permission) {
            $permissions[$permission->key->local()] = $permission;
        }

        return $permissions;
    }
}
