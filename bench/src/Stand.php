<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

use AzGuard\Kernel\Support\Narrow;
use AzGuard\Tests\TestCase;
use AzGuardBench\Load\Profiles\DecisionSetSize;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PDO;

/**
 * The bench application: a Testbench Laravel app (the one of the test suite) with the generated catalog, on one
 * database driver and one cache store. A file database replaces SQLite in memory, because forked workers must see
 * each other's writes.
 */
final class Stand
{
    public const array DRIVERS = ['sqlite', 'pgsql', 'mysql', 'mariadb'];

    public const array CACHES = ['none', 'array', 'redis'];

    private function __construct(public readonly Application $app, public readonly string $driver, public readonly string $cache) {}

    public static function boot(string $driver, string $cache, string $workdir): self
    {
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException("Unknown driver [{$driver}]: ".implode(', ', self::DRIVERS).'.');
        }

        if (! in_array($cache, self::CACHES, true)) {
            throw new InvalidArgumentException("Unknown cache [{$cache}]: ".implode(', ', self::CACHES).'.');
        }
        putenv('APP_ENV=testing');
        putenv('DB_CONNECTION='.$driver);
        $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = $driver;
        Catalog::load();
        $sqlite = $workdir.'/azguard_bench.sqlite';

        $case = new class('bench') extends TestCase
        {
            public static string $sqlite = '';

            public static ?string $store = null;

            protected function getEnvironmentSetUp($app): void
            {
                parent::getEnvironmentSetUp($app);
                $app['config']->set('azguard.panels.providers', [Catalog::class('BenchPanelProvider'), Catalog::class('WorkspacePanelProvider')]);
                $app['config']->set('azguard.schedule.enabled', false);
                // Sets above the default limit are measured on purpose (profile decision-set).
                $app['config']->set('azguard.decision_sets.max_subjects', DecisionSetSize::STAND_LIMIT);
                $app['config']->set('bench.cache_store', self::$store);
                $app['config']->set('app.debug', false);
                $app['config']->set('database.redis.client', 'phpredis');
                $app['config']->set('database.redis.default', ['host' => getenv('REDIS_HOST') ?: '127.0.0.1', 'port' => getenv('REDIS_PORT') ?: '6379', 'database' => 15]);
                $app['config']->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'default']);
            }

            protected function databaseConnectionConfig(): array
            {
                $config = parent::databaseConnectionConfig();

                return $config['driver'] === 'sqlite'
                    ? ['driver' => 'sqlite', 'database' => self::$sqlite, 'prefix' => '', 'journal_mode' => 'wal', 'busy_timeout' => 10_000, 'synchronous' => 'normal', 'foreign_key_constraints' => false]
                    : $config;
            }
        };
        $case::$sqlite = $sqlite;
        $case::$store = $cache === 'none' ? null : $cache;

        if ($driver === 'sqlite') {
            if (! is_dir($workdir)) {
                mkdir($workdir, 0777, true);
            }
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($sqlite.$suffix);
            }
            touch($sqlite);
        }
        // Panels compile while the application boots: the morph aliases of the subject and the tenant come first.
        Relation::enforceMorphMap(['user' => Catalog::model('User'), 'post' => Catalog::model('Post'), 'team' => Catalog::model('Team')]);
        $app = $case->createApplication();
        $connection = config('database.connections.testbench');
        DisposableDatabase::assertSafe(is_array($connection) ? Narrow::map($connection, 'testbench connection') : []);

        return new self($app, $driver, $cache);
    }

    /** Drops every table, migrates the package and creates the application tables. */
    public function migrate(): void
    {
        $this->app->make(Kernel::class)->call('migrate:fresh', ['--force' => true]);
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('posts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->index();
        });
        Schema::create('teams', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('team_user', static function (Blueprint $table): void {
            $table->foreignId('team_id');
            $table->foreignId('user_id');
            $table->primary(['user_id', 'team_id']);
        });
    }

    /** A new request: scoped instances (the request memo of the engine) are forgotten, as Octane and queues do. */
    public function newRequest(): void
    {
        $this->app->forgetScopedInstances();
    }

    /**
     * Closes every connection before a fork; each worker reopens its own on first use. The connection objects stay:
     * the storage of the package holds them, and a purged connection would no longer be the one of the queries.
     */
    public function disconnect(): void
    {
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::connection($name)->disconnect();
        }

        if ($this->cache === 'redis' && $this->app->bound('redis')) {
            $this->app->make('redis')->purge();
        }
    }

    /** @return array{driver: string, server: string} */
    public function database(): array
    {
        $connection = DB::connection();

        if ($connection->getRawPdo() === null) {
            $connection->reconnect();
        }
        $pdo = $connection->getPdo();
        $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

        return ['driver' => $connection->getDriverName(), 'server' => is_string($version) ? $version : ''];
    }
}
