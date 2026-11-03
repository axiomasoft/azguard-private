<?php

declare(strict_types=1);

use AzGuard\Audit\AuditPlugin;
use AzGuard\Tests\Engines\Support\CacheEngineWorld;
use AzGuard\Tests\Engines\Support\Processes;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Changes\HostFencePipe;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use AzGuard\Tests\Support\AuditTable;
use Illuminate\Database\Schema\Blueprint;

it('keeps a rolled back journal and notification invisible while a competing writer waits', function (): void {
    CrmWorld::seed();
    AuditTable::create();
    EventWorld::listen();
    $panel = W::panel(configure: static fn ($builder) => $builder->plugins([AuditPlugin::make()]));
    $connection = CrmWorld::storage()->connection();
    $observer = app('db')->connection('secondary');
    $version = W::version();
    $id = sys_get_temp_dir().'/azguard-audit-race-'.bin2hex(random_bytes(8));
    $processes = new Processes;

    try {
        $connection->beginTransaction();
        $result = W::grant($panel, 'analyst', 2, 1);
        expect($result->committed)->toBeFalse()
            ->and(EventWorld::auditRows())->toHaveCount(1)
            ->and($observer->table('azg_audit_log')->count())->toBe(0)
            ->and(EventWorld::events())->toBeEmpty();
        $worker = $processes->start(['op' => 'grant', 'role' => 'auditor', 'user' => 2, 'id_file' => $id], 'change-worker.php');
        Processes::wait($id);
        CacheEngineWorld::waitForLock((int) file_get_contents($id));
        $connection->rollBack();
        $report = $processes->finish($worker);

        expect($report['error'])->toBeNull()->and($report['status'])->toBe('applied')
            ->and(W::version())->toBe($version + 1)
            ->and(W::keys())->not->toContain('crm.organization:1|analyst|2|crm.project:1|manual')
            ->and(W::keys())->toContain('crm.organization:1|auditor|2|global|manual')
            // The worker uses a panel without AuditPlugin; the rolled-back audited grant leaves no row.
            ->and(EventWorld::auditRows())->toBeEmpty()->and(EventWorld::events())->toBeEmpty();
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }
        @unlink($id);
        EventWorld::reset();
        CrmWorld::resetRuntime();
    }
})->group('engines');

it('bounds a waiting writer on lock timeout without committing authority or audit', function (): void {
    CrmWorld::seed();
    AuditTable::create();
    $connection = CrmWorld::storage()->connection();
    $version = W::version();
    $before = W::keys();
    $id = sys_get_temp_dir().'/azguard-audit-timeout-'.bin2hex(random_bytes(8));
    $processes = new Processes;

    try {
        $connection->beginTransaction();
        $connection->table('azg_panel_state')->where('panel', 'crm')->lockForUpdate()->first();
        $worker = $processes->start(['op' => 'grant', 'role' => 'analyst', 'user' => 2, 'project' => 1,
            'audit' => true, 'lock_timeout' => true, 'id_file' => $id], 'change-worker.php');
        Processes::wait($id);
        CacheEngineWorld::waitForLock((int) file_get_contents($id));
        $report = $processes->finish($worker);
        $connection->rollBack();

        expect($report['error'])->not->toBeNull()->and($report['status'])->toBeNull()
            ->and($report['attempts'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3)
            ->and($report['pipes'])->toBe(0)->and($report['event_ids'])->toBeEmpty()->and(W::version())->toBe($version)
            ->and(W::keys())->toBe($before)->and(EventWorld::auditRows())->toBeEmpty();
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }
        @unlink($id);
        CrmWorld::resetRuntime();
    }
})->group('engines');

it('retries a deadlock after a grant write without publishing the aborted effect', function (): void {
    CrmWorld::seed();
    AuditTable::create();
    HostFencePipe::install('unused');
    $connection = CrmWorld::storage()->connection();
    $connection->getSchemaBuilder()->create('crm_weight', fn (Blueprint $table) => $table->string('value'));
    $version = W::version();
    $ready = sys_get_temp_dir().'/azguard-audit-deadlock-'.bin2hex(random_bytes(8));
    $processes = new Processes;

    try {
        $connection->beginTransaction();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement("SET LOCAL deadlock_timeout = '5s'");
        }
        $connection->table('crm_active_build')->where('id', 1)->lockForUpdate()->first();
        $connection->table('crm_weight')->insert(array_fill(0, 100, ['value' => 'weight']));
        $worker = $processes->start(['op' => 'grant', 'role' => 'analyst', 'user' => 2, 'project' => 1,
            'audit' => true, 'deadlock_after' => true, 'ready' => $ready], 'change-worker.php');
        Processes::wait($ready);
        $connection->table('azg_panel_state')->where('panel', 'crm')->lockForUpdate()->first();
        $connection->commit();
        $report = $processes->finish($worker);
        $rows = EventWorld::auditRows();

        expect($report['error'])->toBeNull()->and($report['pipes'])->toBe(2)
            ->and($report['effects'])->toBe(1)->and(W::version())->toBe($version + 1)
            ->and(array_count_values(W::keys())['crm.organization:1|analyst|2|crm.project:1|manual'])->toBe(1)
            ->and($rows)->toHaveCount(1)->and($report['event_ids'])->toHaveCount(1)
            ->and($rows[0]['event_id'])->toBe($report['event_ids'][0])
            ->and($rows[0]['event_id'])->not->toBe($report['aborted_event_id']);
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }
        @unlink($ready);
        CrmWorld::resetRuntime();
    }
})->group('engines');
