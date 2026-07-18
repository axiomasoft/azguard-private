<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Http\Middleware\SetCurrentPanel;
use AzGuard\Panels\Panel;
use Illuminate\Support\Facades\Route;

it('sets and resets current panel around request lifecycle', function (): void {
    AzGuard::setCurrentPanel(panel: null);

    AzGuard::registerPanel(
        panel: Panel::make()->id(id: 'web')->label(label: 'Web'),
    );

    Route::middleware([SetCurrentPanel::class.':web'])
        ->get('/set-current-panel-test', fn (): string => (string) AzGuard::currentPanel()?->getId());

    $this->get('/set-current-panel-test')
        ->assertOk()
        ->assertSee('web');

    expect(AzGuard::currentPanel())->toBeNull();
});

it('fails with 500 when panel is not registered', function (): void {
    Route::middleware([SetCurrentPanel::class.':unknown'])
        ->get('/set-current-panel-unknown', fn (): string => 'ok');

    $this->get('/set-current-panel-unknown')
        ->assertStatus(500)
        ->assertSee('AzGuard panel [unknown] is not registered.');
});
