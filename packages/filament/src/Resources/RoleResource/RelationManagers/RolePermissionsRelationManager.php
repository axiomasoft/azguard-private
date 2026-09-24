<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\RoleResource\RelationManagers;

use AzGuard\AzGuardManager;
use AzGuard\Models\Role;
use AzGuard\Models\RolePermission;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Roles\RolePermissionSelection;
use AzGuard\Roles\RolePermissionSyncConflictException;
use AzGuard\Roles\RolePermissionSynchronizer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
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
                    ->form(fn (): array => [
                        Hidden::make('fingerprint'),
                        ...$this->buildPermissionsForm(),
                    ])
                    ->fillForm(fn (): array => $this->currentPermissionsFormData())
                    ->action(fn (array $data) => $this->syncPermissions($data)),
            ])
            ->actions([
                DeleteAction::make()->label('Revoke')->using(function (RolePermission $record): bool {
                    $role = $this->ownerRole();
                    $managed = [[(string) $record->panel_id, (string) $record->permission_key]];
                    $synchronizer = app(RolePermissionSynchronizer::class);

                    return $synchronizer->sync(
                        role: $role,
                        selection: RolePermissionSelection::managedSubset(
                            managed: $managed,
                            desired: [],
                            expectedFingerprint: $synchronizer->fingerprint($role, $managed),
                        ),
                    )->changed();
                }),
            ])
            ->bulkActions([
                DeleteBulkAction::make()->label('Revoke selected')->using(function (EloquentCollection|Collection|LazyCollection $records): void {
                    $role = $this->ownerRole();
                    $managed = $records->map(static fn (RolePermission $record): array => [
                        (string) $record->panel_id,
                        (string) $record->permission_key,
                    ])->all();
                    $synchronizer = app(RolePermissionSynchronizer::class);

                    $synchronizer->sync(
                        role: $role,
                        selection: RolePermissionSelection::managedSubset(
                            managed: $managed,
                            desired: [],
                            expectedFingerprint: $synchronizer->fingerprint($role, $managed),
                        ),
                    );
                }),
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

        $data['fingerprint'] = app(RolePermissionSynchronizer::class)->fingerprint(
            role: $role,
            managed: $this->renderedCatalogKeys($catalog, $manager),
        );

        return $data;
    }

    /**
     * Syncs the role's DB permissions: removes the old ones, adds the new ones.
     */
    /**
     * @param  array{permissions?: array<string, array<string, list<string>>>, fingerprint?: string}  $data
     */
    private function syncPermissions(array $data): void
    {
        $role = $this->ownerRole();
        /** @var PermissionCatalog $catalog */
        $catalog = app(PermissionCatalog::class);
        /** @var AzGuardManager $manager */
        $manager = app(AzGuardManager::class);
        $desired = [];

        foreach ($data['permissions'] ?? [] as $panelId => $groups) {
            foreach ($groups as $keys) {
                foreach ($keys as $key) {
                    $desired[] = [(string) $panelId, (string) $key];
                }
            }
        }

        $fingerprint = is_string($data['fingerprint'] ?? null) ? $data['fingerprint'] : null;

        try {
            app(RolePermissionSynchronizer::class)->sync(
                role: $role,
                selection: RolePermissionSelection::managedSubset(
                    managed: $this->renderedCatalogKeys($catalog, $manager),
                    desired: $desired,
                    expectedFingerprint: $fingerprint,
                ),
            );
        } catch (RolePermissionSyncConflictException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }

    }

    /**
     * Catalog keys the edit form actually renders. Rows outside this set survive.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function renderedCatalogKeys(PermissionCatalog $catalog, AzGuardManager $manager): array
    {
        $managed = [];

        foreach (array_keys($manager->getPanels()) as $panelId) {
            foreach ($catalog->groups($panelId) as $definitions) {
                foreach ($definitions as $definition) {
                    $managed[] = [$panelId, $definition->key()];
                }
            }
        }

        return $managed;
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
