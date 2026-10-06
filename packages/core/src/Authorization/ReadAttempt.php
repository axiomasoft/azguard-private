<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Authorization\Cache\PermissionSetCache;
use AzGuard\Authorization\Pipeline\Trace;
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
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Panels\StateRefresh;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Storage\AuthorityTransaction;
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

    private ?AuthorityTransaction $transaction = null;

    /** @var array<string, string> */
    private array $stateKeys = [];

    /** @var array<string, bool> */
    private array $fresh = [];

    /** @var array<string, true> */
    private array $materialized = [];

    /** @var array<int|string, array{key: string, source: ProvidesGrants|ProvidesRoleGrants, items: list<Grant|RoleContribution>}> */
    private array $pending = [];

    /** @param list<Attached> $sources */
    public function __construct(private readonly PanelCatalog $static, private readonly array $sources, private readonly EvaluationFrame $initial, private readonly ?PermissionSetCache $cache = null, private readonly bool $publish = true) {}

    /** @var list<AccessScope>|null */
    private ?array $batchScopes = null;

    /** @var array<string, list<Grant|RoleContribution>> */
    private array $batchAssignments = [];

    /** @var array<string, list<Grant|RoleContribution>> */
    private array $batchSlices = [];

    /** @param list<AccessScope> $scopes */
    public function batch(array $scopes): void
    {
        $unique = [];
        foreach ($scopes as $scope) {
            $unique[IdentityCodec::compose([$scope])] = $scope;
        }
        $this->batchScopes = array_values($unique);
    }

    /** @return list<Grant|RoleContribution> */
    public function databaseContributions(AccessRequest $request, EvaluationFrame $frame): array
    {
        $items = [];
        foreach ($this->sources as ['source' => $source]) {
            if ($source instanceof DatabaseSource) {
                $this->begin($source);
                $items = [...$items, ...$this->databaseItems($source, $request, $frame)];
            }
        }

        return $items;
    }

    /** @return list<Grant|RoleContribution> */
    private function databaseItems(DatabaseSource $source, AccessRequest $request, EvaluationFrame $frame): array
    {
        if ($this->batchScopes === null) {
            $snapshot = $source->readAssignments($this->sessions[$source->id()], $request->subject(), $frame->sourceScopes(), $frame);

            return [...$snapshot['grants'], ...$snapshot['roles']];
        }
        $key = PermissionSetCache::key($this->states[$source->id()], $request->subject(), $frame->scope()->tenant, $frame->sourceScopes(),
            $source::class.':'.$source->id(), $frame->panel()->settings()->reads()->value, $this->sessions[$source->id()]->authorityIdentity(), $frame->panel()->settings()->cacheGeneration());

        if (isset($this->batchSlices[$key])) {
            return $this->batchSlices[$key];
        }
        $items = $this->transaction === null ? $this->cache?->get($key, $frame->panel(), $source->volatility(), true, $frame->now()) : null;

        if ($items !== null) {
            return $this->batchSlices[$key] = $items;
        }
        $this->beforeMaterializing($source);

        if (! array_key_exists($source->id(), $this->batchAssignments)) {
            $items = [];
            foreach (array_chunk($this->batchScopes ?? [], 100) as $chunk) {
                $snapshot = $source->readAssignments($this->sessions[$source->id()], $request->subject(), $chunk, $frame);
                foreach ([...$snapshot['grants'], ...$snapshot['roles']] as $item) {
                    if (($item instanceof Grant && $item->pattern->panel() !== $frame->panel()->id())
                        || ($item->role !== null && $item->role->panel() !== $frame->panel()->id())
                        || ! in_array(IdentityCodec::compose([$item->scope]), array_map(static fn ($scope): string => IdentityCodec::compose([$scope]), $chunk), true)) {
                        throw new InvalidSourceContributionException('Batch contribution differs from the consumed scope chunk.');
                    }
                    $items[] = $item;
                }
            }
            $this->batchAssignments[$source->id()] = $items;
        }
        $items = array_values(array_filter($this->batchAssignments[$source->id()], static fn (Grant|RoleContribution $item): bool => $frame->acceptsContributionScope($item->scope)));

        if ($this->transaction === null) {
            $this->pending[$key] = ['key' => $key, 'source' => $source, 'items' => $items];
        }

        return $this->batchSlices[$key] = $items;
    }

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
            $this->beforeMaterializing($source);
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
    public function contributions(AccessRequest $request, EvaluationFrame $frame, ?Trace $trace = null): array
    {
        $contributions = [];
        $pending = [];
        // Authority is now consumed: recognize its root before any source can reuse request memo.
        foreach ($this->sources as ['source' => $source]) {
            if ($source instanceof DatabaseSource) {
                $this->begin($source);
            }
        }
        foreach ($this->sources as ['source' => $source]) {
            if (! $source instanceof ProvidesGrants && ! $source instanceof ProvidesRoleGrants) {
                continue;
            }
            $this->begin($source);
            $authority = $source instanceof DatabaseSource ? $this->sessions[$source->id()]->authorityIdentity() : $source::class;
            $key = PermissionSetCache::key($this->states[$source->id()] ?? $this->initial->state(), $request->subject(), $frame->scope()->tenant,
                $frame->sourceScopes(), $source::class.':'.$source->id(), $frame->panel()->settings()->reads()->value, $authority, $frame->panel()->settings()->cacheGeneration());
            // Automatic role predicates consume live subject/host data on every operation.
            $liveAutomatic = $source instanceof FolderSource && array_filter(array_column($this->static->roles(), 'class'),
                static fn (string $class): bool => is_subclass_of($class, GrantedAutomatically::class)) !== [];
            $items = $liveAutomatic || $this->transaction !== null ? null : $this->cache?->get($key, $frame->panel(), $source->volatility(), $source instanceof FencesReads, $frame->now());

            if ($items !== null) {
                foreach ($items as $item) {
                    $trace?->contribution($item, $source::class);
                    $contributions[] = [$source, $item];
                }

                continue;
            }
            $this->beforeMaterializing($source);

            if ($source instanceof FolderSource) {
                $source->bindRoleClasses(array_column($this->static->roles(), 'class'));
            }

            if ($source instanceof DatabaseSource) {
                $items = $this->databaseItems($source, $request, $frame);
            } else {
                $items = [];

                if ($source instanceof ProvidesGrants) {
                    foreach (PanelCatalog::untrusted($source->grants($request->subject(), $frame->sourceScopes(), $frame)) as $item) {
                        if (! $item instanceof Grant) {
                            throw new InvalidSourceContributionException('Unexpected direct grant contribution type.');
                        }
                        // Capture declared secrets before advancing a potentially throwing lazy iterator.
                        $trace?->contribution($item, $source::class);
                        $items[] = $item;
                    }
                }

                if ($source instanceof ProvidesRoleGrants) {
                    foreach (PanelCatalog::untrusted($source->roleGrants($request->subject(), $frame->sourceScopes(), $frame)) as $item) {
                        if (! $item instanceof RoleContribution) {
                            throw new InvalidSourceContributionException('Unexpected role contribution type.');
                        }
                        $trace?->contribution($item, $source::class);
                        $items[] = $item;
                    }
                }
            }
            foreach ($items as $item) {
                if ($source instanceof DatabaseSource) {
                    $trace?->contribution($item, $source::class);
                }

                if (($item instanceof Grant && $item->pattern->panel() !== $frame->panel()->id())
                    || ($item->role !== null && $item->role->panel() !== $frame->panel()->id()) || ! $frame->acceptsContributionScope($item->scope)) {
                    throw new InvalidSourceContributionException('Contribution panel or scope differs from the request.');
                }
                $contributions[] = [$source, $item];
            }

            if (! $liveAutomatic && $this->transaction === null) {
                $pending[] = ['key' => $key, 'source' => $source, 'items' => $items];
            }
        }

        if ($this->batchScopes === null) {
            $this->pending = $pending;
        } else {
            foreach ($pending as $entry) {
                $this->pending[$entry['key']] = $entry;
            }
        }

        return $contributions;
    }

    public function confirm(EvaluationFrame $frame): EvaluationFrame
    {
        $this->transaction?->assertActive();
        $stable = true;
        foreach ($this->sources as ['source' => $source]) {
            if (! $source instanceof FencesReads || ! isset($this->states[$source->id()])) {
                continue;
            }

            if ($source instanceof DatabaseSource) {
                $this->sessions[$source->id()]->assertUsable();
            }

            if (! isset($this->materialized[$source->id()])) {
                continue;
            }
            $after = $source instanceof DatabaseSource ? $source->readState($this->sessions[$source->id()], $this->initial)
                : $source->state($this->initial->panel(), $this->initial->scope()->tenant);
            $stable = $this->states[$source->id()]->equals($after) && $stable;
        }

        if (! $stable) {
            foreach ($this->stateKeys as $key) {
                $this->cache?->forgetState($key);
            }

            throw new ReadAttemptChanged($this->initial);
        }

        foreach ($this->stateKeys as $id => $key) {
            if ($this->publish && $this->transaction === null && isset($this->states[$id])) {
                $this->cache?->rememberState($key, $this->states[$id]);
            }
        }
        foreach ($this->pending as ['key' => $key, 'source' => $source, 'items' => $items]) {
            if ($this->publish) {
                $this->cache?->put($key, $frame->panel(), $source->volatility(), $source instanceof FencesReads, $items, $frame->now());
            }
        }
        $this->pending = [];

        return $this->consumedFrame($frame);
    }

    public function discardedFrame(EvaluationFrame $frame): EvaluationFrame
    {
        return $frame->withState($this->initial->state())->withSourceStates([]);
    }

    public function consumedFrame(EvaluationFrame $frame): EvaluationFrame
    {
        $database = null;
        foreach ($this->sources as ['source' => $source]) {
            if ($source instanceof DatabaseSource && isset($this->states[$source->id()])) {
                $database = $this->states[$source->id()];
            }
        }

        return $frame->withSourceStates($this->states, $database)->withAuthorityTransaction($this->transaction);
    }

    private function begin(Source $source): void
    {
        if (! $source instanceof FencesReads || isset($this->states[$source->id()])) {
            return;
        }

        if ($source instanceof DatabaseSource) {
            $this->sessions[$source->id()] = $source->openReadSession($this->initial);
            $this->transaction ??= $this->sessions[$source->id()]->transaction();
        }
        $authority = $source instanceof DatabaseSource
            ? [$this->sessions[$source->id()]->authorityIdentity(), $this->sessions[$source->id()]->handleIdentity()]
            : [$source::class];
        // DB panel_state covers all tenants; a custom state(panel, tenant) may partition its revision.
        $partition = $source instanceof DatabaseSource ? null : $this->initial->scope()->tenant;
        $key = IdentityCodec::digest([$this->initial->panel()->id(), $this->initial->state()->fingerprint,
            $this->initial->panel()->settings()->cacheGeneration(), $partition, $source::class, $source->id(),
            $this->initial->panel()->settings()->reads()->value, $authority]);
        $this->stateKeys[$source->id()] = $key;
        $memo = $this->transaction === null && $this->initial->panel()->settings()->stateRefresh() === StateRefresh::Request ? $this->cache?->state($key) : null;
        $this->fresh[$source->id()] = $memo === null;
        $this->states[$source->id()] = $memo ?? $this->readState($source);
    }

    private function beforeMaterializing(Source $source): void
    {
        if (! $source instanceof FencesReads) {
            return;
        }

        if (! $this->fresh[$source->id()]) {
            $before = $this->readState($source);

            if (! $this->states[$source->id()]->equals($before)) {
                $this->cache?->forgetState($this->stateKeys[$source->id()]);

                throw new ReadAttemptChanged($this->initial);
            }
            $this->fresh[$source->id()] = true;
        }
        $this->materialized[$source->id()] = true;
    }

    private function readState(FencesReads $source): StateToken
    {
        return $source instanceof DatabaseSource ? $source->readState($this->sessions[$source->id()], $this->initial)
            : $source->state($this->initial->panel(), $this->initial->scope()->tenant);
    }
}
