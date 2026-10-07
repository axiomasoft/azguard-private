<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use JsonSerializable;

/**
 * How a role binds one assignment scope type: the classes of its filters and how the scope is shown and looked up.
 * Filter objects, their constructor arguments and closures are not part of it.
 */
final readonly class AssignmentScopeBindingSchema implements JsonSerializable
{
    /**
     * @param  list<string>  $filters  filter classes in declaration order; `Closure` for a closure filter
     * @param  class-string|null  $directory  the directory class the binding names, null for the directory of the type
     * @param  bool  $exactSupport  whether exact lists can be built for the type: a queryable model or an access adapter
     */
    public function __construct(
        public string $contextType,
        public array $filters,
        public string $label,
        public ?string $directory,
        public bool $exactSupport,
    ) {}

    /**
     * @return array{context_type: string, filters: list<string>, label: string, directory: ?string, exact_support: bool}
     */
    public function toArray(): array
    {
        return [
            'context_type' => $this->contextType,
            'filters' => $this->filters,
            'label' => $this->label,
            'directory' => $this->directory,
            'exact_support' => $this->exactSupport,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
