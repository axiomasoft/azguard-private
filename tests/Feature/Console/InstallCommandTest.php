<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Console\GeneratedApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

const INSTALL_USER = 'AzGuard\Tests\Fixtures\Panels\User';

beforeEach(function (): void {
    $this->generated = GeneratedApp::in(app());
    $this->ran = [];
    $this->processes = [];
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
    app()->bind(Process::class, function ($app, array $parameters): Process {
        $this->processes[] = $parameters;
        $name = $parameters['command'][2];
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('run')->once()->andReturnUsing(function (callable $output) use ($name): int {
            $this->ran[] = $name;
            $output(Process::OUT, 'fresh '.$name."\n");

            return $this->answers[$name];
        });

        return $process;
    });
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

it('boots child commands from the application directory with explicit arguments and the selected connection', function (): void {
    file_put_contents($this->generated->path('.env'), "AZGUARD_DB_CONNECTION=testbench\n");

    expect(Artisan::call('azguard:install', ['--host-keys' => 'bigint', '--connection' => 'audit', '--migrate' => true,
        '--force' => true, '--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('fresh migrate', 'fresh azguard:doctor')
        ->and($this->processes)->toHaveCount(2)
        ->and($this->processes[0])->toBe([
            'command' => [PHP_BINARY, $this->generated->path('artisan'), 'migrate', '--no-interaction', '--force'],
            'cwd' => $this->generated->root, 'env' => ['AZGUARD_DB_CONNECTION' => 'audit'], 'timeout' => 300,
        ])
        ->and($this->processes[1]['command'])->toBe([PHP_BINARY, $this->generated->path('artisan'), 'azguard:doctor', '--no-interaction']);
});

it('does not override a kept connection in the child environment', function (): void {
    file_put_contents($this->generated->path('.env'), "AZGUARD_DB_CONNECTION=testbench\n");
    Artisan::call('azguard:install', ['--connection' => 'audit', '--no-interaction' => true]);

    expect($this->processes[0]['env'])->toBe([]);
});

it('preserves dotenv export and whitespace connection declarations unless forced', function (string $declaration): void {
    file_put_contents($this->generated->path('.env'), $declaration."\n");
    Artisan::call('azguard:install', ['--connection' => 'audit', '--no-interaction' => true]);

    expect($this->generated->read('.env'))->toBe($declaration."\n")
        ->and($this->processes[0]['env'])->toBe([]);

    Artisan::call('azguard:install', ['--connection' => 'audit', '--force' => true, '--no-interaction' => true]);
    expect($this->generated->read('.env'))->toBe("AZGUARD_DB_CONNECTION=audit\n")
        ->and($this->processes[1]['env'])->toBe(['AZGUARD_DB_CONNECTION' => 'audit']);
})->with([
    'export' => ['export AZGUARD_DB_CONNECTION=testbench'],
    'whitespace' => ['  AZGUARD_DB_CONNECTION = testbench'],
    'single quoted name' => ["'AZGUARD_DB_CONNECTION'=testbench"],
    'double quoted name' => ['"AZGUARD_DB_CONNECTION" = testbench'],
]);

it('passes the selected artisan environment to both fresh child commands after clearing config cache', function (): void {
    $cache = app()->getCachedConfigPath();
    app('files')->ensureDirectoryExists(dirname($cache));
    file_put_contents($cache, '<?php return [];');
    Artisan::call('azguard:install', ['--env' => 'testing', '--migrate' => true, '--force' => true, '--no-interaction' => true]);

    expect($this->processes[0]['command'])->toBe([PHP_BINARY, $this->generated->path('artisan'), 'migrate', '--no-interaction', '--env=testing', '--force'])
        ->and($this->processes[1]['command'])->toBe([PHP_BINARY, $this->generated->path('artisan'), 'azguard:doctor', '--no-interaction', '--env=testing']);
});

it('clears a cached configuration before publishing and starting fresh commands', function (): void {
    $cache = app()->getCachedConfigPath();
    app('files')->ensureDirectoryExists(dirname($cache));
    file_put_contents($cache, '<?php return [];');

    expect(Artisan::call('azguard:install', ['--no-interaction' => true]))->toBe(0)
        ->and(is_file($cache))->toBeFalse()
        ->and($this->ran)->toBe(['azguard:doctor']);
});

it('stops before writes when the configuration cache cannot be cleared', function (): void {
    $cache = app()->getCachedConfigPath();
    app('files')->ensureDirectoryExists(dirname($cache));
    file_put_contents($cache, '<?php return [];');
    Artisan::registerCommand(new class extends Command
    {
        protected $signature = 'config:clear';

        public function handle(): int
        {
            return 3;
        }
    });

    expect(Artisan::call('azguard:install', ['--migrate' => true, '--no-interaction' => true]))->toBe(3)
        ->and($this->generated->has('config/azguard.php'))->toBeFalse()
        ->and($this->ran)->toBe([]);
});

it('stops before migrations and checks if the requested panel could not be generated', function (): void {
    expect(Artisan::call('azguard:install', ['--panel' => '../escape', '--migrate' => true, '--no-interaction' => true]))->toBe(2)
        ->and($this->ran)->toBe([]);
});

it('uses the installed host key type connection and generated panel in a fresh isolated application', function (): void {
    // Only this disposable fixture script is executed: both database connections are SQLite :memory:.
    app()->bind(Process::class, static fn ($app, array $parameters): Process => new Process(...$parameters));
    app('files')->ensureDirectoryExists($this->generated->path('bootstrap/cache'));
    app('files')->ensureDirectoryExists($this->generated->path('storage/framework/views'));
    $autoload = var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true);
    $namespace = var_export($this->generated->namespace.'\\', true);
    $script = <<<'PHP'
<?php
require AUTOLOAD;
$prefix = NAMESPACE_PREFIX;
spl_autoload_register(static function (string $class) use ($prefix): void {
    if (str_starts_with($class, $prefix)) {
        require __DIR__.'/app/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
});
try {
$app = Orchestra\Testbench\Foundation\Application::create(null, static function ($app): void {
    $app->setBasePath(__DIR__);
    $app->booting(static function ($app): void {
        $app['config']->set('azguard', require __DIR__.'/config/azguard.php');
        $app['config']->set('database.default', 'fixture');
        foreach (['fixture', 'audit'] as $connection) {
            $app['config']->set('database.connections.'.$connection, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        }
    });
}, ['extra' => ['providers' => [AzGuard\AzGuardServiceProvider::class]], 'enabled_package_discoveries' => false]);
$storage = $app->make(AzGuard\Storage\StorageRegistry::class)->get('default');
(require PACKAGE_MIGRATION)->up();
$result = ['pid' => getmypid(), 'command' => $argv[1], 'connection' => $storage->connectionName(), 'host_keys' => $storage->hostKeys(),
    'subject_column' => $storage->connection()->getSchemaBuilder()->getColumnType('azg_role_grants', 'subject_id'),
    'panels' => array_values(array_map(static fn ($panel): string => $panel->id(), $app->make(AzGuard\Panels\PanelRegistry::class)->all()))];
file_put_contents(__DIR__.'/observed-'.$argv[1].'.json', json_encode($result, JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage());
    exit(1);
}
PHP;
    $migration = glob(dirname(__DIR__, 3).'/packages/core/database/migrations/*create_azguard_storage.php')[0];
    file_put_contents($this->generated->path('artisan'), str_replace(['AUTOLOAD', 'NAMESPACE_PREFIX', 'PACKAGE_MIGRATION'],
        [$autoload, $namespace, var_export($migration, true)], $script));

    expect(Artisan::call('azguard:install', ['--panel' => 'Admin', '--host-keys' => 'bigint', '--connection' => 'audit',
        '--migrate' => true, '--no-interaction' => true]))->toBe(0, Artisan::output());
    foreach (['migrate', 'azguard:doctor'] as $command) {
        expect($this->generated->has('observed-'.$command.'.json'))->toBeTrue(Artisan::output());
        $observed = json_decode($this->generated->read('observed-'.$command.'.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($observed['pid'])->not->toBe(getmypid())
            ->and($observed)->toMatchArray(['command' => $command, 'connection' => 'audit', 'host_keys' => 'bigint',
                'subject_column' => 'integer', 'panels' => ['admin']]);
    }
});
