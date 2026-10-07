<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseRoleGrant;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::storage()->connection()->getSchemaBuilder()->table('azg_role_grants', function (Blueprint $table): void {
        $table->integer('score')->nullable();
    });
});

afterEach(function (): void {
    app(StorageSchema::class)->drop('default');
});

it('returns only declared decision fields from the exact custom grant witness', function (): void {
    DatabaseWorld::insert('role', [DatabaseWorld::row(overrides: ['score' => 7, 'meta' => '{"weekdays":[1,5],"private_note":"secret"}']),
        DatabaseWorld::row(overrides: ['origin' => 'import', 'score' => 3, 'meta' => '{"weekdays":[2]}'])]);
    $source = DatabaseSource::make()->models(roleGrant: DatabaseRoleGrant::class)->decisionFields(roleGrant: ['weekdays', 'score']);
    [, $frame] = DatabaseWorld::compile($source);
    $roles = DatabaseWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame));

    expect($roles)->toHaveCount(2)->and($roles[0]->fields())->toBe(['weekdays' => [1, 5], 'score' => 7])
        ->and($roles[1]->fields())->toBe(['weekdays' => [2], 'score' => 3]);
    $plain = DatabaseSource::make()->models(roleGrant: DatabaseRoleGrant::class);
    expect(DatabaseWorld::items($plain->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame))[0]->fields())->toBe([]);
});

it('rejects an undeclared decision field before reading contributions', function (): void {
    $source = DatabaseSource::make()->models(roleGrant: DatabaseRoleGrant::class)->decisionFields(roleGrant: ['not_declared']);
    [, $frame] = DatabaseWorld::compile($source);

    expect(fn () => DatabaseWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame)))
        ->toThrow(DefinitionException::class);
});
