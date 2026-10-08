<?php

declare(strict_types=1);

use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Diagnostics\DoctorWorld;
use AzGuard\Tests\Fixtures\Http\Controllers\ClientController;
use AzGuard\Tests\Fixtures\Http\Controllers\OpenController;
use AzGuard\Tests\Fixtures\Http\Controllers\ReportController;
use AzGuard\Tests\Fixtures\Http\EntryPermission;
use AzGuard\Tests\Fixtures\Http\HttpWorld;
use AzGuard\Tests\Fixtures\Http\OlderRouterRoute;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Carbon;

/*
 * V83 in doctor: `routes.checks` reads routes with the same collector as the middleware — strict panels, a
 * #[CheckPermission] of another panel, and a #[CheckPermission] nothing applies.
 */

beforeEach(function (): void {
    HttpWorld::seed();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

/** @param list<string> $middleware */
function doctorRoute(string $uri, string $controller, string $method, array $middleware = ['azguard.panel:crm'], string $class = LaravelRoute::class): void
{
    $route = new $class(['GET', 'HEAD'], $uri, ['uses' => $controller.'@'.$method, 'controller' => $controller.'@'.$method, 'middleware' => [...HttpWorld::BINDINGS, ...$middleware]]);
    $route->setRouter(app('router'))->setContainer(app());
    app('router')->getRoutes()->add($route);
}

/** @return list<string> */
function routeFindings(): array
{
    return array_map(static fn ($finding): string => $finding->scope.' '.$finding->details['route'], DoctorWorld::only(DoctorWorld::run(), 'routes.checks'));
}

it('passes checked, skipped and Laravel-checked actions of a strict panel', function (): void {
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->requireRouteChecks());
    doctorRoute('clients/{client}', ClientController::class, 'update');
    doctorRoute('ping', OpenController::class, 'ping');
    doctorRoute('gated', OpenController::class, 'gated');
    doctorRoute('reports', ReportController::class, 'index');

    expect(routeFindings())->toBe([]);
});

it('fails an action without any check in a strict panel, but not in a panel without strict mode', function (): void {
    doctorRoute('unchecked', OpenController::class, 'unchecked');
    HttpWorld::panel();
    expect(routeFindings())->toBe([]);

    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->requireRouteChecks());
    expect(routeFindings())->toBe(['panel:crm GET|HEAD /unchecked']);
});

it('fails a #[CheckPermission] that names a permission of another panel than the route enters', function (): void {
    HttpWorld::panel();
    doctorRoute('backoffice/reports', ReportController::class, 'index', ['azguard.panel:backoffice']);

    $finding = DoctorWorld::only(DoctorWorld::run(), 'routes.checks');
    expect(routeFindings())->toBe(['panel:backoffice GET|HEAD /backoffice/reports'])
        ->and($finding[0]->details['permission'])->toEndWith('EntryPermission::Enter');
});

it('fails a #[CheckPermission] outside a panel that the router of this Laravel version does not apply', function (): void {
    HttpWorld::panel();
    doctorRoute('applied', ReportController::class, 'rebuild', []);
    expect(routeFindings())->toBe([]);

    doctorRoute('older', ReportController::class, 'rebuild', [], OlderRouterRoute::class);
    $finding = DoctorWorld::only(DoctorWorld::run(), 'routes.checks');
    expect(routeFindings())->toBe(['core GET|HEAD /older'])
        ->and($finding[0]->details['middleware'])->toBe(['azguard.can:'.EntryPermission::class.'::Enter', 'azguard.can:clients.update,client']);
});

it('reports a route that enters a panel which is not registered', function (): void {
    HttpWorld::panel();
    doctorRoute('ghost', OpenController::class, 'ping', ['azguard.panel:ghost']);

    $finding = DoctorWorld::only(DoctorWorld::run(), 'routes.checks');
    expect(routeFindings())->toBe(['core GET|HEAD /ghost'])->and($finding[0]->details['code'])->toBe('unknown_panel');
});
