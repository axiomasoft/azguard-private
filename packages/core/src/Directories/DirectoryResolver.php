<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Contracts\Scopes\ConfigurableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\TenantDirectory;
use AzGuard\Contracts\Subjects\SubjectDirectory;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Panels\Panel;
use Illuminate\Contracts\Container\Container;

/**
 * The one place that picks the directory of a panel: the class declared in code (`for(..., directory:)`,
 * `BaseAssignmentScope::directory()`) when there is one, otherwise the default directory of that kind.
 *
 * @internal
 */
final readonly class DirectoryResolver
{
    private function __construct(private Panel $panel, private Container $container) {}

    public static function for(Panel $panel, Container $container): self
    {
        return new self($panel, $container);
    }

    /**
     * The directory of the subjects of one morph type, or of every subject model of the panel without a type.
     * An unknown type has no subjects.
     */
    public function subjects(?string $type = null): SubjectDirectory
    {
        $byType = [];
        $default = [];

        foreach ($this->panel->subjects() as $descriptor) {
            $alias = (new $descriptor->model)->getMorphClass();

            if ($type !== null && $alias !== $type) {
                continue;
            }

            if ($descriptor->directory === null) {
                $default[$alias] = $descriptor->model;
                $byType[$alias] = null;

                continue;
            }
            $directory = $this->container->make($descriptor->directory);

            if (! $directory instanceof SubjectDirectory) {
                throw new DefinitionException('Subject directory '.$descriptor->directory.' must implement '.SubjectDirectory::class.'.');
            }
            $byType[$alias] = $directory;
        }
        $models = new ModelSubjectDirectory(array_values($default));

        foreach ($byType as $alias => $directory) {
            $byType[$alias] = $directory ?? $models;
        }

        return $type !== null && count($byType) === 1 ? $byType[$type] : new PanelSubjectDirectory($byType);
    }

    public function scopes(string $type): AssignmentScopeDirectory
    {
        $definition = $this->panel->scopeDefinition($type);
        $class = $definition instanceof ConfigurableAssignmentScopeDefinition ? $definition->settings()->directory : null;

        if ($class === null) {
            return new QueryScopeDirectory($this->container);
        }
        $directory = $this->container->make($class);

        if (! $directory instanceof AssignmentScopeDirectory) {
            throw new DefinitionException('Assignment scope directory '.$class.' must implement '.AssignmentScopeDirectory::class.'.');
        }

        return $directory;
    }

    public function tenants(): TenantDirectory
    {
        return new ModelTenantDirectory($this->panel->tenants()->definition());
    }
}
