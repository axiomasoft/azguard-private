<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Configuration\Config;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Registry\Resolver\PermissionStateRevision;
use AzGuard\Support\RoleSyncPlanner;
use Illuminate\Console\Command;

/**
 * Command guard:sync-roles
 *
 * Syncs PHP role classes with the roles table using panel-qualified persisted
 * names. Preflight classifies create / rename / no-op / collision before write.
 *
 * Options:
 *   --panel=   Sync only the panel with the given ID
 *   --dry-run  Preview the same decisions without writing to the database
 */
final class SyncRolesCommand extends Command
{
    protected $signature = 'guard:sync-roles
                            {--panel= : Sync only the given panel ID (optional)}
                            {--dry-run : Preview changes without writing to the database}';

    protected $description = 'Sync PHP role classes with the roles table in the database';

    public function handle(AzGuardManagerInterface $manager, RoleSyncPlanner $planner, PermissionStateRevision $revision): int
    {
        $panelFilter = $this->option('panel');
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('[dry-run] No changes will be written to the database.');
        }

        if ($manager->getPanels() === []) {
            $this->warn('No AzGuard panels registered. Check az-guard.panels in the config.');

            return self::SUCCESS;
        }

        $plan = $planner->plan(
            $manager,
            is_string($panelFilter) && $panelFilter !== '' ? $panelFilter : null,
        );

        $this->renderPlan($plan['decisions']);

        if ($plan['errors'] !== []) {
            foreach ($plan['errors'] as $error) {
                $this->error($error);
            }

            $this->info('Sync aborted: resolve the collisions above. No rows were written.');

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($plan['decisions'] as $decision) {
            match ($decision['status']) {
                'created' => $created++,
                'renamed', 'updated' => $updated++,
                default => $unchanged++,
            };
        }

        if (! $isDryRun) {
            $revision->mutate(function () use ($plan): array {
                return [null, $this->apply($plan['decisions'])];
            });
        }

        $suffix = $isDryRun ? ' (dry-run)' : '';
        $this->info("Sync complete{$suffix}: created={$created}, updated={$updated}, unchanged={$unchanged}");

        return self::SUCCESS;
    }

    /**
     * @param  list<array{panel: string, name: string, previous: ?string, level: int, class: string, status: string}>  $decisions
     */
    private function renderPlan(array $decisions): void
    {
        if ($decisions === []) {
            return;
        }

        $rows = [];

        foreach ($decisions as $decision) {
            $name = $decision['previous'] !== null && $decision['previous'] !== $decision['name']
                ? $decision['previous'].' → '.$decision['name']
                : $decision['name'];

            $color = match ($decision['status']) {
                'created' => 'green',
                'renamed', 'updated' => 'yellow',
                'collision' => 'red',
                default => 'gray',
            };

            $rows[] = [
                $decision['panel'],
                $name,
                $decision['level'],
                $decision['class'],
                "<fg={$color}>{$decision['status']}</fg={$color}>",
            ];
        }

        $this->table(
            headers: ['Panel', 'Name', 'Level', 'Class', 'Status'],
            rows: $rows,
        );
    }

    /**
     * @param  list<array{panel: string, name: string, previous: ?string, level: int, class: string, status: string}>  $decisions
     */
    private function apply(array $decisions): bool
    {
        $roleModel = Config::roleModel();
        $changed = false;

        foreach ($decisions as $decision) {
            if ($decision['status'] === 'unchanged') {
                continue;
            }

            $existing = $roleModel::query()->where('class_name', $decision['class'])->first();

            if ($decision['status'] === 'created') {
                $role = $roleModel::query()->create([
                    'name' => $decision['name'],
                    'level' => $decision['level'],
                ]);
                $role->class_name = $decision['class'];
                $role->save();
                $changed = true;

                continue;
            }

            if ($existing === null) {
                continue;
            }

            $existing->name = $decision['name'];
            $existing->level = $decision['level'];
            $existing->save();
            $changed = true;
        }

        return $changed;
    }
}
