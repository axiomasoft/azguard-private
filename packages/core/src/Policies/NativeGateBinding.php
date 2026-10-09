<?php

declare(strict_types=1);

namespace AzGuard\Policies;

use AzGuard\Kernel\Support\Narrow;
use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use ReflectionFunction;
use RuntimeException;

/** Resolves the original native callback without running global Gate hooks. */
final readonly class NativeGateBinding
{
    public function __construct(private Container $container) {}

    /** @return array{callback: callable, policy: ?object, ability: string} */
    public function resolve(PolicyBinding $binding): array
    {
        $gate = $this->container->make(Gate::class);
        $ability = $binding->ability ?? throw new RuntimeException('Native binding has no ability.');
        $defined = $gate->has($ability);
        $policy = $binding->resourceModel === null ? null : $gate->getPolicyFor($binding->resourceModel);
        $method = str_contains($ability, '-') ? Str::camel($ability) : $ability;
        $native = is_object($policy) && is_callable([$policy, $method]);

        if ($defined && $native) {
            throw new RuntimeException('Native ability is ambiguous between define and model policy.');
        }

        if ($native) {
            return ['callback' => [$policy, $method], 'policy' => $policy, 'ability' => $ability];
        }

        if (! $defined) {
            throw new RuntimeException('Mapped native ability is unavailable.');
        }

        $callback = $gate->abilities()[$ability] ?? null;

        if (! is_callable($callback)) {
            throw new RuntimeException('Mapped native definition is not callable.');
        }

        // Laravel wraps a string Class@method definition in a zero-argument
        // closure using func_get_args(). Recover its original policy callable
        // so RuntimeInvoker can bind declared inputs and services by name.
        if ($callback instanceof Closure) {
            $reflection = new ReflectionFunction($callback);
            $variables = $reflection->getStaticVariables();

            if ($reflection->getClosureScopeClass()?->getName() === \Illuminate\Auth\Access\Gate::class
                && is_string($variables['callback'] ?? null)) {
                [$class, $action] = Str::parseCallback($variables['callback'], '__invoke');

                if (! is_string($class) || ! class_exists($class) || ! is_string($action) || $action === '') {
                    throw new RuntimeException('Mapped native policy callback is invalid.');
                }
                $policy = Narrow::instance($this->container->make($class), $class, 'native policy');
                $callback = [$policy, $action];

                if (! is_callable($callback)) {
                    throw new RuntimeException('Mapped native policy definition is unavailable.');
                }

                return ['callback' => $callback, 'policy' => $policy, 'ability' => $ability];
            }
        }

        return ['callback' => $callback, 'policy' => null, 'ability' => $ability];
    }
}
