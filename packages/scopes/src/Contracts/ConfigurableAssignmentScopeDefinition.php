<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Contracts;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Scopes\AssignmentScopeSettings;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * An assignment-scope definition whose filters, label and directory are set in code and cloned per change.
 *
 * A string passed to `filter` is a class-string of {@see AssignmentScopeFilter}, never a profile alias.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ConfigurableAssignmentScopeDefinition extends AssignmentScopeDefinition
{
    /**
     * @param  AssignmentScopeFilter|class-string<AssignmentScopeFilter>|(Closure(Builder<Model>, AssignmentScopeRuntime): mixed)  $filter
     */
    public function filter(AssignmentScopeFilter|string|Closure $filter): static;

    public function label(string $label): static;

    /**
     * @param  class-string<AssignmentScopeDirectory>  $class
     */
    public function directory(string $class): static;

    public function settings(): AssignmentScopeSettings;
}
