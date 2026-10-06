<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Pipeline\Stages;

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Policies\RuntimeInvoker;
use Closure;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;

final readonly class BeforeStage
{
    public function __construct(private Container $container, private RuntimeInvoker $invoker) {}

    public function decide(AccessRequest $request, EvaluationFrame $frame, Trace $trace): ?Decision
    {
        if ($frame->panel()->before() === []) {
            $trace->record('before', 'skipped');
        }
        foreach ($frame->panel()->before() as $hook) {
            $component = is_string($hook) ? $hook : $hook::class;

            try {
                $callback = $hook instanceof Closure ? $hook : $this->container->make($hook);

                if (! is_callable($callback)) {
                    throw new RuntimeException('Before hook is not callable.');
                }
                $result = $this->invoker->invoke($callback, ['request' => $request, 'context' => $frame]);

                if (! $result instanceof BeforeResult) {
                    throw new RuntimeException('Before hook must return BeforeResult.');
                }
                $trace->record('before', $result->name, $component);

                if ($result === BeforeResult::Deny) {
                    return Decision::deny(DecisionReason::Hook, $frame->state(), $frame->scope(), $component);
                }
            } catch (Throwable $error) {
                $trace->error('before', 'hook_error', $component, $error);

                return Decision::deny(DecisionReason::HookError, $frame->state(), $frame->scope(), $component);
            }
        }

        return null;
    }
}
