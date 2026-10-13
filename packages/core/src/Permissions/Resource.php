<?php

declare(strict_types=1);

namespace AzGuard\Permissions;

use Attribute;
use AzGuard\Attributes\Api;
use Illuminate\Database\Eloquent\Model;

/**
 * Label and domain model of a permission enum. The model does not by itself create permissions.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0')]
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
