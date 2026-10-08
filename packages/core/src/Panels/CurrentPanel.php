<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use Closure;
use Fiber;
use WeakMap;

/**
 * The panel of the current request or job; one instance per request lifecycle.
 *
 * Besides a panel it can hold a rejected hint: the id of a panel a queued job carried from the request that dispatched
 * it, which no registered panel has any more. The resolver does not fall back to the default panel for a rejected
 * hint, it refuses the short names that would have used it.
 */
final class CurrentPanel
{
    private Panel|string|null $panel = null;

    /** @var WeakMap<object, Panel|string> */
    private WeakMap $fibers;

    public function __construct()
    {
        $this->fibers = new WeakMap;
    }

    public function get(): ?Panel
    {
        $panel = $this->value();

        return $panel instanceof Panel ? $panel : null;
    }

    /**
     * @return string|null the id of the panel hint that no registered panel has, when the current panel is one
     */
    public function rejected(): ?string
    {
        $panel = $this->value();

        return is_string($panel) ? $panel : null;
    }

    public function set(?Panel $panel): void
    {
        $this->store($panel);
    }

    /**
     * Marks the current panel as a hint that names no registered panel; it replaces the current panel.
     */
    public function reject(string $id): void
    {
        $this->store($id);
    }

    /**
     * Runs the callback inside the panel and restores the previous panel afterwards, also when the callback throws.
     *
     * @template TResult
     *
     * @param  Closure(Panel): TResult  $callback
     * @return TResult
     */
    public function run(Panel $panel, Closure $callback): mixed
    {
        $previous = $this->value();
        $this->store($panel);

        try {
            return $callback($panel);
        } finally {
            $this->store($previous);
        }
    }

    private function value(): Panel|string|null
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? $this->panel : ($this->fibers[$fiber] ?? null);
    }

    private function store(Panel|string|null $panel): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $this->panel = $panel;
        } elseif ($panel === null) {
            unset($this->fibers[$fiber]);
        } else {
            $this->fibers[$fiber] = $panel;
        }
    }
}
