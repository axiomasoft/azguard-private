<?php

declare(strict_types=1);

namespace AzGuard\Context;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;

/**
 * What `DescribesAssignmentScope::description()` tells core about a definition. Core compares and fingerprints the
 * components but never interprets them.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class AssignmentScopeDescription
{
    /**
     * @param  list<object|class-string>  $components  configuration only the panel's evaluator applies (for the Scopes
     *                                                 module: its filters), in declaration order; opaque to core
     * @param  class-string<AssignmentScopeDirectory>|null  $directory  default directory of the alias
     * @param  bool  $tenantOwner  false: every record belongs to the global tenant (no owner callback)
     * @param  bool  $resolvesThroughQuery  true: resolve() equals query() + tenantOf() for every ref
     */
    public function __construct(
        public array $components = [],
        public ?string $label = null,
        public ?string $directory = null,
        public bool $tenantOwner = true,
        public bool $resolvesThroughQuery = false,
    ) {}
}
