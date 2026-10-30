<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Internal\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Contracts\Sources\FiltersQueries;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Roles\SelectsAutomaticRoleScopes;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Folder\FolderSource;

/**
 * `panels.sources`: the sources of each panel exist, their ids do not repeat and at most one stores grants. The
 * checks a source brings itself (`ChecksHealth`) run separately for the panel and report under their own keys.
 *
 * @internal
 */
final readonly class PanelsSources implements DoctorCheck
{
    public function __construct(private PanelRegistry $registry) {}

    public function key(): string
    {
        return 'panels.sources';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach ($context->panels() as $panel) {
            $automaticRoles = [];
            foreach ($this->registry->catalog($panel->id())->roles() as $role) {
                if (is_subclass_of($role['class'], GrantedAutomatically::class) && ! is_subclass_of($role['class'], SelectsAutomaticRoleScopes::class)) {
                    $automaticRoles[] = $role['class'];
                }
            }
            yield from self::check($panel->id(), $panel->sources(), $automaticRoles);
        }
    }

    /**
     * @param  list<SourceDescription>  $sources
     * @param  list<class-string>  $automaticRoles
     * @return list<DoctorFinding>
     */
    public static function check(string $panel, array $sources, array $automaticRoles = []): array
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

            if (is_a($source->class, DatabaseSource::class, true)) {
                $writers[] = $source->id;
            }

            $authority = is_a($source->class, ProvidesGrants::class, true) || is_a($source->class, ProvidesRoleGrants::class, true);

            if ($authority && ! is_a($source->class, FiltersQueries::class, true)) {
                $findings[] = DoctorFinding::warning('panels.sources.visibility', 'Source '.$source->id.' has no FiltersQueries declaration; exact visibility is unsupported.', $scope,
                    ['source' => $source->id, 'capability' => 'exact_visibility', 'reason' => 'missing_query_support']);
            }

            if ($authority && $source->volatility === Volatility::Stable && ! is_a($source->class, FencesReads::class, true)) {
                $findings[] = DoctorFinding::warning('panels.sources.cache', 'Source '.$source->id.' declares Stable without FencesReads; cross-request permission-set caching is unavailable.', $scope,
                    ['source' => $source->id, 'capability' => 'stable_cache', 'reason' => 'stable_unfenced']);
            }

            if (is_a($source->class, FolderSource::class, true) && $automaticRoles !== []) {
                $findings[] = DoctorFinding::warning('panels.sources.visibility', 'Automatic folder roles lack exact scope selection; scalar and batch checks remain available.', $scope,
                    ['source' => $source->id, 'capability' => 'exact_visibility', 'reason' => 'automatic_roles', 'roles' => $automaticRoles]);
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
