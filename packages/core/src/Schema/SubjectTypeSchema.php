<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use AzGuard\Contracts\Subjects\SubjectDirectory;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

/**
 * A subject model of a panel: the Laravel auth guard its current user comes from and the directory that looks it up.
 */
final readonly class SubjectTypeSchema implements JsonSerializable
{
    /**
     * @param  class-string<Model>  $model
     * @param  string  $type  the morph type grants store
     * @param  class-string<SubjectDirectory>  $directory  the declared directory, or the default one
     */
    public function __construct(
        public string $model,
        public string $type,
        public string $label,
        public ?string $guard,
        public string $directory,
    ) {}

    /**
     * @return array{model: string, type: string, label: string, guard: ?string, directory: string}
     */
    public function toArray(): array
    {
        return ['model' => $this->model, 'type' => $this->type, 'label' => $this->label, 'guard' => $this->guard, 'directory' => $this->directory];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
