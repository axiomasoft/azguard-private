<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Roles\BaseRole;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * `roles.keys`: every role of each panel declares its stable key with `#[Role]` or `key()`, and that key is still the
 * key the catalog stores grants under.
 *
 * @internal
 */
final readonly class RolesKeys implements DoctorCheck
{
    public function __construct(private PanelRegistry $registry, private Container $container) {}

    public function key(): string
    {
        return 'roles.keys';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach ($context->panels() as $panel) {
            yield from self::check($this->registry->catalog($panel->id()), $this->container);
        }
    }

    /** @return list<DoctorFinding> */
    public static function check(PanelCatalog $catalog, Container $container): array
    {
        $scope = 'panel:'.$catalog->panel();
        $findings = [];

        foreach ($catalog->roles() as $key => $role) {
            try {
                $instance = $container->make($role['class']);
                $declared = $instance instanceof BaseRole ? $instance->key() : null;
            } catch (Throwable $error) {
                $findings[] = DoctorFinding::error('roles.keys', 'Role '.$role['class'].' of panel '.$catalog->panel().' has no stable key: declare #[Role(\'key\')] or override key() ('
                    .$error::class.').', $scope, ['role' => $role['class']]);

                continue;
            }

            if ($declared !== (string) $key) {
                $findings[] = DoctorFinding::error('roles.keys', 'Role '.$role['class'].' of panel '.$catalog->panel().' declares the key '.json_encode($declared)
                    .', but the catalog stores it as "'.$key.'": rebuild the catalog cache or keep the former key in #[FormerKeys].', $scope,
                    ['role' => $role['class'], 'key' => (string) $key]);
            }
        }

        return $findings;
    }
}
