<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;

/** Deliberately returns an unchanged fence as grants change. */
final class BrokenFenceSource implements FencesReads, ProvidesGrants
{
    private bool $granted = false;

    public int $stateCalls = 0;

    public int $grantCalls = 0;

    private int $revokes = 0;

    public function __construct(private readonly bool $drift = false, private readonly ?string $changing = null, private readonly bool $driftOnNoopRevoke = false) {}

    public function id(): string
    {
        return 'broken-fence';
    }

    public function volatility(): Volatility
    {
        return Volatility::Stable;
    }

    public function state(Panel $panel, TenantRef $tenant): StateToken
    {
        $step = ++$this->stateCalls;

        return StateToken::of('broken', $panel->id(), $this->changing === 'incarnation' ? 'root-'.$step : 'root', 0, 0, 'code',
            epoch: $this->changing === 'epoch' ? $step : 0);
    }

    public function mutate(string $operation): void
    {
        $this->granted = $operation === 'grant';

        if ($operation === 'revoke' && ++$this->revokes === 2 && $this->driftOnNoopRevoke) {
            $this->granted = true;
        }
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $this->grantCalls++;

        if ($this->drift) {
            $this->granted = ! $this->granted;
        }

        return $this->granted ? [Grant::of(PermissionPattern::of($context->panel()->id(), 'items.view'), $this->id(), $context->scope())] : [];
    }
}
