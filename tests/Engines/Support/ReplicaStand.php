<?php

declare(strict_types=1);

namespace AzGuard\Tests\Engines\Support;

use PDO;
use RuntimeException;

final class ReplicaStand
{
    public static function connect(bool $replica): PDO
    {
        $port = getenv($replica ? 'AUTHORITY_REPLICA_PORT' : 'AUTHORITY_PRIMARY_PORT') ?: ($replica ? 25434 : 25433);
        $pdo = new PDO('pgsql:host=127.0.0.1;port='.$port.';dbname=azguard_authority_test', 'authority_test', 'authority_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        if ($pdo->query('select current_database()')->fetchColumn() !== 'azguard_authority_test') {
            throw new RuntimeException('Replica qualification requires isolated azguard_authority_test.');
        }
        $recovery = $pdo->query('select pg_is_in_recovery()')->fetchColumn();

        if ((bool) $recovery !== $replica) {
            throw new RuntimeException('Unexpected authority primary/standby topology.');
        }

        return $pdo;
    }

    public static function until(PDO $pdo, string $sql, array $bindings = []): void
    {
        $query = $pdo->prepare($sql);
        $deadline = microtime(true) + 20;
        do {
            $query->execute($bindings);

            if ($query->fetchColumn()) {
                return;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Replica condition timeout: '.$sql);
    }

    public static function catchUp(PDO $replica, string $lsn): void
    {
        self::until($replica, 'select pg_last_wal_replay_lsn() >= cast(? as pg_lsn)', [$lsn]);
    }
}
