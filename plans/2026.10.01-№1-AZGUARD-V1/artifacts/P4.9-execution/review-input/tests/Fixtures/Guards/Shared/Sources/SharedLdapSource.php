<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Shared\Sources;

use AzGuard\Attributes\AsSource;
use AzGuard\Contracts\Sources\Source;

#[AsSource('shared-ldap')]
final class SharedLdapSource implements Source
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(public readonly array $config = []) {}

    public function id(): string
    {
        return 'shared-ldap';
    }
}
