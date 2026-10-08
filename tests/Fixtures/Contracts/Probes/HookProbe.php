<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Probes;

use AzGuard\Testing\Contracts\HookContractTests;
use Closure;
use PHPUnit\Framework\Assert;

/** @mixin Assert */
final class HookProbe
{
    use HookContractTests;
    use RunsAsAProbe;

    /**
     * @param  Closure(): (Closure|string|null)  $hook
     * @param  Closure(): (object|string|null)  $pipe
     */
    public function __construct(private readonly Closure $hook, private readonly Closure $pipe) {}

    protected function azguardBeforeHook(): Closure|string|null
    {
        return ($this->hook)();
    }

    protected function azguardChangePipe(): object|string|null
    {
        return ($this->pipe)();
    }
}
