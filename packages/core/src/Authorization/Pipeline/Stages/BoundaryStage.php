<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Pipeline\Stages;

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;

final class BoundaryStage
{
    public function decide(EvaluationFrame $frame): ?Decision
    {
        if (! $frame->scope()->tenant->isGlobal()) {
            return Decision::deny(DecisionReason::TenantMismatch, $frame->state(), $frame->scope());
        }

        if (! $frame->scope()->context->isGlobal()) {
            return Decision::deny(DecisionReason::AssignmentScopeNotAccepted, $frame->state(), $frame->scope());
        }

        return null;
    }
}
