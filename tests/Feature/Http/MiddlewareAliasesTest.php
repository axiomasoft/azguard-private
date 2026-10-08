<?php

declare(strict_types=1);

use Illuminate\Routing\Router;

/*
 * The package registers exactly two route middleware and no Blade directives of its own; the snapshot is the contract.
 */

it('registers exactly the middleware aliases of the snapshot', function (): void {
    $aliases = array_filter(
        app(Router::class)->getMiddleware(),
        static fn (string $alias): bool => str_starts_with($alias, 'azguard') || str_starts_with($alias, 'az-guard') || str_starts_with($alias, 'check.'),
        ARRAY_FILTER_USE_KEY,
    );
    ksort($aliases);
    $snapshot = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Http/middleware-aliases.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($aliases)->toBe($snapshot);

    foreach ($aliases as $class) {
        expect(class_exists($class))->toBeTrue();
    }
});

it('registers no Blade directive of its own', function (): void {
    $directives = array_keys(app('blade.compiler')->getCustomDirectives());

    expect(array_values(array_filter($directives, static fn (string $name): bool => str_starts_with(strtolower($name), 'az'))))->toBe([]);
});
