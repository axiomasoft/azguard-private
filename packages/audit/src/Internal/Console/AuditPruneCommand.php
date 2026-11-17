<?php

declare(strict_types=1);

namespace AzGuard\Audit\Internal\Console;

use AzGuard\Audit\AuditOptions;
use AzGuard\Audit\Internal\JournalPruner;
use AzGuard\AzGuard;
use AzGuard\Contracts\Panels\PanelRegistry;
use AzGuard\Laravel\Console\CommandSupport;
use AzGuard\Panels\Panel;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * @internal Deletes the rows of the change journal older than the retention of the audit plugin of each panel
 * (`AuditPlugin::make(retentionDays:)`). `--before` moves the cut-off back in time, never past the retention. Panels
 * without `AuditOptions` keep no journal and are skipped. Irreversible: production needs `--force`. Pruning runs in
 * short transactions of its own and never takes the authority lock of a panel (`JournalPruner`).
 */
final class AuditPruneCommand extends Command
{
    use CommandSupport;

    /** @var string */
    protected $signature = 'azguard:audit:prune
        {--panel= : Only this panel}
        {--before= : ISO-8601; delete only rows older than this as well}
        {--force : Delete in production}';

    /** @var string */
    protected $description = 'Delete AzGuard audit journal rows older than the retention';

    public function handle(PanelRegistry $registry, AzGuard $azguard): int
    {
        return $this->attempt(function () use ($registry, $azguard): int {
            $panels = $this->stringOption('panel') === null ? $registry->all() : [$this->selectPanel()];
            $before = $this->dateOption('before');
            /** @var list<array{Panel, int, string}> $retained */
            $retained = [];
            foreach ($panels as $panel) {
                $days = $panel->options(AuditOptions::class)?->retentionDays;
                $storage = $panel->writer()?->storage;

                if ($days !== null && $storage !== null) {
                    $retained[] = [$panel, $days, $storage];
                }
            }

            if ($retained === []) {
                $this->components->info('No selected panel has the audit plugin: there is no journal to prune.');

                return self::SUCCESS;
            }
            $this->confirmIrreversible('Deleting audit journal rows', $this->option('force') === true);
            $now = CarbonImmutable::now('UTC')->startOfSecond()->toDateTimeImmutable();
            $total = 0;
            foreach ($retained as [$panel, $days, $storage]) {
                $cutoff = $now->modify('-'.$days.' days');
                $cutoff = $before !== null && $before < $cutoff ? $before : $cutoff;
                $count = JournalPruner::prune($panel->id(), $azguard->storage($storage), $cutoff);
                $total += $count;
                $this->line('Panel '.$panel->id().': '.$count.' journal row(s) before '.$cutoff->format(DATE_ATOM).' deleted.');
            }
            $this->components->info($total.' journal row(s) deleted.');

            return self::SUCCESS;
        });
    }
}
