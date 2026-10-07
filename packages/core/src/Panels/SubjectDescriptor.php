<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use Illuminate\Database\Eloquent\Model;

/**
 * A compiled subject of a panel as `PanelBuilder::for()` declared it: the model, the Laravel auth guard its current
 * user comes from and the directory that looks subjects up for interfaces.
 *
 * @api
 */
final readonly class SubjectDescriptor
{
    /**
     * @internal built by the panel compiler
     *
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $model,
        public ?string $guard = null,
        public ?string $directory = null,
    ) {}
}
