<?php

declare(strict_types=1);

namespace AzGuard\Sources\Internal;

/**
 * A source that evaluates the `GrantedAutomatically` roles of a panel: only its role contributions may name a role that
 * is not grantable. Core decides this by the marker, never by the class of the source.
 *
 * @internal
 */
interface EvaluatesAutomaticRoles {}
