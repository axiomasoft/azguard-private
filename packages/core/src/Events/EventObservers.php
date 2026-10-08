<?php

declare(strict_types=1);

namespace AzGuard\Events;

use Closure;
use Throwable;

/**
 * @internal The point where the test kit watches events without going through the application dispatcher, which a
 * host's `Event::fake()` replaces. The events are the ones the package publishes; an observer reads them and nothing it
 * does changes a decision or a change. A failing observer is reported and the rest still see the event.
 */
final class EventObservers
{
    /** @var array<int, Closure(AccessEvent): void> */
    private array $observers = [];

    private int $next = 0;

    /**
     * @param  Closure(AccessEvent): void  $observer
     * @return int the handle for `forget()`
     */
    public function add(Closure $observer): int
    {
        $this->observers[$this->next] = $observer;

        return $this->next++;
    }

    public function forget(int $handle): void
    {
        unset($this->observers[$handle]);
    }

    public function active(): bool
    {
        return $this->observers !== [];
    }

    public function observe(AccessEvent $event): void
    {
        foreach ($this->observers as $observer) {
            try {
                $observer($event);
            } catch (Throwable $error) {
                report($error);
            }
        }
    }
}
