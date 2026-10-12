<?php

declare(strict_types=1);

namespace AzGuard\Context;

use AzGuard\Attributes\Api;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;

/**
 * The assignment scopes a panel accepts: the mode, the scope types by alias, a directory per alias and the class that
 * decides scope eligibility. Contributions of the provider, plugins and `configure` merge into one declaration per
 * panel; a panel without one is `AssignmentScopeMode::None`.
 */
#[Api(since: '1.0')]
final readonly class AssignmentScopeDeclaration
{
    /**
     * @param  list<AssignmentScopeDefinition>  $definitions  unique aliases
     * @param  array<string, class-string<AssignmentScopeDirectory>>  $directories  keyed by alias
     * @param  class-string|null  $evaluator  the eligibility evaluator; null means structural eligibility only
     */
    public function __construct(
        public AssignmentScopeMode $mode,
        public array $definitions,
        public array $directories = [],
        public ?string $evaluator = null,
    ) {}
}
