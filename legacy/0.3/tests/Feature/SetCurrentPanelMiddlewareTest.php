<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Http\Middleware\SetCurrentPanel;
use AzGuard\Panels\Panel;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

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

it('using() builds the same middleware string as the string alias DSL', function (): void {
    expect(SetCurrentPanel::using('web'))->toBe(SetCurrentPanel::class.':web');
});

it('sets the current panel via a route built with ::using()', function (): void {
    AzGuard::setCurrentPanel(panel: null);

    AzGuard::registerPanel(
        panel: Panel::make()->id(id: 'web')->label(label: 'Web'),
    );

    Route::middleware([SetCurrentPanel::using('web')])
        ->get('/set-current-panel-using-test', fn (): string => (string) AzGuard::currentPanel()?->getId());

    $this->get('/set-current-panel-using-test')
        ->assertOk()
        ->assertSee('web');
});

it('restores the previous panel after a nested azguard.panel middleware', function (): void {
    AzGuard::setCurrentPanel(panel: null);

    AzGuard::registerPanel(panel: Panel::make()->id(id: 'outer')->label(label: 'Outer'));
    AzGuard::registerPanel(panel: Panel::make()->id(id: 'inner')->label(label: 'Inner'));

    Route::middleware([SetCurrentPanel::class.':outer'])
        ->get('/nested-current-panel', function (): string {
            $inner = app(SetCurrentPanel::class)->handle(request(), function (): Response {
                expect(AzGuard::currentPanel()?->getId())->toBe('inner');

                return response('inner');
            }, 'inner');

            expect($inner->getContent())->toBe('inner')
                ->and(AzGuard::currentPanel()?->getId())->toBe('outer');

            return (string) AzGuard::currentPanel()?->getId();
        });

    $this->get('/nested-current-panel')
        ->assertOk()
        ->assertSee('outer');

    expect(AzGuard::currentPanel())->toBeNull();
});
