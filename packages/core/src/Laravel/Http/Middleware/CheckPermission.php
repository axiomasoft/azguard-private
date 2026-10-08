<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Http\Middleware;

use AzGuard\Concerns\SubjectAccess;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\PanelNotResolvedException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Laravel\Http\PanelUser;
use AzGuard\Laravel\Http\RouteChecks;
use AzGuard\Panels\PanelResolver;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use UnitEnum;

/**
 * `azguard.can:{permission}[,{parameter}]`: the user must hold the permission, on the resource or the assignment
 * scope in the route parameter when one is named.
 *
 * The panel is picked by the rule of the panel resolver: from the permission and the panel of the request, otherwise
 * from the user of the default guard. The user is the one of the guards of that panel. An enum case is written
 * `Enum::Case` and reaches the resolver as the enum; a string is taken as written. A guest is denied. A denial is
 * an `AuthorizationException` with the status and message of the matching `#[CheckPermission]` of the action, by
 * default 403 and the standard message, and never carries an explanation of the decision.
 */
final readonly class CheckPermission
{
    public const string ALIAS = 'azguard.can';

    public function __construct(
        private PanelUser $users,
        private PanelResolver $resolver,
        private RouteChecks $checks,
        private Translator $translator,
    ) {}

    /**
     * The middleware string of a check: `azguard.can:{permission}[,{on}]`, an enum case written as `Enum::Case`.
     */
    public static function using(UnitEnum|string $permission, ?string $on = null): string
    {
        $permission = $permission instanceof UnitEnum ? $permission::class.'::'.$permission->name : $permission;

        return self::ALIAS.':'.$permission.($on === null ? '' : ','.$on);
    }

    /**
     * @param  Closure(Request): mixed  $next
     *
     * @throws AuthorizationException
     * @throws DefinitionException when the named route parameter holds neither a model nor an assignment scope
     */
    public function handle(Request $request, Closure $next, string $permission, ?string $on = null): mixed
    {
        $target = $on === null ? null : $this->target($request, $on);
        $check = self::permission($permission);
        $decision = null;

        try {
            $panel = $this->resolver->select(permissions: [$check]);
        } catch (PanelNotResolvedException) {
            $panel = null;
        }
        $user = $this->users->of($panel);

        try {
            if ($user !== null) {
                $decision = (new SubjectAccess($panel ?? $this->resolver->select($user, [$check]), $user))->decide($check, $target);
            }
        } catch (SubjectNotAcceptedException) {
            $decision = null;
        }

        if ($decision === null || ! $decision->allowed()) {
            $route = $request->route();
            $attribute = $route instanceof Route ? $this->checks->attributeFor($route, self::ALIAS.':'.$permission.($on === null ? '' : ','.$on)) : null;
            $message = $attribute->message ?? $this->translator->get('azguard::http.forbidden');

            Response::deny(is_string($message) ? $message : null, $decision?->reason->value)->withStatus($attribute->status ?? 403)->authorize();
        }

        return $next($request);
    }

    /**
     * An enum case written `Enum::Case` is the case; any other string is the permission as written.
     */
    private static function permission(string $permission): UnitEnum|string
    {
        $separator = strrpos($permission, '::');

        if ($separator === false) {
            return $permission;
        }
        $enum = substr($permission, 0, $separator);
        $case = substr($permission, $separator + 2);

        if (! enum_exists($enum) || ! defined($enum.'::'.$case)) {
            return $permission;
        }
        $value = constant($enum.'::'.$case);

        return $value instanceof UnitEnum ? $value : $permission;
    }

    /**
     * @throws DefinitionException
     */
    private function target(Request $request, string $on): Model|AssignmentScopeRef
    {
        $route = $request->route();
        $value = $route instanceof Route ? $route->parameter($on) : null;

        if ($value instanceof Model || $value instanceof AssignmentScopeRef) {
            return $value;
        }

        throw new DefinitionException('azguard.can names the route parameter "'.$on.'", which holds '.get_debug_type($value)
            .': bind it to a model or an assignment scope reference.');
    }
}
