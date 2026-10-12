<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Probes;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Contracts\Authorization\AfterHook;
use AzGuard\Contracts\Authorization\BeforeHook;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Changes\ChangePipe;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Testing\Contracts\HookContractTests;
use Closure;
use PHPUnit\Framework\Assert;

/** @mixin Assert */
final class HookProbe
{
    use HookContractTests;
    use RunsAsAProbe;

    /**
     * @param  Closure(): (BeforeHook|class-string<BeforeHook>|Closure|null)  $hook
     * @param  Closure(): (ChangePipe|class-string<ChangePipe>|Closure|null)  $pipe
     * @param  Closure(): (AfterHook|class-string<AfterHook>|Closure|null)|null  $after
     */
    public function __construct(private readonly Closure $hook, private readonly Closure $pipe, private readonly ?Closure $after = null) {}

    /** @return BeforeHook|class-string<BeforeHook>|Closure(AccessRequest, EvaluationContext): BeforeResult|null */
    protected function azguardBeforeHook(): BeforeHook|Closure|string|null
    {
        return ($this->hook)();
    }

    /** @return AfterHook|class-string<AfterHook>|Closure(AccessRequest, EvaluationContext, Decision): void|null */
    protected function azguardAfterHook(): AfterHook|Closure|string|null
    {
        return $this->after === null ? null : ($this->after)();
    }

    /** @return ChangePipe|class-string<ChangePipe>|Closure(Change, Closure(Change): ChangeResult): ChangeResult|null */
    protected function azguardChangePipe(): ChangePipe|Closure|string|null
    {
        return ($this->pipe)();
    }
}
