<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Panels\PanelRegistry;
use ReflectionMethod;

/**
 * `panels.policies`: every policy binding of each panel names a public instance method of an existing policy class.
 * One binding per permission is kept by the catalog itself; whether discovery still matches the catalog cache is
 * checked by the folder source.
 *
 * @internal
 */
final readonly class PanelsPolicies implements DoctorCheck
{
    public function __construct(private PanelRegistry $registry) {}

    public function key(): string
    {
        return 'panels.policies';
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
        $findings = [];

        foreach ($catalog->policyBindings() as $local => $binding) {
            if ($binding->kind !== 'php') {
                continue;
            }
            $method = $binding->method ?? $catalog->bindingMethod((string) $local);

            if ($binding->policy === null || ! class_exists($binding->policy) || $method === null || ! method_exists($binding->policy, $method)
                || ! (new ReflectionMethod($binding->policy, $method))->isPublic() || (new ReflectionMethod($binding->policy, $method))->isStatic()) {
                $findings[] = DoctorFinding::error('panels.policies', 'Permission "'.$local.'" of panel '.$catalog->panel().' is bound to '
                    .($binding->policy ?? 'no policy').'::'.($method ?? '?').', which is not a public instance method.', 'panel:'.$catalog->panel(),
                    ['permission' => (string) $local]);
            }
        }

        return $findings;
    }
}
