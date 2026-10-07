<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Authorization\Cache\PermissionSetCache;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Scopes\Query\EligibilityBuilder;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/** @internal Fresh host inputs and eligibility witnesses of one batch attempt; never request memo.
 * @phpstan-import-type Attached from \AzGuard\Sources\PanelSources
 */
final class BatchInputs
{
    /** @var array<string, Model|null|Throwable> */
    private array $subjects = [];

    /** @var array<string, ResolvedAssignmentScope|null|Throwable> */
    private array $scopes = [];

    /** @var array<string, ReadAttempt> */
    private array $attempts = [];

    /** @var list<array{AccessRequest, EvaluationFrame}> */
    private array $witnesses = [];

    /** @var array<string, bool|Throwable> */
    private array $eligibility = [];

    public function __construct(private readonly Container $container) {}

    /** @param list<array{Panel, AccessRequest}> $requests */
    public function loadSubjects(array $requests, ?ActorRef $actor): void
    {
        $groups = [];
        foreach ($requests as [$panel, $request]) {
            $refs = [$request->subject()];

            if ($actor?->id !== null) {
                $refs[] = SubjectRef::of($actor->type, $actor->id);
            }
            foreach ($refs as $ref) {
                $key = self::subjectKey($panel, $ref);
                $this->subjects[$key] = null;
                foreach ($panel->subjectModels() as $class) {
                    if ((new $class)->getMorphClass() === $ref->type()) {
                        $groups[$class][$key] = $ref;

                        break;
                    }
                }
            }
        }
        foreach ($groups as $class => $refs) {
            foreach (array_chunk($refs, 100, true) as $chunk) {
                try {
                    $models = (new $class)->newQuery()->whereKey(array_map(static fn (SubjectRef $ref): string => $ref->id(), $chunk))->get()->keyBy(static fn (Model $model): string => (string) $model->getKey());
                    foreach ($chunk as $key => $ref) {
                        $this->subjects[$key] = $models->get($ref->id());
                    }
                } catch (Throwable $error) {
                    foreach ($chunk as $key => $ref) {
                        $this->subjects[$key] = $error;
                    }
                }
            }
        }
    }

    public function subject(Panel $panel, SubjectRef $ref): ?Model
    {
        $subject = $this->subjects[self::subjectKey($panel, $ref)] ?? null;

        if ($subject instanceof Throwable) {
            throw $subject;
        }

        return $subject;
    }

    /** @param list<array{AccessRequest, EvaluationFrame}> $witnesses */
    public function loadScopes(array $witnesses): void
    {
        $this->witnesses = $witnesses;
        $groups = [];
        foreach ($witnesses as [$request, $frame]) {
            if ($frame->scope()->context->isGlobal()) {
                continue;
            }
            $definition = $frame->panel()->scopeDefinition($frame->scope()->context->type() ?? '');

            if ($definition !== null) {
                $groups[spl_object_id($definition)]['definition'] = $definition;
                $groups[spl_object_id($definition)]['frames'][self::scopeKey($frame)] = $frame;
            }
        }
        foreach ($groups as ['definition' => $definition, 'frames' => $frames]) {
            if (! $definition instanceof QueryableAssignmentScopeDefinition) {
                foreach ($frames as $key => $frame) {
                    try {
                        $this->scopes[$key] = $definition->resolve($frame->scope()->context);
                    } catch (Throwable $error) {
                        $this->scopes[$key] = $error;
                    }
                }

                continue;
            }
            foreach (array_chunk($frames, 100, true) as $chunk) {
                try {
                    $records = $definition->query()->whereKey(array_map(static fn (EvaluationFrame $frame): ?string => $frame->scope()->context->id(), $chunk))->get()->keyBy(static fn (Model $model): string => (string) $model->getKey());
                    foreach ($chunk as $key => $frame) {
                        try {
                            $record = $records->get($frame->scope()->context->id());
                            $this->scopes[$key] = $record === null ? null : new ResolvedAssignmentScope($frame->scope()->context, $definition->tenantOf($record), $record);
                        } catch (Throwable $error) {
                            $this->scopes[$key] = $error;
                        }
                    }
                } catch (Throwable $error) {
                    foreach ($chunk as $key => $frame) {
                        $this->scopes[$key] = $error;
                    }
                }
            }
        }
    }

    public function scope(EvaluationFrame $frame): ?ResolvedAssignmentScope
    {
        $resolved = $this->scopes[self::scopeKey($frame)] ?? null;

        if ($resolved instanceof Throwable) {
            throw $resolved;
        }

        return $resolved;
    }

    /** @param list<Attached> $sources */
    public function attempt(PanelCatalog $catalog, array $sources, EvaluationFrame $frame, PermissionSetCache $cache, SubjectRef $subject): ReadAttempt
    {
        $key = IdentityCodec::compose([$frame->panel()->id(), $subject, $frame->scope()->tenant]);

        return $this->attempts[$key] ??= new ReadAttempt($catalog, $sources, $frame, $cache);
    }

