<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources;

use AzGuard\Configuration\Config;
use AzGuard\Filament\Resources\RoleResource\Pages\CreateRole;
use AzGuard\Filament\Resources\RoleResource\Pages\EditRole;
use AzGuard\Filament\Resources\RoleResource\Pages\ListRoles;
use AzGuard\Models\Role;
use AzGuard\Registry\Resolver\PermissionStateRevision;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\LazyCollection;
use Override;
use RuntimeException;
use UnitEnum;

/**
 * Filament Resource for managing DB roles.
 *
 * Supports two modes:
 *
 *  "Custom role"  — permissions are stored in az_guard_role_permissions,
 *                   edited via RolePermissionsRelationManager.
 *
 *  "Code role"    — permissions are defined by a PHP class (class_name != null);
 *                   the "Permissions" tab is hidden (see RolePermissionsRelationManager::canViewForRecord).
 */
final class RoleResource extends Resource
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|UnitEnum|null $navigationGroup = 'AzGuard';

    protected static ?string $label = 'Role';

    protected static ?string $pluralLabel = 'Roles';

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(128)
                ->unique(ignoreRecord: true)
                ->columnSpan('full'),

            TextInput::make('level')
                ->label('Priority level')
                ->integer()
                ->default(0)
                ->minValue(0)
                ->helperText('The higher the value, the greater the priority when merging permissions.'),

            // ── Mode toggle ──────────────────────────────────────────────────────
            Toggle::make('is_code_role')
                ->label('Managed by a PHP class (code role)')
                ->helperText('Enable if the role permissions are defined by a PHP class rather than the database.')
                ->default(false)
                ->live()
                ->afterStateHydrated(function (Toggle $component, $state, $record): void {
                    // When loading an existing record — set the toggle based on class_name presence.
                    if ($record !== null) {
                        $component->state((bool) $record->class_name);
                    }
                })
                ->dehydrated(false) // not persisted to the database directly
                ->columnSpan('full'),

            // ── Code role: class field ────────────────────────────────────────────
            TextInput::make('class_name')
                ->label('PHP class FQCN')
                ->placeholder('App\\Roles\\EditorRole')
                ->helperText('Fully-qualified name of the class implementing permissions().')
                ->nullable()
                ->visible(fn (Get $get): bool => (bool) $get('is_code_role'))
                ->columnSpan('full'),

            // ── Code role: informational placeholder ──────────────────────────────
            Placeholder::make('code_role_info')
                ->label('')
                ->content(fn (): HtmlString => new HtmlString(
                    '<div style="display:flex;align-items:center;gap:8px;padding:10px 14px;'
                    .'background:oklch(0.96 0.02 220);border:1px solid oklch(0.88 0.04 220);'
                    .'border-radius:6px;font-size:0.875rem;color:oklch(0.35 0.08 220);">'
                    .'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" '
                    .'viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
                    .'<path stroke-linecap="round" stroke-linejoin="round" '
                    .'d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20A10 10 0 0012 2z"/>'
                    .'</svg>'
                    .'This role\'s permissions are defined by a PHP class. The "Permissions" tab is hidden in edit mode.'
                    .'</div>',
                ))
                ->visible(fn (Get $get): bool => (bool) $get('is_code_role'))
                ->columnSpan('full'),
        ])->columns(2);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width('60px'),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                // Role type: Code / Custom
                IconColumn::make('is_code_role')
                    ->label('Type')
                    ->state(fn (Role $record): bool => $record->class_name !== null)
                    ->boolean()
                    ->trueIcon('heroicon-o-code-bracket')
                    ->falseIcon('heroicon-o-circle-stack')
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->tooltip(fn (Role $record): string => $record->class_name !== null
                        ? 'Code role: '.$record->class_name
                        : 'Custom role: permissions from the database',
                    ),

                TextColumn::make('level')
                    ->label('Level')
                    ->sortable(),

                TextColumn::make('dbPermissions_count')
                    ->label('Permissions (DB)')
                    ->counts('dbPermissions')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_code_role')
                    ->label('Role type')
                    ->placeholder('All')
                    ->trueLabel('Code roles')
                    ->falseLabel('Custom roles')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('class_name'),
                        false: fn ($query) => $query->whereNull('class_name'),
                    ),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make()->using(static fn (Role $record): bool => app(PermissionStateRevision::class)->mutate(static function () use ($record): array {
                    $deleted = self::deleteRole($record);

                    return [$deleted, $deleted];
                })),
            ])
            ->bulkActions([
                DeleteBulkAction::make()->using(static function (EloquentCollection|Collection|LazyCollection $records): void {
                    app(PermissionStateRevision::class)->mutate(static function () use ($records): array {
                        $changed = false;

                        foreach ($records as $record) {
                            assert($record instanceof Role);
                            $changed = self::deleteRole($record) || $changed;
                        }

                        return [null, $changed];
                    });
                }),
            ])
            ->defaultSort('level', 'desc');
    }

    /**
     * Apply the role foreign-key effects explicitly as well. SQLite hosts may
     * disable foreign keys, and leaving both pivot and permission rows behind
     * would continue to grant a deleted role until those orphans are removed.
     */
    private static function deleteRole(Role $record): bool
    {
        if (! $record->exists) {
            return false;
        }

        $locked = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->first();

        if (! $locked instanceof Role) {
            return false;
        }

        $connection = $locked->getConnection();
        $roleId = $locked->getKey();
        $connection->table(Config::modelHasRolesTable())->where('role_id', $roleId)->delete();
        $connection->table(Config::rolePermissionsTable())->where('role_id', $roleId)->delete();
        $connection->table(Config::modelHasScopesTable())->where('role_id', $roleId)->update(['role_id' => null]);

        if ($locked->delete() !== true) {
            throw new RuntimeException('AzGuard could not delete the selected role.');
        }

        return true;
    }

    #[Override]
    public static function getModel(): string
    {
        return Config::roleModel();
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
