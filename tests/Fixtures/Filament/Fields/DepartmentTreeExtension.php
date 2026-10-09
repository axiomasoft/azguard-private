<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Fields;

use AzGuard\Filament\Contracts\FilamentFormExtension;
use AzGuard\Schema\FieldTarget;
use AzGuard\Schema\PanelSchema;
use AzGuard\Tests\Fixtures\Storage\Department;
use Filament\Forms\Components\Select;

/** A select of the departments for the department of a role grant of the `admin` panel; it counts the forms it built. */
final class DepartmentTreeExtension implements FilamentFormExtension
{
    public static int $built = 0;

    private int $forms = 0;

    public function appliesTo(PanelSchema $schema, FieldTarget $target): bool
    {
        return $target === FieldTarget::RoleGrant && $schema->panel === 'admin';
    }

    public function components(PanelSchema $schema, FieldTarget $target): array
    {
        self::$built++;
        $this->forms++;

        return ['department_id' => Select::make('department_id')->label('Department tree #'.$this->forms)
            ->options(static fn (): array => Department::query()->pluck('name', 'id')->all())];
    }
}
