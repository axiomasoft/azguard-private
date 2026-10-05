<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Storage\StorageReadSession;

/** @internal One operation's consumed source revisions and pinned handles; never shared or cached.
 * @phpstan-import-type Attached from \AzGuard\Sources\PanelSources
 */
final class ReadAttempt
{
    /** @var array<string, StorageReadSession> */
    private array $sessions = [];

    /** @var array<string, StateToken> */
    private array $states = [];

    private ?PanelCatalog $overlay = null;

    /** @param list<Attached> $sources */
    public function __construct(private readonly PanelCatalog $static, private readonly array $sources, private readonly EvaluationFrame $initial) {}

    public function catalog(): PanelCatalog
    {
        if ($this->overlay !== null) {
            return $this->overlay;
        }
        $definitions = [];
        foreach ($this->sources as ['source' => $source]) {
            if (! $source instanceof ProvidesPermissions || ! $source->isDynamic()) {
                continue;
            }
            $this->begin($source);
            $items = $source instanceof DatabaseSource
                ? $source->readPermissions($this->sessions[$source->id()], $this->initial->panel(), $this->initial->scope()->tenant)
                : $source->permissions($this->initial->panel(), $this->initial->scope()->tenant);
            foreach (PanelCatalog::untrusted($items) as $item) {
                $definitions[] = $item;
            }
        }

        return $this->overlay = $this->static->withDynamic($definitions);
    }

    /** @return list<array{Source, Grant|RoleContribution}> */
    public function contributions(AccessRequest $request, EvaluationFrame $frame): array
    {
        $contributions = [];
        foreach ($this->sources as ['source' => $source]) {
            if (! $source instanceof ProvidesGrants && ! $source instanceof ProvidesRoleGrants) {
                continue;
            }
            $this->begin($source);

            if ($source instanceof FolderSource) {
                $source->bindRoleClasses(array_column($this->static->roles(), 'class'));
            }

            if ($source instanceof DatabaseSource) {
                $snapshot = $source->readAssignments($this->sessions[$source->id()], $request->subject(), [$frame->scope()], $frame);
                $items = [...$snapshot['grants'], ...$snapshot['roles']];
            } else {
                $items = [];

                if ($source instanceof ProvidesGrants) {
                    foreach (PanelCatalog::untrusted($source->grants($request->subject(), [$frame->scope()], $frame)) as $item) {
                        if (! $item instanceof Grant) {
                            throw new InvalidSourceContributionException('Unexpected direct grant contribution type.');
                        }
                        $items[] = $item;
                    }
                }

                if ($source instanceof ProvidesRoleGrants) {
                    foreach (PanelCatalog::untrusted($source->roleGrants($request->subject(), [$frame->scope()], $frame)) as $item) {
                        if (! $item instanceof RoleContribution) {
                            throw new InvalidSourceContributionException('Unexpected role contribution type.');
                        }
                        $items[] = $item;
                    }
                }
            }
            foreach ($items as $item) {
                $contributions[] = [$source, $item];
            }
        }

        return $contributions;
    }

    public function confirm(EvaluationFrame $frame): EvaluationFrame
    {
        $stable = true;
        foreach ($this->sources as ['source' => $source]) {
            if (! $source instanceof FencesReads || ! isset($this->states[$source->id()])) {
                continue;
            }
            $after = $source instanceof DatabaseSource ? $source->readState($this->sessions[$source->id()], $this->initial)
                : $source->state($this->initial->panel(), $this->initial->scope()->tenant);
            $stable = $this->states[$source->id()]->equals($after) && $stable;
        }

        if (! $stable) {
            throw new ReadAttemptChanged($this->initial);
        }

        $database = null;
        foreach ($this->sources as ['source' => $source]) {
            if ($source instanceof DatabaseSource && isset($this->states[$source->id()])) {
                $database = $this->states[$source->id()];
            }
        }

        return $frame->withSourceStates($this->states, $database);
    }

    private function begin(Source $source): void
    {
        if (! $source instanceof FencesReads || isset($this->states[$source->id()])) {
            return;
        }

        if ($source instanceof DatabaseSource) {
            $this->sessions[$source->id()] = $source->openReadSession($this->initial);
            $this->states[$source->id()] = $source->readState($this->sessions[$source->id()], $this->initial);
        } else {
            $this->states[$source->id()] = $source->state($this->initial->panel(), $this->initial->scope()->tenant);
        }
    }
}
