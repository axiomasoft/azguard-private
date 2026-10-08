<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\DescribesSchema;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;

/** A source of grants that describes itself as having no capabilities. */
final class UnderstatedSource implements DescribesSchema, ProvidesGrants
{
    public function id(): string
    {
        return 'understated';
    }

    public function volatility(): Volatility
    {
        return Volatility::Volatile;
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        return [];
    }

    public function describe(Panel $panel, ?TenantRef $tenant = null): SourceDescription
    {
        return new SourceDescription(id: $this->id(), class: self::class, capabilities: [], dynamic: false);
    }
}
