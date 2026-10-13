<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use AzGuard\Attributes\Api;
use JsonSerializable;

/**
 * How a role binds one assignment scope type: the classes of the components its definition describes (filters of the
 * Scopes module) and how the scope is shown and looked up. Component objects, their constructor arguments and closures
 * are not part of it.
 */
#[Api(since: '0.7')]
final readonly class AssignmentScopeBindingSchema implements JsonSerializable
{
    /**
     * @param  list<string>  $filters  filter classes in declaration order; `Closure` for a closure filter
     * @param  class-string|null  $directory  the directory class the binding names, null for the directory of the type
     * @param  bool  $exactSupport  whether the binding is a queryable definition with a model; an adapter-backed type
     *                              reports false
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
