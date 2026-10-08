<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Laravel\Http\RouteChecks;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelResolver;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use UnitEnum;

/**
 * `routes.checks`: in strict mode every action of a panel declares a permission check or opts out; a
 * `#[CheckPermission]` names a permission of the panel the route enters; and outside a panel no `#[CheckPermission]`
 * is left without middleware to apply it (Laravel 11/12, or a parent controller on 13.0–13.4). Uses the same
 * reading of routes as the middleware.
 *
 * @internal
 */
final readonly class RoutesChecks implements DoctorCheck
{
    public function __construct(private Router $router, private RouteChecks $checks, private PanelResolver $resolver) {}

    public function key(): string
    {
        return 'routes.checks';
    }

    public function run(DoctorContext $context): iterable
    {
        $selected = [];
        foreach ($context->panels() as $panel) {
            $selected[$panel->id()] = $panel;
        }

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $id = $this->checks->panelOf($route);

            if ($id === null) {
                yield from $this->unapplied($route);

                continue;
            }

            try {
                $panel = $this->resolver->resolve(panel: $id)['panel'];
            } catch (AzGuardException $error) {
                yield DoctorFinding::error($this->key(), 'Route '.self::name($route).' enters the panel "'.$id.'", which does not resolve: '.$error->getMessage(),
                    details: ['route' => self::name($route), 'code' => $error->code()]);

                continue;
            }

            if (isset($selected[$panel->id()])) {
                yield from $this->inPanel($route, $panel);
            }
        }
    }

    /** @return list<DoctorFinding> */
    private function inPanel(Route $route, Panel $panel): array
    {
        $scope = 'panel:'.$panel->id();
        $findings = [];

        if ($panel->requiresRouteChecks() && ! $this->checks->covered($route)) {
            $findings[] = DoctorFinding::error($this->key(), 'Action '.$route->getActionName().' of route '.self::name($route).' has no permission check in the strict panel '
                .$panel->id().': add #[CheckPermission], azguard.can, can or #[SkipPermissionCheck].', $scope, ['route' => self::name($route)]);
        }

        foreach ($this->checks->attributes($route) as $check) {
            try {
                $this->resolver->resolve(permission: $check->permission, panel: $panel->id());
            } catch (AzGuardException $error) {
                $findings[] = DoctorFinding::error($this->key(), '#[CheckPermission('.self::permission($check->permission).')] on route '.self::name($route)
                    .' does not name a permission of panel '.$panel->id().': '.$error->getMessage(), $scope,
                    ['route' => self::name($route), 'permission' => self::permission($check->permission), 'code' => $error->code()]);
            }
        }

        return $findings;
    }

    /** @return list<DoctorFinding> */
    private function unapplied(Route $route): array
    {
        $missing = $this->checks->missing($route);

        if ($missing === []) {
            return [];
        }

        return [DoctorFinding::error($this->key(), 'Route '.self::name($route).' declares '.implode(', ', $missing).' with #[CheckPermission], but nothing applies it: '
            .'the router of this Laravel version does not read the attribute there and the route enters no panel (azguard.panel).', details: [
                'route' => self::name($route), 'middleware' => $missing])];
    }

    private static function name(Route $route): string
    {
        return $route->getName() ?? implode('|', $route->methods()).' /'.ltrim($route->uri(), '/');
    }

    private static function permission(UnitEnum|string $permission): string
    {
        return $permission instanceof UnitEnum ? $permission::class.'::'.$permission->name : $permission;
    }
}
