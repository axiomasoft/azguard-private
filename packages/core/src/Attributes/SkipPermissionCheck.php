<?php

declare(strict_types=1);

namespace AzGuard\Attributes;

use Attribute;

/**
 * Marks a controller or one of its actions as deliberately checked by nothing, which strict mode of a panel accepts.
 *
 * Only the controller class itself and the action are read; a parent controller does not opt its children out.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
#[Api(since: '1.0')]
final readonly class SkipPermissionCheck {}
