<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Authorization\Pipeline\AccessPipeline;
use AzGuard\Authorization\Pipeline\Stages\PrepareStage;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Exceptions\ConsistencyException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\DecisionSet;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Panels\Panel;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Throwable;

/** @internal Integrates the scalar stages against one grouped raw authority attempt.
 * @phpstan-type Prepared array{request: AccessRequest, catalog: PanelCatalog, definition: PermissionDefinition, frame: EvaluationFrame, denial: ?Decision, trace: Trace}
 */
final readonly class BatchEvaluation
{
    public function __construct(private PrepareStage $prepare, private AccessPipeline $pipeline, private Container $container) {}

    /** @param list<array{Panel, AccessRequest}> $requests
     * @param  Closure(Panel, AccessRequest): void  $enter
     * @param  Closure(Panel, AccessRequest): void  $leave
     */
    public function evaluate(array $requests, ?ActorRef $actor, DateTimeImmutable $now, Closure $enter, Closure $leave): DecisionSet
    {
        if ($requests === []) {
            return DecisionSet::of();
        }
        // Different subjects may consume the same storage/panel revision. Preserve DecisionSet's frozen contract.
        // Host inputs are prepared once; every group reads its sources consistently and is evaluated once.
        $prepared = $this->prepare($requests, $actor, $now, $enter, $leave);
        $groups = [];
        foreach ($prepared as $i => $entry) {
            $attempt = $entry['frame']->readAttempt;
            $groups[$attempt === null ? 'code'.$i : (string) spl_object_id($attempt)][$i] = $entry;
        }
        // Every group starts (admission, before hooks) first; then the database reads of all requests that consume
        // authority run in one snapshot per storage connection (design doc, step 5); then each group evaluates.
        $starts = [];
        $consuming = [];
        foreach ($groups as $g => $group) {
            $starts[$g] = $this->start($group, $enter, $leave);
            foreach (is_array($starts[$g]) ? $starts[$g]['consuming'] : [] as $entry) {
                $consuming[] = [$entry['request'], $entry['frame']];
            }
        }
        ReadAttempt::readMany($consuming);
        $decisions = [];
        $finished = [];
        foreach ($groups as $g => $group) {
            [$results, $entries] = $this->group($group, $enter, $leave, $starts[$g]);
            $decisions += $results;
            $finished += $entries;
        }
        ksort($decisions);

        try {
            $set = DecisionSet::of(...array_values($decisions));
        } catch (ConsistencyException) {
            // Decisions of one storage/panel read at different states cannot share the set: they are denied, not
            // evaluated again with new host inputs.
            $states = [];
            $conflicts = [];
            foreach ($decisions as $decision) {
                $state = $decision->state;

                if ($state instanceof StateToken) {
                    $state = $state->panelState();
                    $key = IdentityCodec::compose([$state->storageId, $state->panel]);

                    if (isset($states[$key]) && ! $states[$key]->equals($state)) {
                        $conflicts[$key] = true;
                    }
                    $states[$key] = $state;
                }
            }
            foreach ($decisions as $i => $decision) {
                $state = $decision->state;

                if ($state instanceof StateToken && isset($conflicts[IdentityCodec::compose([$state->storageId, $state->panel])])) {
                    $frame = $finished[$i]['frame'];
                    $frame = $frame->readAttempt?->discardedFrame($frame) ?? $frame;
                    $finished[$i] = array_replace($finished[$i], ['frame' => $frame]);
                    $decisions[$i] = Decision::failed(DecisionReason::ConsistencyError, $frame->state(), $frame->scope(), 'dynamic_sources');
                }
            }
            $set = DecisionSet::of(...array_values($decisions));
        }
        ksort($finished);
        foreach ($finished as $i => $entry) {
            $enter($entry['frame']->panel(), $entry['request']);

            try {
                $this->pipeline->complete($entry['request'], $entry['frame'], $decisions[$i], $entry['trace'], confirm: false);
            } finally {
                $leave($entry['frame']->panel(), $entry['request']);
            }
        }

        return $set;
    }

    /** @param list<array{Panel, AccessRequest}> $requests
     * @param  Closure(Panel, AccessRequest): void  $enter
     * @param  Closure(Panel, AccessRequest): void  $leave
     * @return array<int, Prepared>
     */
    private function prepare(array $requests, ?ActorRef $actor, DateTimeImmutable $now, Closure $enter, Closure $leave): array
    {
        $batch = new BatchInputs($this->container);
        $batch->loadSubjects($requests, $actor);
        $raw = [];
        $witnesses = [];
        foreach ($requests as $i => [$panel, $request]) {
            $trace = new Trace($request->isTraced());
            $enter($panel, $request);

            try {
                $raw[$i] = [...$this->prepare->inputs($panel, $request, $actor, $trace, $now, $batch), $trace];
            } finally {
                $leave($panel, $request);
            }
            $witnesses[] = [$request, $raw[$i][2]];
        }
        $batch->loadScopes($witnesses);
        $entries = [];
        foreach ($raw as $i => [$catalog, $definition, $frame, $denial, $trace]) {
            $request = $requests[$i][1];
            $enter($frame->panel(), $request);

            try {
                [$catalog, $definition, $frame, $denial] = $this->prepare->complete($request, $catalog, $definition, $frame, $denial, $trace, $batch);
            } finally {
                $leave($frame->panel(), $request);
            }
            $entries[$i] = ['request' => $request, 'catalog' => $catalog, 'definition' => $definition, 'frame' => $frame, 'denial' => $denial, 'trace' => $trace];
        }
        $scopes = [];
        $attempts = [];
        foreach ($entries as $entry) {
            $attempt = $entry['frame']->readAttempt;

            if ($attempt !== null) {
                $id = spl_object_id($attempt);
                $attempts[$id] = $attempt;
                foreach ($entry['frame']->sourceScopes() as $scope) {
                    $scopes[$id][IdentityCodec::compose([$scope])] = $scope;
                }
            }
        }
        foreach ($attempts as $id => $attempt) {
            $attempt->batch(array_values($scopes[$id]));
        }

        return $entries;
    }

    /**
     * Starts every request of a group (admission, before hooks) and narrows the attempt's batch to the scopes of
     * the requests that consume authority. A failure is handed to the group, which degrades it.
     *
     * @param  non-empty-array<int, Prepared>  $entries
     * @param  Closure(Panel, AccessRequest): void  $enter
     * @param  Closure(Panel, AccessRequest): void  $leave
     * @return array{started: array<int, array{?Decision, list<Restriction>, ?Decision}>, consuming: array<int, Prepared>}|Throwable
     */
    private function start(array $entries, Closure $enter, Closure $leave): array|Throwable
    {
        $started = [];
        $consuming = [];
        $scopes = [];

        try {
            foreach ($entries as $i => $entry) {
                $enter($entry['frame']->panel(), $entry['request']);

                try {
                    $started[$i] = $this->pipeline->start($entry['request'], $entry['frame'], $entry['trace'], $entry['denial']);
                } finally {
                    $leave($entry['frame']->panel(), $entry['request']);
                }

                if ($started[$i][0] === null) {
                    $consuming[$i] = $entry;
                    foreach ($entry['frame']->sourceScopes() as $scope) {
                        $scopes[IdentityCodec::compose([$scope])] = $scope;
                    }
                }
            }
            $entries[array_key_first($entries)]['frame']->readAttempt?->batch(array_values($scopes));
        } catch (Throwable $error) {
            return $error;
        }

        return ['started' => $started, 'consuming' => $consuming];
    }

    /** @param non-empty-array<int, Prepared> $entries
     * @param  Closure(Panel, AccessRequest): void  $enter
     * @param  Closure(Panel, AccessRequest): void  $leave
     * @param  array{started: array<int, array{?Decision, list<Restriction>, ?Decision}>, consuming: array<int, Prepared>}|Throwable  $start
     * @return array{array<int, Decision>, array<int, Prepared>}
     */
    private function group(array $entries, Closure $enter, Closure $leave, array|Throwable $start): array
    {
        $first = $entries[array_key_first($entries)];
        $attempt = $first['frame']->readAttempt;
        $decisions = [];
        $started = is_array($start) ? $start['started'] : [];

        try {
            if ($start instanceof Throwable) {
                throw $start;
            }
            $this->roleWitnesses($start['consuming']);
            foreach ($entries as $i => $entry) {
                $enter($entry['frame']->panel(), $entry['request']);

                try {
                    $decisions[$i] = $this->pipeline->evaluate($entry['request'], $entry['frame'], $entry['catalog'], $entry['definition'], $entry['trace'], $entry['denial'], deferred: true, capture: function (EvaluationFrame $frame) use (&$entries, $i, $entry): void {
                        $entries[$i] = array_replace($entry, ['frame' => $frame]);
                    }, started: $started[$i]);
                } finally {
                    $leave($entry['frame']->panel(), $entry['request']);
                }
            }

            if ($attempt !== null) {
                $attempt->confirm($first['frame']);
                foreach ($entries as $i => $entry) {
                    $frame = match (true) {
                        // A read that stayed inconsistent consumed no state.
                        $decisions[$i]->reason === DecisionReason::ConsistencyError => $attempt->discardedFrame($entry['frame']),
                        $entry['frame']->sourceStates === [] && ! $entry['frame']->dynamicRead => $entry['frame'],
                        default => $attempt->consumedFrame($entry['frame']),
                    };
                    $entries[$i]['frame'] = $frame;
                    $decision = $decisions[$i];
                    $decisions[$i] = $decision->allowed()
                        ? Decision::allow($decision->reason, $frame->state(), $decision->scope, $decision->component, $decision->grants, $decision->message, $decision->status, $decision->code)
                        : Decision::deny($decision->reason, $frame->state(), $decision->scope, $decision->component, $decision->message, $decision->status, $decision->code);
                }
            }

            return [$decisions, $entries];
        } catch (Throwable $error) {
            // A configuration or input error is the caller's, exactly as in decide(); only source failures degrade.
            if ($error instanceof DefinitionException || $error instanceof UnknownPermissionException
                || ($error instanceof InvalidConfigurationException && $error->code() !== 'invalid_configuration.authority_transaction')) {
                throw $error;
            }

            $reason = $error instanceof ConsistencyException ? DecisionReason::ConsistencyError : DecisionReason::SourceError;
            foreach ($entries as $i => $entry) {
                $entry['trace']->error('state', $reason->value, 'dynamic_sources', $error);
                $decisions[$i] = ($started[$i][0] ?? null) !== null && ! $entry['frame']->dynamicRead ? $started[$i][0]
                    : Decision::failed($reason, $entry['frame']->state(), $entry['frame']->scope(), 'dynamic_sources');
            }

            return [$decisions, $entries];
        }
    }

    /** @param array<int, Prepared> $entries */
    private function roleWitnesses(array $entries): void
    {
        $seen = [];
        $scopes = [];
        foreach ($entries as $entry) {
            $frame = $entry['frame'];

            if ($frame->readAttempt === null || $frame->scope()->context->isGlobal()) {
                continue;
            }

            $scopeKey = IdentityCodec::compose([$entry['request']->subject(), $frame->panel()->id(), $frame->scope()]);

            if (isset($scopes[$scopeKey])) {
                continue;
            }
            $scopes[$scopeKey] = true;

            try {
                $contributions = $frame->readAttempt->databaseContributions($entry['request'], $frame);
            } catch (Throwable) {
                // The scalar authority stage owns the source failure and its component/reason.
                continue;
            }
            foreach ($contributions as $item) {
                $role = $item->role === null ? null : ($entry['catalog']->roles()[$item->role->key()] ?? null);

                try {
                    $instance = $role === null ? null : $this->container->make($role['class']);
                } catch (Throwable) {
                    continue;
                }
                $branch = $frame->forContribution($item, $instance);
                $runtime = (new ScopeEligibility($this->container))->runtime($entry['request']->subject(), $branch);
                $definition = $frame->panel()->scopeDefinition($frame->scope()->context->type() ?? '');

                if ($definition !== null) {
                    $key = $frame->batchInputs?->eligibilityKey($definition, $runtime) ?? '';

                    if (! isset($seen[$key])) {
                        $seen[$key] = true;
                        $frame->batchInputs?->addWitness($entry['request'], $branch);
                    }
                }
            }
        }
    }
}
