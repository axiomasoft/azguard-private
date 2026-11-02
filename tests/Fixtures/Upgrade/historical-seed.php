<?php

declare(strict_types=1);
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageRegistry;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;

// Run only in a clean subprocess against the pinned historical checkout.
[$script, $checkout, $output] = $argv;
require dirname(__DIR__, 3).'/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($checkout): void {
    if (str_starts_with($class, 'AzGuard\\')) {
        $file = $checkout.'/packages/core/src/'.str_replace('\\', '/', substr($class, 8)).'.php';

        if (is_file($file)) {
            require $file;
        }
    }
}, prepend: true);

$container = new Container;
Container::setInstance($container);
$pdo = new PDO('sqlite::memory:');
$connection = new SQLiteConnection($pdo, ':memory:', '', ['name' => 'historical_test', 'driver' => 'sqlite']);
$directory = realpath(dirname($output));

if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:' || file_exists($output)
    || $directory === false || dirname($directory) !== realpath(sys_get_temp_dir())
    || ! preg_match('/\Aazguard-historical-[0-9a-f]{32}\z/', basename($directory))
    || basename($output) !== 'historical_test.sqlite') {
    throw new RuntimeException('Fixture requires a fresh in-memory SQLite database and fresh output.');
}
$registry = (new ReflectionClass(StorageRegistry::class))->newInstanceWithoutConstructor();
$registry->register(new Storage('default', $connection));
$container->instance(StorageSchema::class, new StorageSchema($registry));
$migration = require $checkout.'/packages/core/database/migrations/2026_10_01_000000_create_azguard_storage.php';
$migration->up();
$connection->table('azg_role_grants')->insert(['panel' => 'fixture', 'tenant_key' => 'global', 'role' => 'reader',
    'subject_type' => 'fixture.user', 'subject_id' => '1', 'context_key' => 'global', 'origin' => 'manual']);
$connection->table('azg_permission_grants')->insert(['panel' => 'admin', 'tenant_key' => 'global', 'permission' => 'documents.view',
    'subject_type' => 'user', 'subject_id' => '1', 'context_key' => 'global', 'origin' => 'manual']);
$connection->table('azg_audit_log')->insert(['event_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'fixture.seed',
    'panel' => 'admin', 'tenant_key' => 'global', 'subject_type' => 'user', 'subject_id' => '1',
    'correlation_id' => '01BBBBBBBBBBBBBBBBBBBBBBBB', 'payload' => '{}', 'occurred_at' => '2026-01-01 00:00:00']);
$connection->table('azg_panel_state')->insert(['panel' => 'fixture', 'version' => 1,
    'incarnation' => '01AAAAAAAAAAAAAAAAAAAAAAAA', 'updated_at' => '2026-01-01 00:00:00']);
$connection->table('azg_panel_state')->insert(['panel' => 'admin', 'version' => 1,
    'incarnation' => '01BBBBBBBBBBBBBBBBBBBBBBBB', 'updated_at' => '2026-01-01 00:00:00']);
$connection->getSchemaBuilder()->create('migrations', static function (Blueprint $table): void {
    $table->increments('id');
    $table->string('migration');
    $table->integer('batch');
});
$connection->table('migrations')->insert(['migration' => '2026_10_01_000000_create_azguard_storage', 'batch' => 1]);
$connection->statement('VACUUM INTO ?', [$output]);
