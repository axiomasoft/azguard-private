<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\PluginConflictException;
use AzGuard\Exceptions\PluginException;
use AzGuard\Panels\GateMode;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelFingerprint;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Panels\PanelSettings;
use AzGuard\Panels\Reads;
use AzGuard\Panels\StateRefresh;
use AzGuard\Tests\Fixtures\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Roles\RootRole;
use AzGuard\Tests\Fixtures\Roles\SellerRole;

/**
 * @param  Closure(PanelBuilder, PanelRecipe): mixed  $describe
 * @param  array<string, bool|int|string|null>  $defaults  the `defaults` section of the configuration by setting name
 * @return array{0: Panel, 1: PanelRecipe, 2: PanelCompiler}
 */
function settingsPanel(Closure $describe, array $defaults = []): array
{
    $recipe = new PanelRecipe('admin');
    $describe(new PanelBuilder($recipe), $recipe);
    $recipe->seal();
    $compiler = new PanelCompiler(static fn (): array => $defaults);

    return [$compiler->compile($recipe), $recipe, $compiler];
}

it('declares the setting enums with stable string values', function (): void {
    expect(array_column(GateMode::cases(), 'value', 'name'))->toBe(['Authoritative' => 'authoritative'])
        ->and(array_column(Reads::cases(), 'value', 'name'))->toBe(['Primary' => 'primary', 'Default' => 'default'])
        ->and(array_column(StateRefresh::cases(), 'value', 'name'))->toBe(['Request' => 'request', 'Check' => 'check']);
});

it('falls back to the built-in values when nothing sets a setting', function (): void {
    [$panel] = settingsPanel(static fn (): null => null);
    $settings = $panel->settings();

    expect($settings->resourcePrefix())->toBe('admin')
        ->and($settings->gateMode())->toBe(GateMode::Authoritative)
        ->and($settings->cacheStore())->toBeNull()
        ->and($settings->cacheTtl())->toBe(3600)
        ->and($settings->cacheGeneration())->toBe(1)
        ->and($settings->reads())->toBe(Reads::Primary)
        ->and($settings->stateRefresh())->toBe(StateRefresh::Request)
        ->and($settings->traceDecisions())->toBeFalse()
        ->and(array_unique(array_column($settings->toArray(), 'origin')))->toBe(['default']);
});

it('records what the provider sets and tells where every value came from', function (): void {
    [$panel] = settingsPanel(
        static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->resourcePrefix('backoffice')
            ->gate()
            ->cache(store: 'redis', ttl: 120)
            ->consistency(Reads::Default, StateRefresh::Check),
        ['cache.generation' => 7, 'trace_decisions' => true],
    );

    expect($panel->settings()->toArray())->toBe([
        'resource_prefix' => ['value' => 'backoffice', 'origin' => 'provider'],
        'gate.mode' => ['value' => 'authoritative', 'origin' => 'provider'],
        'cache.store' => ['value' => 'redis', 'origin' => 'provider'],
        'cache.ttl' => ['value' => 120, 'origin' => 'provider'],
        'cache.generation' => ['value' => 7, 'origin' => 'default'],
        'consistency.reads' => ['value' => 'default', 'origin' => 'provider'],
        'consistency.state_refresh' => ['value' => 'check', 'origin' => 'provider'],
        'trace_decisions' => ['value' => true, 'origin' => 'default'],
    ])->and($panel->prefix())->toBe('backoffice')
        ->and($panel->settings()->origin(PanelSettings::CACHE_TTL))->toBe('provider')
        ->and($panel->settings()->origin('cache.generation'))->toBe('default');
});

it('rejects the origin of a setting that does not exist', function (): void {
    [$panel] = settingsPanel(static fn (): null => null);

    expect(fn () => $panel->settings()->origin('cache.driver'))->toThrow(DefinitionException::class, 'Unknown panel setting "cache.driver"');
});

