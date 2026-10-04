<?php

declare(strict_types=1);

namespace AzGuard\Policies;

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
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
     * @param  class-string|null  $policy
     * @param  string|null  $method  policy method that carries #[Decides] for this permission
     * @param  class-string<Model>|null  $resourceModel
     */
    private function __construct(
        public BackedEnum|string $permission,
        public ?string $policy,
        public ?string $method = null,
        public string $kind = 'php',
        public ?string $ability = null,
        public ?string $resourceModel = null,
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

    /** @param class-string<Model>|null $resourceModel */
    public static function gate(BackedEnum|string $permission, string $ability, ?string $resourceModel = null): self
    {
        if (($permission instanceof BackedEnum && ! is_string($permission->value))
            || (is_string($permission) && ! PermissionGrammar::isLocalKey($permission))) {
            throw new DefinitionException('PolicyBinding::gate() expects a string-backed permission case or local name.');
        }

        if (trim($ability) === '') {
            throw new DefinitionException('PolicyBinding::gate() requires a nonempty native ability.');
        }

        $resourceModel = $resourceModel === null ? null : ltrim($resourceModel, '\\');

        if ($resourceModel !== null && ! is_a($resourceModel, Model::class, true)) {
            throw new DefinitionException('PolicyBinding::gate() requires an existing resource model class.');
        }

        return new self(permission: $permission, policy: null, kind: 'gate', ability: $ability, resourceModel: $resourceModel);
    }

    private static function describe(UnitEnum|string $permission): string
    {
        return $permission instanceof UnitEnum ? $permission::class.'::'.$permission->name : '"'.$permission.'"';
    }
}
