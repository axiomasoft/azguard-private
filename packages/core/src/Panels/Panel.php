<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Contracts\Permission;
use AzGuard\Permissions\PermissionKey;
use AzGuard\Roles\BaseRole;
use UnitEnum;

/** @api */
final class Panel
{
    protected string $id = '';

    protected string $label = '';

    protected string $path = '';

    protected string $namespace = '';

    protected string $basePath = '';

    protected bool $isScopedByPanelId = true;

    /** @var list<class-string<UnitEnum>> */
    protected array $permissionEnums = [];

    /** @var list<class-string<BaseRole>> */
    protected array $roleClasses = [];

    public static function make(): static
    {
        return new self;
    }

    public function id(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label ?: $this->id;
    }

    public function path(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function namespace(string $namespace): static
    {
        $this->namespace = $namespace;

        return $this;
    }

    public function basePath(string $basePath): static
    {
        $this->basePath = $basePath;

        return $this;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /** @param list<class-string<UnitEnum>> $enums */
    public function permissionEnums(array $enums): static
    {
        $this->permissionEnums = $enums;

        return $this;
    }

    /** @return list<class-string<UnitEnum>> */
    public function getPermissionEnums(): array
    {
        return $this->permissionEnums;
    }

    /** @param list<class-string<BaseRole>> $classes */
    public function roleClasses(array $classes): static
    {
        $this->roleClasses = $classes;

        return $this;
    }

    /** @return list<class-string<BaseRole>> */
    public function getRoleClasses(): array
    {
        return $this->roleClasses;
    }

    public function scopedByPanelId(bool $condition = true): static
    {
        $this->isScopedByPanelId = $condition;

        return $this;
    }

    /**
     * Resolve a permission (string or enum) to its fully-qualified key,
     * applying panel scoping ("{panelId}.{permission}") when enabled.
     */
    public function resolvePermission(string|UnitEnum $permission): string
    {
        if ($permission instanceof UnitEnum) {
            return $this->scope(PermissionKey::normalize($permission));
        }

        // A class-based permission (implements Permission). A permission key
        // always contains a '.' (or is '*'); a class-string never does — so the
        // dotted-key common path never triggers autoload.
        if (! str_contains($permission, '.') && is_subclass_of($permission, Permission::class)) {
            return $this->scope($permission::ability());
        }

        return $this->scope($permission);
    }

    private function scope(string $permission): string
    {
        return $this->isScopedByPanelId
            ? "{$this->id}.{$permission}"
            : $permission;
    }
}
