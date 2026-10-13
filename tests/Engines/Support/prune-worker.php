<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use AzGuard\Audit\AuditPlugin;
use AzGuard\Audit\Internal\JournalPruner;
use AzGuard\Facades\AzGuard;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/*
 * One process of a journal retention race on a real engine: `prune` deletes the journal rows of panel crm before the
 * cut-off through JournalPruner, `write` journals `rounds` grant/revoke pairs of panel crm with the Audit plugin. Both
 * wait for the `start` file; an error is reported, not raised, so the coordinator can assert it.
 */
$options = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$test = new class('worker') extends TestCase {};
$app = $test->createApplication();
Relation::morphMap(['crm.user' => User::class, 'crm.organization' => Organization::class], false);
Carbon::setTestNow('2026-10-06T12:00:00Z');
$report = ['mode' => $options['mode'], 'deleted' => null, 'effects' => 0, 'seconds' => null, 'error' => null, 'message' => null];

try {
    $panel = $options['mode'] === 'write' ? ChangeWorld::panel(configure: static fn (PanelBuilder $builder) => $builder->plugins([AuditPlugin::make()])) : null;
    $deadline = microtime(true) + 20;

    while (isset($options['start']) && ! file_exists($options['start'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timeout: '.$options['start']);
        }
        usleep(1000);
    }
    $started = microtime(true);

    if ($panel === null) {
        $report['deleted'] = JournalPruner::prune('crm', AzGuard::storage(), new DateTimeImmutable($options['cutoff']));
    } else {
        for ($round = 0; $round < $options['rounds']; $round++) {
            $report['effects'] += count(ChangeWorld::grant($panel, 'auditor', 2, null)->effects);
            $report['effects'] += count(ChangeWorld::revoke($panel, 'auditor', 2, null)->effects);
        }
    }
    $report['seconds'] = microtime(true) - $started;
} catch (Throwable $error) {
    $report['error'] = $error::class;
    $report['message'] = $error->getMessage();
}

echo json_encode($report, JSON_THROW_ON_ERROR);
exit(0);
