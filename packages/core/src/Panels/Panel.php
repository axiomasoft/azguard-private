<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeResolver;
use AzGuard\Contracts\Scopes\ResourceScopeResolver;
use AzGuard\Contracts\Scopes\TenantResolver;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Contracts\Sources\StoresGrants;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\TenantPolicy;
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
        /** @var array<string, list<Field>> */
        private array $grantFields = [],
        /** @var list<Closure|class-string> */
        private array $beforeHooks = [],
        /** @var list<Closure|class-string> */
        private array $afterHooks = [],
        /** @var list<Restriction|class-string<Restriction>> */
        private array $accessRestrictions = [],
        /** @var list<GrantCondition|class-string<GrantCondition>> */
        private array $conditions = [],
        private ?TenantPolicy $tenantPolicy = null,
        private ?AssignmentScopePolicy $scopePolicy = null,
        /** @var array<string, AssignmentScopeDefinition> */
        private array $scopeDefinitions = [],
        /** @var list<TenantResolver|class-string<TenantResolver>> */
        private array $tenantResolvers = [],
        /** @var list<AssignmentScopeResolver|class-string<AssignmentScopeResolver>> */
        private array $scopeResolvers = [],
        /** @var array<class-string<Model>, ResourceScopeResolver|class-string<ResourceScopeResolver>> */
        private array $resourceScopes = [],
        /** @var list<Closure|object|class-string> */
        private array $changingPipes = [],
        /** @var list<SubjectDescriptor> */
        private array $subjectDescriptors = [],
    ) {}

    /**
     * Pipes every change of grants passes through, in the order provider, plugins, `configure`.
     *
     * @return list<Closure|object|class-string>
     */
    public function changing(): array
    {
        return $this->changingPipes;
    }

    /**
     * Compiled subjects of the panel with their auth guard and directory.
     *
     * @return list<SubjectDescriptor>
     */
    public function subjects(): array
    {
        return $this->subjectDescriptors;
    }

    /**
     * The descriptor of a model class, a model or a subject reference, or null when the panel does not accept it.
     */
    public function subject(Model|SubjectRef|string $subject): ?SubjectDescriptor
    {
        foreach ($this->subjectDescriptors as $descriptor) {
            $matches = match (true) {
                $subject instanceof Model => $subject instanceof $descriptor->model,
                $subject instanceof SubjectRef => $subject->type() === (new $descriptor->model)->getMorphClass(),
                default => is_a($subject, $descriptor->model, true),
            };

            if ($matches) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * Distinct auth guards of the subjects, in declaration order.
     *
     * @return list<string>
     */
    public function guards(): array
    {
        $guards = [];
        foreach ($this->subjectDescriptors as $descriptor) {
            if ($descriptor->guard !== null && ! in_array($descriptor->guard, $guards, true)) {
                $guards[] = $descriptor->guard;
            }
        }

        return $guards;
    }

    public function tenants(): TenantPolicy
    {
        return $this->tenantPolicy ?? TenantPolicy::none();
    }

    public function scopes(): AssignmentScopePolicy
    {
        return $this->scopePolicy ?? AssignmentScopePolicy::none();
    }

    public function scopeDefinition(string $type): ?AssignmentScopeDefinition
    {
        return $this->scopeDefinitions[$type] ?? null;
    }

    /** @return array<string, AssignmentScopeDefinition> */
    public function scopeDefinitions(): array
    {
        return $this->scopeDefinitions;
    }

    /** @return list<TenantResolver|class-string<TenantResolver>> */
    public function tenantResolvers(): array
    {
        return $this->tenantResolvers;
    }

    /** @return list<AssignmentScopeResolver|class-string<AssignmentScopeResolver>> */
    public function scopeResolvers(): array
    {
        return $this->scopeResolvers;
    }

    /** @return array<class-string<Model>, ResourceScopeResolver|class-string<ResourceScopeResolver>> */
    public function resourceScopes(): array
    {
        return $this->resourceScopes;
    }

    /** @return list<Closure|class-string> */
    public function before(): array
    {
        return $this->beforeHooks;
    }

    /** @return list<Closure|class-string> */
    public function after(): array
    {
        return $this->afterHooks;
    }

    /** @return list<Restriction|class-string<Restriction>> */
    public function restrictions(): array
    {
        return $this->accessRestrictions;
    }

    /** @return list<GrantCondition|class-string<GrantCondition>> */
    public function grantConditions(): array
    {
        return $this->conditions;
    }

    public function id(): string
    {
        return $this->id;
    }

    /** @return list<Field> */
    public function fields(FieldTarget $target): array
    {
        return $this->grantFields[$target->value] ?? [];
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
