<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use Closure;

/**
 * Immutable configuration of one assignment-scope definition: filters, label and directory class.
 *
 * The object stores no model, request or query builder. A class-string filter is resolved when the
 * operation runs, not here.
 *
 * @api
 *
 * @phpstan-type Filter AssignmentScopeFilter|class-string<AssignmentScopeFilter>|Closure
 */
final readonly class AssignmentScopeSettings
{
    /**
     * @param  list<Filter>  $filters
     * @param  class-string<AssignmentScopeDirectory>|null  $directory
     */
    public function __construct(
        public array $filters,
        public ?string $label,
        public ?string $directory,
    ) {}
}
