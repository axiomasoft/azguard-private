<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use Closure;

/**
 * The panel of the current request or job; one instance per request lifecycle.
 */
final class CurrentPanel
{
    private ?Panel $panel = null;

    public function get(): ?Panel
    {
        return $this->panel;
    }

    public function set(?Panel $panel): void
    {
        $this->panel = $panel;
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
        $previous = $this->panel;
        $this->panel = $panel;

        try {
            return $callback($panel);
        } finally {
            $this->panel = $previous;
        }
    }
}
