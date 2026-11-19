<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Api;

/**
 * Immutable configuration of one assignment-scope definition: filters, label and directory class.
 *
 * The object stores no model, request or query builder. A class-string filter is resolved when the
 * operation runs, not here.
 *
 *
 * @phpstan-type Filter AssignmentScopeFilter|class-string<AssignmentScopeFilter>|(Closure(Builder<Model>, AssignmentScopeRuntime): mixed)
 */
#[Api(since: '1.0')]
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
