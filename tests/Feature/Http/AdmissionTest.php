<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\FailingSource;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Http\EntryPermission;
use AzGuard\Tests\Fixtures\Http\HttpMemberRole;
use AzGuard\Tests\Fixtures\Http\HttpWorld;
use AzGuard\Tests\Fixtures\Http\TraceMiddleware;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
 * V73: entering a panel over HTTP takes an accepted subject that is a member of the tenant and holds a qualifying role
 * of the panel there; direct grants and policy allows never admit. An optional entry permission is decided AND.
 */

beforeEach(function (): void {
    HttpWorld::seed();
    Route::middleware([...HttpWorld::BINDINGS, 'azguard.panel:crm'])->group(function (): void {
        Route::get('/crm', static fn (): array => ['panel' => app(CurrentPanel::class)->get()?->id()]);
        Route::get('/crm/clients/{client}/peek', static function (Client $client): array {
            $user = User::query()->findOrFail((int) HttpWorld::$user);
            $decide = static fn (ClientPermission $permission, ?Client $resource): bool => app(Authorizer::class)->decide(
                app(CurrentPanel::class)->get() ?? throw new LogicException('no panel'),
                AccessRequest::for(SubjectRef::of('crm.user', (int) $user->getKey()), PermissionKey::of('crm', $permission->value))
                    ->inTenant(CrmWorld::scope()->tenant)->on(null, $resource),
            )->allowed();

            return ['tenant_wide' => $decide(ClientPermission::ViewAny, null), 'client' => $decide(ClientPermission::View, $client)];
        });
    });
});
afterEach(function (): void {
    TraceMiddleware::$panels = [];
    CrmWorld::resetRuntime();
    HttpMemberRole::$users = [];
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('admits a member with a qualifying stored role before a project is chosen and makes the panel the panel of the request', function (int $user): void {
    HttpWorld::panel();
    HttpWorld::actingAs($user);

    $this->getJson('/crm', ['X-Tenant' => '1'])->assertOk()->assertExactJson(['panel' => 'crm']);
    expect(app(CurrentPanel::class)->get())->toBeNull();
})->with([
    'seller of P1' => 1,
    'seller of P2' => 2,
    'tenant admin (super admin) of P1' => 3,
]);

it('admits a project-only role in the tenant of its project without granting tenant-wide permissions', function (): void {
    HttpWorld::panel();
    HttpWorld::member(4);
    CrmWorld::assign('caller', 4, 1);
    HttpWorld::actingAs(4);

    $this->getJson('/crm', ['X-Tenant' => '1'])->assertOk();
    $this->getJson('/crm/clients/1/peek', ['X-Tenant' => '1'])->assertOk()->assertExactJson(['tenant_wide' => false, 'client' => true]);
    $this->getJson('/crm', ['X-Tenant' => '2'])->assertForbidden();
});

it('refuses entry without a qualifying role', function (Closure $arrange, string $tenant): void {
    HttpWorld::panel();
    HttpWorld::member(4);
    $arrange();
    HttpWorld::actingAs(4);

    $this->getJson('/crm', ['X-Tenant' => $tenant])->assertForbidden()
        ->assertExactJson(['message' => 'This action is unauthorized.']);
})->with([
    'nothing' => [static fn () => null, '1'],
    'direct grant only' => [static fn () => CrmWorld::assign('clients.view', 4, 1, kind: 'permission'), '1'],
    'expired role' => [static function (): void {
        CrmWorld::assign('seller', 4, 1);
        HttpWorld::expire(4);
    }, '1'],
    'role in an inactive project' => [static fn () => CrmWorld::assign('seller', 4, 3), '1'],
    'role in another tenant' => [static fn () => CrmWorld::assign('seller', 4, 1), '2'],
    'unknown role key' => [static fn () => CrmWorld::assign('ghost', 4, 1), '1'],
    'role of another panel' => [static fn () => CrmWorld::assign('seller', 4, 1, panel: 'backoffice'), '1'],
]);

it('refuses a role holder who is not a member of the tenant', function (): void {
    HttpWorld::panel();
    CrmWorld::assign('seller', 4, 1);
    HttpWorld::actingAs(4);

    $this->getJson('/crm', ['X-Tenant' => '1'])->assertForbidden();
    HttpWorld::member(4);
    HttpWorld::actingAs(4);
    $this->getJson('/crm', ['X-Tenant' => '1'])->assertOk();
});

it('refuses a model the panel does not accept and sends a guest to authentication', function (): void {
    HttpWorld::panel();
    HttpWorld::actingAs(1, organization: true);
    $this->getJson('/crm', ['X-Tenant' => '1'])->assertForbidden();

    HttpWorld::actingAs(null);
    $this->getJson('/crm', ['X-Tenant' => '1'])->assertUnauthorized();
});

it('requires the tenant of a panel with required tenants', function (): void {
    HttpWorld::panel();
    HttpWorld::actingAs(1);

    $this->getJson('/crm')->assertForbidden();
});

it('admits a code role granted automatically and a role from a relation source', function (): void {
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->roles([HttpMemberRole::class]));
    HttpWorld::member(4);
    HttpWorld::actingAs(4);
    $this->getJson('/crm', ['X-Tenant' => '1'])->assertForbidden();
    HttpMemberRole::$users = [4];
    HttpWorld::actingAs(4);
    $this->getJson('/crm', ['X-Tenant' => '1'])->assertOk();

    HttpMemberRole::$users = [];
    Project::query()->findOrFail(1)->members()->attach(4, ['role' => 'seller']);
    HttpWorld::panel(sources: [CrmWorld::database(), RelationSource::make(ProjectScope::make(), 'members', 'pivot.role')]);
    HttpWorld::actingAs(4);
    $this->getJson('/crm', ['X-Tenant' => '1'])->assertOk();
});

it('refuses entry when a source fails or cannot select the assignment scopes of its roles', function (): void {
    HttpWorld::panel(sources: [CrmWorld::database(), new FailingSource]);
    HttpWorld::actingAs(1);
    $this->getJson('/crm', ['X-Tenant' => '1'])->assertForbidden();

    // An automatic code role cannot tell in which projects it applies: the project witness of a stored role is not enough.
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->roles([HttpMemberRole::class]));
    HttpWorld::actingAs(1);
    $this->getJson('/crm', ['X-Tenant' => '1'])->assertForbidden();
    $this->getJson('/crm', ['X-Tenant' => '1', 'X-Project' => '1'])->assertOk();
});