it('treats a null cache argument as not set on this layer', function (): void {
    [$panel, $recipe] = settingsPanel(static function (PanelBuilder $panel, PanelRecipe $recipe): void {
        $recipe->during(PanelRecipe::configure(), fn () => $panel->cache(store: 'redis', ttl: 300, generation: 4));
        $panel->cache(ttl: 60)->cache();
    });

    expect(array_column($recipe->records(), 'setting'))->toBe(['cache.store', 'cache.ttl', 'cache.generation', 'cache.ttl'])
        ->and($panel->settings()->cacheStore())->toBe('redis')
        ->and($panel->settings()->cacheTtl())->toBe(60)
        ->and($panel->settings()->cacheGeneration())->toBe(4)
        ->and($panel->settings()->origin('cache.store'))->toBe('configure')
        ->and($panel->settings()->origin('cache.ttl'))->toBe('provider');
});

it('resolves the prefix from the configuration default unless the panel sets it', function (bool $default, ?Closure $describe, ?string $prefix, string $origin): void {
    [$panel] = settingsPanel($describe ?? static fn (): null => null, ['resource_prefix' => $default]);

    expect($panel->prefix())->toBe($prefix)
        ->and($panel->settings()->origin('resource_prefix'))->toBe($origin);
})->with([
    'default on' => [true, null, 'admin', 'default'],
    'default off' => [false, null, null, 'default'],
    'default off, the panel names a prefix' => [false, fn (PanelBuilder $panel) => $panel->resourcePrefix('backoffice'), 'backoffice', 'provider'],
    'default off, the panel turns it on' => [false, fn (PanelBuilder $panel) => $panel->resourcePrefix(), 'admin', 'provider'],
    'default on, the panel turns it off' => [true, fn (PanelBuilder $panel) => $panel->resourcePrefix(false), null, 'provider'],
]);

it('reports two plugins that set one setting to different values', function (): void {
    $describe = static function (PanelBuilder $panel, PanelRecipe $recipe): void {
        $recipe->during(PanelRecipe::plugin('acme/audit', 1), fn () => $panel->cache(ttl: 60));
        $recipe->during(PanelRecipe::plugin('acme/reports', 2), fn () => $panel->cache(ttl: 600));
    };

    try {
        settingsPanel($describe);
        $this->fail('The panel compiled with conflicting plugins.');
    } catch (PluginConflictException $e) {
        expect($e)->toBeInstanceOf(PluginException::class)
            ->and($e->code())->toBe('plugin_conflict')
            ->and($e->getMessage())->toContain('"acme/audit"', '"acme/reports"', '"cache.ttl"', '60', '600');
    }
});

it('lets the provider settle a setting two plugins disagree on', function (): void {
    [$panel] = settingsPanel(static function (PanelBuilder $panel, PanelRecipe $recipe): void {
        $recipe->during(PanelRecipe::plugin('acme/audit', 1), fn () => $panel->cache(ttl: 60));
        $recipe->during(PanelRecipe::plugin('acme/reports', 2), fn () => $panel->cache(ttl: 600));
        $panel->cache(ttl: 900);
    });

    expect($panel->settings()->cacheTtl())->toBe(900)
        ->and($panel->settings()->origin('cache.ttl'))->toBe('provider');
});

it('accepts plugins that agree and names the first one as the origin', function (): void {
    [$panel] = settingsPanel(static function (PanelBuilder $panel, PanelRecipe $recipe): void {
        $recipe->during(PanelRecipe::plugin('acme/reports', 2), fn () => $panel->cache(ttl: 60)->consistency(Reads::Default));
        $recipe->during(PanelRecipe::plugin('acme/audit', 1), fn () => $panel->cache(ttl: 30)->cache(ttl: 60));
    });

    expect($panel->settings()->cacheTtl())->toBe(60)
        ->and($panel->settings()->origin('cache.ttl'))->toBe('plugin:acme/audit')
        ->and($panel->settings()->reads())->toBe(Reads::Default)
        ->and($panel->settings()->origin('consistency.reads'))->toBe('plugin:acme/reports');
});

