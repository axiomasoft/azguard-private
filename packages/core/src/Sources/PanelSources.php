<?php

declare(strict_types=1);

namespace AzGuard\Sources;

use AzGuard\Contracts\Sources\Source;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\UnknownSourceException;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Panels\PanelRecipe;
use BackedEnum;

/**
 * Source objects of a panel in the order of the recipe layers and of registration, each with the layer that
 * attached it.
 *
 * Enum classes of `permissions([...])` are not sources here: the folder source takes them. A source name is
 * resolved by the source factory; until a name is registered, it is an unknown source.
 *
 * @phpstan-type Attached array{source: Source, origin: string, plugin: ?string}
 */
final readonly class PanelSources
{
    /**
     * @param  list<Attached>  $sources
     */
    private function __construct(
        private string $panel,
        private array $sources,
    ) {}

    /**
     * @throws UnknownSourceException when a source is named and no source is registered under the name
     * @throws InvalidIdentityException when the id of a source is malformed
     * @throws DefinitionException when two sources of the panel share an id
     */
    public static function of(PanelRecipe $recipe): self
    {
        $panel = $recipe->panelId();
        $sources = $byId = [];

        foreach ($recipe->layered(PanelRecipe::PERMISSIONS) as $record) {
            foreach (is_array($record['value']) ? $record['value'] : [] as $definition) {
                if (is_string($definition) && is_subclass_of($definition, BackedEnum::class)) {
                    continue;
                }

                if (! $definition instanceof Source) {
                    throw new UnknownSourceException(
                        'Panel "'.$panel.'" names the source '.json_encode($definition).', which is not registered: attach a source object '
                        .'or register the name.',
                    );
                }

                $id = $definition->id();
                IdentityCodec::assertSourceLabel($id);

                if (isset($byId[$id])) {
                    throw new DefinitionException(
                        'Panel "'.$panel.'" has two sources with the id "'.$id.'" ('.$byId[$id]::class.' and '.$definition::class
                        .'): every source of a panel needs its own id.',
                    );
                }

                $byId[$id] = $definition;
                $origin = $record['origin'];
                $sources[] = [
                    'source' => $definition,
                    'origin' => $origin['kind'] === PanelRecipe::PLUGIN ? PanelRecipe::PLUGIN.':'.$origin['plugin'] : $origin['kind'],
                    'plugin' => $origin['plugin'],
                ];
            }
        }

        return new self($panel, $sources);
    }

    public function panel(): string
    {
        return $this->panel;
    }

    /**
     * @return list<Attached>
     */
    public function all(): array
    {
        return $this->sources;
    }

    /**
     * Sources that implement the capability.
     *
     * @template TCapability of Source
     *
     * @param  class-string<TCapability>  $capability
     * @return list<array{source: TCapability, origin: string, plugin: ?string}>
     */
    public function with(string $capability): array
    {
        $sources = [];

        foreach ($this->sources as $attached) {
            if ($attached['source'] instanceof $capability) {
                $sources[] = $attached;
            }
        }

        return $sources;
    }
}