it('decides the entry permission AND in the scope of the request', function (): void {
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->entry(ClientPermission::ViewAny));
    HttpWorld::actingAs(1);

    // Anna sells in P1 only: view_any is not hers tenant-wide, it is in P1.
    $this->getJson('/crm', ['X-Tenant' => '1'])->assertForbidden();
    $this->getJson('/crm', ['X-Tenant' => '1', 'X-Project' => '1'])->assertOk();
});

it('never lets a policy allow of the entry permission replace the role', function (): void {
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->entry(EntryPermission::Enter));
    HttpWorld::member(4);
    HttpWorld::actingAs(4);
    $this->getJson('/crm', ['X-Tenant' => '1'])->assertForbidden();

    HttpWorld::actingAs(1);
    $this->getJson('/crm', ['X-Tenant' => '1'])->assertOk();
});

it('lets a veto of the entry permission stop a super admin', function (): void {
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->entry(ClientPermission::ViewAny)
        ->before(static fn (): BeforeResult => BeforeResult::Deny));
    HttpWorld::actingAs(3);
    $this->getJson('/crm', ['X-Tenant' => '1', 'X-Project' => '1'])->assertForbidden();

    User::query()->whereKey(3)->update(['locked_at' => '2026-10-01 00:00:00']);
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->entry(ClientPermission::ViewAny));
    HttpWorld::actingAs(3);
    $this->getJson('/crm', ['X-Tenant' => '1', 'X-Project' => '1'])->assertForbidden();

    User::query()->whereKey(3)->update(['locked_at' => null]);
    HttpWorld::actingAs(3);
    $this->getJson('/crm', ['X-Tenant' => '1', 'X-Project' => '1'])->assertOk();
});

it('answers a denied entry with the onDenied response of the panel', function (): void {
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->onDenied(static fn ($request, $panel) => response('choose a tenant of '.$panel->id(), 409)));
    HttpWorld::actingAs(4);
    $this->get('/crm', ['X-Tenant' => '1'])->assertStatus(409)->assertSeeText('choose a tenant of crm');

    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->onDenied('/tenants'));
    HttpWorld::actingAs(4);
    $this->get('/crm', ['X-Tenant' => '1'])->assertRedirect('/tenants');

    Route::get('/choose', static fn (): string => 'choose')->name('tenants.choose');
    Route::getRoutes()->refreshNameLookups();
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->onDenied('tenants.choose'));
    HttpWorld::actingAs(4);
    $this->get('/crm', ['X-Tenant' => '1'])->assertRedirect('/choose');
});

it('runs the middleware of the panel before admission, once when the route runs it too', function (): void {
    Route::aliasMiddleware('crm.trace', TraceMiddleware::class);
    Route::middleware([TraceMiddleware::class, 'azguard.panel:crm'])->get('/traced', static fn (): string => 'traced');
    HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->middleware(['crm.trace']));
    HttpWorld::actingAs(1);

    $this->getJson('/crm', ['X-Tenant' => '1'])->assertOk();
    expect(TraceMiddleware::$panels)->toBe([null]);

    TraceMiddleware::$panels = [];
    $this->getJson('/traced', ['X-Tenant' => '1'])->assertOk();
    expect(TraceMiddleware::$panels)->toBe([null]);

    TraceMiddleware::$panels = [];
    HttpWorld::actingAs(4);
    $this->getJson('/crm', ['X-Tenant' => '1'])->assertForbidden();
    expect(TraceMiddleware::$panels)->toBe([null]);
});
