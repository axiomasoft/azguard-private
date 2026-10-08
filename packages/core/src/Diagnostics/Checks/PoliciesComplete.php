<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\Decides;
use ReflectionClass;
use ReflectionMethod;

/**
 * `policies.complete`: a policy-only permission has a policy that decides it (error), and a public method of a
 * policy decides a permission or implements an interface of the policy (warning: any other public method without
 * `#[Decides]` is never called by AzGuard).
 *
 * @internal
 */
final readonly class PoliciesComplete implements DoctorCheck
{
    public function __construct(private PanelRegistry $registry) {}

    public function key(): string
    {
        return 'policies.complete';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach ($context->panels() as $panel) {
            yield from self::check($this->registry->catalog($panel->id()));
        }
    }

    /** @return list<DoctorFinding> */
    public static function check(PanelCatalog $catalog): array
    {
        $scope = 'panel:'.$catalog->panel();
        $bindings = $catalog->policyBindings();
        $findings = [];

        foreach ($catalog->all() as $local => $definition) {
            if ($definition->authority === PermissionAuthority::Policy && ! isset($bindings[(string) $local])) {
                $findings[] = DoctorFinding::error('policies.complete', 'Policy-only permission "'.$local.'" of panel '.$catalog->panel()
                    .' has no policy method: add #[Decides] or declare #[RequiresGrant].', $scope, ['permission' => (string) $local]);
            }
        }

        $policies = [];
        foreach ($bindings as $local => $binding) {
            if ($binding->kind === 'php' && is_string($binding->policy) && class_exists($binding->policy)) {
                $policies[$binding->policy][] = $binding->method ?? $catalog->bindingMethod((string) $local);
            }
        }
        ksort($policies, SORT_STRING);

        foreach ($policies as $policy => $bound) {
            $class = new ReflectionClass($policy);
            // A method an interface of the policy declares serves that contract, such as a query predicate.
            foreach ($class->getInterfaces() as $interface) {
                foreach ($interface->getMethods() as $method) {
                    $bound[] = $method->getName();
                }
            }

            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $policy || str_starts_with($method->getName(), '__')
                    || $method->getAttributes(Decides::class) !== [] || in_array($method->getName(), $bound, true)) {
                    continue;
                }
                $findings[] = DoctorFinding::warning('policies.complete', $policy.'::'.$method->getName().' is public but decides no permission of panel '
                    .$catalog->panel().': add #[Decides] or make it non-public.', $scope, ['policy' => $policy, 'method' => $method->getName()]);
            }
        }

        return $findings;
    }
}
