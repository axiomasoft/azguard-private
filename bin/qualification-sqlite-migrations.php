<?php

declare(strict_types=1);

// This probe never accepts a database target. Bootstrap and all connections are
// constrained to an isolated in-memory SQLite database, irrespective of the shell.
$root = dirname(__DIR__);
$expected = [
    $root.'/packages/core/database/migrations/2026_10_01_000000_create_azguard_storage.php',
    $root.'/packages/core/modules/audit/database/migrations/2026_10_20_000000_create_azguard_audit_log.php',
];

try {
    if ($argc > 2 || ($argc === 2 && ! str_starts_with($argv[1], '--core-migration='))) {
        throw new RuntimeException('Usage: php bin/qualification-sqlite-migrations.php [--core-migration=PATH]');
    }

    // A supplied path can only identify the expected file; it cannot execute an arbitrary PHP file.
    if ($argc === 2 && substr($argv[1], strlen('--core-migration=')) !== $expected[0]) {
        throw new RuntimeException('Core migration path is unavailable or is not the expected repository migration');
    }

    foreach ($expected as $path) {
        if (! is_file($path) || ! is_readable($path) || realpath($path) !== $path) {
            throw new RuntimeException('Migration unavailable or not a regular repository file: '.$path);
        }
    }

    foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'AZGUARD_DB_CONNECTION' => 'preflight'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    require $root.'/vendor/autoload.php';

    $case = new class('qualification-sqlite-migrations') extends \AzGuard\Tests\TestCase {
        protected function getPackageProviders($app): array
        {
            return [\AzGuard\AzGuardServiceProvider::class, \AzGuard\Audit\AuditServiceProvider::class];
        }

        protected function getEnvironmentSetUp($app): void
        {
            $app['config']->set('database.default', 'preflight');
            $app['config']->set('database.connections', ['preflight' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ]]);
            $app['config']->set('azguard.storages.default.connection', 'preflight');
        }
    };
    $app = $case->createApplication();
    $config = $app['config'];
    if ($config->get('database.default') !== 'preflight'
        || $config->get('database.connections') !== ['preflight' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]
        || $config->get('azguard.storages.default.connection') !== 'preflight') {
        throw new RuntimeException('Database configuration is not isolated');
    }
    $connection = $app['db']->connection('preflight');
    if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
        throw new RuntimeException('Resolved database is not SQLite :memory:');
    }
    $pdo = $connection->getPdo();
    $databaseList = $pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
    if (count($databaseList) !== 1 || $databaseList[0]['name'] !== 'main' || $databaseList[0]['file'] !== '') {
        throw new RuntimeException('SQLite resolved to a file or attached database');
    }

    foreach ($expected as $path) {
        $migration = require $path;
        if (! $migration instanceof \Illuminate\Database\Migrations\Migration) {
            throw new RuntimeException('File did not return a Laravel migration: '.$path);
        }
        $migration->up();
    }
    $tables = ['azg_permissions', 'azg_role_grants', 'azg_permission_grants', 'azg_panel_state', 'azg_subject_revisions', 'azg_storage_state', 'azg_audit_log'];
    $schema = $connection->getSchemaBuilder();
    foreach ($tables as $table) {
        if (! $schema->hasTable($table)) {
            throw new RuntimeException('Migration did not create expected table: '.$table);
        }
    }
    $connection->disconnect();
    echo json_encode(['status' => 'passed', 'target' => 'sqlite::memory:', 'migrations' => array_map('basename', $expected), 'tables' => $tables], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'SQLite migration probe failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
