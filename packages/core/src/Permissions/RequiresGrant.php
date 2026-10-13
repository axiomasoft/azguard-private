<?php

declare(strict_types=1);

namespace AzGuard\Permissions;

use Attribute;
use AzGuard\Attributes\Api;

/**
 * The permission is decided by a qualified assignment; a bound policy may only veto it.
 *
 * On a case it replaces the same attribute of the enum. Both this and {@see PolicyOnly} on one
 * element is a definition error, and so is an element with neither.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_CLASS_CONSTANT)]
#[Api(since: '1.0')]
final readonly class RequiresGrant {}
