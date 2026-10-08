<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Exceptions\PrefixConflictException;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Database\DatabaseSource;

/**
 * `catalog.collisions`: permission names of the panels do not collide through their prefixes, and the permissions a
 * database source created at run time do not repeat a name of the code catalog of their panel, tenant by tenant.
 *
 * @internal
 */
final readonly class CatalogCollisions implements DoctorCheck
{
    public function __construct(private PanelRegistry $registry) {}

    public function key(): string
    {
        return 'catalog.collisions';
    }

    public function run(DoctorContext $context): iterable
    {
        $panels = $this->registry->all();

        yield from self::prefixes($panels, array_map(fn (Panel $panel): PanelCatalog => $this->registry->catalog($panel->id()), $panels));

        foreach ($context->panels() as $panel) {
            foreach ($panel->attachedSources() as $source) {
                if (! $source instanceof DatabaseSource || ! $source->isDynamic()) {
                    continue;
                }

                foreach ($source->dynamicTenants($panel) as $tenant) {
                    try {
                        $source->inspectCatalog($panel, $tenant);
                    } catch (AzGuardException $error) {
                        yield DoctorFinding::error($this->key(), 'Permissions created at run time in panel '.$panel->id().' for tenant '.$tenant->key()
                            .' collide with its catalog: '.$error->getMessage(), 'panel:'.$panel->id(), ['tenant' => $tenant->key(), 'code' => $error->code()]);
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, Panel>  $panels  all registered panels by id
     * @param  array<string, PanelCatalog>  $catalogs  their static catalogs by panel id
     * @return list<DoctorFinding>
     */
    public static function prefixes(array $panels, array $catalogs): array
    {
        try {
            (new PanelCompiler)->prefixes($panels, $catalogs);
        } catch (PrefixConflictException $error) {
            return [DoctorFinding::error('catalog.collisions', $error->getMessage(), details: ['code' => $error->code()])];
        }

        return [];
    }
}
