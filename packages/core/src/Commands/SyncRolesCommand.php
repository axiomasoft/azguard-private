<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Support\Config;
use Illuminate\Console\Command;

/**
 * Command guard:sync-roles
 *
 * Syncs PHP role classes with the roles table in the database.
 * Uses class_name registered via PanelProviders.
 *
 * Options:
 *   --panel=   Sync only the panel with the given ID
 *   --dry-run  Preview changes without writing to the database
 */
final class SyncRolesCommand extends Command
{
    protected $signature = 'guard:sync-roles
                            {--panel= : Sync only the given panel ID (optional)}
                            {--dry-run : Preview changes without writing to the database}';

    protected $description = 'Sync PHP role classes with the roles table in the database';

    public function handle(AzGuardManagerInterface $manager): int
    {
        $panels = $manager->getPanels();
        $panelFilter = $this->option('panel');
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('[dry-run] No changes will be written to the database.');
        }

        if ($panels === []) {
            $this->warn('No AzGuard panels registered. Check az-guard.panels in the config.');

            return self::SUCCESS;
        }

        $roleModel = Config::roleModel();

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $rows = [];

        foreach ($panels as $panelId => $panel) {
            if (is_string($panelFilter) && $panelFilter !== '' && $panelFilter !== $panelId) {
                continue;
            }

            $explicitClasses = $panel->getRoleClasses();

            if ($explicitClasses !== []) {
                $classNames = $explicitClasses;
            } else {
                $basePath = $panel->getBasePath();
                $namespace = $panel->getNamespace();

                if ($basePath === '' || $namespace === '') {
                    $this->warn("Panel [{$panelId}]: basePath or namespace not set — skipping.");

                    continue;
                }

                $rolesPath = rtrim($basePath, '/').'/Roles';

                if (! is_dir($rolesPath)) {
                    continue;
                }

                $classNames = array_map(
                    fn (string $file): string => $namespace.'\\Roles\\'.basename($file, '.php'),
                    glob($rolesPath.'/*Role.php') ?: [],
                );
            }

            foreach ($classNames as $className) {
                if (! class_exists($className)) {
                    continue;
                }

                $roleLogic = new $className;

                if (! method_exists($roleLogic, 'getName')) {
                    continue;
                }

                if (! method_exists($roleLogic, 'getLevel')) {
                    continue;
                }

                $name = $roleLogic->getName();
                $level = $roleLogic->getLevel();

                $existing = $roleModel::query()->where('class_name', $className)->first();

                if ($existing !== null) {
                    if ($existing->name !== $name || $existing->level !== $level) {
                        $rows[] = [$panelId, $name, $level, $className, '<fg=yellow>updated</fg=yellow>'];
                        $updated++;

                        if (! $isDryRun) {
                            $existing->update(['name' => $name, 'level' => $level]);
                        }
                    } else {
                        $rows[] = [$panelId, $name, $level, $className, '<fg=gray>unchanged</fg=gray>'];
                        $unchanged++;
                    }
                } else {
                    $rows[] = [$panelId, $name, $level, $className, '<fg=green>created</fg=green>'];
                    $created++;

                    if (! $isDryRun) {
                        $roleModel::query()->create([
                            'name' => $name,
                            'level' => $level,
                            'class_name' => $className,
                        ]);
                    }
                }
            }
        }

        if ($rows !== []) {
            $this->table(
                headers: ['Panel', 'Name', 'Level', 'Class', 'Status'],
                rows: $rows,
            );
        }

        $suffix = $isDryRun ? ' (dry-run)' : '';
        $this->info("Sync complete{$suffix}: created={$created}, updated={$updated}, unchanged={$unchanged}");

        return self::SUCCESS;
    }
}
