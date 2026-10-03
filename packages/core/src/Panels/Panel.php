<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Contracts\Sources\StoresGrants;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Sources\PanelSources;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * A compiled panel: an immutable description produced from the recipe of its provider.
 */
final readonly class Panel
{
    /**
     * @internal built by the panel compiler
     *
     * @param  list<class-string<Model>>  $subjectModels
     * @param  list<string>  $pluginIds
     * @param  Closure(): (?StoresGrants)  $writer
     */
    public function __construct(
        private string $id,
        private string $label,
        private bool $default,
        private PanelSettings $settings,
        private array $subjectModels,
        private array $pluginIds,
        private ?PanelSources $resolved = null,
        private bool $writable = false,
        private ?Closure $writer = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * Whether the panel is declared as the default one for its subject models.
     */
    public function isDefault(): bool
    {
        return $this->default;
    }

    /**
     * The segment that qualifies permission names of the panel, or null when the prefix is turned off.
     */
    public function prefix(): ?string
    {
        return $this->settings->resourcePrefix();
    }

    /**
     * Effective settings of the panel and where each value came from.
     */
    public function settings(): PanelSettings
    {
        return $this->settings;
    }

    /**
     * @return list<class-string<Model>>
     */
    public function subjectModels(): array
    {
        return $this->subjectModels;
    }

    /**
     * @return list<string> ids of the plugins of the panel in the order they were attached
     */
    public function pluginIds(): array
    {
        return $this->pluginIds;
    }

    /**
     * Sources of the panel in the order they were assembled.
     *
     * @return list<SourceDescription>
     */
    public function sources(): array
    {
        return $this->resolved === null ? [] : $this->resolved->descriptions($this);
    }

    /**
     * Whether a source of the panel stores grants.
     */
    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * The writer of the current request or job, or null when the panel stores nothing.
     * An object writer is the object of the recipe; a named writer is built again for each scope.
     */
    public function writer(): ?StoresGrants
    {
        if ($this->writer === null) {
            return null;
        }

        $writer = ($this->writer)();

        return $writer instanceof StoresGrants ? $writer : null;
    }

    /**
     * A model is accepted when it is an instance of a subject model; a reference when its type is the morph
     * class of a subject model.
     */
    public function accepts(Model|SubjectRef $subject): bool
    {
        foreach ($this->subjectModels as $model) {
            if ($subject instanceof Model ? $subject instanceof $model : $subject->type() === (new $model)->getMorphClass()) {
                return true;
            }
        }

        return false;
    }
}
