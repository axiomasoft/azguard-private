<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Pipeline\Stages;

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Policies\RuntimeInvoker;
use Closure;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;

final readonly class AfterStage
{
    public function __construct(private Container $container, private RuntimeInvoker $invoker) {}

    public function observe(AccessRequest $request, EvaluationFrame $frame, Decision $decision, Trace $trace): void
    {
        if ($frame->panel()->after() === []) {
            $trace->record('after', 'skipped');
        }
        foreach ($frame->panel()->after() as $hook) {
            $component = is_string($hook) ? $hook : $hook::class;

            try {
                $callback = $hook instanceof Closure ? $hook : $this->container->make($hook);

                if (! is_callable($callback)) {
                    throw new RuntimeException('After hook is not callable.');
                }
                $this->invoker->invoke($callback, ['request' => $request, 'context' => $frame, 'decision' => $decision]);
                $trace->record('after', 'observed', $component);
            } catch (Throwable $error) {
                $trace->error('after', 'after_error', $component, $error);
            }
        }
    }
}
