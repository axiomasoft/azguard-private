<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\AzGuardManager;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Database\DatabaseSource;
use BackedEnum;
use Illuminate\Console\Command;

/**
 * Lists the registered panels; with options also their effective settings and where each value came from, their
 * sources and plugins, and the schema of their permissions and roles. Reads only.
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

    public function handle(PanelRegistry $registry, AzGuardManager $azguard): int
    {
        return $this->attempt(function () use ($registry, $azguard): int {
            $panels = [];
            foreach ($registry->all() as $panel) {
                $entry = $this->describe($panel);

                if ($this->option('settings') === true) {
                    $entry['settings'] = $panel->settings()->toArray();
                }

                if ($this->option('sources') === true) {
                    $entry['sources'] = $this->sources($panel, $registry->recipe($panel->id()));
                }

                if ($this->option('schema') === true) {
                    // The tenant-independent schema: static permissions and the code roles.
                    $entry['schema'] = $azguard->panel($panel->id())->inTenant(TenantRef::global())->schema()->toArray();
                }
                $panels[] = $entry;
            }

            if ($this->option('json') === true) {
                $this->printJson($panels);

                return self::SUCCESS;
            }
            $this->print($panels);

            return self::SUCCESS;
        });
    }

    /** @return array{id: string, label: string, default: bool, prefix: ?string, tenants: string, subjects: list<string>, writer: ?string, storage: ?string, plugins: list<string>} */
    private function describe(Panel $panel): array
    {
        $writer = $panel->writer();

        return [
            'id' => $panel->id(),
            'label' => $panel->label(),
            'default' => $panel->isDefault(),
            'prefix' => $panel->prefix(),
            'tenants' => $panel->tenants()->mode(),
            'subjects' => $panel->subjectModels(),
            'writer' => $writer === null ? null : $writer->id(),
            'storage' => $writer instanceof DatabaseSource ? $writer->boundStorage()->id() : null,
            'plugins' => $panel->pluginIds(),
        ];
    }

    /**
     * Each source of the panel with the capabilities it brings, the named sources of the factory the panel uses, and
     * the plugins with where they were attached.
     *
     * @return array{sources: list<array{id: string, label: string, class: string, capabilities: list<string>, dynamic: bool}>, named: list<array{name: string, origin: string}>, plugins: list<string>}
     */
    private function sources(Panel $panel, PanelRecipe $recipe): array
    {
        $named = [];
        foreach ($recipe->layered(PanelRecipe::PERMISSIONS) as $record) {
            foreach (is_array($record['value']) ? $record['value'] : [] as $definition) {
                if (is_string($definition) && ! is_subclass_of($definition, BackedEnum::class)) {
                    $origin = $record['origin'];
                    $named[] = ['name' => $definition, 'origin' => $origin['plugin'] === null ? $origin['kind'] : $origin['kind'].':'.$origin['plugin']];
                }
            }
        }

        return [
            'sources' => array_map(static fn (SourceDescription $source): array => [
                'id' => $source->id,
                'label' => $source->label,
                'class' => $source->class,
                'capabilities' => array_map(class_basename(...), $source->capabilities),
                'dynamic' => $source->dynamic,
            ], $panel->sources()),
            'named' => $named,
            'plugins' => $panel->pluginIds(),
        ];
    }

    /** @param list<array<string, mixed>> $panels */
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
