<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Authorization\Pipeline\AccessPipeline;
use AzGuard\Authorization\Pipeline\Stages\PrepareStage;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Exceptions\ConsistencyException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\DecisionSet;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Panels\Panel;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
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
        for ($setAttempt = 0; ; $setAttempt++) {
            try {
                $prepared = $this->prepare($requests, $actor, $now, $enter, $leave, terminal: $setAttempt === 2);
            } catch (ReadAttemptChanged) {
                continue;
            }
            $groups = [];
            foreach ($prepared as $i => $entry) {
                $attempt = $entry['frame']->readAttempt;
                $groups[$attempt === null ? 'code'.$i : (string) spl_object_id($attempt)][$i] = $entry;
            }
            $decisions = [];
            $finished = [];
            foreach ($groups as $group) {
                [$results, $entries] = $this->group($group, $actor, $now, $enter, $leave);
                $decisions += $results;
                $finished += $entries;
            }
            ksort($decisions);

            try {
                $set = DecisionSet::of(...array_values($decisions));
            } catch (ConsistencyException $error) {
                if ($setAttempt < 2) {
                    continue;
                }

                $states = [];
                $conflicts = [];
                foreach ($decisions as $decision) {
                    $state = $decision->state;

                    if ($state instanceof StateToken) {
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
                        $decisions[$i] = Decision::deny(DecisionReason::ConsistencyError, $frame->state(), $frame->scope(), 'dynamic_sources');
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
    }

    /** @param list<array{Panel, AccessRequest}> $requests
     * @param  Closure(Panel, AccessRequest): void  $enter
     * @param  Closure(Panel, AccessRequest): void  $leave
     * @return array<int, Prepared>
     */
    private function prepare(array $requests, ?ActorRef $actor, DateTimeImmutable $now, Closure $enter, Closure $leave, bool $terminal = false): array
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
            } catch (ReadAttemptChanged $changed) {
                if (! $terminal) {
                    throw $changed;
                }
                $definition ??= new PermissionDefinition($request->permission()->local(), PermissionAuthority::Grants);
                $denial = Decision::deny(DecisionReason::ConsistencyError, $frame->state(), $frame->scope(), 'dynamic_sources');
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

    /** @param non-empty-array<int, Prepared> $entries
     * @param  Closure(Panel, AccessRequest): void  $enter
     * @param  Closure(Panel, AccessRequest): void  $leave
     * @return array{array<int, Decision>, array<int, Prepared>}
     */
    private function group(array $entries, ?ActorRef $actor, DateTimeImmutable $now, Closure $enter, Closure $leave): array
    {
        $keys = array_keys($entries);
        $requests = array_map(static fn (array $entry): array => [$entry['frame']->panel(), $entry['request']], array_values($entries));
        for ($retry = 0; ; $retry++) {
            $first = $entries[array_key_first($entries)];
            $attempt = $first['frame']->readAttempt;
            $decisions = [];

            try {
                if ($retry > 0) {
                    $entries = array_combine($keys, array_values($this->prepare($requests, $actor, $now, $enter, $leave, terminal: $retry === 2)));
                    $first = $entries[array_key_first($entries) ?? throw new RuntimeException('Batch retry produced no requests.')];
                    $attempt = $first['frame']->readAttempt;
                }
                $started = [];
                $consuming = [];
                $scopes = [];
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
                $attempt?->batch(array_values($scopes));
                $this->roleWitnesses($consuming);
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
                        $frame = $entry['frame']->sourceStates === [] && ! $entry['frame']->dynamicRead ? $entry['frame'] : $attempt->consumedFrame($entry['frame']);
                        $entries[$i]['frame'] = $frame;
                        $decision = $decisions[$i];
                        $decisions[$i] = $decision->allowed()
                            ? Decision::allow($decision->reason, $frame->state(), $decision->scope, $decision->component, $decision->grants, $decision->message, $decision->status, $decision->code)
                            : Decision::deny($decision->reason, $frame->state(), $decision->scope, $decision->component, $decision->message, $decision->status, $decision->code);
                    }
                }

                return [$decisions, $entries];
            } catch (ReadAttemptChanged) {
                if ($retry === 2) {
                    foreach ($entries as $i => $entry) {
                        $frame = $attempt?->discardedFrame($entry['frame']) ?? $entry['frame'];
                        $entries[$i]['frame'] = $frame;
                        $decisions[$i] = ($started[$i][0] ?? null) !== null && ! $frame->dynamicRead ? $started[$i][0]
                            : Decision::deny(DecisionReason::ConsistencyError, $frame->state(), $frame->scope(), 'dynamic_sources');
                    }

                    return [$decisions, $entries];
                }

            } catch (Throwable $error) {
                foreach ($entries as $i => $entry) {
                    $entry['trace']->error('state', 'source_error', 'dynamic_sources', $error);
                    $decisions[$i] = ($started[$i][0] ?? null) !== null && ! $entry['frame']->dynamicRead ? $started[$i][0]
                        : Decision::deny(DecisionReason::SourceError, $entry['frame']->state(), $entry['frame']->scope(), 'dynamic_sources');
                }

                return [$decisions, $entries];
            }
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
            } catch (ReadAttemptChanged $error) {
                throw $error;
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
