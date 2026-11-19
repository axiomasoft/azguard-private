<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Scopes\Contracts\AssignmentScopeAccessAdapter;
use AzGuard\Scopes\Contracts\AssignmentScopeMembership;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Scope behavior settings `ScopesPlugin` contributes to a panel: the scope membership adapter and the access adapters
 * of external scope types. Read them with `Panel::options(ScopesOptions::class)`.
 */
#[Api(since: '2.0')]
final readonly class ScopesOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array ROOT_KEYS = ['membership', 'adapters'];

    /**
     * @param  AssignmentScopeMembership|class-string<AssignmentScopeMembership>|null  $membership
     * @param  array<string, AssignmentScopeAccessAdapter|class-string<AssignmentScopeAccessAdapter>>  $adapters  keyed by scope type alias
     */
    public function __construct(
        public AssignmentScopeMembership|string|null $membership = null,
        public array $adapters = [],
    ) {}

    /** @param array<mixed> $config */
    public static function fromArray(array $config, string $path = 'azguard-scopes'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::ROOT_KEYS, $path);

        $rawMembership = $config['membership'] ?? null;
        $membership = null;

        if ($rawMembership !== null) {
            if ($rawMembership instanceof AssignmentScopeMembership) {
                $membership = $rawMembership;
            } elseif (is_string($rawMembership) && $rawMembership !== '' && is_a($rawMembership, AssignmentScopeMembership::class, true)) {
                /** @var class-string<AssignmentScopeMembership> $rawMembership */
                $membership = $rawMembership;
            } else {
                $validator->add("{$path}.membership", 'must be null, an AssignmentScopeMembership instance, or a class implementing AssignmentScopeMembership');
            }
        }

        $rawAdapters = $config['adapters'] ?? [];
        $adapters = [];

        if (! is_array($rawAdapters)) {
            $validator->add("{$path}.adapters", 'must be an associative array of scope adapters');
        } else {
            foreach ($rawAdapters as $alias => $adapter) {
                if (! is_string($alias) || $alias === '') {
                    $validator->add("{$path}.adapters", 'adapter keys must be non-empty scope alias strings');

                    continue;
                }

                if ($adapter instanceof AssignmentScopeAccessAdapter) {
                    $adapters[$alias] = $adapter;

                    continue;
                }

                if (is_string($adapter) && $adapter !== '' && is_a($adapter, AssignmentScopeAccessAdapter::class, true)) {
                    /** @var class-string<AssignmentScopeAccessAdapter> $adapter */
                    $adapters[$alias] = $adapter;

                    continue;
                }

                $validator->add("{$path}.adapters.{$alias}", 'must be an AssignmentScopeAccessAdapter instance or class-string');
            }
        }

        $validator->failIfAny();

        return new self($membership, $adapters);
    }
}
