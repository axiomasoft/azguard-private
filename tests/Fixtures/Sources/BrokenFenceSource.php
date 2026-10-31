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

    public function __construct(private readonly bool $drift = false) {}

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
        return StateToken::of('broken', $panel->id(), 'root', 0, 0, 'code');
    }

    public function mutate(string $operation): void
    {
        $this->granted = $operation === 'grant';
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        if ($this->drift) {
            $this->granted = ! $this->granted;
        }

        return $this->granted ? [Grant::of(PermissionPattern::of($context->panel()->id(), 'items.view'), $this->id(), $context->scope())] : [];
    }
}
