<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Laravel\Gate\GateBridge;
use AzGuard\Laravel\Http\DecisionResponder;
use AzGuard\Tests\Fixtures\Filament\BootsFilament;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Pages\ReportsPage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(BootsFilament::class);

it('keeps default failures generic and maps only opt-in responses', function (DecisionReason $reason, int $mapped): void {
    config(['app.debug' => false]);
    $decision = Decision::deny($reason, CodeStateToken::of('admin', 'build', str_repeat('b', 64)), AccessScope::in(TenantRef::global()), 'SecretSubject-123');
    Route::get('/failure-default', static fn () => GateBridge::toGateResult($decision)->authorize());
    Route::get('/failure-opt-in', static fn () => DecisionResponder::authorize($decision));

    foreach (['/failure-default' => 403, '/failure-opt-in' => $mapped] as $uri => $status) {
        $response = $this->getJson($uri);
        expect($response->status())->toBe($status)
            ->and($response->getContent())->not->toContain('SecretSubject-123', $reason->value, 'grant', 'tenant', 'trace');

        if ($status === 503) {
            expect($response->headers->get('Retry-After'))->toBe('5');
        }
    }
})->with([
    [DecisionReason::NotGranted, 403],
    [DecisionReason::SourceError, 503],
    [DecisionReason::ConsistencyError, 503],
    [DecisionReason::PolicyError, 500],
    [DecisionReason::HookError, 500],
    [DecisionReason::RestrictionError, 500],
    [DecisionReason::ConditionError, 500],
    [DecisionReason::AssignmentScopeFilterError, 500],
]);

it('keeps a revoked Livewire call generic', function (): void {
    GateWorld::prepare();
    FilamentFixture::$memberPermissions = [];
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));
    GateWorld::grant(['pages.reports', 'orders.delete']);
    $page = Livewire::test(ReportsPage::class);
    GateWorld::revoke('pages.reports');
    $result = $page->call('deleteOrder');
    $result->assertForbidden();
    expect(invade($result)->lastState->getResponse()->getContent())->not->toContain('not_granted', 'source_error', 'grant', 'tenant', 'trace');
    FilamentFixture::reset();
});
