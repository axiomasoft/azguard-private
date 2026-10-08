<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Contracts\Scopes\TenantDirectory;
use AzGuard\Contracts\Subjects\SubjectDirectory;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Panels\Panel;
use Illuminate\Contracts\Container\Container;

/**
 * The directories of one panel for an interface: who can be chosen as a subject, a tenant or an assignment scope.
 *
 * Each is the directory declared in code for the panel (`for(..., directory:)`, the directory of an assignment scope),
 * otherwise the default one of that kind; the schema of the panel names the same classes. A directory only searches and
 * describes; it decides neither membership nor access, and a caller checks every chosen value with `describe()` again
 * before it writes.
 *
 * @api
 */
final readonly class PanelDirectories
{
    private DirectoryResolver $resolver;

    /**
     * @internal made by the panel access
     */
    public function __construct(Panel $panel, Container $container)
    {
        $this->resolver = DirectoryResolver::for($panel, $container);
    }

    /**
     * The subjects of one morph type, or of every subject model of the panel without a type; an unknown type has none.
     *
     * @throws DefinitionException when a declared directory does not implement the contract
     */
    public function subjects(?string $type = null): SubjectDirectory
    {
        return $this->resolver->subjects($type);
    }

    /**
     * The tenants of the panel; a panel without a tenant model has none.
     */
    public function tenants(): TenantDirectory
    {
        return $this->resolver->tenants();
    }

    /**
     * The assignment scopes of one type.
     *
     * @throws DefinitionException when a declared directory does not implement the contract
     */
    public function scopes(string $type): AssignmentScopeDirectory
    {
        return $this->resolver->scopes($type);
    }
}
