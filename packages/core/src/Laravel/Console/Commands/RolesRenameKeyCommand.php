<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Changes\RoleKeyMigration;
use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

/**
 * Moves the stored grants of a former role key to the code role that lists it in `#[FormerKeys]`, in one tenant and
 * one mutation, through the change pipeline as the system actor `azguard:roles:rename-key`. `--dry-run` reads the
 * same plan under the panel lock and writes nothing. Production needs `--force`.
 */
final class RolesRenameKeyCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:roles:rename-key
        {role : The former key, or panel:key}
        {new : The current key, panel:key or the code role class that lists the former key}
        {--panel= : The panel; otherwise the panel resolver decides}
        {--tenant= : type:id, required for a panel with tenants}
        {--dry-run : Show what would move without writing}
        {--force : Rename in production}';

    /** @var string */
    protected $description = 'Move grants of a former AzGuard role key to its current key';

    public function handle(RoleKeyMigration $migration): int
    {
        return $this->attempt(function () use ($migration): int {
            $former = $this->stringArgument('role');
            $current = $this->stringArgument('new');
            $panel = $this->selectPanel(roles: [$former, $current]);
            $from = str_contains($former, ':') ? explode(':', $former, 2)[1] : $former;
            $tenant = $this->tenantOf($panel);

            if ($this->option('dry-run') === true) {
                $now = CarbonImmutable::now('UTC')->toDateTimeImmutable();
                $rows = [];
                foreach ($migration->plan($panel, $from, $current, $tenant)[$tenant->key()] ?? [] as ['source' => $source, 'target' => $target, 'until' => $until]) {
                    $rows[] = [...$this->grantArray($source, $now), 'merges_into' => $target?->id,
                        'until_after' => $until?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM)];
                }
                $this->components->info('Dry run: '.count($rows).' grant(s) of '.$from.' would move to '.$current.' in tenant '.$tenant->key().'; nothing was written.');

                if ($rows !== []) {
                    $this->table(['Grant', 'Context', 'Origin', 'Merges into', 'Until after'], array_map(static fn (array $row): array => [
                        $row['id'], $row['context'], $row['origin'], $row['merges_into'] ?? '', $row['until_after'] ?? 'never',
                    ], $rows));
                }

                return self::SUCCESS;
            }
            $this->confirmIrreversible('Renaming role key '.$from, $this->option('force') === true);
            $results = $this->asSystem(static fn (): array => $migration->run($panel, $from, $current, $tenant));

            return $this->reportChange('Rename of role key '.$from.' to '.$current, $results[$tenant->key()]);
        });
    }
}
