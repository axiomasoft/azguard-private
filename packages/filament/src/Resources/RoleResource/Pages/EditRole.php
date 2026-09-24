<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\RoleResource\Pages;

use AzGuard\Filament\Resources\RoleResource;
use AzGuard\Filament\Resources\RoleResource\RelationManagers\RolePermissionsRelationManager;
use AzGuard\Filament\Resources\RoleResource\RelationManagers\RoleUsersRelationManager;
use AzGuard\Models\Role;
use AzGuard\Registry\Resolver\PermissionStateRevision;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Override;

final class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    #[Override]
    public function getRelationManagers(): array
    {
        return [
            RolePermissionsRelationManager::class,
            RoleUsersRelationManager::class,
        ];
    }

    /**
     * class_name is guarded (C-11, not mass-assignable — see Role::$fillable)
     * so the mass-assigned update() call would silently ignore it; set it via
     * a direct property assignment (bypasses fillable, unlike fill()/update())
     * before a single save, inside the revision transaction.
     */
    #[Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Role $record */
        return app(PermissionStateRevision::class)->mutate(static function () use ($record, $data): array {
            $record->fill(Arr::except($data, ['class_name']));
            $record->class_name = $data['class_name'] ?? null;
            $changed = $record->isDirty();

            if ($changed) {
                $record->save();
            }

            return [$record, $changed];
        });
    }
}
