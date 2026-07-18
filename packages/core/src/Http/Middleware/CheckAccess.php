<?php

declare(strict_types=1);

namespace AzGuard\Http\Middleware;

use AzGuard\Attributes\CheckPermission as CheckPermissionAttribute;
use AzGuard\Attributes\SkipGuardCheck;
use AzGuard\Facades\AzGuard;
use AzGuard\Permissions\PermissionKey;
use BackedEnum;
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
        foreach ($this->getPermissionAttributes(request: $request) as $attribute) {
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

    /**
     * @return list<CheckPermissionAttribute>
     */
    private function getPermissionAttributes(Request $request): array
    {
        $route = $request->route();

        if ($route === null) {
            return [];
        }

        $actionName = $route->getActionName();

        if (! is_string($actionName)) {
            return [];
        }

        if (str_contains(haystack: $actionName, needle: '@')) {
            [$controllerClass, $methodName] = explode(separator: '@', string: $actionName, limit: 2);
        } elseif (class_exists($actionName)) {
            $controllerClass = $actionName;
            $methodName = '__invoke';
        } else {
            return [];
        }

        if (! class_exists($controllerClass) || ! method_exists(object_or_class: $controllerClass, method: $methodName)) {
            return [];
        }

        $method = new ReflectionMethod($controllerClass, $methodName);

        if ($method->getAttributes(SkipGuardCheck::class) !== []) {
            return [];
        }

        $attributes = $method->getAttributes(name: CheckPermissionAttribute::class);

        return array_map(
            callback: static fn (ReflectionAttribute $attribute): CheckPermissionAttribute => $attribute->newInstance(),
            array: $attributes,
        );
    }

    /**
     * @param  list<string>  $parameterNames
     * @return list<mixed>
     */
    private function resolveArguments(Request $request, array $parameterNames): array
    {
        return array_values(array: array_map(
            callback: static fn (string $parameterName): mixed => $request->route($parameterName),
            array: $parameterNames,
        ));
    }

    private function resolveAbility(UnitEnum $permission): string
    {
        $panel = AzGuard::currentPanel();

        if ($panel !== null && $permission instanceof BackedEnum) {
            return $panel->resolvePermission(permission: $permission);
        }

        return PermissionKey::normalize($permission);
    }
}
