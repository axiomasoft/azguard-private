<?php

declare(strict_types=1);

use AzGuard\Attributes\CheckPermission;
use AzGuard\Exceptions\AmbiguousPanelException;
use AzGuard\Exceptions\MissingPermissionCheckException;
use AzGuard\Laravel\Http\Middleware\CheckPermission as CheckPermissionMiddleware;
use AzGuard\Laravel\Http\RouteChecks;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Http\Controllers\ChildOfSkippedController;
use AzGuard\Tests\Fixtures\Http\Controllers\ClientController;
use AzGuard\Tests\Fixtures\Http\Controllers\ClientListController;
use AzGuard\Tests\Fixtures\Http\Controllers\OpenController;
use AzGuard\Tests\Fixtures\Http\Controllers\ReportController;
use AzGuard\Tests\Fixtures\Http\Controllers\SkippedController;
use AzGuard\Tests\Fixtures\Http\CountingCheck;
use AzGuard\Tests\Fixtures\Http\EntryPermission;
use AzGuard\Tests\Fixtures\Http\EntryPolicy;
use AzGuard\Tests\Fixtures\Http\HttpWorld;
use AzGuard\Tests\Fixtures\Http\OlderRouterRoute;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/*
 * V83: `#[CheckPermission]` is controller middleware the router applies; `azguard.panel` adds only what the router did
 * not apply, so a check never runs twice. Strict mode refuses an action without any check.
 */

const UPDATE_CHECK = 'azguard.can:clients.update,client';
const ENTER_CHECK = 'azguard.can:'.EntryPermission::class.'::Enter';

