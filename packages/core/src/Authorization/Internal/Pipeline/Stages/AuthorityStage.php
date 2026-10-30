<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Internal\Pipeline\Stages;

use AzGuard\Authorization\Internal\ContributionQualifier;
use AzGuard\Authorization\Internal\EvaluationFrame;
use AzGuard\Authorization\Internal\Pipeline\Trace;
use AzGuard\Authorization\Internal\ReadAttempt;
use AzGuard\Catalog\Internal\PanelCatalog;
use AzGuard\Catalog\Internal\RoleCompiler;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Context\Internal\ContextIdentity;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Exceptions\ConsistencyException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Kernel\Internal\PatternMatcher;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Policies\Internal\PolicyDecider;
use AzGuard\Roles\BaseRole;
use AzGuard\Sources\Internal\PanelSources;
use AzGuard\Tenancy\Internal\TenantMembershipRestriction;
use Closure;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;

/** @phpstan-import-type CompiledRole from RoleCompiler */
final readonly class AuthorityStage
{
    public function __construct(private Container $container, private PanelRegistry $registry, private PolicyDecider $policies) {}

    /** @return array{EvaluationFrame,Decision} */
    public function decide(AccessRequest $request, EvaluationFrame $frame, PanelCatalog $catalog, PermissionDefinition $definition, Trace $trace): array
    {
        $qualified = false;

        if ($definition->authority === PermissionAuthority::Grants) {
            [$frame, $qualified, $denial] = $this->qualify($request, $frame, $catalog, $trace);

            if ($denial !== null) {
                return [$frame, $denial];
            }

            if (! $qualified) {
                $trace->record('policy', 'skipped');

                return [$frame, Decision::deny(DecisionReason::NotGranted, $frame->state(), $frame->scope())];
            }
        } else {
            $trace->record('sources', 'skipped');
            $trace->record('superadmin', 'skipped');
        }

        try {
            $membership = new TenantMembershipRestriction($this->container);

            $denied = $membership->check($request, $frame)->denied();
            $trace->record('membership', $denied ? 'restricted' : 'pass', 'membership', null, [], $denied ? 'deny' : 'pass');

            if ($denied) {
                return [$frame, Decision::deny(reason: DecisionReason::Restricted, state: $frame->state(), scope: $frame->scope(), component: 'membership')];
            }
        } catch (Throwable $error) {
            $trace->error('membership', 'restriction_error', 'membership', $error);

            return [$frame, Decision::deny(reason: DecisionReason::RestrictionError, state: $frame->state(), scope: $frame->scope(), component: 'membership')];
        }

        try {
            $veto = $this->policies->decide($request, $frame, $catalog, $definition->authority, qualified: $qualified);
            $binding = $catalog->policyBindings()[$request->permission()->local()] ?? null;
            $trace->record('policy', $binding === null ? 'skipped' : ($veto?->allowed() === false ? 'deny' : 'pass'),
                $binding === null ? null : ($binding->policy ?? $binding->ability), null,
                ['message' => $veto?->message, 'status' => $veto?->status, 'code' => $veto?->code],
                $binding === null ? 'skipped' : ($veto?->allowed() === false ? 'deny' : 'pass'));

            if ($veto !== null) {
                return [$frame, $veto];
            }
        } catch (Throwable $error) {
            $binding = $catalog->policyBindings()[$request->permission()->local()] ?? null;
            $component = $binding->policy ?? $binding->ability ?? 'policy';
            $trace->error('policy', 'policy_error', $component, $error);

            return [$frame, Decision::deny(DecisionReason::PolicyError, $frame->state(), $frame->scope(), $component)];
        }

        $reason = $definition->authority === PermissionAuthority::Policy ? DecisionReason::Policy : ($frame->qualifiedSuperAdmin ? DecisionReason::SuperAdmin : DecisionReason::Granted);

        return [$frame, Decision::allow($reason, $frame->state(), $frame->scope(), grants: $request->isTraced() ? $frame->matchingGrants() : [])];
    }

    /** @return array{EvaluationFrame,bool,?Decision} */
    public function qualify(AccessRequest $request, EvaluationFrame $frame, PanelCatalog $catalog, Trace $trace): array
    {
        $matching = [];
        $superAdmin = false;
        $qualified = false;
        $permission = $request->permission()->local();
        $wildcards = [];
        [$frame, $denial, $count] = $this->walk($request, $frame, $catalog, $trace,
            function (Source $source, Grant|RoleContribution $item, ?array $roleDefinition) use ($permission, $trace, &$matching, &$superAdmin, &$qualified, &$wildcards): void {
                $admin = $item instanceof RoleContribution && $this->superAdmin($roleDefinition);
                // Exact patterns are a lookup, as in permissionSet(); only wildcards are matched.
                $covers = self::covers($permission, $item, $roleDefinition, $wildcards);

                if ($admin || $covers) {
                    $qualified = true;
                    $superAdmin = $superAdmin || $admin;

                    if ($item instanceof Grant && $covers) {
                        $matching[] = $item;
                    }
                    $trace->record('contribution', 'qualified', $source::class);

                    if ($admin && $roleDefinition !== null && $roleDefinition['permissions'] === []) {
                        $trace->record('contribution', 'qualified_empty_super_admin', $source::class);
                    }
                } else {
                    $trace->record('contribution', 'not_matching', $source::class, null, [], 'skipped');
                }
            });

        if ($denial !== null) {
            return [$frame, false, $denial];
        }
        $frame = $frame->withAuthority($matching, $superAdmin);
        $trace->record('sources', 'evaluated', null, null, ['count' => $count]);
        $trace->record('superadmin', $superAdmin ? 'qualified' : 'not_granted');

        return [$frame, $qualified, null];
    }

    /**
     * The contributions of the subject that qualify in the selected scope, whatever permission they cover: the same
     * checks as `qualify()` — activity, tenant, known grantable role, role scope, eligibility and grant conditions.
     *
     * @return array{EvaluationFrame, list<array{Grant|RoleContribution, CompiledRole|null}>, ?Decision}
     */
    public function qualifiedContributions(AccessRequest $request, EvaluationFrame $frame, PanelCatalog $catalog, Trace $trace): array
    {
        $qualified = [];
        [$frame, $denial] = $this->walk($request, $frame, $catalog, $trace,
            static function (Source $source, Grant|RoleContribution $item, ?array $roleDefinition) use (&$qualified): void {
                $qualified[] = [$item, $roleDefinition];
            });

        return [$frame, $denial === null ? $qualified : [], $denial];
    }

    /**
     * Reads the contributions of the subject and hands every one that passes the qualification checks to `$accept`.
     *
     * @param  Closure(Source, Grant|RoleContribution, CompiledRole|null): void  $accept
     * @return array{EvaluationFrame, ?Decision, int}
     */
    private function walk(AccessRequest $request, EvaluationFrame $frame, PanelCatalog $catalog, Trace $trace, Closure $accept): array
    {
        try {
            $contributions = $frame->readAttempt?->contributions($request, $frame, $trace);

            if ($frame->readAttempt !== null) {
                $frame = $frame->readAttempt->consumedFrame($frame);
            }

            if ($contributions === null) {
                $sources = PanelSources::of($this->registry->recipe($frame->panel()->id()), $this->container, catalog: $catalog);
                $contributions = [];
                foreach ($sources->all() as ['source' => $source]) {
                    [$items, $frame] = ReadAttempt::readSource($source, $request, $frame, $trace);
                    foreach ($items as $item) {
                        $contributions[] = [$source, $item];
                    }
                }
            }
            foreach ($contributions as [$source, $item]) {
                if ($item instanceof Grant) {
                    PermissionGrammar::assertPattern($item->pattern->local());
                }

                if (($item instanceof Grant && $item->pattern->panel() !== $frame->panel()->id()) || ($item->role !== null && $item->role->panel() !== $frame->panel()->id()) || ! $frame->acceptsContributionScope($item->scope)) {
                    throw new InvalidSourceContributionException('Contribution panel or scope differs from the request.');
                }
            }
        } catch (Throwable $error) {
            $component = isset($source) ? $source::class : 'sources';
            $reason = $error instanceof ConsistencyException ? DecisionReason::ConsistencyError : DecisionReason::SourceError;
            $trace->error('sources', $reason->value, $component, $error);

            return [$frame, Decision::failed($reason, $frame->state(), $frame->scope(), $component), 0];
        }
        foreach ($contributions as [$source,$item]) {
            $trace->qualifying($item);

            [$roleDefinition, $reason] = ContributionQualifier::qualify($source, $item, $catalog, $frame->panel(), $frame->scope()->tenant, $frame->now());

            if ($reason !== null) {
                $trace->record('contribution', $reason, $source::class);

                continue;
            }

            try {
                $role = $roleDefinition === null ? null : $this->container->make($roleDefinition['class']);

                if ($role !== null && ! $role instanceof BaseRole) {
                    throw new RuntimeException('Role resolver did not return BaseRole.');
                }
                $branch = $frame->forContribution($item, $role);

                try {
                    if (! $this->contributionEligible($request, $branch, $trace)) {
                        $trace->record('contribution', 'scope_ineligible', $source::class);

                        continue;
                    }
                } catch (Throwable $error) {
                    $trace->error('filter', DecisionReason::AssignmentScopeFilterError->value, $source::class, $error);

                    return [$frame, Decision::deny(DecisionReason::AssignmentScopeFilterError, $frame->state(), $frame->scope(), $source::class), 0];
                }
                $passes = true;
                foreach ($frame->panel()->grantConditions() as $declared) {
                    $condition = is_string($declared) ? $this->container->make($declared) : $declared;

                    if (! $condition instanceof GrantCondition) {
                        throw new RuntimeException('Condition resolver did not return GrantCondition.');
                    }

                    $allowed = $condition->allows($item, $request, $branch);
                    $trace->record('condition', $allowed ? 'pass' : 'condition_false', $condition::class);

                    if (! $allowed) {
                        $passes = false;

                        break;
                    }
                }
            } catch (Throwable $error) {
                $component = isset($condition) && is_object($condition) ? $condition::class : $source::class;
                $trace->error('condition', 'condition_error', $component, $error);

                return [$frame, Decision::deny(DecisionReason::ConditionError, $frame->state(), $frame->scope(), $component), 0];
            }

            if (! $passes) {
                $trace->record('contribution', 'condition_false', $source::class);

                continue;
            }
            $accept($source, $item, $roleDefinition);
        }

        return [$frame, null, count($contributions)];
    }

    /**
     * Whether the grant pattern or a permission pattern of the role covers the permission: an exact pattern by lookup,
     * only wildcards by matching. `$wildcards` keeps the wildcard patterns of each role for the rest of the decision.
     *
     * @param  CompiledRole|null  $role
     * @param  array<string, list<string>>  $wildcards
     */
    private static function covers(string $permission, Grant|RoleContribution $item, ?array $role, array &$wildcards): bool
    {
        if ($item instanceof Grant) {
            return PatternMatcher::coversValidated($item->pattern->local(), $permission);
        }

        if ($role === null) {
            return false;
        }

        if (in_array($permission, $role['permissions'], true)) {
            return true;
        }
        $wildcards[$role['key']] ??= array_values(array_filter($role['permissions'], static fn (string $pattern): bool => str_ends_with($pattern, '*')));
        foreach ($wildcards[$role['key']] as $pattern) {
            if (PatternMatcher::coversValidated($pattern, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** @param CompiledRole|null $role */
    private function superAdmin(?array $role): bool
    {
        return $role !== null && $role['super_admin'];
    }

    /**
     * The scope eligibility of one contribution: none for a global context; a context other than the one the boundary
     * verified is verified structurally here and must pass the common check first; then the evaluator answers for the
     * contribution's runtime.
     */
    private function contributionEligible(AccessRequest $request, EvaluationFrame $branch, Trace $trace): bool
    {
        $context = $branch->scope()->context;

        if ($context->isGlobal()) {
            return true;
        }
        $evaluator = ContextIdentity::evaluator($branch->panel(), $this->container);

        $resolved = $branch->assignmentScope;

        if ($resolved === null) {
            $definition = $branch->panel()->scopeDefinition($context->type() ?? '') ?? throw new RuntimeException('Selected scope definition is missing.');
            $resolved = $definition->resolve($context);

            if ($resolved === null || ! $resolved->ref->equals($context) || ! $resolved->tenant->equals($branch->scope()->tenant)) {
                return false;
            }
            $branch = $branch->withAssignmentScope($resolved);

            if (! BoundaryStage::eligible($evaluator, $branch->scopeRuntime($request->subject(), common: true), $resolved, $branch, $branch->batchInputs, null)) {
                return false;
            }
        }

        return BoundaryStage::eligible($evaluator, $branch->scopeRuntime($request->subject()), $resolved, $branch, $branch->batchInputs, $trace);
    }
}
