<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Internal;

use AzGuard\Authorization\Internal\Cache\PermissionSetCache;
use AzGuard\Catalog\Internal\PanelCatalog;
use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Context\Internal\ContextIdentity;
use AzGuard\Contracts\Scopes\AssignmentScopeEvaluator;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Sources\Internal\PanelSources;
use AzGuard\Support\ModelKey;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RuntimeException;
use Throwable;

/** @internal Fresh host inputs and eligibility witnesses of one batch attempt; never request memo.
 * @phpstan-import-type Attached from PanelSources
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

    private readonly ReadSessions $sessions;

    /** @var array<string, bool|Throwable> */
    private array $eligibility = [];

    public function __construct()
    {
        $this->sessions = new ReadSessions;
    }

    /** Ends operation ownership even when observer frames retain the batch's attempts. */
    public function close(): void
    {
        foreach ($this->attempts as $attempt) {
            $attempt->close();
        }
        $this->sessions->close();
        $this->attempts = [];
        $this->witnesses = [];
    }

    /** @param list<array{Panel, AccessRequest}> $requests */
    public function loadSubjects(array $requests, ?ActorRef $actor): void
    {
        $groups = [];
        foreach ($requests as [$panel, $request]) {
            $refs = ModelSubjectResolver::given($panel, $request->subjectModel(), $request->subject()) === null
                ? [$request->subject()] : [];

            if ($actor?->id !== null && ($actor->type !== $request->subject()->type() || $actor->id !== $request->subject()->id())) {
                $refs[] = SubjectRef::of($actor->type, $actor->id);
            }
            foreach ($refs as $ref) {
                $key = self::subjectKey($panel, $ref);
                $this->subjects[$key] = null;
                foreach ($panel->subjectModels() as $class) {
                    $model = new $class;

                    if ($model->getMorphClass() === $ref->type()) {
                        // An id the key cannot hold names no model; querying it would fail the whole chunk on PostgreSQL.
                        if (ModelKey::canHold($model, $ref->id())) {
                            $groups[$class][$key] = $ref;
                        }

                        break;
                    }
                }
            }
        }
        foreach ($groups as $class => $refs) {
            foreach (array_chunk($refs, 100, true) as $chunk) {
                try {
                    $models = (new $class)->newQuery()->whereKey(array_map(static fn (SubjectRef $ref): string => $ref->id(), $chunk))->get()->keyBy(static fn (Model $model): string => ModelKey::of($model));
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

    public function forRequest(Panel $panel, AccessRequest $request): ?Model
    {
        return ModelSubjectResolver::given($panel, $request->subjectModel(), $request->subject())
            ?? $this->subject($panel, $request->subject());
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
            // Only a resolver the definition describes as query + tenantOf is batched. A custom
            // resolver may refuse a record or report an unavailable structural owner.
            if (! $definition instanceof QueryableAssignmentScopeDefinition
                || ! ContextIdentity::describe($definition)->resolvesThroughQuery) {
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
                    $records = ModelKey::where($definition->query(), array_values(array_map(static fn (EvaluationFrame $frame): ?string => $frame->scope()->context->id(), $chunk)))->get()->keyBy(static fn (Model $model): string => ModelKey::of($model));
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
        $key = IdentityCodec::compose([$frame->panel()->id(), $subject, $frame->scope()->tenant, $frame->reads()->value]);

        return $this->attempts[$key] ??= new ReadAttempt($catalog, $sources, $frame, $cache, shared: $this->sessions);
    }

    /** Actual role/contribution witnesses, populated after the grouped raw read. */
    public function addWitness(AccessRequest $request, EvaluationFrame $frame): void
    {
        $this->witnesses[] = [$request, $frame];
    }

    /**
     * The evaluator's answer for one verified scope and runtime of the set. The first question of a kind (the common
     * runtime of a decision, or a contribution's) asks the evaluator once, through `allowsMany()`, for every witness of
     * that kind that has no answer yet; an answer list of another length fails all of them. When `allowsMany()` throws,
     * each witness is asked alone, so one failing witness fails only its own decisions.
     */
    public function allows(AssignmentScopeEvaluator $evaluator, AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved, EvaluationFrame $frame): bool
    {
        $key = $this->eligibilityKey($runtime);

        if (! array_key_exists($key, $this->eligibility)) {
            $common = $runtime->role === null && $runtime->grant === null;
            $checks = [];
            foreach ($this->witnesses as [$request, $candidate]) {
                if ($candidate->panel() !== $frame->panel() || $candidate->scope()->context->isGlobal()
                    || ($candidate->role() === null && $candidate->grant() === null) !== $common) {
                    continue;
                }

                try {
                    $witness = $candidate->assignmentScope ?? $this->scope($candidate);
                } catch (Throwable) {
                    continue;
                }

                if ($witness === null || ! $witness->ref->equals($candidate->scope()->context) || ! $witness->tenant->equals($candidate->scope()->tenant)) {
                    continue;
                }
                $candidateRuntime = $candidate->scopeRuntime($request->subject(), common: $common);
                $candidateKey = $this->eligibilityKey($candidateRuntime);

                if (! array_key_exists($candidateKey, $this->eligibility)) {
                    $checks[$candidateKey] = [$candidateRuntime, $witness];
                }
            }
            $checks[$key] = [$runtime, $resolved];

            try {
                $answers = $evaluator->allowsMany(array_values($checks));
            } catch (Throwable) {
                $answers = null;
            }

            if ($answers === null) {
                foreach ($checks as $candidateKey => [$candidateRuntime, $witness]) {
                    try {
                        $this->eligibility[$candidateKey] = $evaluator->allows($candidateRuntime, $witness);
                    } catch (Throwable $error) {
                        $this->eligibility[$candidateKey] = $error;
                    }
                }
            } elseif (count($answers) !== count($checks)) {
                $error = new RuntimeException($evaluator::class.'::allowsMany() returned '.count($answers).' answers for '.count($checks).' checks.');
                foreach (array_keys($checks) as $candidateKey) {
                    $this->eligibility[$candidateKey] = $error;
                }
            } else {
                foreach (array_keys($checks) as $i => $candidateKey) {
                    $this->eligibility[$candidateKey] = ($answers[$i] ?? null) === true;
                }
            }
        }
        $result = $this->eligibility[$key];

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function eligibilityKey(AssignmentScopeRuntime $runtime): string
    {
        return IdentityCodec::compose([$runtime->panel->id(), $runtime->subject, $runtime->actor, $runtime->scope,
            $runtime->user === null ? null : spl_object_id($runtime->user), $runtime->actorModel === null ? null : spl_object_id($runtime->actorModel),
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
            hash('sha256', self::plain($contribution->fields()))];
    }

    /**
     * A key of a plain field value that tells apart everything an evaluator can: `1` from `1.0`, `0.0` from `-0.0`, any
     * byte string, the order of an array. JSON would merge the first, reject the last.
     */
    private static function plain(mixed $value): string
    {
        if (is_array($value)) {
            $encoded = '';
            foreach ($value as $key => $nested) {
                $encoded .= self::plain($key).self::plain($nested);
            }

            return 'a'.count($value).'{'.$encoded.'}';
        }

        return match (true) {
            $value === null => 'n;',
            is_bool($value) => $value ? 'b1;' : 'b0;',
            is_int($value) => 'i'.$value.';',
            is_float($value) => 'f'.bin2hex(pack('E', $value)).';',
            is_string($value) => 's'.strlen($value).':'.$value.';',
            default => throw new LogicException('A decision field holds a scalar, null or an array.'),
        };
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
