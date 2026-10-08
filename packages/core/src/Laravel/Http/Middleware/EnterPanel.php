<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Http\Middleware;

use AzGuard\Authorization\PanelAdmission;
use AzGuard\Exceptions\MissingPermissionCheckException;
use AzGuard\Laravel\Http\PanelUser;
use AzGuard\Laravel\Http\RouteChecks;
use AzGuard\Laravel\Queue\PanelContext;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelResolver;
use AzGuard\Scopes\CurrentContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * `azguard.panel:{id}`: enters the interface of a panel for the rest of the request.
 *
 * Runs the middleware of the panel that the route does not run already, then admits the user of the panel guards
 * (see `PanelAdmission`). A guest gets the standard authentication response. A denied user gets the `onDenied`
 * response of the panel, otherwise a 403 `AuthorizationException`. An admitted request runs with the panel as the
 * panel of the request and the admitted tenant and assignment scope as its current scope; both are restored when the
 * request leaves the middleware, also after an exception. Queued jobs dispatched meanwhile carry the panel id as a
 * hint (see `PanelContext`).
 *
 * Inside the panel the middleware adds the `azguard.can` checks of `#[CheckPermission]` attributes the router has not
 * applied itself, each once. In strict mode an action without any check and without `#[SkipPermissionCheck]` throws
 * `MissingPermissionCheckException` in the local and testing environments and answers 403 elsewhere.
 */
final readonly class EnterPanel
{
    public const string ALIAS = 'azguard.panel';

    public function __construct(
        private Container $container,
        private PanelUser $users,
        private CurrentPanel $current,
        private CurrentContext $contexts,
        private PanelResolver $resolver,
        private PanelAdmission $admission,
        private RouteChecks $checks,
        private PanelContext $jobs,
        private Router $router,
        private Translator $translator,
    ) {}

    /**
     * @param  Closure(Request): mixed  $next
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     * @throws MissingPermissionCheckException
     */
    public function handle(Request $request, Closure $next, string $panel): mixed
    {
        $panel = $this->resolver->resolve(panel: $panel)['panel'];

        return $this->through($request, $this->panelMiddleware($request, $panel), fn (Request $request): mixed => $this->enter($request, $next, $panel));
    }

    /**
     * @param  Closure(Request): mixed  $next
     */
    private function enter(Request $request, Closure $next, Panel $panel): mixed
    {
        $user = $this->users->of($panel);

        if ($user === null) {
            throw new AuthenticationException('Unauthenticated.', $panel->guards());
        }

        $scope = $this->admission->admit($panel, $user);

        if ($scope === null) {
            return $this->denied($request, $panel);
        }

        return $this->current->run($panel, function (Panel $panel) use ($request, $next, $scope): mixed {
            // The admitted scope passed the scope boundary already; it is the ambient scope of the panel for the request.
            $previous = $this->contexts->get($panel);
            $this->contexts->set($panel, $scope);

            try {
                // Jobs dispatched while the request runs carry the panel as the hint for their short names.
                return $this->jobs->carry($panel, function () use ($request, $next, $panel): mixed {
                    $route = $request->route();

                    if (! $route instanceof Route) {
                        return $next($request);
                    }

                    if ($panel->requiresRouteChecks() && ! $this->checks->covered($route)) {
                        $this->missingCheck($route, $panel);
                    }

                    return $this->through($request, array_values($this->router->resolveMiddleware($this->checks->missing($route))), $next);
                });
            } finally {
                $this->contexts->set($panel, $previous);
            }
        });
    }

    /**
     * @param  list<mixed>  $middleware
     * @param  Closure(Request): mixed  $destination
     */
    private function through(Request $request, array $middleware, Closure $destination): mixed
    {
        return $middleware === [] ? $destination($request)
            : (new Pipeline($this->container))->send($request)->through($middleware)->then($destination);
    }

    /**
     * Middleware of the panel, resolved by the router, without the ones the route runs anyway.
     *
     * @return list<mixed>
     */
    private function panelMiddleware(Request $request, Panel $panel): array
    {
        if ($panel->middleware() === []) {
            return [];
        }
        $route = $request->route();
        $running = $route instanceof Route ? $this->router->gatherRouteMiddleware($route) : [];

        return array_values(array_filter(
            $this->router->resolveMiddleware($panel->middleware()),
            static fn (mixed $middleware): bool => ! in_array($middleware, $running, true),
        ));
    }

    /**
     * @throws AuthorizationException
     */
    private function denied(Request $request, Panel $panel): mixed
    {
        $response = $panel->onDenied();

        if ($response instanceof Closure) {
            return $this->router->prepareResponse($request, $response($request, $panel));
        }

        if (is_string($response)) {
            return $this->container->make('redirect')->to($this->router->has($response) ? $this->container->make('url')->route($response) : $response);
        }

        $message = $this->translator->get('azguard::http.forbidden');

        return Response::deny(is_string($message) ? $message : null)->authorize();
    }

    /**
     * @throws AuthorizationException
     * @throws MissingPermissionCheckException
     */
    private function missingCheck(Route $route, Panel $panel): never
    {
        $application = $this->container instanceof Application ? $this->container : null;

        if ($application?->environment(['local', 'testing']) === true) {
            throw new MissingPermissionCheckException('Route action '.$route->getActionName().' of panel "'.$panel->id()
                .'" has no permission check: add #[CheckPermission], azguard.can, Laravel can or #[Authorize], or #[SkipPermissionCheck].');
        }
        $message = $this->translator->get('azguard::http.forbidden');

        Response::deny(is_string($message) ? $message : null)->authorize();

        throw new AuthorizationException;
    }
}
