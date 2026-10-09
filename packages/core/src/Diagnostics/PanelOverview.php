<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics;

use AzGuard\AzGuardManager;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Database\DatabaseSource;
use BackedEnum;

/**
 * What the registered panels are: each panel with its settings and where every value came from, its sources and
 * plugins, and the schema of its permissions and roles. It is the one place that gathers this; `azguard:panels:list`
 * and the panels page of the Filament package print it. It only reads.
 *
 * @api
 *
 * @phpstan-type Sources array{sources: list<array{id: string, label: string, class: string, capabilities: list<string>, dynamic: bool}>, named: list<array{name: string, origin: string}>, plugins: list<string>}
 * @phpstan-type Entry array{id: string, label: string, default: bool, prefix: ?string, tenants: string, subjects: list<string>, writer: ?string, storage: ?string, plugins: list<string>, settings?: array<string, array{value: bool|int|string|null, origin: string}>, sources?: Sources, schema?: array<string, mixed>}
 */
final readonly class PanelOverview
{
    public function __construct(private PanelRegistry $registry, private AzGuardManager $azguard) {}

    /**
     * Every registered panel, in the order of registration.
     *
     * @param  bool  $settings  the effective settings with their origins
     * @param  bool  $sources  the sources, the named sources and the plugins
     * @param  bool  $schema  the tenant-independent schema of permissions and roles
     * @return list<Entry>
     */
    public function all(bool $settings = false, bool $sources = false, bool $schema = false): array
    {
        $panels = [];

        foreach ($this->registry->all() as $panel) {
            $panels[] = $this->panel($panel, $settings, $sources, $schema);
        }

        return $panels;
    }

    /**
     * One panel; see `all()` for the parts that the arguments add.
     *
     * @return Entry
     */
    public function panel(Panel $panel, bool $settings = false, bool $sources = false, bool $schema = false): array
    {
        $entry = $this->describe($panel);

        if ($settings) {
            $entry['settings'] = $panel->settings()->toArray();
        }

        if ($sources) {
            $entry['sources'] = $this->sources($panel, $this->registry->recipe($panel->id()));
        }

        if ($schema) {
            // The tenant-independent schema: static permissions and the code roles.
            $entry['schema'] = $this->azguard->panel($panel->id())->inTenant(TenantRef::global())->schema()->toArray();
        }

        return $entry;
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
     * @return Sources
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
}
