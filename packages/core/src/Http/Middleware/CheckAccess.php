<?php

declare(strict_types=1);

namespace AzGuard\Http\Middleware;

use AzGuard\Attributes\CheckPermission as CheckPermissionAttribute;
use AzGuard\Attributes\SkipGuardCheck;
use AzGuard\Configuration\Config;
use AzGuard\Exceptions\MissingPermissionAttributeException;
use AzGuard\Facades\AzGuard;
use AzGuard\Permissions\PermissionGrammar;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use ReflectionAttribute;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;
use UnitEnum;

final class CheckAccess
{
    /**
     * Build the `azguard.check` middleware definition string. Takes no
     * arguments — checks are driven by `#[CheckPermission]` on the action —
     * provided for DX symmetry with the other AzGuard middleware.
     */
    public static function using(): string
    {
        return self::class;
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $method = $this->actionMethod($request);

        if ($method !== null) {
            $skipped = $method->getAttributes(SkipGuardCheck::class) !== [];
            $attributes = $skipped ? [] : $this->instantiateAttributes($method);

            if (! $skipped && $attributes === [] && Config::requirePermissionAttributes()) {
                throw new MissingPermissionAttributeException($this->actionLabel($method));
            }
        } else {
            $attributes = [];
        }

        foreach ($attributes as $attribute) {
            $arguments = $this->resolveArguments(request: $request, parameterNames: $attribute->arguments);
            $ability = $this->resolveAbility(permission: $attribute->permission);

            abort_if(
                boolean: ! Gate::allows(ability: $ability, arguments: $arguments),
                code: $attribute->status,
                message: $attribute->message ?? '',
            );
        }

        return $next($request);
    }

    private function actionMethod(Request $request): ?ReflectionMethod
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        $actionName = $route->getActionName();

        if (str_contains(haystack: $actionName, needle: '@')) {
            [$controllerClass, $methodName] = explode(separator: '@', string: $actionName, limit: 2);
        } elseif (class_exists($actionName)) {
            $controllerClass = $actionName;
            $methodName = '__invoke';
        } else {
            return null;
        }

        if (! class_exists($controllerClass) || ! method_exists(object_or_class: $controllerClass, method: $methodName)) {
            return null;
        }

        return new ReflectionMethod($controllerClass, $methodName);
    }

    /**
     * @return list<CheckPermissionAttribute>
     */
    private function instantiateAttributes(ReflectionMethod $method): array
    {
        $attributes = $method->getAttributes(name: CheckPermissionAttribute::class);

        return array_map(
            callback: static fn (ReflectionAttribute $attribute): CheckPermissionAttribute => $attribute->newInstance(),
            array: $attributes,
        );
    }

    private function actionLabel(ReflectionMethod $method): string
    {
        return $method->class.'::'.$method->name;
    }

    /**
     * @param  list<string>  $parameterNames
     * @return list<mixed>
     */
    private function resolveArguments(Request $request, array $parameterNames): array
    {
        return array_map(
            callback: static fn (string $parameterName): mixed => $request->route($parameterName),
            array: $parameterNames,
        );
    }

    private function resolveAbility(UnitEnum $permission): string
    {
        $panel = AzGuard::currentPanel();

        if ($panel !== null) {
            return $panel->resolvePermission(permission: $permission);
        }

        $raw = PermissionGrammar::raw($permission);
        PermissionGrammar::assertValid($raw);

        return $raw;
    }
}