it('applies the same precedence to the label and the default flag', function (): void {
    [$panel] = settingsPanel(static function (PanelBuilder $panel, PanelRecipe $recipe): void {
        $recipe->during(PanelRecipe::configure(), fn () => $panel->label('configure')->default());
        $recipe->during(PanelRecipe::plugin('acme/audit', 1), fn () => $panel->label('plugin'));
    });

    expect($panel->label())->toBe('plugin')
        ->and($panel->isDefault())->toBeTrue();
});

it('rejects a value outside its enum or range when the panel is compiled', function (Closure $describe, array $defaults, string $code, string $message): void {
    try {
        settingsPanel($describe, $defaults);
        $this->fail('The panel compiled.');
    } catch (InvalidConfigurationException $e) {
        expect($e->code())->toBe($code)
            ->and($e->getMessage())->toContain($message);
    }
})->with([
    'gate mode from the configuration' => [fn () => null, ['gate.mode' => 'permissive'], 'invalid_configuration.enum', '"gate.mode" is "permissive", expected one of authoritative'],
    'reads from the configuration' => [fn () => null, ['consistency.reads' => 'replica'], 'invalid_configuration.enum', 'expected one of primary, default'],
    'state refresh from the configuration' => [fn () => null, ['consistency.state_refresh' => 'never'], 'invalid_configuration.enum', 'expected one of request, check'],
    'ttl below one from the configuration' => [fn () => null, ['cache.ttl' => 0], 'invalid_configuration.enum', '"cache.ttl" is 0'],
    'ttl below one from the panel' => [fn (PanelBuilder $panel) => $panel->cache(ttl: -5), [], 'invalid_configuration.enum', '"cache.ttl" is -5'],
    'generation below one' => [fn (PanelBuilder $panel) => $panel->cache(generation: 0), [], 'invalid_configuration.enum', '"cache.generation" is 0'],
    'a store without a ttl' => [fn (PanelBuilder $panel) => $panel->cache(store: 'redis'), ['cache.ttl' => null], 'invalid_configuration.cache_ttl', 'store "redis" without a ttl'],
    'a default store without a ttl' => [fn () => null, ['cache.store' => 'redis', 'cache.ttl' => null], 'invalid_configuration.cache_ttl', 'set cache.ttl'],
]);

it('allows no ttl while permission sets live for the request only', function (): void {
    [$panel] = settingsPanel(static fn (): null => null, ['cache.ttl' => null]);

    expect($panel->settings()->cacheTtl())->toBeNull()
        ->and($panel->settings()->cacheStore())->toBeNull();
});

