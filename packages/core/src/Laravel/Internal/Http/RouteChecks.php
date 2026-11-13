<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Internal\Http;

use AzGuard\Attributes\CheckPermission;
use AzGuard\Attributes\SkipPermissionCheck;
use AzGuard\Laravel\Internal\Http\Middleware\CheckPermission as CheckPermissionMiddleware;
use AzGuard\Laravel\Internal\Http\Middleware\EnterPanel;
use Closure;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
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
 * gathered for the route; resolving aliases, groups and exclusions first never runs one check twice, whichever Laravel version applied
 * which attributes. Strict mode and the doctor read the same answer.
 *
 * @internal
 */
final class RouteChecks
{
    private const array LARAVEL_CHECKS = ['can:', Authorize::class.':'];

    /** @var array<string, list<CheckPermission>> attribute lists do not depend on the route instance */
    private array $attributeCache = [];

    /** @var array<int, list<mixed>> resolved gathered middleware for one route object */
    private array $gatheredCache = [];

    /** @var list<mixed>|null */
    private ?array $checkPrefixes = null;

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
        $cacheKey = $class->getName().'::'.$method->getName();

        if (isset($this->attributeCache[$cacheKey])) {
            return $this->attributeCache[$cacheKey];
        }
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

        return $this->attributeCache[$cacheKey] = $attributes;
    }

    /**
     * `azguard.can` middleware of the attributes that the router has not gathered for the route, each once.
     *
     * @return list<string>
     */
    public function missing(Route $route): array
    {
        $router = app(Router::class);
        $excluded = $route->excludedMiddleware();
        $gathered = $this->gathered($route);
        $missing = [];

        foreach ($this->attributes($route) as $check) {
            $middleware = $check->middleware;

            if (! is_string($middleware)) {
                continue;
            }

            foreach ($router->resolveMiddleware([$middleware], $excluded) as $resolved) {
                if (! in_array($resolved, $gathered, true)) {
                    $missing[$middleware] = $middleware;

                    break;
                }
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
        if ($this->skipped($route)) {
            return true;
        }
        $router = app(Router::class);
        $excluded = $route->excludedMiddleware();

        foreach ($this->attributes($route) as $check) {
            if (is_string($check->middleware) && $router->resolveMiddleware([$check->middleware], $excluded) === []) {
                return false;
            }
        }
        $middleware = $router->resolveMiddleware([
            ...$route->gatherMiddleware(),
            ...array_map(static fn (CheckPermission $check): Closure|string => $check->middleware, $this->attributes($route)),
        ], $excluded);
        $prefixes = $this->prefixes($router);

        foreach ($middleware as $check) {
            if (! is_string($check)) {
                continue;
            }

            foreach ([...$prefixes, CheckPermissionMiddleware::class.':', ...self::LARAVEL_CHECKS] as $prefix) {
                if (is_string($prefix) && str_starts_with($check, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The panel id the route enters with `azguard.panel:{id}`, or null when the route enters no panel.
     */
    public function panelOf(Route $route): ?string
    {
        $prefix = EnterPanel::class.':';

        foreach ($this->gathered($route) as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, $prefix)) {
                return substr($middleware, strlen($prefix));
            }
        }

        return null;
    }

    /** @return list<mixed> */
    private function gathered(Route $route): array
    {
        $id = spl_object_id($route);

        return $this->gatheredCache[$id] ??= app(Router::class)->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());
    }

    /** @return list<mixed> */
    private function prefixes(Router $router): array
    {
        return $this->checkPrefixes ??= $router->resolveMiddleware([CheckPermissionMiddleware::ALIAS.':', 'can:']);
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
