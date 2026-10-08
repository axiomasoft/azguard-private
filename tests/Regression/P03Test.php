<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Gate\GateWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

it('P03 honors denied hooks and sources across decide batch explain Gate inspect Blade azguard.can and CLI', function (bool $hook): void {
    GateWorld::seed();
    $source = new GeneratedSource(direct: [AuthorizationWorld::grant()]);
    $deny = false;
    [$engine, $panel, $request] = GateWorld::compile($source, function (PanelBuilder $p) use ($hook, &$deny): void {
        $p->before(function () use ($hook, &$deny): BeforeResult {
            return $hook && $deny ? BeforeResult::Deny : BeforeResult::Continue;
        });
    });
    $user = User::findOrFail(1);
    Gate::swap(Gate::forUser($user));
    // The stand's user is a plain model: a request guard authenticates it for the azguard.can route.
    Auth::viaRequest('p03', static fn (): User => User::findOrFail(1));
    config(['auth.guards.web' => ['driver' => 'p03'], 'app.debug' => false]);
    Auth::forgetGuards();
    Route::get('/p03', static fn (): string => 'allowed')->middleware('azguard.can:admin:orders.view');
    expect($engine->decide($panel, $request)->allowed())->toBeTrue()
        ->and(AzGuard::check($user, 'admin:orders.view'))->toBeTrue()
        ->and(AzGuard::panel('admin')->for($user)->hasPermission('orders.view'))->toBeTrue()
        ->and(AzGuard::panel('admin')->decide($request)->allowed())->toBeTrue()
        ->and(Gate::allows('admin:orders.view'))->toBeTrue()
        ->and(Blade::render("@can('admin:orders.view')\nyes\n@else\nno\n@endcan"))->toContain('yes');
    $this->get('/p03')->assertOk()->assertSeeText('allowed');
    $deny = true;

    if (! $hook) {
        $source->direct = [];
    }
    expect($engine->decide($panel, $request)->allowed())->toBeFalse()
        ->and($engine->decideMany([$request])->get(0)->allowed())->toBeFalse()
        ->and($engine->explain($panel, $request)->decision()->allowed())->toBeFalse()
        ->and(AzGuard::check($user, 'admin:orders.view'))->toBeFalse()
        ->and(fn () => AzGuard::authorize($user, 'admin:orders.view'))->toThrow(AuthorizationException::class)
        ->and(AzGuard::panel('admin')->for($user)->hasPermission('orders.view'))->toBeFalse()
        ->and(AzGuard::panel('admin')->decide($request)->allowed())->toBeFalse()
        ->and(AzGuard::panel('admin')->decideMany([$request])->get(0)->allowed())->toBeFalse()
        ->and(AzGuard::panel('admin')->explain($request)->decision()->allowed())->toBeFalse()
        ->and(Gate::allows('admin:orders.view'))->toBeFalse()
        ->and(Gate::inspect('admin:orders.view')->denied())->toBeTrue()
        ->and(Blade::render("@can('admin:orders.view')\nyes\n@else\nno\n@endcan"))->toContain('no')->not->toContain('yes');
    $this->get('/p03')->assertForbidden();
    expect(Artisan::call('azguard:explain', ['subject' => 'user:1', 'permission' => 'admin:orders.view', '--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['decision']['effect'])->toBe('deny');
    Carbon::setTestNow();
    Relation::morphMap([], false);
})->with([false, true]);
