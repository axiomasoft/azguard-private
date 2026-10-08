<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Panels\Panel;
use AzGuard\Scopes\ModelAssignmentScopeDefinition;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;

/**
 * `membership.configured`: a membership a panel requires can be created, a required tenant owns every assignment
 * scope (error), and an inheriting assignment scope without a membership is reported (warning).
 *
 * @internal
 */
final readonly class MembershipConfigured implements DoctorCheck
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return 'membership.configured';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach ($context->panels() as $panel) {
            yield from self::check($panel, $this->container);
        }
    }

    /** @return list<DoctorFinding> */
    public static function check(Panel $panel, Container $container): array
    {
        $scope = 'panel:'.$panel->id();
        $findings = [];

        foreach (['tenant' => $panel->tenants()->membership(), 'assignment scope' => $panel->scopes()->membership()] as $kind => $membership) {
            if (is_string($membership) && ! $container->bound($membership) && (! class_exists($membership) || ! (new ReflectionClass($membership))->isInstantiable())) {
                $findings[] = DoctorFinding::error('membership.configured', 'Panel '.$panel->id().' requires a '.$kind.' membership, but '.$membership
                    .' has no container binding and cannot be created.', $scope, ['membership' => $membership]);
            }
        }

        if ($panel->tenants()->mode() === 'required') {
            foreach ($panel->scopeDefinitions() as $type => $definition) {
                if ($definition instanceof ModelAssignmentScopeDefinition && ! $definition->hasOwner()) {
                    $findings[] = DoctorFinding::error('membership.configured', 'Assignment scope '.$type.' of the tenant panel '.$panel->id()
                        .' has no tenant owner.', $scope, ['scope' => (string) $type]);
                }
            }
        }

        if ($panel->scopes()->mode() === 'inherit' && $panel->scopeDefinitions() !== [] && $panel->scopes()->membership() === null) {
            $findings[] = DoctorFinding::warning('membership.configured', 'Panel '.$panel->id().' inherits roles into assignment scopes without a scope membership: '
                .'a role granted on a parent applies to every scope below it.', $scope, ['mode' => 'inherit']);
        }

        return $findings;
    }
}
