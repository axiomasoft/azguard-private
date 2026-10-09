<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Pipeline\Stages;

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Authorization\ScopeEligibility;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Catalog\RoleCompiler;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Exceptions\ConsistencyException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Grammar\PatternMatcher;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyDecider;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Scopes\MembershipRestriction;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Sources\PanelSources;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
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
            $membership = new MembershipRestriction($this->container);

            $denied = $membership->check(request: $request, context: $frame)->denied();
            $trace->record('membership', $denied ? 'restricted' : 'pass', 'membership', outcome: $denied ? 'deny' : 'pass');

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
                $binding === null ? null : ($binding->policy ?? $binding->ability),
                detail: ['message' => $veto?->message, 'status' => $veto?->status, 'code' => $veto?->code],
                outcome: $binding === null ? 'skipped' : ($veto?->allowed() === false ? 'deny' : 'pass'));

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
                    $trace->record('contribution', 'not_matching', $source::class, outcome: 'skipped');
                }
            });

        if ($denial !== null) {
            return [$frame, false, $denial];
        }
        $frame = $frame->withAuthority($matching, $superAdmin);
        $trace->record('sources', 'evaluated', detail: ['count' => $count]);
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
                $sources = PanelSources::of($this->registry->recipe($frame->panel()->id()), $this->container);
                $contributions = [];
                foreach ($sources->all() as ['source' => $source]) {
                    if ($source instanceof FolderSource) {
                        $source->bindRoleClasses(array_column($catalog->roles(), 'class'));
                    }

                    [$items, $frame] = $this->readSource($source, $request, $frame, $trace);
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

            if (! $item->activeAt($frame->now())) {
                $trace->record('contribution', 'expired', $source::class);

                continue;
            }
            $roleDefinition = $item->role === null ? null : ($catalog->roles()[$item->role->key()] ?? null);

            if (! $item->scope->tenant->equals($frame->scope()->tenant)
                && ($roleDefinition === null || ! in_array($roleDefinition['class'], $frame->panel()->tenants()->globalRoles(), true))) {
                $trace->record('contribution', 'global_role_not_allowed', $source::class);

                continue;
            }

            $automatic = $item instanceof RoleContribution && $source instanceof FolderSource && $roleDefinition !== null
                && is_subclass_of($roleDefinition['class'], GrantedAutomatically::class);

            if ($item->role !== null && ($roleDefinition === null || (! $roleDefinition['grantable'] && ! $automatic))) {
                $current = null;
                foreach ($catalog->roles() as $candidate) {
                    if (in_array($item->role->key(), $candidate['former_keys'], true)) {
                        $current = $candidate['key'];

                        break;
                    }
                }
                $reason = $current !== null ? 'former_role_key' : ($roleDefinition === null ? 'unknown_role' : 'not_grantable_role');
                $trace->record('contribution', $reason, $source::class);
                Log::notice('AzGuard ignored role contribution.', ['component' => $source::class, 'reason' => $reason, 'role' => $item->role->full(), 'current_key' => $current ?? $roleDefinition['key'] ?? null]);

                continue;
            }

            if ($roleDefinition !== null && ! $this->roleScopeAccepted($roleDefinition, $item)) {
                $trace->record('contribution', 'role_scope_not_accepted', $source::class);

                continue;
            }

            try {
                $role = $roleDefinition === null ? null : $this->container->make($roleDefinition['class']);

                if ($role !== null && ! $role instanceof BaseRole) {
                    throw new RuntimeException('Role resolver did not return BaseRole.');
                }
                $branch = $frame->forContribution($item, $role);

                try {
                    if (! (new ScopeEligibility($this->container))->contribution($request, $branch, $frame->batchInputs, $trace)) {
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

    /** @return array{list<Grant|RoleContribution>, EvaluationFrame} */
    private function readSource(Source $source, AccessRequest $request, EvaluationFrame $frame, Trace $trace): array
    {
        if (! $source instanceof ProvidesGrants && ! $source instanceof ProvidesRoleGrants) {
            return [[], $frame];
        }

        if ($source instanceof DatabaseSource) {
            $snapshot = $source->readContributions($request->subject(), $frame->sourceScopes(), $frame);
            foreach ([...$snapshot['grants'], ...$snapshot['roles']] as $item) {
                $trace->contribution($item, $source::class);
            }

            return [[...$snapshot['grants'], ...$snapshot['roles']], $frame->withState($snapshot['state'])];
        }
        // Materialize every capability before accepting a fenced source revision.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $before = $source instanceof FencesReads ? $source->state($frame->panel(), $frame->scope()->tenant) : null;
            $items = [];

            if ($source instanceof ProvidesGrants) {
                foreach (PanelCatalog::untrusted($source->grants($request->subject(), $frame->sourceScopes(), $frame)) as $item) {
                    if (! $item instanceof Grant) {
                        throw new InvalidSourceContributionException('Unexpected direct grant contribution type.');
                    }
                    $trace->contribution($item, $source::class);
                    $items[] = $item;
                }
            }

            if ($source instanceof ProvidesRoleGrants) {
                foreach (PanelCatalog::untrusted($source->roleGrants($request->subject(), $frame->sourceScopes(), $frame)) as $item) {
                    if (! $item instanceof RoleContribution) {
                        throw new InvalidSourceContributionException('Unexpected role contribution type.');
                    }
                    $trace->contribution($item, $source::class);
                    $items[] = $item;
                }
            }

            if ($before === null || $before->equals($source->state($frame->panel(), $frame->scope()->tenant))) {
                return [$items, $before === null ? $frame : $frame->withState($before)];
            }
        }

        throw new ConsistencyException('Source authority changed during all three read attempts.');
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

    /** @param CompiledRole $role */
    private function roleScopeAccepted(array $role, Grant|RoleContribution $contribution): bool
    {
        if ($contribution->scope->context->isGlobal()) {
            return ! $role['scope_required'];
        }

        foreach ($role['scopes'] as $scope) {
            if ($scope['type'] === $contribution->scope->context->type()) {
                return true;
            }
        }

        return false;
    }
}
