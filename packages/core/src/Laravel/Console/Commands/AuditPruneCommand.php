<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Changes\ChangePipeline;
use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Plugins\Audit\AuditPlugin;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Deletes the rows of the change journal older than the retention of the audit plugin of each panel
 * (`AuditPlugin::make(retentionDays:)`). `--before` moves the cut-off back in time, never past the retention. Panels
 * without the audit plugin keep no journal and are skipped. Irreversible: production needs `--force`.
 */
final class AuditPruneCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:audit:prune
        {--panel= : Only this panel}
        {--before= : ISO-8601; delete only rows older than this as well}
        {--force : Delete in production}';

    /** @var string */
    protected $description = 'Delete AzGuard audit journal rows older than the retention';

    public function handle(ChangePipeline $pipeline, PanelRegistry $registry): int
    {
        return $this->attempt(function () use ($pipeline, $registry): int {
            $panels = $this->stringOption('panel') === null ? $registry->all() : [$this->selectPanel()];
            $before = $this->dateOption('before');
            /** @var list<array{Panel, int}> $retained */
            $retained = [];
            foreach ($panels as $panel) {
                $days = AuditPlugin::retentionIn($registry->recipe($panel->id()));

                if ($days !== null && in_array('azguard/audit', $panel->pluginIds(), true)) {
                    $retained[] = [$panel, $days];
                }
            }

            if ($retained === []) {
                $this->components->info('No selected panel has the audit plugin: there is no journal to prune.');

                return self::SUCCESS;
            }
            $this->confirmIrreversible('Deleting audit journal rows', $this->option('force') === true);
            $now = CarbonImmutable::now('UTC')->startOfSecond()->toDateTimeImmutable();
            $total = 0;
            foreach ($retained as [$panel, $days]) {
                $cutoff = $now->modify('-'.$days.' days');
                $cutoff = $before !== null && $before < $cutoff ? $before : $cutoff;
                $count = $pipeline->pruneJournal($panel, $cutoff);
                $total += $count;
                $this->line('Panel '.$panel->id().': '.$count.' journal row(s) before '.$cutoff->format(DATE_ATOM).' deleted.');
            }
            $this->components->info($total.' journal row(s) deleted.');

            return self::SUCCESS;
        });
    }
}
