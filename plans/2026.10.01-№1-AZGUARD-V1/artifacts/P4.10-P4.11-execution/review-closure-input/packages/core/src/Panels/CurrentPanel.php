<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use Closure;
use Fiber;
use WeakMap;

/**
 * The panel of the current request or job; one instance per request lifecycle.
 */
final class CurrentPanel
{
    private ?Panel $panel = null;

    /** @var WeakMap<object, Panel> */
    private WeakMap $fibers;

    public function __construct()
    {
        $this->fibers = new WeakMap;
    }

    public function get(): ?Panel
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? $this->panel : ($this->fibers[$fiber] ?? null);
    }

    public function set(?Panel $panel): void
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
        $previous = $this->get();
        $this->set($panel);

        try {
            return $callback($panel);
        } finally {
            $this->set($previous);
        }
    }
}
