<?php

declare(strict_types=1);

use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use Filament\Facades\Filament;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

/*
 * V24: two Filament panels with different guard panels do not influence each other; the plugin keeps its state in
 * its instance and enters the guard panel on every request to the Filament panel, also on a Livewire update.
 */

it('gives every Filament panel its own plugin instance and guard panel without writing the configuration', function (): void {
    $before = config('azguard-filament');

    $admin = Filament::getPanel('admin');
    $backoffice = Filament::getPanel('backoffice');
    $admin->boot();
    $backoffice->boot();

    $adminPlugin = $admin->getPlugin('azguard');
    $backofficePlugin = $backoffice->getPlugin('azguard');

    expect($adminPlugin)->toBeInstanceOf(AzGuardPlugin::class)
        ->and($adminPlugin)->not->toBe($backofficePlugin)
        ->and($adminPlugin->getGuardPanel())->toBe('admin')
        ->and($adminPlugin->getManages())->toBe(['admin', 'seller'])
        ->and($backofficePlugin->getGuardPanel())->toBe('backoffice')
        ->and($backofficePlugin->getManages())->toBe(['backoffice'])
        ->and(config('azguard-filament'))->toBe($before)
        ->and($before['guard_panel'])->toBeNull();
});

it('enters the guard panel of the Filament panel that serves the request', function (): void {
    $this->actingAs(User::query()->findOrFail(1));

    $this->get('/admin/probe')->assertOk();
    $this->get('/backoffice/probe')->assertOk();
    $this->get('/admin/probe')->assertOk();

    expect(FilamentFixture::$seen)->toBe([
        ['guard' => 'admin', 'filament' => 'admin'],
        ['guard' => 'backoffice', 'filament' => 'backoffice'],
        ['guard' => 'admin', 'filament' => 'admin'],
    ]);
});

it('refuses a user whom the guard panel does not admit', function (): void {
    $this->actingAs(User::query()->findOrFail(2));

    $this->get('/admin/probe')->assertForbidden();
    $this->get('/backoffice/probe')->assertForbidden();
    expect(FilamentFixture::$seen)->toBe([]);
});

/**
 * A Livewire update request replaying the snapshot of the probe page, made by the user who is acting now.
 */
function replayProbeSnapshot(object $test, int $replayedBy): TestResponse
{
    $test->actingAs(User::query()->findOrFail(1));
    $page = $test->get('/admin/probe')->assertOk()->getContent();
    expect(preg_match('/wire:snapshot="([^"]+)"/', (string) $page, $matches))->toBe(1);
    $test->actingAs(User::query()->findOrFail($replayedBy));

    return $test->postJson(
        app(HandleRequests::class)->getUpdateUri(),
        ['components' => [['snapshot' => html_entity_decode($matches[1]), 'updates' => [], 'calls' => []]]],
        ['X-Livewire' => 'true'],
    );
}

it('makes the entry of the guard panel persistent for Livewire', function (): void {
    expect(Livewire::getPersistentMiddleware())->toContain(app(Router::class)->getMiddleware()['azguard.panel']);
});

it('lets a member update a component of the page', function (): void {
    replayProbeSnapshot($this, replayedBy: 1)->assertOk();
});

it('repeats the admission on a Livewire update', function (): void {
    replayProbeSnapshot($this, replayedBy: 2)->assertForbidden();
});
