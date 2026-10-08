<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Testing\Contracts\ContractWorld;
use AzGuard\Testing\Contracts\WriteLog;
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
    'CTE update' => ["with input as (select 'updated' as note) update contract_probe set note = (select note from input)"],
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
