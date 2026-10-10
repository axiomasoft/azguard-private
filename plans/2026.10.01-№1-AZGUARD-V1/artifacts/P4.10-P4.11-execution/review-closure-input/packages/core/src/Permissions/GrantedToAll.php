<?php

declare(strict_types=1);

namespace AzGuard\Permissions;

use Attribute;

/**
 * Marks a case that every subject of the panel holds. Discovery records the mark; grants read it later.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class GrantedToAll {}
