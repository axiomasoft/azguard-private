<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Pipeline;

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Authorization\Pipeline\Stages\AfterStage;
use AzGuard\Authorization\Pipeline\Stages\AuthorityStage;
use AzGuard\Authorization\Pipeline\Stages\BeforeStage;
use AzGuard\Authorization\Pipeline\Stages\BoundaryStage;
use AzGuard\Authorization\Pipeline\Stages\RestrictionStage;
use AzGuard\Authorization\ReadAttemptChanged;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use Closure;
use Throwable;

final readonly class AccessPipeline
{
    public function __construct(private BoundaryStage $boundary, private BeforeStage $before, private AuthorityStage $authority, private RestrictionStage $restrictions, private AfterStage $after) {}

    /** @param array{?Decision, list<Restriction>, ?Decision}|null $started
     * @param  Closure(EvaluationFrame): void|null  $capture
     */
    public function evaluate(AccessRequest $request, EvaluationFrame $frame, PanelCatalog $catalog, PermissionDefinition $definition, Trace $trace, ?Decision $denial = null, bool $deferred = false, ?Closure $capture = null, ?array $started = null): Decision
    {
        [$decision, $restrictions, $restrictionDenial] = $started ?? $this->start($request, $frame, $trace, $denial);

        if ($decision === null) {
            [$frame,$decision] = $this->authority->decide($request, $frame, $catalog, $definition, $trace);
            $trace->record('authority', $decision->reason->value);
        }

        if ($decision->allowed()) {
            $decision = $restrictionDenial ?? $this->restrictions->decide($request, $frame, $restrictions, $trace) ?? $decision;
        }

        if ($deferred) {
            $capture?->__invoke($frame);

            return $decision;
        }

        return $this->complete($request, $frame, $decision, $trace);
    }

    /** @return array{?Decision, list<Restriction>, ?Decision} */
    public function start(AccessRequest $request, EvaluationFrame $frame, Trace $trace, ?Decision $denial): array
    {
        $trace->record('prepare', 'prepared');
        $restrictions = [];
        $restrictionDenial = null;

        try {
            $restrictions = $this->restrictions->resolve($frame);
        } catch (Throwable $error) {
            $trace->error('restriction', 'restriction_error', 'restrictions', $error);
            $restrictionDenial = Decision::deny(DecisionReason::RestrictionError, $frame->state(), $frame->scope(), 'restrictions');
        }
        $restrictionDenial ??= $this->restrictions->validateKeys($frame, $restrictions, $trace);

        $decision = $denial ?? $this->boundary->decide($frame);
        $trace->record('boundary', $decision?->reason->value ?? 'pass');
        $decision ??= $this->before->decide($request, $frame, $trace);

        return [$decision, $restrictions, $restrictionDenial];
    }

    public function complete(AccessRequest $request, EvaluationFrame $frame, Decision $decision, Trace $trace, bool $confirm = true): Decision
    {
        if ($confirm && $frame->readAttempt !== null) {
            try {
                $frame = $frame->readAttempt->confirm($frame);
                $decision = $decision->allowed()
                    ? Decision::allow($decision->reason, $frame->state(), $decision->scope, $decision->component, $decision->grants, $decision->message, $decision->status, $decision->code)
                    : Decision::deny($decision->reason, $frame->state(), $decision->scope, $decision->component, $decision->message, $decision->status, $decision->code);
            } catch (ReadAttemptChanged $changed) {
                // The root retries every stage; no observer sees a discarded decision.
                throw $changed;
            } catch (Throwable $error) {
                $trace->error('state', 'source_error', 'dynamic_sources', $error);
                $decision = Decision::deny(DecisionReason::SourceError, $frame->state(), $frame->scope(), 'dynamic_sources');
            }
        }
        $decision = $decision->allowed()
            ? Decision::allow($decision->reason, $frame->state(), $decision->scope, $decision->component, $decision->grants, $decision->message, $decision->status, $decision->code)
            : Decision::deny($decision->reason, $frame->state(), $decision->scope, $decision->component, $decision->message, $decision->status, $decision->code);
        $this->after->observe($request, $frame, $decision, $trace);

        return $decision;
    }

    public function inconsistent(AccessRequest $request, EvaluationFrame $frame, Trace $trace): Decision
    {
        $decision = Decision::deny(DecisionReason::ConsistencyError, $frame->state(), $frame->scope(), 'dynamic_sources');
        $this->after->observe($request, $frame, $decision, $trace);

        return $decision;
    }
}
