<?php

declare(strict_types=1);

use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(fn () => CrmWorld::seed());
afterEach(function (): void {
    app()->instance('env', 'testing');
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

/** Journal rows of the crm panel written on 2026-10-01, 2026-10-04 and 2026-10-06 (retention 3 days: one is old). */
function auditPruneJournal(): void
{
    $panel = W::panel(configure: static fn (PanelBuilder $panel) => $panel->plugins([AuditPlugin::make(3)]));
    foreach (['2026-10-01T09:00:00Z', '2026-10-04T09:00:00Z', '2026-10-06T09:00:00Z'] as $i => $moment) {
        Carbon::setTestNow($moment);
        W::grant($panel, 'auditor', $i + 1, null);
    }
    Carbon::setTestNow('2026-10-06T12:00:00Z');
}

/** @return list<string> */
function auditPruneMoments(): array
{
    return CrmWorld::storage()->table('audit_log')->orderBy('id')->pluck('occurred_at')->map(static fn (string $at): string => substr($at, 0, 10))->all();
}

it('deletes journal rows older than the retention of the audit plugin', function (): void {
    auditPruneJournal();

    expect(auditPruneMoments())->toBe(['2026-10-01', '2026-10-04', '2026-10-06'])
        ->and(Artisan::call('azguard:audit:prune'))->toBe(0)
        ->and(Artisan::output())->toContain('Panel crm: 1 journal row(s)')
        ->and(auditPruneMoments())->toBe(['2026-10-04', '2026-10-06'])
        ->and(W::keys())->toContain('crm.organization:1|auditor|1|global|manual');
});

it('moves the cut-off back with --before but never past the retention', function (): void {
    auditPruneJournal();

    expect(Artisan::call('azguard:audit:prune', ['--panel' => 'crm', '--before' => '2026-10-06T10:00:00Z']))->toBe(0)
        ->and(auditPruneMoments())->toBe(['2026-10-04', '2026-10-06'])
        ->and(Artisan::call('azguard:audit:prune', ['--panel' => 'crm', '--before' => '2026-09-30T00:00:00Z']))->toBe(0)
        ->and(auditPruneMoments())->toBe(['2026-10-04', '2026-10-06']);
});

it('has nothing to prune on a panel without the audit plugin', function (): void {
    W::panel();

    expect(Artisan::call('azguard:audit:prune', ['--panel' => 'crm']))->toBe(0)
        ->and(Artisan::output())->toContain('no journal to prune');
});

it('needs --force in production', function (): void {
    auditPruneJournal();
    app()->instance('env', 'production');

    expect(Artisan::call('azguard:audit:prune'))->toBe(2)
        ->and(auditPruneMoments())->toHaveCount(3)
        ->and(Artisan::call('azguard:audit:prune', ['--force' => true]))->toBe(0)
        ->and(auditPruneMoments())->toHaveCount(2);
});
