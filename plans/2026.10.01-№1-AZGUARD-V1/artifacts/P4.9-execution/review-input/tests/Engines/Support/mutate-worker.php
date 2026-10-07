<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use AzGuard\Storage\StorageMutation;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;

$options = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$test = new class('worker') extends TestCase {};
$app = $test->createApplication();
$db = $app->make('db');

if (isset($options['sqlite'])) {
    $app['config']->set('database.connections.testbench', ['driver' => 'sqlite', 'database' => $options['sqlite'], 'prefix' => '', 'busy_timeout' => 5000]);
    $db->purge('testbench');
}
$registry = $app->make(StorageRegistry::class);
$storage = $registry->get('default');
$connection = $storage->connection();
$wait = static function (string $file): void {
    $deadline = microtime(true) + 20;
    while (! file_exists($file)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timeout: '.$file);
        }
        usleep(1000);
    }
};

try {
    if (isset($options['barrier'])) {
        $wait($options['barrier']);
    }

    if (($options['mode'] ?? '') === 'raw-deadlock') {
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement("SET deadlock_timeout = '5s'");
        }
        $connection->transaction(function () use ($storage, $options, $wait): void {
            $storage->table('panel_state')->where('panel', 'b')->lockForUpdate()->first();
            $storage->table('probe_rows')->insert(array_fill(0, 100, ['value' => 'weight']));
            touch($options['ready']);
            $wait($options['blocked']);
            $storage->table('panel_state')->where('panel', 'a')->lockForUpdate()->first();
        });
        echo json_encode(['attempts' => 1]);
        exit(0);
    }

    if (($options['mode'] ?? '') === 'deadlock') {
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement("SET deadlock_timeout = '100ms'");
        }
        $connection->beforeExecuting(static function (string $query, array $bindings) use ($options): void {
            if (str_starts_with(strtolower($query), 'select') && $bindings === ['b']) {
                touch($options['blocked']);
            }
        });
    }

    if (($options['mode'] ?? '') === 'hold-commit') {
        $connection->getEventDispatcher()->listen(TransactionCommitting::class,
            static function () use ($options): void {
                touch($options['ready']);
                usleep($options['hold_ms'] * 1000);
            });
    }
    $attempts = 0;
    $connection->getEventDispatcher()->listen(TransactionBeginning::class,
        static function () use (&$attempts): void {
            $attempts++;
        });
    $storage->mutate($options['panels'] ?? 'admin', function (StorageMutation $mutation) use (&$attempts, $options): void {
        foreach ((array) ($options['panels'] ?? 'admin') as $panel) {
            $mutation->touch($panel);
        }

        if (isset($options['ready']) && ($options['mode'] ?? '') !== 'hold-commit') {
            touch($options['ready']);
        }

        if (isset($options['hold_ms']) && ($options['mode'] ?? '') !== 'hold-commit') {
            usleep($options['hold_ms'] * 1000);
        }
    });

    if (isset($options['done'])) {
        touch($options['done']);
    }
    echo json_encode(['attempts' => $attempts], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage());
    exit(1);
}
