<?php

declare(strict_types=1);
use AzGuard\Contracts\RoleInterface;
use AzGuard\Exceptions\AzGuardException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

arch()->preset()->php()->ignoring('AzGuard\\Filament');

arch()->preset()->security()->ignoring('AzGuard\\Filament');

arch('no debugging calls in source')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed()
    ->ignoring('AzGuard\\Tests');

arch('contracts are interfaces')
    ->expect('AzGuard\\Contracts')
    ->toBeInterfaces();

arch('models extend Eloquent Model')
    ->expect('AzGuard\\Models')
    ->toExtend(Model::class);

arch('service provider extends ServiceProvider')
    ->expect('AzGuard\\AzGuardServiceProvider')
    ->toExtend(ServiceProvider::class);

arch('roles implement RoleInterface')
    ->expect('AzGuard\\Roles')
    ->toImplement(RoleInterface::class)
    ->ignoring('AzGuard\\Roles\\BaseRole');

arch('facades extend Illuminate Facade')
    ->expect('AzGuard\\Facades')
    ->toExtend(Facade::class);

arch('strict types declared in all source files')
    ->expect('AzGuard')
    ->toUseStrictTypes()
    ->ignoring([
        'AzGuard\\Tests',
    ]);

arch('commands extend Illuminate Console Command')
    ->expect('AzGuard\\Commands')
    ->toExtend(Command::class)
    ->ignoring('AzGuard\\Commands\\Concerns');

/**
 * Every exception shipped by any AzGuard package must extend AzGuardException so
 * a single `catch (AzGuardException)` handles the whole domain. Scans the source
 * tree directly (not just autoloaded classes) so a new *Exception dropped into
 * any sub-namespace is caught by CI, not discovered in production.
 */
test('every package exception extends AzGuardException', function (): void {
    $roots = [
        'AzGuard\\' => dirname(__DIR__).'/packages/core/src',
        'AzGuard\\Context\\' => dirname(__DIR__).'/packages/context/src',
        'AzGuard\\Filament\\' => dirname(__DIR__).'/packages/filament/src',
    ];

    $found = [];

    foreach ($roots as $namespace => $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), 'Exception.php')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($dir) + 1, -4);
            $found[] = $namespace.str_replace('/', '\\', $relative);
        }
    }

    expect($found)->not->toBeEmpty();

    foreach ($found as $class) {
        expect(class_exists($class))->toBeTrue("Exception class [{$class}] could not be autoloaded.");

        if ($class === AzGuardException::class) {
            continue;
        }

        expect(is_subclass_of($class, AzGuardException::class))
            ->toBeTrue("Exception [{$class}] must extend ".AzGuardException::class.'.');
    }
});

/**
 * Architectural ratchets (F49): enforce immutability and structural patterns
 * across specific subsystems to prevent regression of implementation contracts.
 *
 * Matrix (parametrized by namespace arrays):
 *  - Events: AzGuard\Events, AzGuard\Context\Events (final)
 *  - Registry\Values: immutable value objects (final readonly)
 *  - Abilities: concrete resolvers like DefaultAbilitiesResolver (final readonly)
 *  - Concerns: composition traits across core, commands, filament packages
 *
 * @see https://pestphp.com/docs/arch-testing — architecture testing with Pest
 */
arch('events are final')
    ->expect([
        'AzGuard\\Events',
        'AzGuard\\Context\\Events',
    ])
    ->toBeFinal();

arch('registry values are final and readonly')
    ->expect('AzGuard\\Registry\\Values')
    ->toBeFinal()
    ->toBeReadonly();

arch('concrete ability resolvers are final and readonly')
    ->expect('AzGuard\\Abilities\\DefaultAbilitiesResolver')
    ->toBeFinal()
    ->toBeReadonly();

arch('concerns are traits (core, commands, filament)')
    ->expect([
        'AzGuard\\Concerns',
        'AzGuard\\Commands\\Concerns',
        'AzGuard\\Filament\\Concerns',
    ])
    ->toBeTraits();
