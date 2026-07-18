<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\RoleResource\Pages;

use AzGuard\Filament\Resources\RoleResource;
use AzGuard\Models\Role;
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
     * after creation instead.
     */
    #[Override]
    protected function handleRecordCreation(array $data): Role
    {
        $record = Role::query()->create(Arr::except($data, ['class_name']));
        $record->class_name = $data['class_name'] ?? null;
        $record->save();

        return $record;
    }
}
