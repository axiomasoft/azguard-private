<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use PDO;

/**
 * A second, independent connection to the storage database that commits while the engine reads: the write of another
 * process, not a write inside the reader's own transaction. On SQLite the storage connection is moved to a WAL file
 * for the test (an in-memory database has a single connection); server drivers use the `secondary` connection.
 */
final class ConcurrentWriter
{
    private static ?PDO $memory = null;

    private static ?string $file = null;

    private static ?Connection $writer = null;

    public static function open(bool $wal = true): void
    {
        $connection = DatabaseWorld::storage()->connection();

        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }
        self::$file = sys_get_temp_dir().'/azg-concurrent-'.bin2hex(random_bytes(6)).'.sqlite';
        // The rows the test has written so far move with it.
        $connection->getPdo()->exec("VACUUM INTO '".self::$file."'");
        $pdo = new PDO('sqlite:'.self::$file, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode='.($wal ? 'WAL' : 'DELETE'));
        self::$memory = $connection->getPdo();
        $connection->setPdo($pdo)->setReadPdo($pdo);
    }

    public static function connection(): Connection
    {
        if (self::$file === null) {
            return self::$writer ??= app('db')->connection('secondary');
        }

        return self::$writer ??= new SQLiteConnection(new PDO('sqlite:'.self::$file, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), self::$file);
    }

    /** @param Closure(Connection): void $work */
    public static function commit(Closure $work): void
    {
        $connection = self::connection();
        $connection->transaction(static fn () => $work($connection));
    }

    /** Raises the panel version as the write protocol does at the root commit. */
    /** Raises the panel version as a grant write does; with `$epoch` also the epoch, as a definition write does. */
    public static function touch(Connection $connection, string $panel = 'admin', bool $epoch = false): void
    {
        $connection->table('azg_panel_state')->where('panel', $panel)->incrementEach($epoch ? ['version' => 1, 'epoch' => 1] : ['version' => 1]);
    }

    public static function close(): void
    {
        self::$writer?->disconnect();
        self::$writer = null;

        if (self::$file === null) {
            return;
        }

        if (self::$memory !== null) {
            DatabaseWorld::storage()->connection()->setPdo(self::$memory)->setReadPdo(self::$memory);
        }
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            if (is_file(self::$file.$suffix)) {
                unlink(self::$file.$suffix);
            }
        }
        self::$file = self::$memory = null;
    }
}
