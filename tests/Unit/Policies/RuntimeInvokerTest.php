<?php

declare(strict_types=1);

use AzGuard\Policies\Internal\RuntimeInvoker;
use Illuminate\Container\Container;

it('caches a method signature without retaining a policy instance or reusing its result', function (): void {
    $invoker = new RuntimeInvoker(new Container);
    $policy = new class(true)
    {
        public function __construct(public bool $allowed) {}

        public function view(string $subject): bool
        {
            return $this->allowed && $subject === 'user';
        }
    };
    $class = $policy::class;
    $weak = WeakReference::create($policy);
    expect($invoker->invoke([$policy, 'view'], ['subject' => 'user']))->toBeTrue();
    unset($policy);
    gc_collect_cycles();

    expect($weak->get())->toBeNull()
        ->and($invoker->invoke([new $class(false), 'view'], ['subject' => 'user']))->toBeFalse();
});

it('invokes magic callables and closures with their current inputs', function (): void {
    $invoker = new RuntimeInvoker(new Container);
    $callable = new class
    {
        public function __call(string $method, array $arguments): string
        {
            return $method.':'.implode(',', $arguments);
        }

        private function hidden(stdClass $unavailable): string
        {
            return $unavailable::class;
        }
    };

    expect($invoker->invoke([$callable, 'view'], []))->toBe('view:')
        ->and($invoker->invoke([$callable, 'hidden'], ['user' => null, 'resource' => null]))->toBe('hidden:')
        ->and($invoker->invoke(static fn (string $subject): string => $subject, ['subject' => 'first']))->toBe('first')
        ->and($invoker->invoke(static fn (string $subject): string => $subject, ['subject' => 'second']))->toBe('second');
});
