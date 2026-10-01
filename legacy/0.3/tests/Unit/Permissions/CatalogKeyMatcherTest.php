<?php

declare(strict_types=1);

use AzGuard\Permissions\CatalogKeyMatcher;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Contracts\PermissionDefinition;
use AzGuard\Registry\Definitions\SimplePermissionDefinition;

function matcherCatalog(array $definitions): PermissionCatalog
{
    return new class($definitions) implements PermissionCatalog
    {
        /** @param list<PermissionDefinition> $definitions */
        public function __construct(private array $definitions) {}

        public function all(string $panelId): array
        {
            return $this->definitions;
        }

        public function has(string $panelId, string $resolvedKey): bool
        {
            foreach ($this->definitions as $definition) {
                if (! $definition->isDynamic() && $definition->key() === $resolvedKey) {
                    return true;
                }
            }

            return false;
        }

        public function get(string $panelId, string $resolvedKey): ?PermissionDefinition
        {
            return null;
        }

        public function assert(string $panelId, string $resolvedKey): PermissionDefinition
        {
            throw new RuntimeException;
        }

        public function groups(string $panelId): array
        {
            return [];
        }

        public function panels(): array
        {
            return ['app'];
        }

        public function flush(): void {}
    };
}

it('owns exact catalog keys and concrete dynamic matches, not prefixes', function () {
    $catalog = matcherCatalog([
        new SimplePermissionDefinition(key: 'app.docs.view', panelId: 'app'),
        new SimplePermissionDefinition(key: 'app.team.{id}.edit', panelId: 'app', dynamic: true),
    ]);

    expect(CatalogKeyMatcher::owns($catalog, 'app', 'app.docs.view'))->toBeTrue()
        ->and(CatalogKeyMatcher::owns($catalog, 'app', 'app.team.42.edit'))->toBeTrue()
        ->and(CatalogKeyMatcher::owns($catalog, 'app', 'app.docs'))->toBeFalse()
        ->and(CatalogKeyMatcher::owns($catalog, 'app', 'posts.update'))->toBeFalse();
});
