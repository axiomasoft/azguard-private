<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Contracts\PermissionDefinition;
use Illuminate\Console\Command;

/**
 * Lists all registered permissions from PermissionCatalog.
 *
 * Examples:
 *   php artisan guard:catalog
 *   php artisan guard:catalog --panel=app
 *   php artisan guard:catalog --panel=app --group=Documents
 *   php artisan guard:catalog --format=json
 *   php artisan guard:catalog --format=csv
 */
final class CatalogListCommand extends Command
{
    protected $signature = 'guard:catalog
        {--panel= : Filter by panel ID}
        {--group= : Filter by group}
        {--format=table : Output format: table, json, csv}';

    protected $description = 'List all permissions from PermissionCatalog';

    public function handle(PermissionCatalog $catalog): int
    {
        $panelFilter = $this->normalizeOption('panel');
        $groupFilter = $this->normalizeOption('group');
        $format = (string) ($this->option('format') ?? 'table');

        $panelIds = $panelFilter !== null
            ? [$panelFilter]
            : $catalog->panels();

        if ($panelIds === []) {
            $this->warn('No AzGuard panels registered.');

            return self::SUCCESS;
        }

        $rows = $this->collectRows($catalog, $panelIds, $groupFilter);

        if ($rows === []) {
            $this->warn('No permissions match the given filters.');

            return self::SUCCESS;
        }

        match ($format) {
            'json' => $this->outputJson($rows),
            'csv' => $this->outputCsv($rows),
            default => $this->outputTable($rows),
        };

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $panelIds
     * @return list<array{panel: string, group: string, key: string, label: string|null}>
     */
    private function collectRows(PermissionCatalog $catalog, array $panelIds, ?string $groupFilter): array
    {
        $rows = [];

        foreach ($panelIds as $panelId) {
            if ($groupFilter !== null) {
                $groups = $catalog->groups($panelId);
                $definitions = $groups[$groupFilter] ?? [];
            } else {
                $definitions = $catalog->all($panelId);
            }

            foreach ($definitions as $definition) {
                $rows[] = $this->definitionToRow($panelId, $definition);
            }
        }

        return $rows;
    }

    /**
     * @return array{panel: string, group: string, key: string, label: string|null}
     */
    private function definitionToRow(string $panelId, PermissionDefinition $definition): array
    {
        return [
            'panel' => $panelId,
            'group' => $definition->group() ?? '—',
            'key' => $definition->key(),
            'label' => $definition->meta()->label() ?? '—',
        ];
    }

    /**
     * @param  list<array{panel: string, group: string, key: string, label: string|null}>  $rows
     */
    private function outputTable(array $rows): void
    {
        $this->table(
            headers: ['Panel', 'Group', 'Key', 'Label'],
            rows: array_map(
                static fn (array $r): array => [$r['panel'], $r['group'], $r['key'], $r['label'] ?? '—'],
                $rows,
            ),
        );

        $this->line(<<<TEXT
        Total: <info>{$this->countLabel(count($rows))}</info>
        TEXT);
    }

    /**
     * @param  list<array{panel: string, group: string, key: string, label: string|null}>  $rows
     */
    private function outputJson(array $rows): void
    {
        $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  list<array{panel: string, group: string, key: string, label: string|null}>  $rows
     */
    private function outputCsv(array $rows): void
    {
        $this->line('panel,group,key,label');

        foreach ($rows as $row) {
            $this->line(implode(',', [
                $this->csvEscape($row['panel']),
                $this->csvEscape($row['group']),
                $this->csvEscape($row['key']),
                $this->csvEscape($row['label'] ?? ''),
            ]));
        }
    }

    private function csvEscape(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    private function countLabel(int $count): string
    {
        return match (true) {
            $count === 1 => '1 permission',
            default => "{$count} permissions",
        };
    }

    private function normalizeOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