it('gives the builder no method that turns a guarantee off', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => strtolower($method->getName()),
        (new ReflectionClass(PanelBuilder::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    $forbidden = array_filter($methods, static fn (string $method): bool => preg_match(
        '/^(without|disable|skip|allow|permit|unsafe|bypass|ignore)/',
        $method,
    ) === 1);

    expect(array_values($forbidden))->toBe([])
        ->and($methods)->not->toContain('withoutrestrictions', 'allowdirectwrites', 'failopen', 'strictwrites')
        ->and(array_keys(PanelSettings::DEFAULTS))->toBe([
            'resource_prefix', 'gate.mode', 'cache.store', 'cache.ttl', 'cache.generation',
            'consistency.reads', 'consistency.state_refresh', 'trace_decisions',
        ]);
});

it('keeps the items of every layer in a list and collapses a class named twice', function (): void {
    [, $recipe] = settingsPanel(static function (PanelBuilder $panel, PanelRecipe $recipe): void {
        $recipe->during(PanelRecipe::configure(), fn () => $panel->restrictions(['App\Restrictions\Shared', 'App\Restrictions\OfficeHours'])->roles([RootRole::class]));
        $recipe->during(PanelRecipe::plugin('acme/audit', 1), fn () => $panel->restrictions(['App\Restrictions\Audit'])->roles([AnalystRole::class, SellerRole::class]));
        $panel->restrictions(['App\Restrictions\OfficeHours'])->roles([SellerRole::class]);
    });

    expect(PanelCompiler::items($recipe, PanelRecipe::RESTRICTIONS))->toBe([
        'App\Restrictions\OfficeHours', 'App\Restrictions\Audit', 'App\Restrictions\Shared',
    ])->and($recipe->roles())->toBe([SellerRole::class, AnalystRole::class, RootRole::class]);
});

it('keeps closures and objects apart when it collapses duplicates', function (): void {
    $closure = static fn (): null => null;
    [, $recipe] = settingsPanel(static fn (PanelBuilder $panel): PanelBuilder => $panel->before([$closure, $closure, 'App\Hooks\A', 'App\Hooks\A']));

    expect(PanelCompiler::items($recipe, PanelRecipe::BEFORE))->toBe([$closure, $closure, 'App\Hooks\A']);
});

it('merges presentation by key with the precedence of settings', function (): void {
    [, $recipe, $compiler] = settingsPanel(static function (PanelBuilder $panel, PanelRecipe $recipe): void {
        $recipe->during(PanelRecipe::configure(), fn () => $panel->presentation(['icon' => 'configure', 'group' => 'configure', 'sort' => 5]));
        $recipe->during(PanelRecipe::plugin('acme/audit', 1), fn () => $panel->presentation(['icon' => 'plugin', 'group' => 'plugin', 'badge' => 'audit']));
        $panel->presentation(['icon' => 'provider']);
    });

    expect($compiler->presentation($recipe))->toEqualCanonicalizing([
        'icon' => 'provider', 'group' => 'plugin', 'badge' => 'audit', 'sort' => 5,
    ]);
});

it('reports two plugins that disagree on a presentation key', function (): void {
    [, $recipe, $compiler] = settingsPanel(static function (PanelBuilder $panel, PanelRecipe $recipe): void {
        $recipe->during(PanelRecipe::plugin('acme/audit', 1), fn () => $panel->presentation(['icon' => 'eye', 'group' => 'tools']));
        $recipe->during(PanelRecipe::plugin('acme/reports', 2), fn () => $panel->presentation(['icon' => 'chart', 'group' => 'tools']));
    });

    expect(fn () => $compiler->presentation($recipe))->toThrow(PluginConflictException::class, '"presentation.icon"');
});

it('holds plain values only', function (): void {
    [$panel] = settingsPanel(static fn (PanelBuilder $panel): PanelBuilder => $panel->cache(store: 'redis', ttl: 60)->onDenied(static fn (): null => null));

    foreach ($panel->settings()->toArray() as $setting) {
        expect($setting['value'] === null || is_scalar($setting['value']))->toBeTrue()
            ->and($setting['origin'])->toBeString();
    }

    expect((new ReflectionClass(PanelSettings::class))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass(PanelSettings::class))->isFinal())->toBeTrue();
});

it('makes the effective settings part of the panel fingerprint', function (): void {
    $fingerprint = static fn (Closure $describe, array $defaults = []): string => PanelFingerprint::of(...array_slice(settingsPanel($describe, $defaults), 0, 2));
    $base = $fingerprint(static fn (): null => null);

    expect($fingerprint(static fn (PanelBuilder $panel): PanelBuilder => $panel->cache(ttl: 60)))->not->toBe($base)
        ->and($fingerprint(static fn (): null => null, ['consistency.reads' => 'default']))->not->toBe($base)
        ->and($fingerprint(static fn (PanelBuilder $panel): PanelBuilder => $panel->cache(ttl: 3600)))->toBe($base)
        ->and($fingerprint(static fn (PanelBuilder $panel): PanelBuilder => $panel->label('Renamed')->presentation(['icon' => 'x'])))->toBe($base);
});
