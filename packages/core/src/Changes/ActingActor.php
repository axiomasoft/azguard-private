<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Panels\Panel;
use Closure;
use Fiber;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use WeakMap;

/**
 * @internal Scoped actor of changes in the current request or job.
 *
 * Order: the innermost explicit `run()` frame — `null` there means "no actor", not a fallback — then the user of an
 * auth guard of the panel's subjects, then `ActorRef::system('console')` in the console, then null. The actor never
 * replaces the target of a change.
 */
final class ActingActor
{
    /** @var list<array{actor: ?ActorRef}> */
    private array $stack = [];

    /** @var WeakMap<object, list<array{actor: ?ActorRef}>> */
    private WeakMap $fibers;

    public function __construct(private readonly Container $container)
    {
        $this->fibers = new WeakMap;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function run(?ActorRef $actor, Closure $work): mixed
    {
        $stack = $this->frames();
        $this->store([...$stack, ['actor' => $actor]]);

        try {
            return $work();
        } finally {
            $this->store($stack);
        }
    }

    public function current(Panel $panel): ?ActorRef
    {
        return $this->resolve($panel)[0];
    }

    /** @return array{?ActorRef, ?Model} the actor and, when it came from an auth guard, its model */
    public function resolve(Panel $panel): array
    {
        $frames = $this->frames();

        if ($frames !== []) {
            return [$frames[count($frames) - 1]['actor'], null];
        }

        if ($this->container->bound('auth')) {
            $auth = $this->container->make(AuthFactory::class);
            foreach ($panel->guards() as $guard) {
                $user = $auth->guard($guard)->user();

                if ($user instanceof Model && $panel->accepts($user) && (is_int($user->getKey()) || is_string($user->getKey()))) {
                    return [ActorRef::of($user->getMorphClass(), $user->getKey()), $user];
                }
            }
        }

        if ($this->container instanceof Application && $this->container->runningInConsole()) {
            return [ActorRef::system('console'), null];
        }

        return [null, null];
    }

    /** @return list<array{actor: ?ActorRef}> */
    private function frames(): array
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? $this->stack : ($this->fibers[$fiber] ?? []);
    }

    /** @param list<array{actor: ?ActorRef}> $frames */
    private function store(array $frames): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $this->stack = $frames;
        } elseif ($frames === []) {
            unset($this->fibers[$fiber]);
        } else {
            $this->fibers[$fiber] = $frames;
        }
    }
}
