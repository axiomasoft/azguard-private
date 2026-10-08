<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Console\GeneratedApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

const INSTALL_USER = 'AzGuard\Tests\Fixtures\Panels\User';

beforeEach(function (): void {
    $this->generated = GeneratedApp::in(app());
    $this->ran = [];
    // Neither the real migrations nor the real checks run here: the spies record the call and answer with a chosen code.
    $this->answers = ['migrate' => 0, 'azguard:doctor' => 0];
    foreach (['migrate', 'azguard:doctor'] as $name) {
        Artisan::registerCommand(new class($name, $this->ran, $this->answers) extends Command
        {
            /** @param array<string, int> $answers */
            public function __construct(string $name, private array &$ran, private array &$answers)
            {
                $this->name = $name;
                $this->ignoreValidationErrors();
                parent::__construct();
            }

            public function handle(): int
            {
                $this->ran[] = $this->getName();

                return $this->answers[$this->getName()];
            }
        });
    }
    config()->set('auth.providers.users.model', INSTALL_USER);
    config()->set('database.connections.audit', ['driver' => 'sqlite', 'database' => ':memory:']);
});
afterEach(fn () => $this->generated->release());

function installedHostKeys(GeneratedApp $generated): string
{
    return (require $generated->path('config/azguard.php'))['ids']['host_keys'];
}

it('V37 does not run the migrations without --migrate and prints the checks', function (): void {
    expect(Artisan::call('azguard:install', ['--no-interaction' => true]))->toBe(0)
        ->and($this->ran)->toBe(['azguard:doctor'])
        ->and(Artisan::output())->toContain('create_azguard_storage', 'The migrations were not run');
});

it('V37 runs the migrations with --migrate, then the checks, and exits with the code of the checks', function (): void {
    $this->answers['azguard:doctor'] = 1;

    expect(Artisan::call('azguard:install', ['--migrate' => true, '--no-interaction' => true]))->toBe(1)
        ->and($this->ran)->toBe(['migrate', 'azguard:doctor']);
});

it('V37 returns the failing code of migrate as it is and skips the checks', function (int $code): void {
    $this->answers['migrate'] = $code;

    expect(Artisan::call('azguard:install', ['--migrate' => true, '--no-interaction' => true]))->toBe($code)
        ->and($this->ran)->toBe(['migrate'])
        ->and(Artisan::output())->toContain('failed with exit code '.$code);
})->with([1, 3]);

it('publishes the configuration with the chosen type of host keys', function (): void {
    expect(Artisan::call('azguard:install', ['--host-keys' => 'ulid', '--no-interaction' => true]))->toBe(0)
        ->and(installedHostKeys($this->generated))->toBe('ulid');
});

it('takes string host keys by default', function (): void {
    Artisan::call('azguard:install', ['--no-interaction' => true]);

    expect(installedHostKeys($this->generated))->toBe('string');
});

it('refuses an unknown type of host keys before anything is written', function (): void {
    expect(Artisan::call('azguard:install', ['--host-keys' => 'int', '--migrate' => true, '--no-interaction' => true]))->toBe(2)
        ->and($this->generated->files())->toBe([])
        ->and($this->ran)->toBe([])
        ->and(Artisan::output())->toContain('--host-keys must be one of string, bigint, uuid, ulid');
});

it('keeps an existing configuration on a second run and rewrites it with --force', function (): void {
    Artisan::call('azguard:install', ['--host-keys' => 'uuid', '--no-interaction' => true]);
    $custom = $this->generated->read('config/azguard.php')."\n// edited by the application\n";
    file_put_contents($this->generated->path('config/azguard.php'), $custom);

    expect(Artisan::call('azguard:install', ['--host-keys' => 'bigint', '--no-interaction' => true]))->toBe(0)
        ->and($this->generated->read('config/azguard.php'))->toBe($custom)
        ->and(Artisan::output())->toContain('was kept');

    Artisan::call('azguard:install', ['--host-keys' => 'bigint', '--force' => true, '--no-interaction' => true]);

    expect(installedHostKeys($this->generated))->toBe('bigint');
});

it('writes the connection to .env and keeps a key that is already there without --force', function (): void {
    file_put_contents($this->generated->path('.env'), "APP_NAME=Test\n");

    expect(Artisan::call('azguard:install', ['--connection' => 'audit', '--no-interaction' => true]))->toBe(0)
        ->and($this->generated->read('.env'))->toBe("APP_NAME=Test\nAZGUARD_DB_CONNECTION=audit\n");

    config()->set('database.connections.other', ['driver' => 'sqlite', 'database' => ':memory:']);
    Artisan::call('azguard:install', ['--connection' => 'other', '--no-interaction' => true]);

    expect($this->generated->read('.env'))->toBe("APP_NAME=Test\nAZGUARD_DB_CONNECTION=audit\n")
        ->and(Artisan::output())->toContain('already set');

    Artisan::call('azguard:install', ['--connection' => 'other', '--force' => true, '--no-interaction' => true]);

    expect($this->generated->read('.env'))->toBe("APP_NAME=Test\nAZGUARD_DB_CONNECTION=other\n");
});

it('refuses a connection that config/database.php does not have', function (): void {
    expect(Artisan::call('azguard:install', ['--connection' => "x\nAPP_KEY=1", '--no-interaction' => true]))->toBe(2)
        ->and($this->generated->files())->toBe([]);
});

it('creates the first panel from --panel and lists it in the published configuration', function (): void {
    expect(Artisan::call('azguard:install', ['--panel' => 'Admin', '--no-interaction' => true]))->toBe(0)
        ->and($this->generated->has('app/Guards/Admin/AdminGuardPanelProvider.php'))->toBeTrue()
        ->and($this->generated->read('config/azguard.php'))->toContain('AdminGuardPanelProvider::class');
});

it('asks for the choices when it is interactive', function (): void {
    file_put_contents($this->generated->path('.env'), '');

    $this->artisan('azguard:install')
        ->expectsChoice('Which type do the keys of your models have?', 'uuid', ['string', 'bigint', 'uuid', 'ulid'])
        ->expectsChoice('Which database connection holds the AzGuard tables?', 'audit', ['(the default connection)', ...array_map(strval(...), array_keys(config('database.connections')))])
        ->expectsConfirmation('Create the first panel now?', 'yes')
        ->expectsQuestion('Name of the panel', 'Orders')
        ->assertExitCode(0);

    expect(installedHostKeys($this->generated))->toBe('uuid')
        ->and($this->generated->read('.env'))->toBe("AZGUARD_DB_CONNECTION=audit\n")
        ->and($this->generated->has('app/Guards/Orders/OrdersGuardPanelProvider.php'))->toBeTrue()
        ->and($this->ran)->toBe(['azguard:doctor']);
});

it('skips the panel when the owner declines it', function (): void {
    $this->artisan('azguard:install')
        ->expectsChoice('Which type do the keys of your models have?', 'string', ['string', 'bigint', 'uuid', 'ulid'])
        ->expectsChoice('Which database connection holds the AzGuard tables?', '(the default connection)', ['(the default connection)', ...array_map(strval(...), array_keys(config('database.connections')))])
        ->expectsConfirmation('Create the first panel now?', 'no')
        ->assertExitCode(0);

    expect($this->generated->files())->toBe(['config/azguard.php']);
});

it('runs the checks at the end of every installation', function (): void {
    Artisan::call('azguard:install', ['--no-interaction' => true]);

    expect($this->ran)->toBe(['azguard:doctor']);
});
