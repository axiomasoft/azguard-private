<?php

declare(strict_types=1);

use AzGuard\Audit\AuditPlugin;
use AzGuard\Audit\AuditServiceProvider;
use AzGuard\AzGuard;
use AzGuard\AzGuardServiceProvider;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\DevTools\Internal\Scaffold\StubStore;
use AzGuard\Exceptions\PluginConflictException;
use AzGuard\Filament\AzGuardFilamentServiceProvider;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Laravel\Internal\Queue\PanelContext;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;
use AzGuard\Storage\Internal\StorageRegistry;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Formats\FormatSnapshot;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

/*
 * I17, blocking now: the resource identities of knowledge/identities#resources as the code has them. Rows the
 * transition adds are listed as absent, so the phase that adds them changes this fixture deliberately.
 */

/** @return array<string, list<string>> publish tag => paths relative to the package and the application */
function baselinePublishTags(string $provider, string $package): array
{
    $tags = [];

    foreach (ServiceProvider::publishableGroups() as $tag) {
        $paths = ServiceProvider::pathsToPublish($provider, $tag);

        if (! str_starts_with($tag, 'azguard') || $paths === []) {
            continue;
        }
        $tags[$tag] = array_map(static fn (string $from, string $to): string => str_replace(realpath($package).'/', '', (string) realpath($from))
            .' => '.str_replace(base_path().'/', '', $to), array_keys($paths), array_values($paths));
    }
    ksort($tags, SORT_STRING);

    return $tags;
}

/** @return list<string> the files the generator writes for a named storage, with their date and storage as placeholders */
function baselineNamedStorageMigrations(string $command = 'azguard:storage:migration', string $argument = 'name'): array
{
    $scratch = sys_get_temp_dir().'/azguard-baseline-'.bin2hex(random_bytes(8));
    app()->useDatabasePath($scratch);
    config()->set('azguard.storages.archive', ['connection' => null, 'table_prefix' => 'archive_', 'host_keys' => null]);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(StorageRegistry::class);

    try {
        expect(Artisan::call($command, [$argument => 'archive']))->toBe(0);
        $files = array_map('basename', glob($scratch.'/migrations/*') ?: []);
        sort($files, SORT_STRING);

        expect($files)->not->toBeEmpty()->each->toMatch('/\A\d{4}_\d{2}_\d{2}_\d{6}_/');

        return array_map(static fn (string $file): string => str_replace('archive', '<storage>', (string) preg_replace('/\A\d{4}_\d{2}_\d{2}_\d{6}/', '<date>', $file)), $files);
    } finally {
        array_map('unlink', glob($scratch.'/migrations/*') ?: []);
        @rmdir($scratch.'/migrations');
        @rmdir($scratch);
    }
}