    /** Actual role/contribution witnesses, populated after the grouped raw read. */
    public function addWitness(AccessRequest $request, EvaluationFrame $frame): void
    {
        $this->witnesses[] = [$request, $frame];
    }

    /** @param list<AssignmentScopeFilter|class-string<AssignmentScopeFilter>|Closure> $filters */
    public function native(QueryableAssignmentScopeDefinition $definition, AssignmentScopeDefinition $configuration, array $filters, AssignmentScopeRuntime $runtime, EvaluationFrame $frame): bool
    {
        $key = $this->eligibilityKey($configuration, $runtime);

        if (! array_key_exists($key, $this->eligibility)) {
            $witnesses = [];
            foreach ($this->witnesses as [$request, $candidate]) {
                if ($candidate->panel() !== $frame->panel() || $candidate->scope()->context->type() !== $frame->scope()->context->type()
                    || $candidate->role()?->key() !== $frame->role()?->key() || ($candidate->grant() === null) !== ($frame->grant() === null)) {
                    continue;
                }

                try {
                    $resolved = $this->scope($candidate);
                } catch (Throwable) {
                    continue;
                }

                if ($resolved === null || ! $resolved->tenant->equals($candidate->scope()->tenant)) {
                    continue;
                }
                $candidateRuntime = (new ScopeEligibility($this->container))->runtime($request->subject(), $candidate, common: $runtime->role === null && $runtime->grant === null);
                $candidateKey = $this->eligibilityKey($configuration, $candidateRuntime);

                if (! array_key_exists($candidateKey, $this->eligibility)) {
                    $witnesses[$candidateKey] = [$resolved, $candidateRuntime];
                }
            }
            $resolved = $frame->assignmentScope ?? $this->scope($frame) ?? throw new RuntimeException('Batch scope witness is missing.');
            $witnesses[$key] = [$resolved, $runtime];
            foreach (array_chunk($witnesses, 100, true) as $chunk) {
                try {
                    $this->eligibility += EligibilityBuilder::matchesMany($definition, $chunk, $filters, $this->container);
                } catch (Throwable $error) {
                    foreach ($chunk as $candidateKey => $witness) {
                        $this->eligibility[$candidateKey] = $error;
                    }
                }
            }
        }
        $result = $this->eligibility[$key];

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function external(AssignmentScopeAccessAdapter $adapter, EvaluationFrame $frame, AssignmentScopeRuntime $runtime): bool
    {
        $key = IdentityCodec::compose(['external', $adapter::class, $runtime->panel->id(), $runtime->subject, $runtime->actor, $runtime->scope,
            $runtime->role?->key(), ...self::contributionParts($runtime->grant)]);

        if (! array_key_exists($key, $this->eligibility)) {
            try {
                $ref = $frame->scope()->context;
                $results = $adapter->allowsMany([$ref], $runtime);
                $this->eligibility[$key] = ($results[$ref->key()] ?? false) === true;
            } catch (Throwable $error) {
                $this->eligibility[$key] = $error;
            }
        }
        $result = $this->eligibility[$key];

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function eligibilityKey(AssignmentScopeDefinition $configuration, AssignmentScopeRuntime $runtime): string
    {
        return IdentityCodec::compose([$this->configurationKey($configuration), $runtime->panel->id(), $runtime->subject, $runtime->actor, $runtime->scope,
            $runtime->role?->key(), ...self::contributionParts($runtime->grant)]);
    }

    /**
     * Every component of a contribution that an eligibility verdict may depend on; value objects enter as identities,
     * never through json_encode, which drops their private state.
     *
     * @return list<mixed>
     */
    private static function contributionParts(Grant|RoleContribution|null $contribution): array
    {
        if ($contribution === null) {
            return [null];
        }

        return [$contribution::class, $contribution->role, $contribution->scope, $contribution->source, $contribution->origin,
            $contribution instanceof Grant ? $contribution->pattern : null, $contribution->expiresAt?->format('U.u'),
            json_encode($contribution->fields(), JSON_THROW_ON_ERROR)];
    }

    /** @var array<int, AssignmentScopeDefinition> */
    private array $closureConfigurations = [];

    private function configurationKey(AssignmentScopeDefinition $configuration): string
    {
        try {
            return hash('sha256', serialize($configuration));
        } catch (Throwable) {
            // Runtime closures have no structural equality; keep their actual instance separate.
            $id = array_search($configuration, $this->closureConfigurations, true);

            if ($id === false) {
                $id = count($this->closureConfigurations);
                $this->closureConfigurations[$id] = $configuration;
            }

            return 'closure:'.$id;
        }
    }

    private static function subjectKey(Panel $panel, SubjectRef $ref): string
    {
        return IdentityCodec::compose([$panel->id(), $ref]);
    }

    private static function scopeKey(EvaluationFrame $frame): string
    {
        return IdentityCodec::compose([$frame->panel()->id(), $frame->scope()->context]);
    }
}
