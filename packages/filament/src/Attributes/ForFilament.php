<?php

declare(strict_types=1);

namespace AzGuard\Filament\Attributes;

use Attribute;

/**
 * Marks a permission enum as the definitions of a resource, page or widget of Filament. `azguard:filament:generate`
 * writes it; the doctor reports an enum whose class no longer exists in the Filament panel.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ForFilament
{
    /**
     * @param  class-string  $class  the resource, page or widget the enum defines the permissions of
     */
    public function __construct(public string $class) {}
}
