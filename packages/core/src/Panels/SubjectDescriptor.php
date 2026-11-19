<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;

/**
 * A compiled subject of a panel as `PanelBuilder::for()` declared it: the model, the Laravel auth guard its current
 * user comes from and the directory that looks subjects up for interfaces.
 */
#[Api(since: '1.0')]
final readonly class SubjectDescriptor
{
    /**
     * @param  class-string<Model>  $model
     */
    #[Internal('built by the panel compiler')]
    public function __construct(
        public string $model,
        public ?string $guard = null,
        public ?string $directory = null,
    ) {}
}
