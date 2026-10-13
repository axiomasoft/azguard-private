<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Internal\Pipeline\Stages;

use AzGuard\Authorization\Internal\EvaluationFrame;
use AzGuard\Authorization\Internal\Pipeline\Trace;
use AzGuard\Contracts\Authorization\AfterHook;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use Closure;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;

final readonly class AfterStage
{
    public function __construct(private Container $container) {}

    public function observe(AccessRequest $request, EvaluationFrame $frame, Decision $decision, Trace $trace): void
    {
        if ($frame->panel()->after() === []) {
            $trace->record('after', 'skipped');
        }
        foreach ($frame->panel()->after() as $hook) {
            $component = is_string($hook) ? $hook : $hook::class;

            try {
                $resolved = is_string($hook) ? $this->container->make($hook) : $hook;

                if ($resolved instanceof Closure) {
                    $resolved($request, $frame, $decision);
                } elseif ($resolved instanceof AfterHook) {
                    $resolved->after($request, $frame, $decision);
                } else {
                    throw new RuntimeException('After hook must implement '.AfterHook::class.'.');
                }
                $trace->record('after', 'observed', $component);
            } catch (Throwable $error) {
                $trace->error('after', 'after_error', $component, $error);
            }
        }
    }
}
