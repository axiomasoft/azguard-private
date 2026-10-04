<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Pipeline;

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Authorization\Pipeline\Stages\AfterStage;
use AzGuard\Authorization\Pipeline\Stages\AuthorityStage;
use AzGuard\Authorization\Pipeline\Stages\BeforeStage;
use AzGuard\Authorization\Pipeline\Stages\BoundaryStage;
use AzGuard\Authorization\Pipeline\Stages\RestrictionStage;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use Throwable;

final readonly class AccessPipeline
{
    public function __construct(private BoundaryStage $boundary, private BeforeStage $before, private AuthorityStage $authority, private RestrictionStage $restrictions, private AfterStage $after) {}

    public function evaluate(AccessRequest $request, EvaluationFrame $frame, PanelCatalog $catalog, PermissionDefinition $definition, Trace $trace, ?Decision $denial = null): Decision
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

        if ($decision === null) {
            [$frame,$decision] = $this->authority->decide($request, $frame, $catalog, $definition, $trace);
            $trace->record('authority', $decision->reason->value);
        }

        if ($decision->allowed()) {
            $decision = $restrictionDenial ?? $this->restrictions->decide($request, $frame, $restrictions, $trace) ?? $decision;
        }
        $this->after->observe($request, $frame, $decision, $trace);

        return $decision;
    }
}
