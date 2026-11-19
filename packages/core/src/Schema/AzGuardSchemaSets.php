<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use Packman\Attributes\Api;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;

/**
 * Declares AzGuard core storage migrations to the Packman schema coordinator.
 */
#[Api(since: '1.0')]
final readonly class AzGuardSchemaSets implements SchemaSetProvider
{
    /** @return list<SchemaSet> */
    public function sets(): array
    {
        return [
            new SchemaSet(
                package: 'axiomasoft/azguard',
                name: 'azguard-storage',
                scope: SchemaScope::Central,
                path: dirname(__DIR__, 2).'/database/migrations',
                version: 1,
                drivers: ['pgsql', 'mysql', 'mariadb', 'sqlite'],
                lifecycle: SchemaLifecycle::Platform,
            ),
        ];
    }
}
