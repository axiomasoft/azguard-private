<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\RoleResource\Pages;

use AzGuard\Filament\Resources\RoleResource;
use AzGuard\Models\Role;
use AzGuard\Registry\Resolver\PermissionStateRevision;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Override;

final class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * class_name is guarded (C-11, not mass-assignable — see Role::$fillable)
     * so the mass-assigned create() call would silently drop it; set it via a
     * direct property assignment (bypasses fillable, unlike fill()/create())
     * before the single save, inside the revision transaction.
     */
    #[Override]
    protected function handleRecordCreation(array $data): Role
    {
        $model = self::getModel();

        return app(PermissionStateRevision::class)->mutate(static function () use ($model, $data): array {
            /** @var Role $record */
            $record = new $model;
            $record->fill(Arr::except($data, ['class_name']));
            $record->class_name = $data['class_name'] ?? null;
            $record->save();

            return [$record, true];
        });
    }
}
