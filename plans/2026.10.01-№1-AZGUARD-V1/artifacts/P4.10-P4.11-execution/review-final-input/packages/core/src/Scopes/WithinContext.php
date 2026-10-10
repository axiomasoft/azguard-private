<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Exceptions\InvalidAssignmentScopeException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Panels\Panel;
use Closure;

/**
 * Runs an operation inside a panel scope and restores state even when entry resolution fails.
 *
 * @api
 */
final readonly class WithinContext
{
    public function __construct(private CurrentContext $current) {}

    /** @template TResult
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(Panel $panel, AccessScope $scope, Closure $callback): mixed
    {
        $previous = $this->current->get($panel);

        try {
            $this->current->set(panel: $panel, scope: $scope);

            if ($panel->tenants()->mode() === 'required' && ($scope->tenant->isGlobal()
                || $scope->tenant->type() !== $panel->tenants()->definition()?->type())) {
                throw new InvalidAssignmentScopeException('The selected tenant is not accepted by this panel.');
            }

            if ($panel->tenants()->mode() === 'none' && ! $scope->tenant->isGlobal()) {
                throw new InvalidAssignmentScopeException('This panel does not accept tenants.');
            }

            if ($scope->context->isGlobal()) {
                if ($panel->scopes()->mode() === 'required') {
                    throw new InvalidAssignmentScopeException('This panel requires an assignment scope.');
                }
            } else {
                $definition = $panel->scopeDefinition((string) $scope->context->type());
                $resolved = $definition?->resolve($scope->context);

                if ($resolved === null || ! $resolved->ref->equals($scope->context) || ! $resolved->tenant->equals($scope->tenant)) {
                    throw new InvalidAssignmentScopeException('The selected assignment scope is not accepted by this panel or tenant.');
                }

                if ($resolved->record !== null) {
                    $model = $definition->model();
                    $key = $resolved->record->getKey();

                    if ($model === null || ! $resolved->record instanceof $model || (! is_int($key) && ! is_string($key))
                        || IdentityCodec::canonicalId($key) !== $scope->context->id()) {
                        throw new InvalidAssignmentScopeException('The assignment scope resolver returned a record with another identity.');
                    }
                }
            }

            return $callback();
        } finally {
            $this->current->set(panel: $panel, scope: $previous);
        }
    }
}
