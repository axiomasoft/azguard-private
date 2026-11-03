<?php

declare(strict_types=1);

use AzGuard\Tests\Support\DatabaseLaneEnvironment;

it('loads only missing test database endpoints and preserves explicit environment', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'azguard-db-env-');

    try {
        file_put_contents($path, "PGSQL_PORT=33435\nPGSQL_DATABASE=azguard_p61_test\nMYSQL_PORT=35306\nDB_CONNECTION=mysql\n");
        expect(DatabaseLaneEnvironment::read($path, ['PGSQL_PORT' => '5432']))->toBe([
            'PGSQL_DATABASE' => 'azguard_p61_test', 'MYSQL_PORT' => '35306',
        ]);
    } finally {
        unlink($path);
    }
});

it('rejects an unsafe database name or port in the local configuration', function (string $setting): void {
    $path = tempnam(sys_get_temp_dir(), 'azguard-db-env-');

    try {
        file_put_contents($path, $setting);
        expect(fn () => DatabaseLaneEnvironment::read($path, []))->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
})->with(['PGSQL_DATABASE=production', 'MYSQL_PORT=65536', 'MARIADB_PORT=0']);

it('leaves the environment alone when no local configuration exists', function (): void {
    expect(DatabaseLaneEnvironment::read(sys_get_temp_dir().'/missing-'.bin2hex(random_bytes(8)), []))->toBe([]);
});
