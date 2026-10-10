<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources;

use AzGuard\Attributes\AsSource;
use AzGuard\Contracts\Sources\Source;

/**
 * A second class that wants the name `ldap`.
 */
#[AsSource('ldap')]
final class OtherLdapSource implements Source
{
    public function id(): string
    {
        return 'other-ldap';
    }
}