it('keeps the publish tags, migrations, aliases, namespaces and reserved identities', function (): void {
    $root = dirname(__DIR__, 3).'/packages';
    $router = app(Router::class);
    $migrations = array_map(static fn (string $file): string => basename($file, '.php'), glob($root.'/core/database/migrations/*.php') ?: []);
    $loaded = array_values(array_filter(app('migrator')->paths(), static fn (string $path): bool => realpath($path) === realpath($root.'/core/database/migrations')));
    app(StorageSchema::class)->create('default');
    // The keys come back in the order of the driver's JSON column (MySQL sorts them), so the snapshot sorts them.
    $state = json_decode((string) app(StorageRegistry::class)->get('default')->table('storage_state')->where('id', 1)->value('schema'), true, flags: JSON_THROW_ON_ERROR);
    ksort($state, SORT_STRING);
    $named = baselineNamedStorageMigrations();
    $namedAudit = '<date>_create_azguard_<storage>_audit_log.php';
    $auditMigrations = array_map(static fn (string $file): string => basename($file, '.php'), glob($root.'/core/modules/audit/database/migrations/*.php') ?: []);
    $auditLoaded = array_values(array_filter(app('migrator')->paths(), static fn (string $path): bool => realpath($path) === realpath($root.'/core/modules/audit/database/migrations')));
    $namedAuditFiles = baselineNamedStorageMigrations('azguard:audit:migration', 'storage');

    $snapshot = [
        'publish_tags' => [
            'core' => baselinePublishTags(AzGuardServiceProvider::class, $root.'/core'),
            'filament' => baselinePublishTags(AzGuardFilamentServiceProvider::class, $root.'/filament'),
            'audit' => baselinePublishTags(AuditServiceProvider::class, $root.'/core'),
        ],
        'stub_publish_path' => StubStore::PUBLISHED,
        'core_migrations' => ['basenames' => $migrations, 'loaded_by_provider' => count($loaded) === 1],
        'named_storage_migration' => implode(', ', array_diff($named, [$namedAudit])),
        'audit_migrations' => ['basenames' => $auditMigrations, 'loaded_by_provider' => count($auditLoaded) === 1],
        'named_audit_migration' => implode(', ', $namedAuditFiles),
        'middleware_aliases' => array_filter($router->getMiddleware(), static fn (string $alias): bool => str_starts_with($alias, 'azguard'), ARRAY_FILTER_USE_KEY),
        'container_alias' => ['azguard' => app('azguard')::class, 'same_instance' => app('azguard') === app(AzGuard::class)],
        'translation_namespaces' => array_values(array_filter(array_keys(app('translator')->getLoader()->namespaces()), static fn (string $name): bool => str_starts_with($name, 'azguard'))),
        'view_namespaces' => array_values(array_filter(array_keys(app('view')->getFinder()->getHints()), static fn (string $name): bool => str_starts_with($name, 'azguard'))),
        'queue_hidden_context_key' => PanelContext::KEY,
        'plugin_ids' => ['audit' => AuditPlugin::make()->id()],
        'system_actor_type' => ['constant' => ActorRef::SYSTEM_TYPE, 'system()' => ActorRef::system()->type],
        'storage_state_schema_keys' => array_keys($state),
        'storage_state_schema_default' => $state,
        'module_resources' => [
            'publish_tag azguard-audit-migrations' => ServiceProvider::pathsToPublish(null, 'azguard-audit-migrations') !== [],
            'migration 2026_10_20_000000_create_azguard_audit_log' => in_array('2026_10_20_000000_create_azguard_audit_log', $auditMigrations, true),
            'generated migration '.$namedAudit => in_array($namedAudit, $namedAuditFiles, true),
            'command azguard:audit:migration' => array_key_exists('azguard:audit:migration', Artisan::all()),
            'plugin id azguard/scopes' => class_exists('AzGuard\\Scopes\\ScopesPlugin'),
            'provider AzGuard\\Audit\\AuditServiceProvider' => class_exists('AzGuard\\Audit\\AuditServiceProvider'),
            'provider AzGuard\\DevTools\\DevToolsServiceProvider' => class_exists('AzGuard\\DevTools\\DevToolsServiceProvider'),
        ],
    ];
    [$actual, $stored] = FormatSnapshot::compare('resources', $snapshot);

    expect($actual)->toBe($stored);
});

describe('the reserved azguard/* plugin prefix', function (): void {
    beforeEach(fn () => CrmWorld::seed());
    afterEach(fn () => CrmWorld::resetRuntime());

    it('is enforced since P2.4: a host plugin cannot take an azguard/ id', function (): void {
        $plugin = new class implements Plugin
        {
            public function id(): string
            {
                return 'azguard/host-plugin';
            }

            public function register(PanelBuilder $panel, PluginContext $context): void {}
        };
        $accepted = true;

        try {
            CrmWorld::compile(static fn (PanelBuilder $builder) => $builder->plugins([$plugin]));
        } catch (PluginConflictException) {
            $accepted = false;
        }
        [$actual, $stored] = FormatSnapshot::compare('reserved-plugin-prefix', ['azguard/host-plugin accepted' => $accepted]);

        expect($actual)->toBe($stored);
    });
});
