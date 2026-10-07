<?php

declare(strict_types=1);

namespace AzGuard\Roles;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Exceptions\InvalidRoleKeyException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Roles\Attributes\FormerKeys;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;
use ReflectionClass;
use UnitEnum;

/**
 * A role defined by a PHP class; the database only stores who holds it.
 *
 * Every attribute has a method with the same meaning, and overriding the method is equivalent to the attribute.
 * Attributes are read by reflection once per class.
 */
abstract class BaseRole
{
    /**
     * @var array<class-string<self>, array{role: ?Role, formerKeys: list<string>, superAdmin: bool, grantable: bool}>
     */
    private static array $declarations = [];

    /**
     * Permissions as enum cases, local keys or patterns such as `orders.*`.
     *
     * @return list<UnitEnum|string>
     */
    abstract public function permissions(): array;

    /**
     * @throws InvalidRoleKeyException
     */
    public function key(): string
    {
        $key = $this->declaration()['role']?->key;

        if ($key === null) {
            throw new InvalidRoleKeyException(
                'Role '.static::class.' has no key: declare #[Role(\'key\')] or override key().',
            );
        }

        PermissionGrammar::assertRoleKey($key);

        return $key;
    }

    /**
     * @throws InvalidRoleKeyException
     */
    public function label(): string
    {
        $label = $this->declaration()['role']?->label;

        if ($label === null) {
            return $this->key();
        }

        $translated = __($label);

        return is_string($translated) ? $translated : $label;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidRoleKeyException
     */
    public function formerKeys(): array
    {
        $keys = $this->declaration()['formerKeys'];

        foreach ($keys as $former) {
            PermissionGrammar::assertRoleKey($former);
        }

        if ($keys !== [] && in_array($this->key(), $keys, true)) {
            throw new InvalidRoleKeyException(
                'Role '.static::class.' lists its current key "'.$this->key().'" among former keys.',
            );
        }

        return $keys;
    }

    public function grantable(): bool
    {
        return $this->declaration()['grantable'];
    }

    public function superAdmin(): bool
    {
        return $this->declaration()['superAdmin'];
    }

    /**
     * Assignment scopes the role may be granted in; an empty list means tenant-wide only.
     *
     * @return list<AssignmentScopeDefinition|class-string<AssignmentScopeDefinition>>
     */
    public function scopes(): array
    {
        return [];
    }

    /**
     * Whether every assignment of the role must name a concrete scope.
     */
    public function scopeRequired(): bool
    {
        return false;
    }

    /**
     * Display order only; the access check never reads it.
     */
    public function level(): int
    {
        return $this->declaration()['role']->level ?? 0;
    }

    /**
     * @return array{role: ?Role, formerKeys: list<string>, superAdmin: bool, grantable: bool}
     */
    private function declaration(): array
    {
        return self::$declarations[static::class] ??= self::read(static::class);
    }

    /**
     * @param  class-string<self>  $name
     * @return array{role: ?Role, formerKeys: list<string>, superAdmin: bool, grantable: bool}
     */
    private static function read(string $name): array
    {
        $class = new ReflectionClass($name);
        $role = ($class->getAttributes(Role::class)[0] ?? null)?->newInstance();
        $former = ($class->getAttributes(FormerKeys::class)[0] ?? null)?->newInstance();

        return [
            'role' => $role,
            'formerKeys' => $former === null ? [] : $former->keys,
            'superAdmin' => $class->getAttributes(SuperAdmin::class) !== [],
            'grantable' => $class->getAttributes(NotGrantable::class) === [],
        ];
    }
}
