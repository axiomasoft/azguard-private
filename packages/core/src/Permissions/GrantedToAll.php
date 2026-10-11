<?php

declare(strict_types=1);

namespace AzGuard\Permissions;

use Attribute;
use AzGuard\Attributes\Api;

/**
 * Marks a case that every subject of the panel holds. Discovery records the mark; grants read it later.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
#[Api(since: '0.7')]
final readonly class GrantedToAll {}
