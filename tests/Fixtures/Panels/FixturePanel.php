<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use Closure;

/**
 * A panel provider whose description is supplied by the test: `AdminPanel::describe(fn (PanelBuilder $panel) => …)`.
 */
abstract class FixturePanel extends PanelProvider
{
    /** @var array<class-string<self>, Closure(PanelBuilder): mixed> */
    private static array $descriptions = [];

    /** @var array<class-string<self>, int> */
    private static array $calls = [];

    /**
     * @param  Closure(PanelBuilder): mixed  $description
     */
    public static function describe(Closure $description): void
    {
        self::$descriptions[static::class] = $description;
    }

    /**
     * How many times `panel()` of the provider has run since the last reset.
     */
    public static function calls(): int
    {
        return self::$calls[static::class] ?? 0;
    }

    public static function reset(): void
    {
        self::$descriptions = [];
        self::$calls = [];
        AnyIdPanel::$id = 'any';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        self::$calls[static::class] = self::calls() + 1;

        (self::$descriptions[static::class] ?? static fn (PanelBuilder $panel): null => null)($panel);

        return $panel;
    }
}
