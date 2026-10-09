<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Authorization\Cache\PermissionSetCache;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Contracts\Sources\AssignmentScopeSelection;
use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Contracts\Sources\FiltersQueries;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Exceptions\ConsistencyException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Panels\StateRefresh;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Sources\PanelSources;
use AzGuard\Storage\AuthorityTransaction;
use AzGuard\Storage\StorageReadSession;
use Closure;
use Throwable;

/**
 * @internal One operation's consumed source revisions and pinned handles; never shared or cached.
 *
 * The boundary of a consistent read (audits/2026-10-09-consistency-design.md, step 1): the engine prepares host inputs
 * once, this attempt materializes source data, and the engine evaluates once. Each materialization reads a source at
 * one state (its own bounded retry repeats raw source reads only); it never asks the engine to run the pipeline again,
 * so host hooks, model events and policies run once per operation. A decision reflects the sources at read time.
 *
 * @phpstan-import-type Attached from PanelSources
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

    /** @var array<string, StateToken> */
    private array $fresh = [];

    /** @var array<string, string> memo key of the observed state of the database source, by source id */
    private array $memoKeys = [];

    /** @var array<string, StateToken> the state each fenced source was materialized at, by source id */
    private array $observed = [];

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

    /** @var array<string, Throwable> */
    private array $batchFailures = [];

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
            [$state, $snapshot] = $source->materializeAssignments($this->sessions[$source->id()], $frame, $request->subject(), $frame->sourceScopes(), $this->unread($source));
            $this->observe($source, $state);

            return [...$snapshot['grants'], ...$snapshot['roles']];
        }

        if (isset($this->batchFailures[$source->id()])) {
            // One failed batch read fails every request of the group; it is not read again per request.
            throw $this->batchFailures[$source->id()];
        }
        $key = $this->key($source, $request, $frame);

        if (isset($this->batchSlices[$key])) {
            return $this->batchSlices[$key];
        }
        $items = $this->transaction === null && $this->cache !== null ? $this->cache->get($key, $frame->panel(), $source->volatility(), true, $frame->now()) : null;

        if ($items !== null) {
            return $this->batchSlices[$key] = $items;
        }

        if (! array_key_exists($source->id(), $this->batchAssignments)) {
            $scopes = $this->batchScopes ?? [];

            try {
                [$state, $snapshot] = $source->materializeAssignments($this->sessions[$source->id()], $frame, $request->subject(), $scopes, $this->unread($source));
            } catch (ConsistencyException $error) {
                throw $this->batchFailures[$source->id()] = $error;
            }
            $this->observe($source, $state);
            $this->acceptBatch($source, $frame->panel()->id(), $scopes, $snapshot);
        }
        $items = array_values(array_filter($this->batchAssignments[$source->id()], static fn (Grant|RoleContribution $item): bool => $frame->acceptsContributionScope($item->scope)));

        if ($this->transaction === null && $this->cache !== null) {
            $published = $this->key($source, $request, $frame);
            $this->pending[$published] = ['key' => $published, 'source' => $source, 'items' => $items];
        }

        return $this->batchSlices[$key] = $items;
    }

    /**
     * @param  list<AccessScope>  $scopes
     * @param  array{grants: list<Grant>, roles: list<RoleContribution>}  $snapshot
     */
    private function acceptBatch(DatabaseSource $source, string $panel, array $scopes, array $snapshot): void
    {
        $consumed = array_flip(array_map(static fn (AccessScope $scope): string => IdentityCodec::compose([$scope]), $scopes));
        $items = [];
        foreach ([...$snapshot['grants'], ...$snapshot['roles']] as $item) {
            if (($item instanceof Grant && $item->pattern->panel() !== $panel)
                || ($item->role !== null && $item->role->panel() !== $panel)
                || ! isset($consumed[IdentityCodec::compose([$item->scope])])) {
                throw new InvalidSourceContributionException('Batch contribution differs from the consumed scopes.');
            }
            $items[] = $item;
        }
        $this->batchAssignments[$source->id()] = $items;
    }

    /**
     * One snapshot for a DecisionSet (audits/2026-10-09-consistency-design.md, step 5): the database reads of every
     * batch attempt that shares a storage connection run in one read-only transaction: the observed state of each
     * subject, the cache lookups of its requests and the raw rows of the subjects with a miss. Nothing of the host
     * runs inside; rows are hydrated after COMMIT. Each attempt then evaluates from what was read, so all its
     * decisions and those of the other subjects match one database state. A failed read fails the attempts of that
     * snapshot; an attempt inside an authority transaction or a test baseline keeps its own read.
     *
     * @param  list<array{AccessRequest, EvaluationFrame}>  $entries
     */
    public static function readMany(array $entries): void
    {
        $plans = [];
        foreach ($entries as [$request, $frame]) {
            $attempt = $frame->readAttempt;

            if ($attempt === null || $attempt->batchScopes === null) {
                continue;
            }
            $plans[spl_object_id($attempt)]['attempt'] = $attempt;
            $plans[spl_object_id($attempt)]['requests'][] = [$request, $frame];
        }
        $groups = [];
        foreach ($plans as ['attempt' => $attempt, 'requests' => $requests]) {
            foreach ($attempt->sources as ['source' => $source]) {
                if (! $source instanceof DatabaseSource) {
                    continue;
                }

                try {
                    $attempt->begin($source);
                    $session = $attempt->sessions[$source->id()];

                    if ($attempt->transaction !== null || ! $session->canSnapshot() || array_key_exists($source->id(), $attempt->batchAssignments)) {
                        continue;
                    }
                    $source->prepareAssignments($session, $attempt->initial);
                    $subject = $requests[0][0]->subject();
                    $attempt->memoKeys[$source->id()] = IdentityCodec::digest([$attempt->stateKeys[$source->id()], $subject]);

                    if ($attempt->servedByMemo($source, $requests)) {
                        continue;
                    }
                } catch (Throwable $error) {
                    $attempt->batchFailures[$source->id()] = $error;

                    continue;
                }
                $groups[$session->authorityIdentity()."\0".$session->handleIdentity()][] = [$attempt, $source, $requests[0][0]->subject(), $requests];
            }
        }
        foreach ($groups as $members) {
            $session = $members[0][0]->sessions[$members[0][1]->id()];

            try {
                $read = $session->snapshot(static function () use ($members, $session): array {
                    $read = [];
                    foreach ($members as $n => [$attempt, $source, $subject, $requests]) {
                        $observed = $source->readObserved($session, $attempt->initial, $subject);
                        $hits = [];
                        $miss = $attempt->cache === null;
                        foreach ($requests as [$request, $frame]) {
                            $key = $attempt->keyAt($observed, $source, $request, $frame);
                            $items = $attempt->cache?->get($key, $frame->panel(), $source->volatility(), true, $frame->now());
                            $miss = $miss || $items === null;

                            if ($items !== null) {
                                $hits[$key] = $items;
                            }
                        }
                        $read[$n] = [$observed, $hits, $miss ? $source->assignmentRows($session, $attempt->initial, $subject, $attempt->batchScopes ?? []) : null];
                    }

                    return $read;
                });
            } catch (Throwable $error) {
                foreach ($members as [$attempt, $source]) {
                    $attempt->batchFailures[$source->id()] = $error;
                }

                continue;
            }
            foreach ($members as $n => [$attempt, $source, $subject]) {
                [$observed, $hits, $rows] = $read[$n];

                try {
                    $attempt->observe($source, $observed);
                    $attempt->batchSlices = [...$attempt->batchSlices, ...$hits];

                    if ($rows !== null) {
                        $attempt->acceptBatch($source, $attempt->initial->panel()->id(), $attempt->batchScopes ?? [],
                            $source->hydrateAssignments($attempt->sessions[$source->id()], $attempt->initial, $subject, $rows));
                    }
                } catch (Throwable $error) {
                    $attempt->batchFailures[$source->id()] = $error;
                }
            }
        }
    }

    /**
     * With `state_refresh = request`, a subject whose observed state this request already read and whose every
     * request hits the cache under it needs no read at all: the relaxed mode of the panel, as for a single check.
     *
     * @param  list<array{AccessRequest, EvaluationFrame}>  $requests
     */
    private function servedByMemo(DatabaseSource $source, array $requests): bool
    {
        $memo = $this->memoized($this->memoKeys[$source->id()] ?? '');

        // A state this attempt already read (the dynamic catalog) is newer than a request memo.
        if ($memo === null || $memo->subjectRevision === null || $this->cache === null || isset($this->states[$source->id()])) {
            return false;
        }
        $hits = [];
        foreach ($requests as [$request, $frame]) {
            $key = $this->keyAt($memo, $source, $request, $frame);
            $items = $this->cache->get($key, $frame->panel(), $source->volatility(), true, $frame->now());

            if ($items === null) {
                return false;
            }
            $hits[$key] = $items;
        }
        $this->states[$source->id()] = $memo;
        $this->batchSlices = [...$this->batchSlices, ...$hits];

        return true;
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

            if ($source instanceof DatabaseSource) {
                [$state, $items] = $source->materializePermissions($this->sessions[$source->id()], $this->initial, $this->initial->scope()->tenant, $this->unread($source));
                $this->observe($source, $state);
            } else {
                $items = $this->fenced($source, fn (): array => [...PanelCatalog::untrusted($source->permissions($this->initial->panel(), $this->initial->scope()->tenant))]);
            }
            foreach ($items as $item) {
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
            // Automatic role predicates consume live subject/host data on every operation.
            $liveAutomatic = $source instanceof FolderSource && array_filter(array_column($this->static->roles(), 'class'),
                static fn (string $class): bool => is_subclass_of($class, GrantedAutomatically::class)) !== [];
            $cache = $liveAutomatic || $this->transaction !== null ? null : $this->cache;
            $cached = $cache !== null;
            $items = $cache?->get($this->key($source, $request, $frame), $frame->panel(), $source->volatility(), $source instanceof FencesReads, $frame->now());

            if ($items !== null) {
                foreach ($items as $item) {
                    $trace?->contribution($item, $source::class);
                    $contributions[] = [$source, $item];
                }

                continue;
            }

            if ($source instanceof FolderSource) {
                $source->bindRoleClasses(array_column($this->static->roles(), 'class'));
            }

            if ($source instanceof DatabaseSource) {
                $items = $this->databaseItems($source, $request, $frame);
            } else {
                $items = $this->fenced($source, fn (): array => $this->external($source, $request, $frame, $trace));
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

            if ($cached) {
                // Published under the state the items were read at, not the state the lookup was keyed by.
                $published = $this->key($source, $request, $frame);
                $pending[] = ['key' => $published, 'source' => $source, 'items' => $items];
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

    /**
     * Accepts the attempt: publishes what it read to the cache under the states it was read at. No source is read
     * again: a write after the materialization does not discard the decision (it reflects the sources at read time).
     */
    public function confirm(EvaluationFrame $frame): EvaluationFrame
    {
        $this->transaction?->assertActive();
        foreach ($this->sessions as $session) {
            $session->assertUsable();
        }

        foreach ($this->stateKeys as $id => $key) {
            $key = $this->memoKeys[$id] ?? $key;
            $state = $this->states[$id] ?? null;

            if ($this->publish && $this->transaction === null && $state !== null && (! isset($this->memoKeys[$id]) || $state->subjectRevision !== null)) {
                $this->cache?->rememberState($key, $state);
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

    /** @return list<array{Source, Grant|RoleContribution}> */
    public function selections(AccessRequest $request, EvaluationFrame $frame, string $type): array
    {
        $result = [];
        $allRefs = [];
        foreach ($this->sources as ['source' => $source]) {
            if (! $source instanceof ProvidesGrants && ! $source instanceof ProvidesRoleGrants) {
                continue;
            }

            if (! $source instanceof FiltersQueries) {
                throw new VisibilityNotSupportedException('source_error', $source::class);
            }
            $this->begin($source);

            if ($source instanceof FolderSource) {
                $source->bindRoleClasses(array_column($this->static->roles(), 'class'));
            }

            if ($source instanceof DatabaseSource) {
                [$state, $selection] = $source->materializeSelection($this->sessions[$source->id()], $request->subject(), $request->permission(), $type, $frame, $this->unread($source));
                $this->observe($source, $state);
            } else {
                $selection = $this->fenced($source, fn (): ?AssignmentScopeSelection => $source->contextsCovering($request->subject(), $request->permission(), $type, $frame));
            }

            if ($selection === null) {
                throw new VisibilityNotSupportedException('unsupported_selection', $source::class);
            }
            $refs = [];
            foreach ($selection->refs() as $ref) {
                if ($ref->type() !== $type) {
                    throw new InvalidSourceContributionException('Selection reference has a different scope type.');
                }
                $refs[$ref->key()] = true;
                $allRefs[$ref->key()] = true;
            }

            // A materialized selection never joins host rows with assignment tables.
            if (count($allRefs) > 1000) {
                throw new VisibilityNotSupportedException('selection_budget', $source::class);
            }
            foreach ($selection->contributions() as $item) {
                $ref = $item->scope->context;

                if (! $ref->isGlobal()) {
                    $allRefs[$ref->key()] = true;

                    if (count($allRefs) > 1000) {
                        throw new VisibilityNotSupportedException('selection_budget', $source::class);
                    }
                }

                if ($item->source !== $source->id()
                    || ($item instanceof Grant && $item->pattern->panel() !== $frame->panel()->id())
                    || ($item->role !== null && $item->role->panel() !== $frame->panel()->id())
                    || (! $ref->isGlobal() && $ref->type() !== $type)
                    || (! $ref->isGlobal() && ! $selection->isEverywhere() && ! isset($refs[$ref->key()]))
                    || ($ref->isGlobal() && ! $selection->isEverywhere())
                    || (! $item->scope->tenant->equals($frame->scope()->tenant) && ! $item->scope->tenant->isGlobal())) {
                    throw new InvalidSourceContributionException('Selection witness differs from its source, panel or scope.');
                }
                $result[] = [$source, $item];
            }
        }

        return $result;
    }

    public function discardedFrame(EvaluationFrame $frame): EvaluationFrame
    {
        return $frame->withState($this->initial->state())->withSourceStates([]);
    }

    public function consumedFrame(EvaluationFrame $frame): EvaluationFrame
    {
        $database = null;
        foreach ($this->sources as ['source' => $source]) {
            if ($source instanceof FencesReads && isset($this->stateKeys[$source->id()]) && ! isset($this->states[$source->id()])) {
                $this->states[$source->id()] = $this->readState($source);
            }

            if ($source instanceof DatabaseSource && isset($this->states[$source->id()])) {
                $database = $this->states[$source->id()];
            }
        }

        return $frame->withSourceStates($this->states, $database)->withAuthorityTransaction($this->transaction);
    }

    private function begin(Source $source): void
    {
        if (! $source instanceof FencesReads || isset($this->stateKeys[$source->id()])) {
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

        if ($source instanceof DatabaseSource) {
            // The observed state of the database source is read with the subject's revision when a key needs it.
            return;
        }
        $memo = $this->memoized($key);

        // Without a cache no lookup needs the state before the read: the materialization reads it.
        if ($memo !== null) {
            $this->states[$source->id()] = $memo;
        } elseif ($this->cache !== null) {
            $this->states[$source->id()] = $this->fresh[$source->id()] = $this->readState($source);
        }
    }

    /** A state read on the source's session by this attempt and not yet used as the start of a read fence. */
    private function unread(Source $source): ?StateToken
    {
        $state = $this->fresh[$source->id()] ?? null;
        unset($this->fresh[$source->id()]);

        return $state;
    }

    private function memoized(string $key): ?StateToken
    {
        return $this->transaction === null && $this->initial->panel()->settings()->stateRefresh() === StateRefresh::Request ? $this->cache?->state($key) : null;
    }

    /**
     * The cache key of a source's contributions at the state the attempt holds for it now. For the database source
     * that state is the observed state of the subject (one statement: panel state and subject revision), and the key
     * is its ContributionKey; the decision reports that observed state, also on a cache hit.
     */
    private function key(Source $source, AccessRequest $request, EvaluationFrame $frame): string
    {
        $authority = $source instanceof DatabaseSource ? $this->sessions[$source->id()]->authorityIdentity() : $source::class;

        if ($source instanceof DatabaseSource && ($this->states[$source->id()]->subjectRevision ?? null) === null) {
            $this->begin($source);
            $memoKey = $this->memoKeys[$source->id()] = IdentityCodec::digest([$this->stateKeys[$source->id()], $request->subject()]);
            // A state this attempt already read (the dynamic catalog) is newer than a request memo.
            $this->states[$source->id()] = (isset($this->states[$source->id()]) ? null : $this->memoized($memoKey))
                ?? ($this->fresh[$source->id()] = $source->readObserved($this->sessions[$source->id()], $this->initial, $request->subject()));
        } elseif ($source instanceof FencesReads && ! isset($this->states[$source->id()])) {
            $this->states[$source->id()] = $this->fresh[$source->id()] = $this->readState($source);
        }

        return $this->keyAt($this->states[$source->id()] ?? $this->initial->state(), $source, $request, $frame, $authority);
    }

    private function keyAt(StateToken|CodeStateToken $state, Source $source, AccessRequest $request, EvaluationFrame $frame, ?string $authority = null): string
    {
        $authority ??= $source instanceof DatabaseSource ? $this->sessions[$source->id()]->authorityIdentity() : $source::class;

        return PermissionSetCache::key($state, $request->subject(), $frame->scope()->tenant, $frame->sourceScopes(),
            $source::class.':'.$source->id(), $frame->panel()->settings()->reads()->value, $authority, $frame->panel()->settings()->cacheGeneration());
    }

    /**
     * Records the state a source was materialized at. Two reads of one source in one attempt (the dynamic catalog,
     * then the contributions) must agree, otherwise the attempt fails with ConsistencyException (a bounded
     * consistency_error, nothing runs again). For the database source they agree when incarnation and epoch are
     * equal: definitions change only with the epoch, and a grant of another subject moves the version alone. Another
     * FencesReads source is opaque, so its states must be equal.
     */
    private function observe(Source $source, StateToken $state): void
    {
        $previous = $this->observed[$source->id()] ?? null;

        if ($previous !== null && ! ($source instanceof DatabaseSource ? self::sameEpoch($previous, $state) : $previous->equals($state))) {
            throw new ConsistencyException('The source changed between two reads of one operation.');
        }
        $this->observed[$source->id()] = $state;
        $this->states[$source->id()] = $state;
    }

    private static function sameEpoch(StateToken $a, StateToken $b): bool
    {
        return $a->storageId === $b->storageId && $a->panel === $b->panel && $a->incarnation === $b->incarnation
            && $a->epoch === $b->epoch && $a->generation === $b->generation && $a->fingerprint === $b->fingerprint;
    }

    /**
     * A bounded fence around one read of a source that is not the database source: the state before, the read, the
     * state after; up to three attempts, then ConsistencyException. Only the source read repeats.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    private function fenced(Source $source, Closure $read): mixed
    {
        if (! $source instanceof FencesReads) {
            return $read();
        }
        $earlier = $this->unread($source);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $before = $attempt === 0 && $earlier !== null ? $earlier : $source->state($this->initial->panel(), $this->initial->scope()->tenant);
            $result = $read();

            if ($before->equals($source->state($this->initial->panel(), $this->initial->scope()->tenant))) {
                $this->observe($source, $before);

                return $result;
            }
        }

        throw new ConsistencyException('Source authority changed during all three read attempts.');
    }

    /** @return list<Grant|RoleContribution> */
    private function external(Source $source, AccessRequest $request, EvaluationFrame $frame, ?Trace $trace): array
    {
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

        return $items;
    }

    private function readState(FencesReads $source): StateToken
    {
        return $source instanceof DatabaseSource ? $source->readState($this->sessions[$source->id()], $this->initial)
            : $source->state($this->initial->panel(), $this->initial->scope()->tenant);
    }
}
