<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Marks a source that evaluates `GrantedAutomatically` roles of a panel; only its role contributions may name a
 * role that is not grantable.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface EvaluatesAutomaticRoles {}
