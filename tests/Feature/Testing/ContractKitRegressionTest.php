<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Testing\Internal\Contracts\ContractWorld;
use AzGuard\Testing\Internal\Contracts\WriteLog;
use AzGuard\Tests\Fixtures\Contracts\Probes\HookProbe;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use PHPUnit\Framework\AssertionFailedError;

uses(DatabaseMigrations::class);

it('distinguishes executing explain writes from read-only plans and selects', function (string $sql, bool $write): void {
    expect(WriteLog::isWrite($sql))->toBe($write);
})->with([
    'analyze update' => ['EXPLAIN ANALYZE UPDATE contract_probe SET note = 1', true],
    'analyze options' => ['EXPLAIN (ANALYZE TRUE, BUFFERS) UPDATE contract_probe SET note = 1', true],
    'implicit true' => ['EXPLAIN (ANALYZE) DELETE FROM contract_probe', true],
    'on' => ['EXPLAIN (FORMAT JSON, ANALYZE ON) INSERT INTO contract_probe VALUES (1)', true],
    'cte' => ['EXPLAIN ANALYZE WITH input AS (SELECT 1) UPDATE contract_probe SET note = 1', true],
    'leading CTE write' => ["WITH input AS (SELECT 'updated' AS note) UPDATE contract_probe SET note = (SELECT note FROM input)", true],
    'second statement' => ['SELECT 1; EXPLAIN ANALYZE UPDATE contract_probe SET note = 1', true],
    'plain plan' => ['EXPLAIN UPDATE contract_probe SET note = 1', false],
    'false' => ['EXPLAIN (ANALYZE FALSE) UPDATE contract_probe SET note = 1', false],
    'off' => ['EXPLAIN (ANALYZE OFF, FORMAT JSON) DELETE FROM contract_probe', false],
    'zero' => ['EXPLAIN (ANALYZE 0) UPDATE contract_probe SET note = 1', false],
    'quoted false' => ["EXPLAIN (ANALYZE 'false') UPDATE contract_probe SET note = 1", false],
    'analyze select' => ['EXPLAIN ANALYZE SELECT * FROM contract_probe', false],
]);

beforeEach(function (): void {
    app('db')->connection()->getSchemaBuilder()->create('contract_probe', static function (Blueprint $table): void {
        $table->string('note');
    });
});

it('observes commented and CTE writes in contract hooks', function (string $sql): void {
    $writes = ContractWorld::writesDuring(static fn () => app('db')->statement($sql));

    expect($writes)->toBe([$sql]);
})->with([
    'block comment' => ["/* request tag */ insert into contract_probe (note) values ('written')"],
    'line comment' => ["-- request tag\ninsert into contract_probe (note) values ('written')"],
    // MariaDB 10.11 supports a CTE in a subquery, but not before UPDATE.
    'CTE update' => ["update contract_probe set note = (with input as (select 'updated' as note) select note from input)"],
]);

it('does not mistake read literals or comments for writes', function (): void {
    expect(ContractWorld::writesDuring(static fn () => app('db')->select("with input as (select 'update' as note) select * from input /* insert */")))->toBe([]);
});

it('fails the pipe contract when its own SQL write starts with a comment', function (): void {
    $pipe = static fn (Change $change, Closure $next): ChangeResult => tap($next($change),
        static fn () => app('db')->statement("/* after writer */ insert into contract_probe (note) values ('written')"));

    expect(fn () => (new HookProbe(static fn (): null => null, static fn () => $pipe))->run('pipeWritesNothingBesidesTheWriter'))
        ->toThrow(AssertionFailedError::class);
});

it('counts the rows of the tables of its own database only', function (): void {
    app('db')->connection()->table('contract_probe')->insert([['note' => 'a'], ['note' => 'b']]);

    // MySQL and MariaDB list the tables of every database of the server unless told which one; counting a table of
    // another database through this connection fails and used to drop the whole connection from the result.
    expect(ContractWorld::rowCounts())->toHaveKey('testbench.contract_probe', 2);
});

it('counts tables of a connection that has a table prefix without prefixing the physical name twice', function (): void {
    $connection = app('db')->connection();
    $connection->table('contract_probe')->insert([['note' => 'a']]);
    $connection->setTablePrefix('az_');

    try {
        expect(ContractWorld::rowCounts())->toHaveKey($connection->getName().'.contract_probe', 1);
    } finally {
        $connection->setTablePrefix('');
    }
});

it('leaves out the tables of another schema or attached database that the connection can see', function (): void {
    $connection = app('db')->connection();
    $driver = $connection->getDriverName();
    // SQLite sees attached files and PostgreSQL every schema unless the listing names the current one; MySQL and
    // MariaDB were already narrowed to the current database and keep that.
    $foreign = match ($driver) {
        'sqlite' => static fn () => $connection->statement("ATTACH DATABASE ':memory:' AS contract_other"),
        'pgsql' => static fn () => $connection->statement('CREATE SCHEMA contract_other'),
        default => null,
    };

    if ($foreign !== null) {
        $foreign();
        $connection->statement('CREATE TABLE contract_other.contract_foreign (note varchar(10))');
        $connection->statement("INSERT INTO contract_other.contract_foreign (note) VALUES ('other')");
    }

    try {
        $counts = ContractWorld::rowCounts();
        $own = array_values(array_filter(array_keys($counts), static fn (string $key): bool => str_starts_with($key, $connection->getName().'.')));
        $builder = $connection->getSchemaBuilder();
        // Laravel 11 has no schema argument and lists every schema on PostgreSQL: the foreign schema is left out by name.
        $listing = (new ReflectionClass($builder))->hasMethod('getCurrentSchemaName')
            ? $builder->getTableListing($builder->getCurrentSchemaName(), false)
            : array_column(array_values(array_filter($builder->getTables(), static fn (array $table): bool => ($table['schema'] ?? null) !== 'contract_other')), 'name');

        expect($counts)->toHaveKey($connection->getName().'.contract_probe', 0)
            ->and($counts)->not->toHaveKey($connection->getName().'.contract_foreign')
            ->and($counts)->not->toHaveKey($connection->getName().'.contract_other.contract_foreign')
            ->and($own)->toBe(array_map(static fn (string $table): string => $connection->getName().'.'.$table, $listing));
    } finally {
        match ($driver) {
            'sqlite' => $connection->statement('DETACH DATABASE contract_other'),
            'pgsql' => $connection->statement('DROP SCHEMA contract_other CASCADE'),
            default => null,
        };
    }
});