beforeEach(function (): void {
    HttpWorld::seed();
    Gate::define('crm-open', static fn (?object $user): bool => $user !== null && $user->getKey() === 1);
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

/** Registers a route of the given class, so it can stand for an older router. */
function httpRoute(string $uri, array $action, array $middleware = ['azguard.panel:crm'], string $class = LaravelRoute::class, string $generation = 'laravel-12'): LaravelRoute
{
    $route = new $class(['GET', 'HEAD'], $uri, ['uses' => $action[0].'@'.$action[1], 'controller' => $action[0].'@'.$action[1], 'middleware' => [...HttpWorld::BINDINGS, ...$middleware]]);

    if ($route instanceof OlderRouterRoute) {
        $route->generation = $generation;
    }
    $route->setRouter(app('router'))->setContainer(app());
    app('router')->getRoutes()->add($route);

    return $route;
}

/** Counts the runs of `azguard.can` checks during the callback. */
function countChecks(Closure $callback): int
{
    Route::aliasMiddleware(CheckPermissionMiddleware::ALIAS, CountingCheck::class);
    CountingCheck::$runs = 0;

    try {
        $callback();
    } finally {
        Route::aliasMiddleware(CheckPermissionMiddleware::ALIAS, CheckPermissionMiddleware::class);
    }

    return CountingCheck::$runs;
}

it('encodes an enum case as Enum::Case and a string as written, with the route parameter', function (): void {
    expect(CheckPermissionMiddleware::using(ClientPermission::Update, 'client'))->toBe('azguard.can:'.ClientPermission::class.'::Update,client')
        ->and(CheckPermissionMiddleware::using('clients.view'))->toBe('azguard.can:clients.view')
        ->and(new CheckPermission(EntryPermission::Enter))->toBeInstanceOf(Middleware::class)
        ->and((new CheckPermission(EntryPermission::Enter))->middleware)->toBe(ENTER_CHECK)
        ->and((new CheckPermission('clients.view', only: ['index']))->appliesTo('index'))->toBeTrue()
        ->and((new CheckPermission('clients.view', only: ['index']))->appliesTo('export'))->toBeFalse()
        ->and((new CheckPermission('clients.view', except: ['export']))->appliesTo('export'))->toBeFalse();
});

it('reads the checks of an action in the order of the router: parents, the controller, the action', function (): void {
    $checks = new RouteChecks;
    $rebuild = httpRoute('/r/{client}', [ReportController::class, 'rebuild']);

    expect(array_map(static fn (CheckPermission $check): string => $check->middleware, $checks->attributes($rebuild)))->toBe([ENTER_CHECK, UPDATE_CHECK])
        ->and($checks->attributes(httpRoute('/l', [ClientListController::class, 'index'])))->toHaveCount(1)
        ->and($checks->attributes(httpRoute('/e', [ClientListController::class, 'export'])))->toBe([])
        ->and($checks->attributes(Route::get('/closure', static fn () => 'x')))->toBe([]);
});

it('adds only the checks the router did not apply, for every router generation', function (string $class, string $generation, array $missing): void {
    $route = httpRoute('/rebuild/{client}', [ReportController::class, 'rebuild'], class: $class, generation: $generation);
    $checks = new RouteChecks;

    expect($checks->missing($route))->toBe($missing)
        ->and(array_values(array_filter($route->gatherMiddleware(), static fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'azguard.can:'))))
        ->toBe(array_values(array_diff([ENTER_CHECK, UPDATE_CHECK], $missing)));
})->with([
    'Laravel 11/12: no controller attributes' => [OlderRouterRoute::class, 'laravel-12', [ENTER_CHECK, UPDATE_CHECK]],
    'Laravel 13.0–13.4: no parent attributes' => [OlderRouterRoute::class, 'laravel-13.4', [ENTER_CHECK]],
    'Laravel 13.5+: everything applied' => [LaravelRoute::class, 'laravel-13.5', []],
]);

it('runs each check of an action exactly once whatever the router applied', function (string $class, string $generation): void {
    HttpWorld::panel();
    httpRoute('/rebuild/{client}', [ReportController::class, 'rebuild'], class: $class, generation: $generation);

    HttpWorld::actingAs(1);
    expect(countChecks(fn () => $this->get('/rebuild/1', ['X-Tenant' => '1'])->assertOk()->assertSeeText('rebuilt 1')))->toBe(2);
    HttpWorld::actingAs(2);
    expect(countChecks(fn () => $this->get('/rebuild/1', ['X-Tenant' => '1'])->assertForbidden()))->toBe(2);
    EntryPolicy::$allow = false;
    HttpWorld::actingAs(1);
    expect(countChecks(fn () => $this->get('/rebuild/1', ['X-Tenant' => '1'])->assertForbidden()))->toBe(1);
})->with([
    'Laravel 11/12' => [OlderRouterRoute::class, 'laravel-12'],
    'Laravel 13.0–13.4' => [OlderRouterRoute::class, 'laravel-13.4'],
    'Laravel 13.5+' => [LaravelRoute::class, 'laravel-13.5'],
]);

it('checks a method attribute against the model of the route and answers with the status and message of the attribute', function (): void {
    HttpWorld::panel();
    Route::middleware([...HttpWorld::BINDINGS, 'azguard.panel:crm'])->group(function (): void {
        Route::get('/clients/{client}/update', [ClientController::class, 'update']);
        Route::get('/clients/{client}', [ClientController::class, 'show']);
    });

    HttpWorld::actingAs(1);
    $this->get('/clients/1/update', ['X-Tenant' => '1'])->assertOk()->assertSeeText('updated 1');
    $this->get('/clients/2/update', ['X-Tenant' => '1'])->assertForbidden();
    $this->getJson('/clients/1', ['X-Tenant' => '1'])->assertOk();

    HttpWorld::actingAs(2);
    $this->get('/clients/1/update', ['X-Tenant' => '1'])->assertForbidden();
    $this->getJson('/clients/1', ['X-Tenant' => '1'])->assertNotFound()->assertExactJson(['message' => 'No such client.']);

    // Anna views client 5 of organization B as an analyst of P4 there, never through a request for organization A.
    HttpWorld::actingAs(1);
    $this->getJson('/clients/5', ['X-Tenant' => '2'])->assertOk();
    $this->getJson('/clients/5', ['X-Tenant' => '1'])->assertNotFound();
});

it('applies a class attribute only to the actions of only', function (): void {
    HttpWorld::panel();
    Route::middleware([...HttpWorld::BINDINGS, 'azguard.panel:crm'])->group(function (): void {
        Route::get('/clients', [ClientListController::class, 'index']);
        Route::get('/export', [ClientListController::class, 'export']);
    });

    HttpWorld::actingAs(1);
    $this->get('/clients', ['X-Tenant' => '1'])->assertForbidden();
    $this->get('/clients', ['X-Tenant' => '1', 'X-Project' => '1'])->assertOk();
    $this->get('/export', ['X-Tenant' => '1'])->assertOk()->assertSeeText('export');
});

it('refuses an unchecked action in strict mode: an exception in testing, 403 in production', function (): void {
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->requireRouteChecks());
    Route::middleware([...HttpWorld::BINDINGS, 'azguard.panel:crm'])->group(function (): void {
        Route::get('/export', [ClientListController::class, 'export']);
        Route::get('/unchecked', [OpenController::class, 'unchecked']);
        Route::get('/closure', static fn (): string => 'closure');
    });
    HttpWorld::actingAs(1);

    foreach (['/export', '/unchecked', '/closure'] as $uri) {
        $this->withoutExceptionHandling();
        expect(fn () => $this->get($uri, ['X-Tenant' => '1']))->toThrow(MissingPermissionCheckException::class);
        $this->withExceptionHandling();
    }

    app()->detectEnvironment(static fn (): string => 'production');

    try {
        foreach (['/export', '/unchecked', '/closure'] as $uri) {
            $this->getJson($uri, ['X-Tenant' => '1'])->assertForbidden()->assertExactJson(['message' => 'This action is unauthorized.']);
        }
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }
    expect((new MissingPermissionCheckException('x'))->code())->toBe('missing_permission_check');
});

