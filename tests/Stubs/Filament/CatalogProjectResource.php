<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Filament;

use AzGuard\Tests\Stubs\Project;
use Filament\Resources\Resource;

class CatalogProjectResource extends Resource
{
    public static function getModel(): string
    {
        return Project::class;
    }
}
