<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Gate;

use AzGuard\Concerns\HasAzGuard;
use AzGuard\Contracts\AzGuardSubject;
use AzGuard\Tests\Fixtures\Panels\User;

final class SubjectUser extends User implements AzGuardSubject
{
    use HasAzGuard;

    public function getMorphClass(): string
    {
        return 'user';
    }
}
