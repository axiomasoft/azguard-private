<?php

declare(strict_types=1);

namespace AzGuard\Permissions;

use Attribute;

/**
 * The permission is decided by its policy alone; assignments are not read and the case must be bound.
 *
 * On a case it replaces the same attribute of the enum. Both this and {@see RequiresGrant} on one
 * element is a definition error, and so is an element with neither.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_CLASS_CONSTANT)]
final readonly class PolicyOnly {}