it('counts azguard.can, Laravel can and #[Authorize] as checks and lets #[SkipPermissionCheck] opt out in strict mode', function (): void {
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->requireRouteChecks());
    Route::middleware([...HttpWorld::BINDINGS, 'azguard.panel:crm'])->group(function (): void {
        Route::get('/ping', [OpenController::class, 'ping']);
        Route::get('/gated', [OpenController::class, 'gated']);
        Route::get('/health', [SkippedController::class, 'health']);
        Route::get('/can', static fn (): string => 'can')->can('crm-open');
        Route::get('/clients/{client}/route-check', static fn (Client $client): string => 'route check')->middleware('azguard.can:clients.view,client');
        Route::get('/clients', [ClientListController::class, 'index']);
        Route::get('/child', [ChildOfSkippedController::class, 'open']);
    });
    HttpWorld::actingAs(1);
    $headers = ['X-Tenant' => '1', 'X-Project' => '1'];

    $this->get('/ping', $headers)->assertOk()->assertSeeText('pong');
    $this->get('/gated', $headers)->assertOk()->assertSeeText('gated');
    $this->get('/health', $headers)->assertOk();
    $this->get('/can', $headers)->assertOk();
    $this->get('/clients/1/route-check', $headers)->assertOk();
    $this->get('/clients', $headers)->assertOk();
    $this->withoutExceptionHandling();
    expect(fn () => $this->get('/child', $headers))->toThrow(MissingPermissionCheckException::class);
});

it('takes an enum of one panel as the enum and leaves an enum of several panels ambiguous inside the request panel', function (): void {
    HttpWorld::panel();
    Route::middleware([...HttpWorld::BINDINGS, 'azguard.panel:crm'])->group(function (): void {
        Route::get('/enter', static fn (): string => 'entered')->middleware(CheckPermissionMiddleware::using(EntryPermission::Enter));
        Route::get('/shared', static fn (): string => 'shared')->middleware(CheckPermissionMiddleware::using(ClientPermission::ViewAny));
    });
    HttpWorld::actingAs(1);

    $this->get('/enter', ['X-Tenant' => '1'])->assertOk();
    EntryPolicy::$allow = false;
    $this->get('/enter', ['X-Tenant' => '1'])->assertForbidden();
    // An enum is an explicit signal; the panel of the request does not pick one of its panels (the panel rule).
    $this->withoutExceptionHandling();
    expect(fn () => $this->get('/shared', ['X-Tenant' => '1', 'X-Project' => '1']))->toThrow(AmbiguousPanelException::class);
});

it('leaves routes outside strict mode alone', function (): void {
    HttpWorld::panel();
    Route::middleware([...HttpWorld::BINDINGS, 'azguard.panel:crm'])->get('/unchecked', [OpenController::class, 'unchecked']);
    HttpWorld::actingAs(1);

    $this->get('/unchecked', ['X-Tenant' => '1'])->assertOk();
});

it('refuses an excluded check in strict mode', function (string $kind, string $class, string $generation): void {
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->requireRouteChecks());
    $route = $kind === 'attribute'
        ? httpRoute('/excluded/{client}', [ClientController::class, 'update'], class: $class, generation: $generation)
        : Route::middleware([...HttpWorld::BINDINGS, 'azguard.panel:crm'])->get('/excluded/{client}', static fn (): string => 'unchecked');
    $middleware = match ($kind) {
        'attribute', 'azguard' => UPDATE_CHECK,
        default => 'can:crm-open',
    };

    if ($kind !== 'attribute') {
        $route->middleware($middleware);
    }
    $route->withoutMiddleware($middleware);
    HttpWorld::actingAs(2);
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/excluded/1', ['X-Tenant' => '1']))->toThrow(MissingPermissionCheckException::class);
    $this->withExceptionHandling();
    app()->detectEnvironment(static fn (): string => 'production');
    $this->getJson('/excluded/1', ['X-Tenant' => '1'])->assertForbidden();
})->with([
    'attribute on Laravel 11/12' => ['attribute', OlderRouterRoute::class, 'laravel-12'],
    'attribute on Laravel 13.0-13.4' => ['attribute', OlderRouterRoute::class, 'laravel-13.4'],
    'attribute on Laravel 13.5+' => ['attribute', LaravelRoute::class, 'laravel-13.5'],
    'route azguard.can' => ['azguard', LaravelRoute::class, 'laravel-13.5'],
    'route Laravel can' => ['can', LaravelRoute::class, 'laravel-13.5'],
]);
