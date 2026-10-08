<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/*
 * The name and signature of every `azguard:*` command are public surface: a renamed command, argument or option shows
 * up here first. Update the snapshot only together with the changelog.
 */

/** @return array<string, string> name => synopsis, sorted by name */
function azguardCommands(): array
{
    $commands = [];
    foreach (Artisan::all() as $name => $command) {
        if (str_starts_with($name, 'azguard:') && $command instanceof Command) {
            $commands[$name] = $command->getDefinition()->getSynopsis();
        }
    }
    ksort($commands, SORT_STRING);

    return $commands;
}

it('keeps the names and signatures of the azguard commands', function (): void {
    $expected = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/Console/commands.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(azguardCommands())->toBe($expected);
});

it('gives every changing command the shared options of its kind', function (): void {
    $definitions = array_map(static fn (string $name) => Artisan::all()[$name]->getDefinition(), array_keys(azguardCommands()));
    $byName = array_combine(array_keys(azguardCommands()), $definitions);

    foreach (['roles:grant', 'roles:revoke', 'permissions:grant', 'permissions:revoke'] as $command) {
        expect($byName['azguard:'.$command]->hasOption('panel'))->toBeTrue()
            ->and($byName['azguard:'.$command]->hasOption('tenant'))->toBeTrue()
            ->and($byName['azguard:'.$command]->hasOption('origin'))->toBeTrue()
            ->and($byName['azguard:'.$command]->hasOption('on'))->toBeTrue();
    }

    foreach (['permissions:delete', 'roles:rename-key', 'audit:prune', 'state:reset'] as $command) {
        expect($byName['azguard:'.$command]->hasOption('force'))->toBeTrue();
    }

    foreach (array_filter(array_keys($byName), static fn (string $name): bool => str_starts_with($name, 'azguard:make:')) as $name) {
        expect($byName[$name]->hasOption('force'))->toBeTrue();
    }

    foreach (['panels:list', 'sources:list', 'catalog:list', 'roles:list', 'grants:list', 'permissions:show', 'explain', 'doctor'] as $command) {
        expect($byName['azguard:'.$command]->hasOption('json'))->toBeTrue();
    }
});
