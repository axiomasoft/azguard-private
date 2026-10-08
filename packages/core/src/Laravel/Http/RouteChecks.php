<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Http;

use AzGuard\Attributes\CheckPermission;
use AzGuard\Attributes\SkipPermissionCheck;
use AzGuard\Laravel\Http\Middleware\CheckPermission as CheckPermissionMiddleware;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Routing\Route;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * What checks a route action declares and which of them the router does not apply itself.
 *
 * The expected checks are the `#[CheckPermission]` attributes of the action in the order Laravel reads controller
 * middleware attributes: parent controllers from the root, the controller, then the action, each filtered by
 * `only`/`except`. A check is missing when its exact `azguard.can` middleware is not among the middleware the router
 * gathered for the route; comparing exact strings never runs one check twice, whichever Laravel version applied
 * which attributes. Strict mode and diagnostics read the same answer.
 *
 * @internal
 */
final class RouteChecks
{
    private const array LARAVEL_CHECKS = ['can:', Authorize::class.':'];

    /**
     * The permission attributes that apply to the action of the route; none for a closure route.
     *
     * @return list<CheckPermission>
     */
    public function attributes(Route $route): array
    {
        $action = $this->action($route);

        if ($action === null) {
            return [];
        }
        [$class, $method] = $action;
        $lineage = [];

        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            array_unshift($lineage, $current);
        }
        $attributes = [];

        foreach ([...$lineage, $method] as $target) {
            foreach ($target->getAttributes(CheckPermission::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $check = $attribute->newInstance();

                if ($check->appliesTo($method->getName())) {
                    $attributes[] = $check;
                }
            }
        }

        return $attributes;
    }

    /**
     * `azguard.can` middleware of the attributes that the router has not gathered for the route, each once.
     *
     * @return list<string>
     */
    public function missing(Route $route): array
    {
        $gathered = array_filter($route->gatherMiddleware(), is_string(...));
        $excluded = array_filter($route->excludedMiddleware(), is_string(...));
        $missing = [];

        foreach ($this->attributes($route) as $check) {
            $middleware = $check->middleware;

            if (is_string($middleware) && ! in_array($middleware, $gathered, true) && ! in_array($middleware, $excluded, true)) {
                $missing[$middleware] = $middleware;
            }
        }

        return array_values($missing);
    }

    /**
     * The first attribute of the action whose middleware is the given one, which gives a denial its status and message.
     */
    public function attributeFor(Route $route, string $middleware): ?CheckPermission
    {
        foreach ($this->attributes($route) as $check) {
            if ($check->middleware === $middleware) {
                return $check;
            }
        }

        return null;
    }

    /**
     * Whether the route action declares a check: a permission attribute, `azguard.can` on the route, Laravel `can`
     * or `#[Authorize]`, or opts out with `#[SkipPermissionCheck]` on the controller or the action.
     */
    public function covered(Route $route): bool
    {
        if ($this->attributes($route) !== [] || $this->skipped($route)) {
            return true;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            foreach ([CheckPermissionMiddleware::ALIAS.':', CheckPermissionMiddleware::class.':', ...self::LARAVEL_CHECKS] as $prefix) {
                if (str_starts_with($middleware, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function skipped(Route $route): bool
    {
        $action = $this->action($route);

        return $action !== null && ($action[0]->getAttributes(SkipPermissionCheck::class) !== []
            || $action[1]->getAttributes(SkipPermissionCheck::class) !== []);
    }

    /**
     * @return array{ReflectionClass<object>, ReflectionMethod}|null
     */
    private function action(Route $route): ?array
    {
        $controller = $route->getControllerClass();

        if (! is_string($controller) || ! class_exists($controller)) {
            return null;
        }

        try {
            $class = new ReflectionClass($controller);

            return [$class, $class->getMethod($route->getActionMethod())];
        } catch (ReflectionException) {
            return null;
        }
    }
}
