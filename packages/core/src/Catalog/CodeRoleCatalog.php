<?php

declare(strict_types=1);

namespace AzGuard\Catalog;

use AzGuard\Contracts\Roles\RoleCatalog;
use AzGuard\Panels\Panel;
use AzGuard\Schema\RoleSchema;
use AzGuard\Schema\SchemaBuilder;

/**
 * The code roles of one panel as the schema shows them, taken once from the build the panel was compiled in. Nothing
 * here writes, and nothing reads storage: a role is defined by its PHP class only.
 *
 * @internal made by the panel managers
 */
final readonly class CodeRoleCatalog implements RoleCatalog
{
    /** @var array<string, RoleSchema> */
    private array $roles;

    public function __construct(private Panel $panel, SchemaBuilder $schema)
    {
        $roles = [];
        foreach ($schema->roles($panel) as $role) {
            $roles[$role->key->key()] = $role;
        }
        $this->roles = $roles;
    }

    public function all(): array
    {
        return array_values($this->roles);
    }

    public function find(string $key): ?RoleSchema
    {
        $key = ltrim($key, '\\');
        foreach ($this->roles as $role) {
            if ($role->class === $key) {
                return $role;
            }
        }

        if (str_contains($key, ':')) {
            $parts = explode(':', $key, 2);

            if ($parts[0] !== $this->panel->id()) {
                return null;
            }
            $key = $parts[1];
        }

        return $this->roles[$key] ?? null;
    }
}
