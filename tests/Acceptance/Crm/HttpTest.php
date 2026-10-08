<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Exceptions\PermissionNotGrantableException;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Http\EntryPermission;
use AzGuard\Tests\Fixtures\Http\HttpWorld;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/*
 * CRM over real HTTP requests: `azguard.panel:crm` admission, `azguard.can` and Laravel `can` on the same actions, and
 * one application serving request after request.
 */

beforeEach(function (): void {
    HttpWorld::authenticate();
    Route::middleware([...HttpWorld::BINDINGS, 'azguard.panel:crm'])->group(function (): void {
        Route::get('/crm', static fn (): string => 'crm');
        Route::get('/clients/{client}/update', static fn (Client $client): string => 'updated')->middleware('azguard.can:clients.update,client');
        Route::get('/clients/{client}/gate', static fn (Client $client): string => 'updated')->middleware('can:crm:clients.update,client');
        Route::get('/clients/{client}/profile', static fn (Client $client): string => 'profile')->middleware('azguard.can:clients.view_own_profile,client');
        Route::get('/clients/{client}/facade', static fn (Client $client): array => [
            'facade' => AzGuard::check(User::query()->findOrFail((int) HttpWorld::$user), 'clients.update', $client),
        ]);
        Route::post('/users/{user}/grants', static function (User $user): string {
            $user->grantPermission(request()->string('permission')->toString(), Project::query()->findOrFail(1));

            return 'granted';
        });
        Route::get('/request', static fn (): array => [
            'user' => HttpWorld::$user,
            'panel' => app(CurrentPanel::class)->get()?->id(),
            'tenant' => app(CurrentContext::class)->get(app(PanelRegistry::class)->get('crm'))?->tenant->key(),
            'authorizer' => spl_object_id(app(Authorizer::class)),
            'update' => AzGuard::check(User::query()->findOrFail((int) HttpWorld::$user), 'clients.update', Client::query()->findOrFail(1)),
        ]);
        Route::get('/fails', static fn (): never => throw new RuntimeException('request A failed'));
    });
});

it('R39 answers one action alike through azguard.can, Laravel can and the facade', function (): void {
    HttpWorld::panel();

    foreach ([1 => [1 => 200, 2 => 403], 2 => [1 => 403, 3 => 200]] as $user => $clients) {
        HttpWorld::actingAs($user);

        foreach ($clients as $client => $status) {
            $this->get("/clients/{$client}/update", ['X-Tenant' => '1'])->assertStatus($status);
            $this->get("/clients/{$client}/gate", ['X-Tenant' => '1'])->assertStatus($status);
            $this->getJson("/clients/{$client}/facade", ['X-Tenant' => '1'])->assertExactJson(['facade' => $status === 200]);
        }
    }
    expect(Gate::has('crm:clients.update'))->toBeFalse();
});

it('R39 refuses entry without a role even with a direct grant or a policy allow', function (): void {
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->entry(EntryPermission::Enter));
    HttpWorld::member(4);
    World::assign('clients.view', 4, 1, kind: 'permission');
    HttpWorld::actingAs(4);

    $this->get('/crm', ['X-Tenant' => '1'])->assertForbidden();
    expect(AzGuard::check(User::query()->findOrFail(4), 'crm:clients.view', Client::query()->findOrFail(1)))->toBeTrue();
});

it('R39 lets a caller of P1 into organization A only, with every action still decided in its project', function (): void {
    HttpWorld::panel();
    HttpWorld::member(4);
    HttpWorld::member(4, 2);
    World::assign('caller', 4, 1);
    HttpWorld::actingAs(4);

    $this->get('/crm', ['X-Tenant' => '1'])->assertOk();
    $this->get('/crm', ['X-Tenant' => '2'])->assertForbidden();
    $this->get('/clients/1/update', ['X-Tenant' => '1'])->assertOk();
    $this->get('/clients/3/update', ['X-Tenant' => '1'])->assertForbidden();
    $this->get('/clients/6/update', ['X-Tenant' => '1'])->assertForbidden();
});

it('R39 refuses an expired role, a role in an inactive project and a role of another organization', function (): void {
    HttpWorld::panel();
    HttpWorld::member(4);
    HttpWorld::actingAs(4);

    World::assign('seller', 4, 1);
    HttpWorld::expire(4);
    $this->get('/crm', ['X-Tenant' => '1'])->assertForbidden();
    World::clear(4);
    World::assign('seller', 4, 3);
    $this->get('/crm', ['X-Tenant' => '1'])->assertForbidden();
    World::clear(4);
    World::assign('seller', 4, 4, 2);
    $this->get('/crm', ['X-Tenant' => '1'])->assertForbidden();
});

it('R39 decides the entry permission AND and lets its veto stop the tenant admin', function (): void {
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->entry(ClientPermission::ViewAny)->before(static fn (): BeforeResult => BeforeResult::Deny));
    HttpWorld::actingAs(3);
    $this->get('/crm', ['X-Tenant' => '1', 'X-Project' => '1'])->assertForbidden();

    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->entry('clients.view_any'));
    HttpWorld::actingAs(3);
    $this->get('/crm', ['X-Tenant' => '1', 'X-Project' => '1'])->assertOk();
});

it('R54 serves requests of A and B from one application without carrying the user, panel, scope or memo over', function (): void {
    HttpWorld::panel();
    $octane = static function (): void {
        // What an Octane worker does between requests: scoped services and resolved guards are dropped.
        app()->forgetScopedInstances();
        Auth::forgetGuards();
    };

    HttpWorld::actingAs(1);
    $a = $this->getJson('/request', ['X-Tenant' => '1'])->assertOk()->json();
    $octane();
    HttpWorld::$user = 2;
    $b = $this->getJson('/request', ['X-Tenant' => '1'])->assertOk()->json();
    $octane();
    HttpWorld::$user = 1;
    $this->getJson('/fails', ['X-Tenant' => '1'])->assertStatus(500);
    $octane();
    HttpWorld::$user = 2;
    $c = $this->getJson('/request', ['X-Tenant' => '1'])->assertOk()->json();

    expect([$a['user'], $a['panel'], $a['tenant'], $a['update']])->toBe([1, 'crm', 'crm.organization:1', true])
        ->and([$b['user'], $b['panel'], $b['tenant'], $b['update']])->toBe([2, 'crm', 'crm.organization:1', false])
        ->and([$c['user'], $c['update']])->toBe([2, false])
        ->and($b['authorizer'])->not->toBe($a['authorizer'])
        ->and(app(CurrentPanel::class)->get())->toBeNull()
        ->and(app(CurrentContext::class)->get(app(PanelRegistry::class)->get('crm')))->toBeNull();
});

it('R63 refuses a grant of the PolicyOnly action over HTTP without a write, and a wildcard never authorizes it', function (): void {
    HttpWorld::panel();
    HttpWorld::actingAs(1);
    $before = [W::rows('role'), W::rows('permission'), W::version()];

    $this->withoutExceptionHandling();
    expect(fn () => $this->post('/users/2/grants', ['permission' => 'clients.view_own_profile'], ['X-Tenant' => '1']))
        ->toThrow(PermissionNotGrantableException::class)
        ->and([W::rows('role'), W::rows('permission'), W::version()])->toBe($before);
    $this->withExceptionHandling();

    $this->post('/users/1/grants', ['permission' => 'clients.*'], ['X-Tenant' => '1'])->assertOk();
    $this->get('/clients/6/profile', ['X-Tenant' => '1'])->assertForbidden();
    $this->get('/clients/1/profile', ['X-Tenant' => '1'])->assertOk();
});
