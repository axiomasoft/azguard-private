<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources;

use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Filament\Resources\PermissionGrantResource\Pages\ListPermissionGrants;
use Filament\Resources\Resource;
use UnitEnum;

/**
 * The stored direct grants of permissions of the panels the plugin manages: a table of the grants of one panel and
 * tenant with filters by permission, subject, context, state, expiry and who granted, a form that grants a permission
 * decided by grants to a subject in a context, the edit of the expiry and the fields of a grant, revocation of one grant
 * or of the selected ones in one change, and «Why?».
 *
 * A permission that a policy decides is never offered and its grant is refused by the writer. Every value of a form is
 * checked again on the server and the writes run as the user who edits. Its permissions are
 * `azguard-permission-grants.{view_any,view,create,update,delete}` in the guard panel.
 *
 * @api
 */
final class PermissionGrantResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $slug = 'azguard-permission-grants';

    protected static ?string $modelLabel = 'permission grant';

    protected static ?string $pluralModelLabel = 'permission grants';

    protected static string|UnitEnum|null $navigationGroup = 'AzGuard';

    protected static bool $isGloballySearchable = false;

    protected static bool $isScopedToTenant = false;

    public static function getPages(): array
    {
        return ['index' => ListPermissionGrants::route('/')];
    }
}
