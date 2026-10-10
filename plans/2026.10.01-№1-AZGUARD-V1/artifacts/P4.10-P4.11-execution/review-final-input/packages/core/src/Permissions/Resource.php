<?php

declare(strict_types=1);

namespace AzGuard\Permissions;

use Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Label and domain model of a permission enum. The model does not by itself create permissions.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Resource
{
    /**
     * @param  class-string<Model>|null  $model
     */
    public function __construct(
        public ?string $label = null,
        public ?string $model = null,
    ) {}
}
