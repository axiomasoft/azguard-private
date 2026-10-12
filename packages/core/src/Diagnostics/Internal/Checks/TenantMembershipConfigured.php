<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Internal\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Panels\Panel;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;

/**
 * `membership.configured`: the tenant membership a panel requires can be created. The scope part is the check
 * `scopes.membership` that `ScopesPlugin` contributes.
 *
 * @internal
 */
final readonly class TenantMembershipConfigured implements DoctorCheck
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
        $membership = $panel->tenants()->membership();

        if (is_string($membership) && ! $container->bound($membership) && (! class_exists($membership) || ! (new ReflectionClass($membership))->isInstantiable())) {
            return [DoctorFinding::error('membership.configured', 'Panel '.$panel->id().' requires a tenant membership, but '.$membership
                .' has no container binding and cannot be created.', 'panel:'.$panel->id(), ['membership' => $membership])];
        }

        return [];
    }
}
