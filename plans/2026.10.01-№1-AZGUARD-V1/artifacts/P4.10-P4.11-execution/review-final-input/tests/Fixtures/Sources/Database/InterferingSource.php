<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Database;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Storage\StorageMutation;

final class InterferingSource implements ProvidesGrants
{
    public int $reads = 0;

    public function id(): string
    {
        return 'interfering';
    }

    public function volatility(): Volatility
    {
        return Volatility::Volatile;
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        if (++$this->reads === 1) {
            DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
                $mutation->table('permissions')->update(['label' => 'After external read']);
                $mutation->table('permission_grants')->delete();
                $mutation->touch('admin');
            });
        }

        return [];
    }
}
