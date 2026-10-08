<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Changes\ChangePipeline;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use AzGuard\Laravel\Console\Concerns\InvalidCommandInput;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Database\DatabaseSource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Removes expired grants through the change pipeline, each removal publishing `GrantExpired`, as the system actor
 * `azguard:grants:prune`. Without `--panel` it runs over every panel with a database writer, as the scheduler does;
 * each tenant is its own mutation. `--before` moves the cut-off back in time, never forward: a grant that has not
 * expired is never removed. `--dry-run` only counts.
 */
final class GrantsPruneCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:grants:prune
        {--panel= : Only this panel}
        {--tenant= : type:id, only this tenant of the panel}
        {--before= : ISO-8601; remove grants that expired by then (default: now)}
        {--dry-run : Count the expired grants without removing them}';

    /** @var string */
    protected $description = 'Remove expired AzGuard grants';

    public function handle(ChangePipeline $pipeline, PanelRegistry $registry): int
    {
        return $this->attempt(function () use ($pipeline, $registry): int {
            $now = CarbonImmutable::now('UTC')->startOfSecond()->toDateTimeImmutable();
            $before = $this->dateOption('before') ?? $now;

            if ($before > $now) {
                throw new InvalidCommandInput('--before lies in the future: only grants that already expired are removed.');
            }
            [$panels, $tenant] = $this->targets($registry);
            $total = 0;
            foreach ($panels as $panel) {
                /** @var DatabaseSource $writer */
                $writer = $panel->writer();
                $count = $this->option('dry-run') === true
                    ? $writer->countExpired($panel, $tenant, $before)
                    : $pipeline->pruneExpired($panel, $tenant, $before, actor: ActorRef::system((string) $this->getName()));
                $total += $count;
                $this->line('Panel '.$panel->id().': '.$count.' expired grant(s)'.($this->option('dry-run') === true ? ' would be removed.' : ' removed.'));
            }
            $this->components->info(($this->option('dry-run') === true ? 'Dry run: ' : '').$total.' expired grant(s) in '.count($panels).' panel(s).');

            return self::SUCCESS;
        });
    }

    /** @return array{list<Panel>, ?TenantRef} */
    private function targets(PanelRegistry $registry): array
    {
        if ($this->stringOption('panel') === null) {
            if ($this->stringOption('tenant') !== null) {
                throw new InvalidCommandInput('--tenant narrows one panel: pass --panel as well.');
            }

            return [array_values(array_filter($registry->all(), static fn (Panel $panel): bool => $panel->writer() instanceof DatabaseSource)), null];
        }
        $panel = $this->selectPanel();

        if (! $panel->writer() instanceof DatabaseSource) {
            throw new InvalidCommandInput('Panel '.$panel->id().' has no database writer: it stores no grants to prune.');
        }

        return [[$panel], $this->stringOption('tenant') === null ? null : $this->tenantOf($panel)];
    }
}
