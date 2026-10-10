<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Pipeline\Stages;

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Authorization\ReadAttemptChanged;
use AzGuard\Authorization\ScopeEligibility;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Catalog\PermissionDefinition;
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
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** @phpstan-import-type CompiledRole from \AzGuard\Catalog\RoleCompiler */
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
        } else {
            $trace->record('sources', 'skipped');
            $trace->record('superadmin', 'skipped');
        }

        try {
            $membership = new MembershipRestriction($this->container);

            if (($qualified || $definition->authority === PermissionAuthority::Policy)
                && $membership->check(request: $request, context: $frame)->denied()) {
                $trace->record('membership', 'restricted', 'membership');

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

        if ($definition->authority === PermissionAuthority::Grants && ! $qualified) {
            return [$frame, Decision::deny(DecisionReason::NotGranted, $frame->state(), $frame->scope())];
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

                    [$items, $frame] = $this->readSource($source, $request, $frame);
                    foreach ($items as $item) {
                        $trace->contribution($item, $source::class);
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
        } catch (ReadAttemptChanged $changed) {
            throw $changed;
        } catch (Throwable $error) {
            $component = isset($source) ? $source::class : 'sources';
            $reason = $error instanceof ConsistencyException ? DecisionReason::ConsistencyError : DecisionReason::SourceError;
            $trace->error('sources', $reason->value, $component, $error);

            return [$frame, false, Decision::deny($reason, $frame->state(), $frame->scope(), $component)];
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
                    $trace->error('eligibility', DecisionReason::AssignmentScopeFilterError->value, $source::class, $error);

                    return [$frame, false, Decision::deny(DecisionReason::AssignmentScopeFilterError, $frame->state(), $frame->scope(), $source::class)];
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
                $component = isset($condition) ? $condition::class : $source::class;
                $trace->error('condition', 'condition_error', $component, $error);

                return [$frame, false, Decision::deny(DecisionReason::ConditionError, $frame->state(), $frame->scope(), $component)];
            }

            if (! $passes) {
                $trace->record('contribution', 'condition_false', $source::class);

                continue;
            }
            $admin = $this->superAdmin($roleDefinition);
            $covers = $item instanceof Grant ? PatternMatcher::covers($item->pattern->local(), $request->permission()->local()) : false;

            if ($item instanceof RoleContribution) {
                foreach ($roleDefinition['permissions'] as $pattern) {
                    $covers = $covers || PatternMatcher::covers($pattern, $request->permission()->local());
                }
            }

            if ($admin || $covers) {
                $qualified = true;
                $superAdmin = $superAdmin || $admin;

                if ($item instanceof Grant && $covers) {
                    $matching[] = $item;
                }
                $trace->record('contribution', 'qualified', $source::class);

                if ($admin && $roleDefinition['permissions'] === []) {
                    $trace->record('contribution', 'qualified_empty_super_admin', $source::class);
                }
            } else {
                $trace->record('contribution', 'not_matching', $source::class, outcome: 'skipped');
            }
        }
        $frame = $frame->withAuthority($matching, $superAdmin);
        $trace->record('sources', 'evaluated', detail: ['count' => count($contributions)]);
        $trace->record('superadmin', $superAdmin ? 'qualified' : 'not_granted');

        return [$frame, $qualified, null];
    }

    /** @return array{list<Grant|RoleContribution>, EvaluationFrame} */
    private function readSource(Source $source, AccessRequest $request, EvaluationFrame $frame): array
    {
        if (! $source instanceof ProvidesGrants && ! $source instanceof ProvidesRoleGrants) {
            return [[], $frame];
        }

        if ($source instanceof DatabaseSource) {
            $snapshot = $source->readContributions($request->subject(), $frame->sourceScopes(), $frame);

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
                    $items[] = $item;
                }
            }

            if ($source instanceof ProvidesRoleGrants) {
                foreach (PanelCatalog::untrusted($source->roleGrants($request->subject(), $frame->sourceScopes(), $frame)) as $item) {
                    if (! $item instanceof RoleContribution) {
                        throw new InvalidSourceContributionException('Unexpected role contribution type.');
                    }
                    $items[] = $item;
                }
            }

            if ($before === null || $before->equals($source->state($frame->panel(), $frame->scope()->tenant))) {
                return [$items, $before === null ? $frame : $frame->withState($before)];
            }
        }

        throw new ConsistencyException('Source authority changed during all three read attempts.');
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
