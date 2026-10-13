<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Internal\Pipeline\Stages;

use AzGuard\Authorization\Internal\EvaluationFrame;
use AzGuard\Authorization\Internal\Pipeline\Trace;
use AzGuard\Contracts\Authorization\BeforeHook;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use Closure;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;

final readonly class BeforeStage
{
    public function __construct(private Container $container) {}

    public function decide(AccessRequest $request, EvaluationFrame $frame, Trace $trace): ?Decision
    {
        if ($frame->panel()->before() === []) {
            $trace->record('before', 'skipped');
        }
        foreach ($frame->panel()->before() as $hook) {
            $component = is_string($hook) ? $hook : $hook::class;

            try {
                $resolved = is_string($hook) ? $this->container->make($hook) : $hook;

                if ($resolved instanceof Closure) {
                    $result = $resolved($request, $frame);
                } elseif ($resolved instanceof BeforeHook) {
                    $result = $resolved->before($request, $frame);
                } else {
                    throw new RuntimeException('Before hook must implement '.BeforeHook::class.'.');
                }

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
