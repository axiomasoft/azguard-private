<?php

declare(strict_types=1);

namespace AzGuard\Filament\Authorization;

use Illuminate\Database\Eloquent\Model;

/**
 * One permission key of a Filament panel with the class that owns it.
 *
 * @internal
 */
final readonly class FilamentKey
{
    /**
     * @param  string  $local  local permission name in the guard panel
     * @param  class-string  $class  resource, page or widget class
     * @param  class-string<Model>|null  $model  model of the resource
     */
    public function __construct(
        public string $local,
        public FilamentSurface $surface,
        public string $class,
        public string $label,
        public ?string $group = null,
        public ?string $model = null,
    ) {}
}
