<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use RuntimeException;

final class ExternalProjectScope implements AssignmentScopeDefinition
{
    public static bool $timeout = false;

    public static int $resolutions = 0;

    public function type(): string
    {
        return 'external.project';
    }

    public function model(): ?string
    {
        return null;
    }

    public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
    {
        self::$resolutions++;

        if (self::$timeout) {
            throw new RuntimeException('external timeout');
        }

        if ($ref->type() !== $this->type() || ! in_array($ref->id(), ['1', '2'], true)) {
            return null;
        }

        return new ResolvedAssignmentScope($ref, TenantRef::of('crm.organization', $ref->id() === '1' ? 1 : 2));
    }
}
