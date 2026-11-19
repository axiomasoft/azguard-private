<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardOptions;
use AzGuard\Filament\Configuration\AzGuardFilamentOptions;
use Opis\JsonSchema\Validator;

it('accepts optional scaffold runtime configuration in the generated core schema', function (array $scaffold): void {
    $config = ['scaffold' => $scaffold];
    expect(AzGuardOptions::fromArray($config, 'azguard'))->toBeInstanceOf(AzGuardOptions::class);
    $schema = json_decode(file_get_contents(__DIR__.'/../../../packages/core/resources/schemas/config.schema.json'));
    $data = (object) ['azguard' => (object) ['scaffold' => (object) $scaffold]];
    expect((new Validator)->validate($data, $schema)->isValid())->toBeTrue();
})->with([
    'empty' => [[]],
    'namespace only' => [['namespace' => 'App\\Access']],
    'path only' => [['path' => 'app/Access']],
    'both' => [['namespace' => 'App\\Access', 'path' => 'app/Access']],
]);

it('accepts nullable and nonempty manages runtime configuration in the generated Filament schema', function (?array $manages): void {
    $config = ['manages' => $manages];
    expect(AzGuardFilamentOptions::fromArray($config, 'azguard-filament')->manages)->toBe($manages);
    $schema = json_decode(file_get_contents(__DIR__.'/../../../packages/filament/resources/schemas/config.schema.json'));
    expect((new Validator)->validate((object) ['azguard-filament' => (object) $config], $schema)->isValid())->toBeTrue();
})->with(['all panels' => [null], 'no panels' => [[]], 'one panel' => [['admin']]]);
