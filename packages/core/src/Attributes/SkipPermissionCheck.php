<?php

declare(strict_types=1);

namespace AzGuard\Attributes;

use Attribute;

/**
 * Marks a controller or one of its actions as deliberately checked by nothing, which strict mode of a panel accepts.
 *
 * Only the controller class itself and the action are read; a parent controller does not opt its children out.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class SkipPermissionCheck {}
