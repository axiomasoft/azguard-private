<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources;

use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Filament\Resources\PermissionResource\Pages\ListPermissions;
use AzGuard\Panels\Panel;
use AzGuard\Schema\PermissionSchema;
use AzGuard\Sources\Database\DatabaseSource;
use Filament\Resources\Resource;
use UnitEnum;

/**
 * Every permission of a managed panel whose database source declares `dynamicPermissions()`. Permissions of enums are
 * shown read-only with their group, description and authority; the dynamic permissions of the chosen tenant are
 * created, renamed, regrouped and deleted with their grants through the permission manager of the panel, as the user
 * who edits.
 *
 * Its permissions are `azguard-permissions.{view_any,view,create,update,delete}` in the guard panel.
 *
 * @api
 */
final class PermissionResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $slug = 'azguard-permissions';

    protected static ?string $modelLabel = 'permission';

    protected static ?string $pluralModelLabel = 'permissions';

    protected static string|UnitEnum|null $navigationGroup = 'AzGuard';

    protected static bool $isGloballySearchable = false;

    protected static bool $isScopedToTenant = false;

    public static function getPages(): array
    {
        return ['index' => ListPermissions::route('/')];
    }

    /** Whether the panel keeps dynamic permissions: its writer is a database source with `dynamicPermissions()`. */
    public static function keepsDynamicPermissions(Panel $panel): bool
    {
        $writer = $panel->writer();

        return $writer instanceof DatabaseSource && $writer->isDynamic();
    }

    /**
     * A permission as a row of the table.
     *
     * @return array{name: string, domain: string, label: string, group: ?string, description: ?string, authority: string, dynamic: bool, decided_by: ?string}
     */
    public static function row(PermissionSchema $permission): array
    {
        $local = $permission->key->local();

        return [
            'name' => $local,
            'domain' => explode('.', $local, 2)[0],
            'label' => $permission->label,
            'group' => $permission->resourceGroup,
            'description' => $permission->description,
            'authority' => $permission->authority->value,
            'dynamic' => $permission->dynamic,
            'decided_by' => $permission->decidedBy,
        ];
    }
}
