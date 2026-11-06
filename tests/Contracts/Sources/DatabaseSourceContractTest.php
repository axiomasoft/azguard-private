<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Sources;

use AzGuard\Contracts\Sources\Source;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Support\ModelKey;
use AzGuard\Testing\Contracts\SourceContractTests;
use AzGuard\Testing\Internal\Contracts\ContractPermission;
use AzGuard\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

final class DatabaseSourceContractTest extends TestCase
{
    use DatabaseMigrations;
    use SourceContractTests;

    protected function azguardSource(): Source
    {
        return DatabaseSource::make();
    }

    protected function azguardFenceMutation(Source $source, Panel $panel): ?callable
    {
        $subject = $this->azguardSubject();
        $ref = SubjectRef::of($subject->getMorphClass(), ModelKey::of($subject));

        return static function (string $operation) use ($panel, $ref): void {
            $access = AzGuard::panel($panel->id())->for($ref);

            if ($operation === 'grant') {
                $access->grantPermission(ContractPermission::View);
            } else {
                $access->revokePermission(ContractPermission::View);
            }
        };
    }
}
