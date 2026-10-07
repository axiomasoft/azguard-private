<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\GrantDetails;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Changes\HostFencePipe;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/*
 * One process of a change race on a real engine. Barriers are files: `ready` is touched inside the mutation (the
 * panel lock is held) and the worker then waits for `release`; `start` gates the operation; `prepared`/`go` frame a
 * preparation read outside the mutation. A refusal is reported, not raised, so the coordinator can assert it.
 */
$options = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$test = new class('worker') extends TestCase {};
$app = $test->createApplication();
Relation::morphMap(['crm.user' => User::class, 'crm.organization' => Organization::class], false);
Carbon::setTestNow('2026-10-06T12:00:00Z');
$connection = CrmWorld::storage()->connection();
$wait = static function (string $file): void {
    $deadline = microtime(true) + 20;
    while (! file_exists($file)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timeout: '.$file);
        }
        usleep(1000);
    }
};
$id = $connection->selectOne($connection->getDriverName() === 'pgsql' ? 'select pg_backend_pid() as id' : 'select connection_id() as id')->id;
$report = ['connection_id' => (int) $id, 'pipes' => 0, 'status' => null, 'effects' => null, 'version' => null, 'error' => null, 'message' => null, 'fingerprint' => null];

if (isset($options['id_file'])) {
    file_put_contents($options['id_file'], (string) $id);
}

try {
    if (($options['mode'] ?? 'change') === 'deploy') {
        // Coordinated deployment or owner transfer: the panel state lock first, then the host rows.
        if (isset($options['start'])) {
            $wait($options['start']);
        }
        $connection->transaction(static function () use ($connection, $options): void {
            $connection->table('azg_panel_state')->where('panel', 'crm')->lockForUpdate()->first();

            if (isset($options['marker'])) {
                $connection->table('crm_active_build')->where('id', 1)->lockForUpdate()->first();
                $connection->table('crm_active_build')->where('id', 1)->update(['fingerprint' => $options['marker']]);
            }

            if (isset($options['revision'])) {
                $connection->table('crm_project_revisions')->where('project_id', '5')->lockForUpdate()->first();
                $connection->table('crm_project_revisions')->where('project_id', '5')->update(['revision' => $options['revision']]);
            }
        });
        $report['status'] = 'deployed';
        echo json_encode($report, JSON_THROW_ON_ERROR);
        exit(0);
    }

    if (($options['deadlock'] ?? false) && $connection->getDriverName() === 'pgsql') {
        $connection->statement("SET deadlock_timeout = '100ms'");
    }
    $hold = static function (Change $change, Closure $next) use ($options, $wait, &$report, $connection): ChangeResult {
        $report['pipes']++;

        if (isset($options['ready']) && $report['pipes'] === 1) {
            touch($options['ready']);
        }

        if (isset($options['release']) && $report['pipes'] === 1) {
            $wait($options['release']);
        }

        if ($options['deadlock'] ?? false) {
            // The second lock of a lock-order cycle with the raw host transaction of the coordinator.
            $connection->table('crm_active_build')->where('id', 1)->lockForUpdate()->first();
        }

        return $next($change);
    };
    $panel = ChangeWorld::panel(($options['fence'] ?? false) ? [$hold, HostFencePipe::class] : [$hold]);
    $report['fingerprint'] = app(PanelRegistry::class)->fingerprint('crm');

    if ($options['fence'] ?? false) {
        HostFencePipe::$preparedBuild = (string) $connection->table('crm_active_build')->where('id', 1)->value('fingerprint');
        HostFencePipe::$preparedRevisions = ['5' => (int) $connection->table('crm_project_revisions')->where('project_id', '5')->value('revision')];

        if (isset($options['prepared'])) {
            touch($options['prepared']);
        }
    }

    if (isset($options['start'])) {
        $wait($options['start']);
    }
    $tenant = ChangeWorld::tenant($options['tenant'] ?? 1);
    $result = match ($options['op']) {
        'grant' => ChangeWorld::grant($panel, $options['role'], $options['user'], $options['project'] ?? null, $options['tenant'] ?? 1,
            fields: $options['fields'] ?? []),
        'update' => ChangeWorld::pipeline()->update($panel, $tenant, 'manual', $options['id'], new GrantDetails(null, $options['fields'] ?? []),
            $options['fingerprint'] ?? null),
        'revoke-ids' => ChangeWorld::pipeline()->revokeIds($panel, $tenant, 'manual', $options['ids']),
        'sync' => ChangeWorld::pipeline()->sync($panel, $tenant, ChangeWorld::user($options['user']), 'role',
            array_map(ChangeWorld::role(...), $options['roles']), ChangeWorld::project($options['project'] ?? null)),
        default => throw new InvalidArgumentException('Unknown operation '.$options['op']),
    };
    $report['status'] = $result->status->value;
    $report['effects'] = count($result->effects);
    $report['version'] = $result->state->version;
} catch (Throwable $error) {
    $report['error'] = $error::class;
    $report['message'] = $error->getMessage();
}

echo json_encode($report, JSON_THROW_ON_ERROR);
