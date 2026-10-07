<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

/**
 * An assignment scope type registered on a panel: how it is shown and which directory looks its scopes up.
 */
final readonly class AssignmentScopeTypeSchema implements JsonSerializable
{
    /**
     * @param  class-string<Model>|null  $model  null for a scope outside the database of the application
     * @param  class-string<AssignmentScopeDirectory>  $directory  the configured directory, or the default one
     */
    public function __construct(
        public string $type,
        public string $label,
        public ?string $model,
        public string $directory,
    ) {}

    /**
     * @return array{type: string, label: string, model: ?string, directory: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'label' => $this->label, 'model' => $this->model, 'directory' => $this->directory];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
