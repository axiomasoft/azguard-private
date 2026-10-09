<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use Illuminate\Database\Eloquent\Model;

/**
 * Entry into the interface of a panel: one evaluator for routes and admin panels.
 *
 * The subject is admitted when the panel accepts it, it is a member of the tenant and assignment scope of the request,
 * and it holds at least one qualifying role of the panel there, from any source. While no assignment scope is selected,
 * one whole qualifying role contribution in an eligible assignment scope of the tenant is enough, so a role bound to a
 * project lets the subject open the interface and choose that project. Then the entry permission of the panel, when it
 * has one, is decided in the scope of the request as any other check (AND).
 *
 * Admission is not a decision on an action: direct grants and policy-only allows never admit, and admission grants
 * nothing; actions and visibility are still checked in their exact scope. A source that fails or cannot select the
 * assignment scopes of its role assignments refuses entry.
 *
 * @internal
 */
final readonly class PanelAdmission
{
    public function __construct(private Authorizer $authorizer) {}

    /**
     * The scope of the request the subject enters, or null when the subject is not admitted.
     */
    public function admit(Panel $panel, Model $subject): ?AccessScope
    {
        if (! $panel->accepts($subject)) {
            return null;
        }
        $key = $subject->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return null;
        }
        $ref = SubjectRef::of($subject->getMorphClass(), $key);
        $scope = $this->authorizer->admissionScope($panel, $ref);

        if ($scope === null || ($scope->tenant->isGlobal() && $panel->tenants()->mode() === 'required') || ! $this->holdsRole($panel, $ref, $scope)) {
            return null;
        }
        $entry = $panel->entry();

        if ($entry === null) {
            return $scope;
        }
        [, $permission] = $this->authorizer->resolve($subject, $entry, $panel->id());

        return $this->authorizer->decide($panel, AccessRequest::for($ref, $permission)->withSubjectModel($subject)->inScope($scope))->allowed() ? $scope : null;
    }

    private function holdsRole(Panel $panel, SubjectRef $subject, AccessScope $scope): bool
    {
        if ($this->authorizer->roles($panel, $subject, $scope) !== []) {
            return true;
        }

        if (! $scope->context->isGlobal() || $panel->scopes()->mode() === 'none') {
            return false;
        }
        foreach (array_keys($panel->scopeDefinitions()) as $type) {
            foreach ($this->authorizer->roleScopes($panel, $subject, $scope->tenant, $type) ?? [] as $context) {
                $witness = $this->authorizer->admissionScope($panel, $subject, AccessScope::in($scope->tenant, $context));

                if ($witness !== null && $this->authorizer->roles($panel, $subject, $witness) !== []) {
                    return true;
                }
            }
        }

        return false;
    }
}
