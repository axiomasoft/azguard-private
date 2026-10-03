<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

/**
 * What a source can contribute to a panel. Parameters of the source are not part of the description.
 *
 * @spi
 */
final readonly class SourceDescription
{
    /**
     * @param  string  $id  id of the source inside the panel
     * @param  class-string<Source>  $class
     * @param  list<class-string<Source>>  $capabilities  capability interfaces the source implements
     */
    public function __construct(
        public string $id,
        public string $class,
        public array $capabilities,
        public bool $dynamic,
    ) {}
}
