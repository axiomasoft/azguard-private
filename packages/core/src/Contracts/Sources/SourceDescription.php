<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Schema\Field;

/**
 * What a source can contribute to a panel. Parameters of the source are not part of the description.
 *
 * @spi
 */
final readonly class SourceDescription
{
    /** A name of the source for people; the id when the source gives none. */
    public string $label;

    /**
     * @param  string  $id  id of the source inside the panel
     * @param  class-string<Source>  $class
     * @param  list<class-string<Source>>  $capabilities  capability interfaces the source implements
     * @param  array<string, list<Field>>  $fields  grant fields of the models the source stores, by `FieldTarget` value
     */
    public function __construct(
        public string $id,
        public string $class,
        public array $capabilities,
        public bool $dynamic,
        ?string $label = null,
        public array $fields = [],
    ) {
        $this->label = $label ?? $id;
    }
}
