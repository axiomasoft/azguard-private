<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Attributes\Api;
use AzGuard\Contracts\Authorization\AfterHook;
use AzGuard\Contracts\Authorization\BeforeHook;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Contracts\Changes\ChangePipe;
use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeResolver;
use AzGuard\Contracts\Scopes\ResourceScopeResolver;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Contracts\Tenancy\TenantResolver;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;
use AzGuard\Schema\WriterSchema;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\PanelSources;
use AzGuard\Tenancy\TenantPolicy;
use Closure;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * A compiled panel: an immutable description produced from the recipe of its provider.
 */
#[Api(since: '0.7')]
final readonly class Panel
{
    /**
     * @internal built by the panel compiler
     *
     * @param  list<class-string<Model>>  $subjectModels
     * @param  list<string>  $pluginIds
     * @param  Closure(): (?DatabaseSource)  $writer
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
        /** @var list<BeforeHook|class-string<BeforeHook>|Closure> */
        private array $beforeHooks = [],
        /** @var list<AfterHook|class-string<AfterHook>|Closure> */
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
        /** @var list<ChangePipe|class-string<ChangePipe>|Closure> */
        private array $changingPipes = [],
        /** @var list<SubjectDescriptor> */
        private array $subjectDescriptors = [],
        private string|UnitEnum|null $entryPermission = null,
        private Closure|string|null $deniedResponse = null,
        /** @var list<string> */
        private array $entryMiddleware = [],
        private bool $routeChecks = false,
        /** @var list<array{check: DoctorCheck|class-string<DoctorCheck>, origin: string}> */
        private array $healthChecks = [],
        /** @var array<class-string, object> */
        private array $options = [],
    ) {}

    /**
     * The permission required to enter the panel in addition to a role of the panel, or null when a role is enough.
     */
    public function entry(): string|UnitEnum|null
    {
        return $this->entryPermission;
    }

    /**
     * The response to a denied entry: a closure that takes the request and the panel and returns a response, or a
     * redirect target (a route name or a URL); null answers 403.
     */
    public function onDenied(): Closure|string|null
    {
        return $this->deniedResponse;
    }

    /**
     * Middleware that runs when a request enters the panel, in the order they were declared.
     *
     * @return list<string>
     */
    public function middleware(): array
    {
        return $this->entryMiddleware;
    }

    /**
     * Strict mode: every route action of the panel declares a permission check or opts out explicitly.
     */
    public function requiresRouteChecks(): bool
    {
        return $this->routeChecks;
    }

    /**
     * Doctor checks of the panel and its plugins in the order provider, plugins, `configure`, each with where it came
     * from: `provider`, `configure` or `plugin:<id>`.
     *
     * @internal read by the doctor
     *
     * @return list<array{check: DoctorCheck|class-string<DoctorCheck>, origin: string}>
     */
    public function doctorChecks(): array
    {
        return $this->healthChecks;
    }

    /**
     * The source objects of the panel in the order they were assembled.
     *
     * @internal read by the doctor
     *
     * @return list<Source>
     */
    public function attachedSources(): array
    {
        return $this->resolved === null ? [] : array_column($this->resolved->all(), 'source');
    }

    /**
     * Pipes every change of grants passes through, in the order provider, plugins, `configure`.
     *
     * @return list<ChangePipe|class-string<ChangePipe>|Closure>
     *
     * @internal raw compiled list, not a supported surface
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

    /**
     * @internal raw compiled list, not a supported surface
     */
    public function scopes(): AssignmentScopePolicy
    {
        return $this->scopePolicy ?? AssignmentScopePolicy::none();
    }

    /**
     * @internal raw compiled list, not a supported surface
     */
    public function scopeDefinition(string $type): ?AssignmentScopeDefinition
    {
        return $this->scopeDefinitions[$type] ?? null;
    }

    /**
     * @return array<string, AssignmentScopeDefinition>
     *
     * @internal raw compiled list, not a supported surface
     */
    public function scopeDefinitions(): array
    {
        return $this->scopeDefinitions;
    }

    /**
     * @return list<TenantResolver|class-string<TenantResolver>>
     *
     * @internal raw compiled list, not a supported surface
     */
    public function tenantResolvers(): array
    {
        return $this->tenantResolvers;
    }

    /**
     * @return list<AssignmentScopeResolver|class-string<AssignmentScopeResolver>>
     *
     * @internal raw compiled list, not a supported surface
     */
    public function scopeResolvers(): array
    {
        return $this->scopeResolvers;
    }

    /**
     * @return array<class-string<Model>, ResourceScopeResolver|class-string<ResourceScopeResolver>>
     *
     * @internal raw compiled list, not a supported surface
     */
    public function resourceScopes(): array
    {
        return $this->resourceScopes;
    }

    /**
     * @return list<BeforeHook|class-string<BeforeHook>|Closure>
     *
     * @internal raw compiled list, not a supported surface
     */
    public function before(): array
    {
        return $this->beforeHooks;
    }

    /**
     * @return list<AfterHook|class-string<AfterHook>|Closure>
     *
     * @internal raw compiled list, not a supported surface
     */
    public function after(): array
    {
        return $this->afterHooks;
    }

    /**
     * @return list<Restriction|class-string<Restriction>>
     *
     * @internal raw compiled list, not a supported surface
     */
    public function restrictions(): array
    {
        return $this->accessRestrictions;
    }

    /**
     * @return list<GrantCondition|class-string<GrantCondition>>
     *
     * @internal raw compiled list, not a supported surface
     */
    public function grantConditions(): array
    {
        return $this->conditions;
    }

    /**
     * The typed readonly options a module contributed to this panel, or null when it contributed none.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    public function options(string $class): ?object
    {
        $options = $this->options[$class] ?? null;

        return $options instanceof $class ? $options : null;
    }

    /**
     * @return array<class-string, object>
     *
     * @internal read by the panel fingerprint
     */
    public function optionObjects(): array
    {
        return $this->options;
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
     * The core database source that writes the panel's grants in the current request or job, or null when the panel
     * stores nothing. An object writer is the object of the recipe; a named writer is built again for each scope.
     *
     * @internal write paths of the package; supported code reads `writer()`
     */
    public function writerSource(): ?DatabaseSource
    {
        if ($this->writer === null) {
            return null;
        }

        return ($this->writer)();
    }

    /**
     * What the writer of the panel can do, or null for a read-only panel.
     */
    public function writer(): ?WriterSchema
    {
        $source = $this->writerSource();

        return $source === null ? null : new WriterSchema($source->boundStorage()->id(), $source->isDynamic(), $source->isRolesOnly());
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
