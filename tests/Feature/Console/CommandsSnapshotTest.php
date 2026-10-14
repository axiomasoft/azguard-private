<?php

declare(strict_types=1);

use AzGuard\Audit\AuditPlugin;
use AzGuard\Catalog\Internal\CatalogCache;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Storage\Internal\StorageRegistry;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Console\GeneratedApp;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Formats\FormatSnapshot;
use AzGuard\Tests\Support\AuditTable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/*
 * The name, signature and exit codes of every `azguard:*` command are public surface: a renamed command, argument or
 * option, or a changed exit code shows up here first. Update the snapshot only together with the changelog.
 *
 * I17, blocking now: the exit codes of each path the scenarios below drive (0 success, 2 input,
 * panel, tenant or identity errors, 1 other failures).
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

function azguardCommandsPath(): string
{
    return __DIR__.'/../../Fixtures/Console/commands.json';
}

/** @return array<string, array{synopsis: string, exit_codes: array<string, int>}> */
function azguardCommandsSnapshot(): array
{
    return json_decode((string) file_get_contents(azguardCommandsPath()), true, flags: JSON_THROW_ON_ERROR);
}

it('keeps the names and signatures of the azguard commands', function (): void {
    if (FormatSnapshot::writing()) {
        $snapshot = azguardCommandsSnapshot();

        foreach (azguardCommands() as $name => $synopsis) {
            $snapshot[$name] = ['synopsis' => $synopsis, 'exit_codes' => $snapshot[$name]['exit_codes'] ?? []];
        }
        $snapshot = array_intersect_key($snapshot, azguardCommands());
        ksort($snapshot, SORT_STRING);
        file_put_contents(azguardCommandsPath(), FormatSnapshot::encode($snapshot));
    }

    expect(azguardCommands())->toBe(array_map(static fn (array $command): string => $command['synopsis'], azguardCommandsSnapshot()));
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

describe('exit codes', function (): void {
    beforeEach(function (): void {
        CrmWorld::seed();
        W::dynamicPanel();
        $this->scratch = sys_get_temp_dir().'/azguard-exit-codes-'.bin2hex(random_bytes(6));
        config()->set('azguard.catalog.cache_path', $this->scratch.'/catalog.php');
        // The cache was resolved at boot with the shared bootstrap path; resolve it again on the scratch path.
        app()->forgetInstance(AzGuardConfig::class);
        app()->forgetInstance(CatalogCache::class);
    });
    afterEach(function (): void {
        app()->instance('env', 'testing');
        CrmWorld::resetRuntime();
        Carbon::setTestNow();
        Relation::morphMap([], false);
        (new Filesystem)->deleteDirectory($this->scratch);
    });

    /**
     * Drives every scenario of one command in the CRM world and returns the exit code of each, in order.
     *
     * @param  array<string, Closure(): int>  $scenarios
     * @return array<string, int>
     */
    function azguardExitCodes(array $scenarios): array
    {
        return array_map(static fn (Closure $scenario): int => $scenario(), $scenarios);
    }

    /** @return array<string, array<string, Closure(): int>> command => label => scenario */
    function azguardExitScenarios(): array
    {
        $tenant = ['--panel' => 'crm', '--tenant' => 'crm.organization:1'];
        $call = static fn (string $command, array $arguments = []): Closure => static fn (): int => Artisan::call($command, $arguments);
        $generated = static function (Closure $run): Closure {
            return static function () use ($run): int {
                $app = GeneratedApp::in(app(), withConfig: true);
                mkdir($app->path('app/Guards/Admin'), 0o755, true);

                try {
                    return $run($app);
                } finally {
                    $app->release();
                }
            };
        };
        $make = static fn (string $command, array $arguments): array => [
            'success' => $generated(static fn (): int => Artisan::call($command, $arguments)),
            'exists without --force' => $generated(static function () use ($command, $arguments): int {
                Artisan::call($command, $arguments);

                return Artisan::call($command, $arguments);
            }),
        ];

        $archive = static function (): void {
            app()->useDatabasePath(test()->scratch);
            config()->set('azguard.storages.archive', ['connection' => null, 'table_prefix' => 'archive_', 'host_keys' => null]);
            app()->forgetInstance(AzGuardConfig::class);
            app()->forgetInstance(StorageRegistry::class);
        };

        return [
            'azguard:audit:migration' => [
                'success' => static function () use ($archive): int {
                    $archive();

                    return Artisan::call('azguard:audit:migration', ['storage' => 'archive']);
                },
                'exists' => static function () use ($archive): int {
                    $archive();
                    Artisan::call('azguard:audit:migration', ['storage' => 'archive']);

                    return Artisan::call('azguard:audit:migration', ['storage' => 'archive']);
                },
                'default storage' => $call('azguard:audit:migration', ['storage' => 'default']),
                'unknown storage' => $call('azguard:audit:migration', ['storage' => 'ghost']),
            ],
            'azguard:audit:prune' => [
                'success' => static function (): int {
                    AuditTable::create();
                    W::panel(configure: static fn (PanelBuilder $builder) => $builder->plugins([AuditPlugin::make()]));

                    return Artisan::call('azguard:audit:prune');
                },
                'production without --force' => static function (): int {
                    W::panel(configure: static fn (PanelBuilder $builder) => $builder->plugins([AuditPlugin::make()]));
                    app()->instance('env', 'production');

                    return Artisan::call('azguard:audit:prune');
                },
                'unknown panel' => $call('azguard:audit:prune', ['--panel' => 'ghost']),
            ],
            'azguard:catalog:cache' => ['success' => $call('azguard:catalog:cache')],
            'azguard:catalog:clear' => ['success' => $call('azguard:catalog:clear')],
            'azguard:catalog:list' => ['success' => $call('azguard:catalog:list', $tenant), 'missing tenant' => $call('azguard:catalog:list', ['--panel' => 'crm']),
                'unknown panel' => $call('azguard:catalog:list', ['--panel' => 'ghost', '--tenant' => 'crm.organization:1'])],
            'azguard:doctor' => [
                'no errors' => $call('azguard:doctor', ['--json' => true]),
                'error findings' => static function (): int {
                    CrmWorld::storage()->connection()->getSchemaBuilder()->drop('azg_panel_state');

                    return Artisan::call('azguard:doctor', ['--json' => true]);
                },
                'unknown panel' => $call('azguard:doctor', ['--panel' => ['ghost']]),
                'unknown storage' => $call('azguard:doctor', ['--storage' => ['ghost']]),
            ],
            'azguard:explain' => ['success' => $call('azguard:explain', ['subject' => 'crm.user:1', 'permission' => 'crm:clients.view', ...$tenant]),
                'unknown subject' => $call('azguard:explain', ['subject' => 'crm.user:999', 'permission' => 'crm:clients.view', ...$tenant]),
                'malformed subject' => $call('azguard:explain', ['subject' => 'missing-colon', 'permission' => 'crm:clients.view', ...$tenant])],
            // The success and Filament paths need a booted Filament panel: tests/Feature/Filament/Generate/FilamentGenerateTest.
            'azguard:filament:generate' => ['invalid --authority' => $call('azguard:filament:generate', ['--authority' => 'bogus'])],
            'azguard:grants:list' => ['success' => $call('azguard:grants:list', ['subject' => 'crm.user:1', ...$tenant]),
                'unknown panel' => $call('azguard:grants:list', ['subject' => 'crm.user:1', '--panel' => 'ghost', '--tenant' => 'crm.organization:1'])],
            'azguard:grants:prune' => ['success' => $call('azguard:grants:prune'), 'malformed --before' => $call('azguard:grants:prune', ['--before' => 'yesterday-ish']),
                'unknown panel' => $call('azguard:grants:prune', ['--panel' => 'ghost'])],
            'azguard:install' => [
                'success' => $generated(static function (): int {
                    config()->set('auth.providers.users.model', 'AzGuard\Tests\Fixtures\Panels\User');
                    app()->bind(Process::class, static function (): Process {
                        $process = Mockery::mock(Process::class);
                        $process->shouldReceive('run')->andReturn(0);

                        return $process;
                    });

                    try {
                        return Artisan::call('azguard:install', ['--host-keys' => 'bigint', '--force' => true, '--no-interaction' => true]);
                    } finally {
                        app()->offsetUnset(Process::class);
                    }
                }),
                'unknown host keys' => $generated(static fn (): int => Artisan::call('azguard:install', ['--host-keys' => 'guid', '--no-interaction' => true])),
            ],
            'azguard:make:models' => ['success' => $generated(static function (): int {
                Artisan::call('azguard:make:panel', ['panel' => 'admin']);

                return Artisan::call('azguard:make:models', ['panel' => 'admin']);
            }), 'unknown storage' => $generated(static fn (): int => Artisan::call('azguard:make:models', ['panel' => 'admin', '--storage' => 'ghost']))],
            'azguard:make:panel' => [...$make('azguard:make:panel', ['panel' => 'admin']),
                'name normalised to a class' => $generated(static fn (): int => Artisan::call('azguard:make:panel', ['panel' => 'Not A Panel']))],
            'azguard:make:permission' => $make('azguard:make:permission', ['panel' => 'admin', 'group' => 'Orders']),
            'azguard:make:pipe' => $make('azguard:make:pipe', ['name' => 'StampPipe', '--panel' => 'admin']),
            'azguard:make:plugin' => $make('azguard:make:plugin', ['name' => 'HostPlugin', '--panel' => 'admin']),
            'azguard:make:policy' => [
                'success' => $generated(static function (): int {
                    Artisan::call('azguard:make:permission', ['panel' => 'admin', 'group' => 'Orders']);

                    return Artisan::call('azguard:make:policy', ['panel' => 'admin', 'group' => 'Orders']);
                }),
                'exists without --force' => $generated(static function (): int {
                    Artisan::call('azguard:make:permission', ['panel' => 'admin', 'group' => 'Orders']);
                    Artisan::call('azguard:make:policy', ['panel' => 'admin', 'group' => 'Orders']);

                    return Artisan::call('azguard:make:policy', ['panel' => 'admin', 'group' => 'Orders']);
                }),
                'group without a permission enum' => $generated(static fn (): int => Artisan::call('azguard:make:policy', ['panel' => 'admin', 'group' => 'Orders'])),
            ],
            'azguard:make:restriction' => $make('azguard:make:restriction', ['name' => 'OfficeHours', '--panel' => 'admin']),
            'azguard:make:role' => $make('azguard:make:role', ['panel' => 'admin', 'name' => 'Manager']),
            'azguard:make:source' => $make('azguard:make:source', ['name' => 'Ldap', '--panel' => 'admin']),
            'azguard:panels:list' => ['success' => $call('azguard:panels:list')],
            'azguard:permissions:create' => ['success' => $call('azguard:permissions:create', ['name' => 'reports.export', ...$tenant]),
                'exists' => static function (): int {
                    Artisan::call('azguard:permissions:create', ['name' => 'reports.export', '--panel' => 'crm', '--tenant' => 'crm.organization:1']);

                    return Artisan::call('azguard:permissions:create', ['name' => 'reports.export', '--panel' => 'crm', '--tenant' => 'crm.organization:1']);
                },
                'missing tenant' => $call('azguard:permissions:create', ['name' => 'reports.export', '--panel' => 'crm'])],
            'azguard:permissions:delete' => ['success' => static function (): int {
                Artisan::call('azguard:permissions:create', ['name' => 'reports.export', '--panel' => 'crm', '--tenant' => 'crm.organization:1']);

                return Artisan::call('azguard:permissions:delete', ['name' => 'reports.export', '--panel' => 'crm', '--tenant' => 'crm.organization:1', '--force' => true]);
            }, 'unknown permission' => $call('azguard:permissions:delete', ['name' => 'reports.export', ...$tenant, '--force' => true])],
            'azguard:permissions:grant' => ['success' => $call('azguard:permissions:grant', ['subject' => 'crm.user:3', 'permission' => 'clients.view', ...$tenant]),
                'unknown permission' => $call('azguard:permissions:grant', ['subject' => 'crm.user:3', 'permission' => 'clients.unknown', ...$tenant]),
                'malformed --until' => $call('azguard:permissions:grant', ['subject' => 'crm.user:3', 'permission' => 'clients.view', ...$tenant, '--until' => 'soon'])],
            'azguard:permissions:revoke' => ['success' => $call('azguard:permissions:revoke', ['subject' => 'crm.user:3', 'permission' => 'clients.view', ...$tenant]),
                'missing tenant' => $call('azguard:permissions:revoke', ['subject' => 'crm.user:3', 'permission' => 'clients.view', '--panel' => 'crm'])],
            'azguard:permissions:show' => ['success' => $call('azguard:permissions:show', ['subject' => 'crm.user:1', ...$tenant]),
                'missing tenant' => $call('azguard:permissions:show', ['subject' => 'crm.user:1', '--panel' => 'crm'])],
            'azguard:roles:grant' => ['success' => $call('azguard:roles:grant', ['subject' => 'crm.user:2', 'role' => 'seller', ...$tenant, '--on' => 'crm.project:2']),
                'unknown role' => $call('azguard:roles:grant', ['subject' => 'crm.user:2', 'role' => 'ghost', ...$tenant]),
                'unknown panel' => $call('azguard:roles:grant', ['subject' => 'crm.user:2', 'role' => 'seller', '--panel' => 'ghost', '--tenant' => 'crm.organization:1'])],
            'azguard:roles:list' => ['success' => $call('azguard:roles:list', $tenant), 'unknown panel' => $call('azguard:roles:list', ['--panel' => 'ghost'])],
            'azguard:roles:rename-key' => ['dry run' => $call('azguard:roles:rename-key', ['role' => 'inspector', 'new' => 'auditor', ...$tenant, '--dry-run' => true]),
                'target is not a former key' => $call('azguard:roles:rename-key', ['role' => 'seller', 'new' => 'auditor', ...$tenant, '--force' => true]),
                'missing tenant' => $call('azguard:roles:rename-key', ['role' => 'inspector', 'new' => 'auditor', '--panel' => 'crm'])],
            'azguard:roles:revoke' => ['success' => $call('azguard:roles:revoke', ['subject' => 'crm.user:2', 'role' => 'seller', ...$tenant, '--on' => 'crm.project:2']),
                'unknown panel' => $call('azguard:roles:revoke', ['subject' => 'crm.user:2', 'role' => 'seller', '--panel' => 'ghost', '--tenant' => 'crm.organization:1'])],
            'azguard:sources:list' => ['success' => $call('azguard:sources:list')],
            'azguard:state:reset' => ['success' => $call('azguard:state:reset', ['panel' => 'crm']), 'unknown panel' => $call('azguard:state:reset', ['panel' => 'ghost']),
                'production without --force' => static function (): int {
                    app()->instance('env', 'production');

                    return Artisan::call('azguard:state:reset', ['panel' => 'crm']);
                }],
            'azguard:storage:migration' => [
                'success' => static function () use ($archive): int {
                    $archive();

                    return Artisan::call('azguard:storage:migration', ['name' => 'archive']);
                },
                'default storage' => $call('azguard:storage:migration', ['name' => 'default']),
                'unknown storage' => $call('azguard:storage:migration', ['name' => 'ghost']),
            ],
            'azguard:stubs' => ['success' => $generated(static fn (): int => Artisan::call('azguard:stubs')),
                'published again without --force keeps them' => $generated(static function (): int {
                    Artisan::call('azguard:stubs');

                    return Artisan::call('azguard:stubs');
                })],
        ];
    }

    it('keeps the exit code of every path the scenarios drive', function (string $command): void {
        $codes = azguardExitCodes(azguardExitScenarios()[$command]);
        $snapshot = azguardCommandsSnapshot();

        if (FormatSnapshot::writing()) {
            $snapshot[$command]['exit_codes'] = $codes;
            file_put_contents(azguardCommandsPath(), FormatSnapshot::encode($snapshot));
        }

        expect($codes)->toBe(azguardCommandsSnapshot()[$command]['exit_codes'] ?? null);
    })->with(array_keys(azguardExitScenarios()));

    it('drives every azguard command', function (): void {
        expect(array_keys(azguardExitScenarios()))->toBe(array_keys(azguardCommands()));
    });
});
