<?php

declare(strict_types=1);

namespace AzGuard\Support;

/**
 * Per-request scratch state for AzGuard. Registered as a `scoped` binding, so the
 * container flushes it at the start of each request/job — Octane-safe by design.
 *
 * Used for "do this at most once per request" side effects (e.g. a diagnostic
 * warning) without the cross-request bleed a `static` flag would cause under
 * Octane (a static warns once per worker, never again).
 *
 * @internal
 */
final class RequestState
{
    /** @var array<string, true> */
    private array $seen = [];

    /** @var array<string, mixed> */
    private array $memo = [];

    public function once(string $key, callable $callback): void
    {
        if (isset($this->seen[$key])) {
            return;
        }

        $this->seen[$key] = true;

        $callback();
    }

    /**
     * Compute-once-per-request memoization that keeps the callback's return
     * value (unlike `once()`, which is fire-and-forget). Scoped lifecycle
     * makes this Octane-safe by construction.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function remember(string $key, callable $callback): mixed
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $callback();
    }
}
