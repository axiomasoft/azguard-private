<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\RoleResource\RelationManagers;

use AzGuard\AzGuardManager;
use AzGuard\Models\Role;
use AzGuard\Models\RolePermission;
use AzGuard\Registry\Contracts\PermissionCatalog;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Relation Manager: DB role permissions.
 *
 * Permissions are grouped by the groups from the PermissionCatalog.
 * For roles with a class_name, permissions come from the class and cannot be edited via the UI.
 */
final class RolePermissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'dbPermissions';

    protected static ?string $title = 'Permissions';

    #[Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        // For PHP class roles the class defines the permissions — hide the tab.
        return $ownerRecord instanceof Role && $ownerRecord->class_name === null;
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('permission_key')->label('Permission'),
            TextInput::make('panel_id')->label('Panel'),
        ]);
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('permission_key')
            ->columns([
                TextColumn::make('panel_id')
                    ->label('Panel')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('permission_key')
                    ->label('Permission key')
                    ->searchable(),
            ])
            ->headerActions([
                Action::make('sync_permissions')
                    ->label('Edit permissions')
                    ->icon('heroicon-o-pencil-square')
                    ->form(fn (): array => $this->buildPermissionsForm())
                    ->fillForm(fn (): array => $this->currentPermissionsFormData())
                    ->action(fn (array $data) => $this->syncPermissions($data)),
            ])
            ->actions([
                DeleteAction::make()->label('Revoke'),
            ])
            ->bulkActions([
                DeleteBulkAction::make()->label('Revoke selected'),
            ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    /**
     * Builds the permission selection form: each panel has its own CheckboxList,
     * grouped by the groups from the catalog.
     */
    /** @return list<Section> */
    private function buildPermissionsForm(): array
    {
        /** @var PermissionCatalog $catalog */
        $catalog = app(PermissionCatalog::class);
        /** @var AzGuardManager $manager */
        $manager = app(AzGuardManager::class);

        $sections = [];

        foreach (array_keys($manager->getPanels()) as $panelId) {
            $groups = $catalog->groups($panelId);

            if ($groups === []) {
                continue;
            }

            $checkboxLists = [];

            foreach ($groups as $groupName => $definitions) {
                $options = [];

                foreach ($definitions as $definition) {
                    $options[$definition->key()] = $definition->label() ?? $definition->key();
                }

                $checkboxLists[] = CheckboxList::make("permissions.{$panelId}.{$groupName}")
                    ->label($groupName)
                    ->options($options)
                    ->columns(2)
                    ->gridDirection('row');
            }

            $sections[] = Section::make($panelId)
                ->heading('Panel: '.$panelId)
                ->schema($checkboxLists)
                ->collapsible();
        }

        return $sections;
    }

    /**
     * Fills the form with current values.
     */
    /** @return array{permissions: array<string, array<string, list<string>>>} */
    private function currentPermissionsFormData(): array
    {
        /** @var PermissionCatalog $catalog */
        $catalog = app(PermissionCatalog::class);
        /** @var AzGuardManager $manager */
        $manager = app(AzGuardManager::class);

        $role = $this->ownerRole();
        $existing = $role->dbPermissions()->get()->groupBy('panel_id');

        $data = ['permissions' => []];

        foreach (array_keys($manager->getPanels()) as $panelId) {
            $groups = $catalog->groups($panelId);
            $granted = $existing->get($panelId, collect())->pluck('permission_key')->flip();

            foreach ($groups as $groupName => $definitions) {
                $checked = [];

                foreach ($definitions as $definition) {
                    if ($granted->has($definition->key())) {
                        $checked[] = $definition->key();
                    }
                }

                $data['permissions'][$panelId][$groupName] = $checked;
            }
        }

        return $data;
    }

    /**
     * Syncs the role's DB permissions: removes the old ones, adds the new ones.
     */
    /** @param array{permissions?: array<string, array<string, list<string>>>} $data */
    private function syncPermissions(array $data): void
    {
        $role = $this->ownerRole();
        $permissionsData = $data['permissions'] ?? [];

        $desired = [];

        foreach ($permissionsData as $panelId => $groups) {
            foreach ($groups as $keys) {
                foreach ($keys as $key) {
                    $desired[$panelId][] = $key;
                }
            }
        }

        $role->dbPermissions()->delete();

        $rows = [];
        $now = now();

        foreach ($desired as $panelId => $keys) {
            foreach (array_unique($keys) as $key) {
                $rows[] = [
                    'role_id' => $role->id,
                    'permission_key' => $key,
                    'panel_id' => $panelId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            RolePermission::insert($rows);
        }

        // Raw delete()/insert() bypasses model events, and editing a role's
        // permissions affects every holder — flush each user's cached set so the
        // change is effective immediately even with a persistent cache store.
        $role->users()->cursor()->each(static function (Model $user): void {
            if (method_exists($user, 'flushPermissions')) {
                $user->flushPermissions();
            }
        });
    }

    /**
     * This relation manager is registered on RoleResource only, so the owner
     * record is always the (possibly extended) Role model.
     */
    private function ownerRole(): Role
    {
        $role = $this->getOwnerRecord();
        assert($role instanceof Role);

        return $role;
    }
}
