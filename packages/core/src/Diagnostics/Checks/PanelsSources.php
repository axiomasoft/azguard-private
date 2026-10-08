<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Contracts\Sources\StoresGrants;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;

/**
 * `panels.sources`: the sources of each panel exist, their ids do not repeat and at most one stores grants. The
 * checks a source brings itself (`ChecksHealth`) run separately for the panel and report under their own keys.
 *
 * @internal
 */
final readonly class PanelsSources implements DoctorCheck
{
    public function key(): string
    {
        return 'panels.sources';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach ($context->panels() as $panel) {
            yield from self::check($panel->id(), $panel->sources());
        }
    }

    /**
     * @param  list<SourceDescription>  $sources
     * @return list<DoctorFinding>
     */
    public static function check(string $panel, array $sources): array
    {
        $scope = 'panel:'.$panel;
        $findings = [];
        $ids = $writers = [];

        foreach ($sources as $source) {
            if (! is_subclass_of($source->class, Source::class)) {
                $findings[] = DoctorFinding::error('panels.sources', 'Panel '.$panel.' has the source '.$source->id.' of '.$source->class.', which is not a source.', $scope,
                    ['source' => $source->id]);
            }
            $ids[$source->id] = ($ids[$source->id] ?? 0) + 1;

            if (in_array(StoresGrants::class, $source->capabilities, true)) {
                $writers[] = $source->id;
            }
        }

        foreach ($ids as $id => $count) {
            if ($count > 1) {
                $findings[] = DoctorFinding::error('panels.sources', 'Panel '.$panel.' has '.$count.' sources with the id "'.$id.'".', $scope, ['source' => (string) $id]);
            }
        }

        if (count($writers) > 1) {
            $findings[] = DoctorFinding::error('panels.sources', 'Panel '.$panel.' has more than one source that stores grants: '.implode(', ', $writers).'.', $scope,
                ['writers' => $writers]);
        }

        return $findings;
    }
}
