<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use AzGuard\Contracts\Scopes\TenantDirectory;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

/**
 * The tenant type of a panel and the directory that looks its tenants up.
 */
final readonly class TenantTypeSchema implements JsonSerializable
{
    /**
     * @param  class-string<Model>  $model
     * @param  class-string<TenantDirectory>  $directory
     */
    public function __construct(
        public string $type,
        public string $label,
        public string $model,
        public string $directory,
    ) {}

    /**
     * @return array{type: string, label: string, model: string, directory: string}
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
