<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Http\HttpWorld;
use AzGuard\Tests\Fixtures\Http\TraceMiddleware;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
 * V103 (request part): one application serves request after request, as an Octane worker does. The panel of a request
 * and the scopes chosen inside it never reach the next request, also when the controller throws or entry is denied.
 */

beforeEach(function (): void {
    HttpWorld::seed();
    HttpWorld::panel();
    Route::middleware(['azguard.panel:crm', TraceMiddleware::class])->group(function (): void {
        Route::get('/inside', static fn (): array => [
            'panel' => app(CurrentPanel::class)->get()?->id(),
            'scope' => AzGuard::withinScope(AssignmentScopeRef::of('crm.project', 1), static fn (): ?string => AzGuard::currentScope()?->key()),
            'after' => AzGuard::currentScope()?->key(),
        ]);
        Route::get('/throws', static function (): never {
            AzGuard::withinScope(AssignmentScopeRef::of('crm.project', 1), static fn () => throw new RuntimeException('controller failed'));
        });
    });
    Route::get('/outside', static fn (): array => ['panel' => app(CurrentPanel::class)->get()?->id()]);
});
afterEach(function (): void {
    TraceMiddleware::$panels = [];
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

/** Nothing of a request is left in the application between requests. */
function expectNoRequestState(): void
{
    $crm = app(PanelRegistry::class)->get('crm');

    expect(app(CurrentPanel::class)->get())->toBeNull()
        ->and(app(CurrentContext::class)->get($crm))->toBeNull();
}

it('leaves no panel and no scope after a request, for the next request of the same application', function (): void {
    HttpWorld::actingAs(1);
    $this->getJson('/inside', ['X-Tenant' => '1'])->assertOk()
        ->assertExactJson(['panel' => 'crm', 'scope' => 'crm.project:1', 'after' => null]);
    expectNoRequestState();

    $this->getJson('/outside')->assertOk()->assertExactJson(['panel' => null]);
    HttpWorld::actingAs(2);
    $this->getJson('/inside', ['X-Tenant' => '1'])->assertOk()->assertJson(['panel' => 'crm']);
    expectNoRequestState();
    expect(TraceMiddleware::$panels)->toBe(['crm', 'crm']);
});

it('restores the panel when the controller throws, when entry is denied and when the guest is sent to log in', function (): void {
    HttpWorld::actingAs(1);
    $this->getJson('/throws', ['X-Tenant' => '1'])->assertStatus(500);
    expectNoRequestState();

    $this->withoutExceptionHandling();
    expect(fn () => $this->getJson('/throws', ['X-Tenant' => '1']))->toThrow(RuntimeException::class, 'controller failed');
    expectNoRequestState();
    $this->withExceptionHandling();

    HttpWorld::actingAs(4);
    $this->getJson('/inside', ['X-Tenant' => '1'])->assertForbidden();
    expectNoRequestState();
    HttpWorld::actingAs(null);
    $this->getJson('/inside', ['X-Tenant' => '1'])->assertUnauthorized();
    expectNoRequestState();
    $this->getJson('/outside')->assertOk()->assertExactJson(['panel' => null]);
});

it('restores the panel that was current before the middleware', function (): void {
    $backoffice = app(PanelRegistry::class)->get('backoffice');
    app(CurrentPanel::class)->set($backoffice);
    HttpWorld::actingAs(1);

    $this->getJson('/inside', ['X-Tenant' => '1'])->assertOk()->assertJson(['panel' => 'crm']);
    expect(app(CurrentPanel::class)->get())->toBe($backoffice);
    app(CurrentPanel::class)->set(null);
});

it('reads entry, the denied response, the middleware and strict mode from the compiled panel', function (): void {
    $denied = static fn () => response('no');
    $panel = HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->entry('clients.view_any')->onDenied($denied)
        ->middleware(['web', 'auth:web'])->middleware(['web', 'throttle:60'])->requireRouteChecks());
    $plain = HttpWorld::panel();

    expect($panel->entry())->toBe('clients.view_any')
        ->and($panel->onDenied())->toBe($denied)
        ->and($panel->middleware())->toBe(['web', 'auth:web', 'throttle:60'])
        ->and($panel->requiresRouteChecks())->toBeTrue()
        ->and($plain->entry())->toBeNull()
        ->and($plain->onDenied())->toBeNull()
        ->and($plain->middleware())->toBe([])
        ->and($plain->requiresRouteChecks())->toBeFalse();
});
