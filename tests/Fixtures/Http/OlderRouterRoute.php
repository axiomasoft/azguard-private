<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http;

use AzGuard\Attributes\CheckPermission;
use Illuminate\Routing\Route;
use ReflectionAttribute;
use ReflectionClass;

/**
 * An explicit simulation of older router handling of CheckPermission attributes on any installed framework:
 * `laravel-12` reads no attributes; `laravel-13.4` reads the controller and action without parent controllers.
 * Real framework compatibility is checked separately through its native Route, never through this simulation.
 */
final class OlderRouterRoute extends Route
{
    public string $generation = 'laravel-12';

    public function controllerMiddleware(): array
    {
        $class = $this->getControllerClass();

        if ($this->generation === 'laravel-12' || ! is_string($class) || ! class_exists($class)) {
            return [];
        }
        $reflection = new ReflectionClass($class);
        $method = $this->getActionMethod();
        $middleware = [];

        foreach ([...$reflection->getAttributes(CheckPermission::class, ReflectionAttribute::IS_INSTANCEOF),
            ...$reflection->getMethod($method)->getAttributes(CheckPermission::class, ReflectionAttribute::IS_INSTANCEOF)] as $attribute) {
            $instance = $attribute->newInstance();

            if (! self::methodExcludedByOptions($method, ['only' => $instance->only, 'except' => $instance->except])) {
                $middleware[] = $instance->middleware;
            }
        }

        return $middleware;
    }
}
