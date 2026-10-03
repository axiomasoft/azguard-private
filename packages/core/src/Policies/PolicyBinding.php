<?php

declare(strict_types=1);

namespace AzGuard\Policies;

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use BackedEnum;
use UnitEnum;

/**
 * Binds a permission to the policy class that decides it.
 *
 * Under a policy-only permission the policy is the only authority; under a grants permission it may only veto.
 *
 * @api
 */
final readonly class PolicyBinding
{
    /**
     * @param  BackedEnum|string  $permission  enum case or local permission name
     * @param  class-string  $policy
     * @param  string|null  $method  policy method that carries #[Decides] for this permission
     */
    private function __construct(
        public BackedEnum|string $permission,
        public string $policy,
        public ?string $method = null,
    ) {}

    /**
     * @param  UnitEnum|string  $permission  string-backed enum case or local permission name
     * @param  string|null  $method  policy method that carries #[Decides] for this permission
     *
     * @throws DefinitionException when the permission is neither, or the policy class does not exist
     */
    public static function for(UnitEnum|string $permission, string $policy, ?string $method = null): self
    {
        if ($permission instanceof UnitEnum && (! $permission instanceof BackedEnum || ! is_string($permission->value))) {
            throw new DefinitionException(
                'PolicyBinding::for() expects a case of a string-backed enum, '.$permission::class.' is not one.',
            );
        }

        if (is_string($permission) && ! PermissionGrammar::isLocalKey($permission)) {
            throw new DefinitionException(
                'PolicyBinding::for() expects a local permission name such as "orders.view", got '.json_encode($permission).'.',
            );
        }

        $class = ltrim($policy, '\\');

        if (! class_exists($class)) {
            throw new DefinitionException(
                'PolicyBinding::for() binds '.self::describe($permission).' to '.json_encode($policy).', which is not an existing class.',
            );
        }

        return new self($permission, $class, $method);
    }

    private static function describe(UnitEnum|string $permission): string
    {
        return $permission instanceof UnitEnum ? $permission::class.'::'.$permission->name : '"'.$permission.'"';
    }
}
