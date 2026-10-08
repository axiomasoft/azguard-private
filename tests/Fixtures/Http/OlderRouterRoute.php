<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http;

use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Routing\Route;
use ReflectionAttribute;
use ReflectionClass;

/**
 * A route as an older router gathers controller middleware, on the installed Laravel 13:
 * `laravel-12` reads no controller middleware attributes (Laravel 11 and 12), `laravel-13.4` reads the attributes of
 * the controller and the action but not of parent controllers (Laravel 13.0–13.4).
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

        foreach ([...$reflection->getAttributes(Middleware::class, ReflectionAttribute::IS_INSTANCEOF),
            ...$reflection->getMethod($method)->getAttributes(Middleware::class, ReflectionAttribute::IS_INSTANCEOF)] as $attribute) {
            $instance = $attribute->newInstance();

            if (! self::methodExcludedByOptions($method, ['only' => $instance->only, 'except' => $instance->except])) {
                $middleware[] = $instance->middleware;
            }
        }

        return $middleware;
    }
}
