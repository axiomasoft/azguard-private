<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Database;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;

final class FencedContributions implements FencesReads, ProvidesGrants, ProvidesRoleGrants
{
    public int $grantCalls = 0;

    public int $roleCalls = 0;

    public int $stateCalls = 0;

    public function id(): string
    {
        return 'external-fence';
    }

    public function volatility(): Volatility
    {
        return Volatility::Stable;
    }

    public function state(Panel $panel, TenantRef $tenant): StateToken
    {
        $this->stateCalls++;

        return StateToken::of('external', $panel->id(), 'root', $this->stateCalls === 1 ? 1 : 2, 0, 'code');
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $this->grantCalls++;

        return [Grant::of(PermissionPattern::of('admin', $this->grantCalls === 1 ? 'documents.view' : 'documents.edit'), $this->id(), $context->scope())];
    }

    public function roleGrants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $this->roleCalls++;

        return [RoleContribution::of(RoleKey::of('admin', $this->roleCalls === 1 ? 'editor' : 'unknown'), $context->scope(), $this->id())];
    }
}
