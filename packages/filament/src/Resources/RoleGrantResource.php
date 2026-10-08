<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources;

use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Filament\Resources\RoleGrantResource\Pages\ListRoleGrants;
use Filament\Resources\Resource;
use UnitEnum;

/**
 * The stored role grants of the panels the plugin manages: a table of the grants of one panel and tenant with filters by
 * role, subject, context, state, expiry and who granted, a form that grants a role to a subject in a context, the edit
 * of the expiry and the fields of a grant, revocation of one grant or of the selected ones in one change, and «Why?».
 *
 * Roles given by a rule, by a relation or by another source are not stored and are not rows here; «Why?» shows them.
 * Every value of a form is checked again on the server and the writes run as the user who edits. Its permissions are
 * `azguard-role-grants.{view_any,view,create,update,delete}` in the guard panel.
 *
 * @api
 */
final class RoleGrantResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $slug = 'azguard-role-grants';

    protected static ?string $modelLabel = 'role grant';

    protected static ?string $pluralModelLabel = 'role grants';

    protected static string|UnitEnum|null $navigationGroup = 'AzGuard';

    protected static bool $isGloballySearchable = false;

    protected static bool $isScopedToTenant = false;

    public static function getPages(): array
    {
        return ['index' => ListRoleGrants::route('/')];
    }
}
