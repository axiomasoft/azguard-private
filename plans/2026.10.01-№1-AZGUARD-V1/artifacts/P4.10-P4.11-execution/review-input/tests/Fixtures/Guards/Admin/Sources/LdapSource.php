<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Sources;

use AzGuard\Attributes\AsSource;
use AzGuard\Contracts\Sources\Source;

#[AsSource('guard-ldap')]
final class LdapSource implements Source
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(public readonly array $config = []) {}

    public function id(): string
    {
        return 'guard-ldap';
    }
}
