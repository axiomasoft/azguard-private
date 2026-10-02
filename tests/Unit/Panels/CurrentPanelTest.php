<?php

declare(strict_types=1);

use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelRecipe;

function currentPanelFixture(string $id): Panel
{
    return (new PanelCompiler)->compile(new PanelRecipe($id));
}

it('has no panel until one is set', function (): void {
    $current = new CurrentPanel;
    $admin = currentPanelFixture('admin');

    expect($current->get())->toBeNull();

    $current->set($admin);
    expect($current->get())->toBe($admin);

    $current->set(null);
    expect($current->get())->toBeNull();
});

it('runs a callback inside a panel and restores the previous one', function (): void {
    $current = new CurrentPanel;
    $admin = currentPanelFixture('admin');
    $cabinet = currentPanelFixture('cabinet');
    $current->set($admin);

    $seen = $current->run($cabinet, function (Panel $panel) use ($current, $admin): array {
        $inner = $current->run($admin, fn (): ?Panel => $current->get());

        return [$panel->id(), $current->get()?->id(), $inner?->id()];
    });

    expect($seen)->toBe(['cabinet', 'cabinet', 'admin'])
        ->and($current->get())->toBe($admin);
});

it('restores the previous panel when the callback throws', function (): void {
    $current = new CurrentPanel;
    $admin = currentPanelFixture('admin');

    expect(fn () => $current->run($admin, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class, 'boom')
        ->and($current->get())->toBeNull();
});

it('is one instance per request lifecycle', function (): void {
    $first = app(CurrentPanel::class);
    $first->set(currentPanelFixture('admin'));

    expect(app(CurrentPanel::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(CurrentPanel::class))->not->toBe($first)
        ->and(app(CurrentPanel::class)->get())->toBeNull();
});
