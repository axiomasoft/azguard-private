<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Gate;

use AzGuard\Concerns\Subjects\HasAzGuard;
use AzGuard\Contracts\Subjects\AzGuardSubject;
use AzGuard\Tests\Fixtures\Panels\User;

final class SubjectUser extends User implements AzGuardSubject
{
    use HasAzGuard;

    public function getMorphClass(): string
    {
        return 'user';
    }
}
