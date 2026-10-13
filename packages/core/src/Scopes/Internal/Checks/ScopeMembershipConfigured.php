<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Internal\Checks;

use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Panels\Panel;
use AzGuard\Scopes\Internal\ScopeConfiguration;
use AzGuard\Scopes\ModelAssignmentScopeDefinition;
use AzGuard\Tenancy\TenantMode;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;

/**
 * `scopes.membership` (findings `membership.configured`): the scope membership a panel requires and every access
 * adapter given as a class can be created (error), a required tenant owns every model-backed assignment scope (error),
 * and an inheriting assignment scope without a membership is reported (warning).
 */
final readonly class ScopeMembershipConfigured implements DoctorCheck
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return 'scopes.membership';
    }

    /** Contributed to one panel by `ScopesPlugin`, so it checks the panel it runs for. */
    public function run(DoctorContext $context): iterable
    {
        foreach ($context->panel() === null ? $context->panels() : [$context->panel()] as $panel) {
            yield from self::check($panel, $this->container);
        }
    }

    /** @return list<DoctorFinding> */
    public static function check(Panel $panel, Container $container): array
    {
        $scope = 'panel:'.$panel->id();
        $findings = [];
        $membership = ScopeConfiguration::membership($panel);

        if (is_string($membership) && ! $container->bound($membership) && (! class_exists($membership) || ! (new ReflectionClass($membership))->isInstantiable())) {
            $findings[] = DoctorFinding::error('membership.configured', 'Panel '.$panel->id().' requires a assignment scope membership, but '.$membership
                .' has no container binding and cannot be created.', $scope, ['membership' => $membership]);
        }

        foreach (ScopeConfiguration::adapters($panel) as $type => $adapter) {
            try {
                ScopeConfiguration::component($adapter, AssignmentScopeAccessAdapter::class, $container, 'Assignment scope access adapter');
            } catch (DefinitionException $error) {
                $findings[] = DoctorFinding::error('membership.configured', 'Panel '.$panel->id().' cannot create the access adapter of assignment scope '
                    .$type.': '.$error->getMessage(), $scope, ['scope' => $type]);
            }
        }

        if ($panel->tenants()->mode() === TenantMode::Required) {
            foreach ($panel->scopeDeclaration()->definitions ?? [] as $definition) {
                if ($definition instanceof ModelAssignmentScopeDefinition && ! $definition->hasOwner()) {
                    $findings[] = DoctorFinding::error('membership.configured', 'Assignment scope '.$definition->type().' of the tenant panel '.$panel->id()
                        .' has no tenant owner.', $scope, ['scope' => $definition->type()]);
                }
            }
        }

        if (($panel->scopeDeclaration()->mode ?? AssignmentScopeMode::None) === AssignmentScopeMode::Inherit && ($panel->scopeDeclaration()->definitions ?? []) !== [] && $membership === null) {
            $findings[] = DoctorFinding::warning('membership.configured', 'Panel '.$panel->id().' inherits roles into assignment scopes without a scope membership: '
                .'a role granted on a parent applies to every scope below it.', $scope, ['mode' => 'inherit']);
        }

        return $findings;
    }
}
