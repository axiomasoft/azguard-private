<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Relation\RelationSource;

/**
 * `panels.relations`: the static role every relation source of a panel names is a role of that panel.
 *
 * @internal
 */
final readonly class PanelsRelations implements DoctorCheck
{
    public function __construct(private PanelRegistry $registry) {}

    public function key(): string
    {
        return 'panels.relations';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach ($context->panels() as $panel) {
            yield from self::check($panel->attachedSources(), $this->registry->catalog($panel->id()));
        }
    }

    /**
     * @param  list<Source>  $sources
     * @return list<DoctorFinding>
     */
    public static function check(array $sources, PanelCatalog $catalog): array
    {
        $findings = [];

        foreach ($sources as $source) {
            if (! $source instanceof RelationSource) {
                continue;
            }

            try {
                $source->validateCatalog($catalog);
            } catch (AzGuardException $error) {
                $findings[] = DoctorFinding::error('panels.relations', $error->getMessage(), 'panel:'.$catalog->panel(), ['code' => $error->code()]);
            }
        }

        return $findings;
    }
}
