<?php

declare(strict_types=1);

use AzGuard\Auth\PolicyAttributeRegistrar;
use AzGuard\Panels\Panel;
use AzGuard\Tests\Stubs\Posts\Policies\PostPolicy;
use Illuminate\Support\Facades\Gate;

test('PolicyAttributeRegistrar регистрирует resolved ability', function (): void {
    $panel = Panel::make()->id(id: 'test')->scopedByPanelId(condition: true);

    app(PolicyAttributeRegistrar::class)->register(
        policyClasses: [PostPolicy::class],
        panel: $panel,
    );

    expect(Gate::has('test.post.view'))->toBeTrue();
});

test('PolicyAttributeRegistrar падает на дубликате ability', function (): void {
    $panel = Panel::make()->id(id: 'test')->scopedByPanelId(condition: true);
    $registrar = app(PolicyAttributeRegistrar::class);

    $registrar->register(policyClasses: [PostPolicy::class], panel: $panel);

    expect(fn () => $registrar->register(policyClasses: [PostPolicy::class], panel: $panel))
        ->toThrow(RuntimeException::class);
});
