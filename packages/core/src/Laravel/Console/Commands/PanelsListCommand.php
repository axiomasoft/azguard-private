<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Diagnostics\PanelOverview;
use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use Illuminate\Console\Command;

/**
 * Lists the registered panels; with options also their effective settings and where each value came from, their
 * sources and plugins, and the schema of their permissions and roles. Reads only.
 *
 * @phpstan-import-type Entry from PanelOverview
 */
final class PanelsListCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:panels:list
        {--settings : Show the effective settings and where each value came from}
        {--sources : Show the sources of each panel, what each contributes, and the plugins}
        {--schema : Show the schema of permissions and roles}
        {--json : Print JSON}';

    /** @var string */
    protected $description = 'List the AzGuard panels with their settings, sources and schema';

    public function handle(PanelOverview $overview): int
    {
        return $this->attempt(function () use ($overview): int {
            $panels = $overview->all($this->option('settings') === true, $this->option('sources') === true, $this->option('schema') === true);

            if ($this->option('json') === true) {
                $this->printJson($panels);

                return self::SUCCESS;
            }
            $this->print($panels);

            return self::SUCCESS;
        });
    }

    /** @param list<Entry> $panels */
    private function print(array $panels): void
    {
        if ($panels === []) {
            $this->components->info('No AzGuard panels are registered.');

            return;
        }
        $this->table(['Panel', 'Label', 'Default', 'Prefix', 'Tenants', 'Writer', 'Storage', 'Plugins'], array_map(static fn (array $panel): array => [
            $panel['id'], $panel['label'], $panel['default'] ? 'yes' : 'no', $panel['prefix'] ?? '', $panel['tenants'],
            $panel['writer'] ?? '', $panel['storage'] ?? '', implode(', ', $panel['plugins']),
        ], $panels));

        foreach ($panels as $panel) {
            if (isset($panel['settings'])) {
                $this->line('Settings of '.$panel['id'].':');
                $rows = [];
                foreach ($panel['settings'] as $setting => ['value' => $value, 'origin' => $origin]) {
                    $rows[] = [$setting, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $origin];
                }
                $this->table(['Setting', 'Value', 'Origin'], $rows);
            }

            if (isset($panel['sources'])) {
                $this->line('Sources of '.$panel['id'].':');
                $this->table(['Source', 'Label', 'Class', 'Contributes', 'Dynamic'], array_map(static fn (array $source): array => [
                    $source['id'], $source['label'], $source['class'], implode(', ', $source['capabilities']), $source['dynamic'] ? 'yes' : 'no',
                ], $panel['sources']['sources']));

                foreach ($panel['sources']['named'] as ['name' => $name, 'origin' => $origin]) {
                    $this->line('  named source '.$name.' ('.$origin.')');
                }
            }

            if (isset($panel['schema'])) {
                $this->line('Schema of '.$panel['id'].':');
                $this->printJson($panel['schema']);
            }
        }
    }
}
