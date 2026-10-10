<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use AzGuard\Tests\Fixtures\Sources\Scoped\ScopedSourceWorld;

beforeEach(fn () => ScopedSourceWorld::seed());
afterEach(fn () => ScopedSourceWorld::reset());

it('runs the scoped scalar acceptance matrix on a real SQL engine', function (string $scenario): void {
    ScopedSourceWorld::run($scenario);
})->with(ScopedSourceWorld::CASES)->group('engines');

it('reports the SQL engine and server version used by the acceptance matrix', function (): void {
    $connection = DatabaseWorld::storage()->connection();
    $version = $connection->selectOne('select version() as version')->version;
    $expected = match ($connection->getDriverName()) {
        'pgsql' => env('PGSQL_DATABASE', 'azguard_test'),
        'mysql' => env('MYSQL_DATABASE', 'azguard_test'),
        'mariadb' => env('MARIADB_DATABASE', 'azguard_test'),
    };
    expect($connection->getDatabaseName())->toBe($expected)->and($version)->toBeString()->not->toBeEmpty();
    fwrite(STDERR, 'Engine: '.$connection->getDriverName().' '.$version.PHP_EOL);
})->group('engines');
