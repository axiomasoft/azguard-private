<?php

declare(strict_types=1);

use AzGuard\Contracts\PermissionLayer;
use AzGuard\Registry\Contracts\GrantPriority;
use AzGuard\Registry\Contracts\GrantSource;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Contracts\PermissionDefinition;
use AzGuard\Registry\Definitions\SimplePermissionDefinition;
use AzGuard\Registry\Resolver\EffectivePermissionResolver;
use AzGuard\Registry\Resolver\PermissionCache;
use AzGuard\Registry\Values\PermissionSet;
use Illuminate\Contracts\Auth\Authenticatable;

// ─── Helpers ──────────────────────────────────────────────────────────────

function makeUser(int $id = 1): Authenticatable
{
    return new class($id) implements Authenticatable
    {
        public function __construct(private int $id) {}

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return $this->id;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };
}

function makeGrantSource(PermissionSet $set, GrantPriority $priority = GrantPriority::ClassRole): GrantSource
{
    return new class($set, $priority) implements GrantSource
    {
        public function __construct(private PermissionSet $s, private GrantPriority $p) {}

        public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
        {
            return $this->s;
        }

        public function priority(): int
        {
            return $this->p->value;
        }
    };
}

function makeCatalog(array $knownKeys, array $panels = ['app']): PermissionCatalog
{
    return new class($knownKeys, $panels) implements PermissionCatalog
    {
        public function __construct(private array $keys, private array $panelList) {}

        public function all(string $panelId): array
        {
            return [];
        }

        public function has(string $panelId, string $key): bool
        {
            return in_array($key, $this->keys, true);
        }

        public function get(string $panelId, string $key): ?PermissionDefinition
        {
            return null;
        }

        public function assert(string $panelId, string $key): PermissionDefinition
        {
            throw new RuntimeException;
        }

        public function groups(string $panelId): array
        {
            return [];
        }

        public function panels(): array
        {
            return $this->panelList;
        }

        public function flush(): void {}
    };
}

/**
 * Catalog backed by real PermissionDefinition objects so that all() returns
 * dynamic definitions (isDynamic()) — required to exercise filterAgainstCatalog's
 * dynamic branch. has() answers exact membership only, exactly like production.
 *
 * @param  list<PermissionDefinition>  $definitions
 */
function makeDefinitionCatalog(array $definitions, array $panels = ['app']): PermissionCatalog
{
    return new class($definitions, $panels) implements PermissionCatalog
    {
        public function __construct(private array $definitions, private array $panelList) {}

        public function all(string $panelId): array
        {
            return array_values(array_filter(
                $this->definitions,
                static fn (PermissionDefinition $d): bool => $d->panelId() === $panelId,
            ));
        }

        public function has(string $panelId, string $key): bool
        {
            foreach ($this->all($panelId) as $definition) {
                if (! $definition->isDynamic() && $definition->key() === $key) {
                    return true;
                }
            }

            return false;
        }

        public function get(string $panelId, string $key): ?PermissionDefinition
        {
            return null;
        }

        public function assert(string $panelId, string $key): PermissionDefinition
        {
            throw new RuntimeException;
        }

        public function groups(string $panelId): array
        {
            return [];
        }

        public function panels(): array
        {
            return $this->panelList;
        }

        public function flush(): void {}
    };
}

function dynamicDefinition(string $key): PermissionDefinition
{
    return new SimplePermissionDefinition(
        key: $key,
        panelId: 'app',
        dynamic: true,
    );
}

function staticDefinition(string $key): PermissionDefinition
{
    return new SimplePermissionDefinition(
        key: $key,
        panelId: 'app',
        dynamic: false,
    );
}

// ─── Tests ────────────────────────────────────────────────────────────────

describe('EffectivePermissionResolver', function () {

    it('returns empty set when no sources', function () {
        $resolver = new EffectivePermissionResolver(
            catalog: makeCatalog([]),
            sources: [],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        expect($set->isEmpty())->toBeTrue();
    });

    it('merges permissions from multiple sources', function () {
        $sourceA = makeGrantSource(PermissionSet::fromKeys(['app.posts.view']), GrantPriority::ClassRole);
        $sourceB = makeGrantSource(PermissionSet::fromKeys(['app.posts.edit']), GrantPriority::DatabaseRole);

        $resolver = new EffectivePermissionResolver(
            catalog: makeCatalog(['app.posts.view', 'app.posts.edit']),
            sources: [$sourceA, $sourceB],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        expect($set->has('app.posts.view'))->toBeTrue()
            ->and($set->has('app.posts.edit'))->toBeTrue();
    });

    it('stops early and returns wildcard when any source grants *', function () {
        $calls = 0;
        $sourceWild = makeGrantSource(PermissionSet::wildcard(), GrantPriority::ClassRole);
        $sourceLow = makeGrantSource(PermissionSet::fromKeys(['app.posts.view']), GrantPriority::DirectGrant);

        $resolver = new EffectivePermissionResolver(
            catalog: makeCatalog(['app.posts.view']),
            sources: [$sourceWild, $sourceLow],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        expect($set->isWildcard())->toBeTrue();
    });

    it('filters out keys not in catalog', function () {
        // Source даёт 3 ключа, но каталог знает только 2
        $source = makeGrantSource(PermissionSet::fromKeys([
            'app.posts.view',
            'app.posts.edit',
            'app.orphan.unknown',   // не зарегистрировано
        ]));

        $resolver = new EffectivePermissionResolver(
            catalog: makeCatalog(['app.posts.view', 'app.posts.edit']),
            sources: [$source],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        expect($set->keys())->toBe(['app.posts.view', 'app.posts.edit'])
            ->and($set->has('app.orphan.unknown'))->toBeFalse();
    });

    it('wildcard set is NOT filtered through catalog', function () {
        $source = makeGrantSource(PermissionSet::wildcard());

        $resolver = new EffectivePermissionResolver(
            catalog: makeCatalog([]),   // каталог пустой — не важно
            sources: [$source],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        expect($set->isWildcard())->toBeTrue();
    });

    it('a wildcard produced by the layer (not a global source) IS filtered through the catalog (C-13 defense-in-depth)', function () {
        $source = makeGrantSource(PermissionSet::fromKeys(['app.posts.view']));

        $layer = new class implements PermissionLayer
        {
            public function apply(PermissionSet $global, Authenticatable $user, string $panelId): PermissionSet
            {
                // Simulates a context layer that (erroneously, or via a bug in a
                // custom MergeStrategy) surfaces a wildcard key — this must NOT
                // be trusted as a real superadmin grant.
                return PermissionSet::wildcard();
            }

            public function cacheDiscriminator(string $panelId): string
            {
                return '';
            }
        };

        $resolver = new EffectivePermissionResolver(
            catalog: makeCatalog(['app.posts.view']),
            sources: [$source],
            cache: new PermissionCache,
            layer: $layer,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        // The wildcard is stripped by filterAgainstCatalog (wildcard feature off
        // by default: any key containing '*' is dropped outright) — NOT expanded
        // into a true superadmin grant.
        expect($set->isWildcard())->toBeFalse()
            ->and($set->isEmpty())->toBeTrue();
    });

    it('caches result and does not call sources twice for same user+panel', function () {
        $callCount = 0;

        $source = new class($callCount) implements GrantSource
        {
            public function __construct(private int &$count) {}

            public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
            {
                $this->count++;

                return PermissionSet::fromKeys(['app.posts.view']);
            }

            public function priority(): int
            {
                return GrantPriority::ClassRole->value;
            }
        };

        $resolver = new EffectivePermissionResolver(
            catalog: makeCatalog(['app.posts.view']),
            sources: [$source],
            cache: new PermissionCache,
        );

        $user = makeUser(1);
        $resolver->forUser($user, 'app');
        $resolver->forUser($user, 'app');

        expect($callCount)->toBe(1);
    });

    it('sources are sorted by priority descending', function () {
        $order = [];

        $makeOrderedSource = function (GrantPriority $priority, string $key) use (&$order): GrantSource {
            return new class($priority, $key, $order) implements GrantSource
            {
                public function __construct(
                    private GrantPriority $p,
                    private string $k,
                    private array &$o,
                ) {}

                public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
                {
                    $this->o[] = $this->p->value;

                    return PermissionSet::fromKeys([$this->k]);
                }

                public function priority(): int
                {
                    return $this->p->value;
                }
            };
        };

        $resolver = new EffectivePermissionResolver(
            catalog: makeCatalog(['app.a', 'app.b', 'app.c']),
            sources: [
                $makeOrderedSource(GrantPriority::DatabaseRole, 'app.b'),
                $makeOrderedSource(GrantPriority::ClassRole, 'app.a'),
                $makeOrderedSource(GrantPriority::DirectGrant, 'app.c'),
            ],
            cache: new PermissionCache,
        );

        $resolver->forUser(makeUser(1), 'app');

        expect($order)->toBe([100, 90, 80]);
    });

    it('skips a throwing source by default and merges the rest', function () {
        $throwing = new class implements GrantSource
        {
            public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
            {
                throw new RuntimeException('boom');
            }

            public function priority(): int
            {
                return 100;
            }
        };

        config()->set('az-guard.fail_on_source_exception', false);

        $resolver = new EffectivePermissionResolver(
            catalog: makeCatalog(['app.posts.view']),
            sources: [$throwing, makeGrantSource(PermissionSet::fromKeys(['app.posts.view']), GrantPriority::DatabaseRole)],
            cache: new PermissionCache,
        );

        expect($resolver->forUser(makeUser(1), 'app')->grants('app.posts.view'))->toBeTrue();
    });

    it('propagates a source exception when fail_on_source_exception is true', function () {
        $throwing = new class implements GrantSource
        {
            public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
            {
                throw new RuntimeException('boom');
            }

            public function priority(): int
            {
                return 100;
            }
        };

        config()->set('az-guard.fail_on_source_exception', true);

        $resolver = new EffectivePermissionResolver(
            catalog: makeCatalog(['app.posts.view']),
            sources: [$throwing],
            cache: new PermissionCache,
        );

        expect(fn () => $resolver->forUser(makeUser(1), 'app'))->toThrow(RuntimeException::class);
    });

    it('unions sources additively — a lower-priority source never removes a higher grant', function () {
        $resolver = new EffectivePermissionResolver(
            catalog: makeCatalog(['app.a', 'app.b']),
            sources: [
                makeGrantSource(PermissionSet::fromKeys(['app.a']), GrantPriority::ClassRole),
                makeGrantSource(PermissionSet::fromKeys(['app.b']), GrantPriority::DirectGrant),
            ],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        expect($set->grants('app.a'))->toBeTrue()
            ->and($set->grants('app.b'))->toBeTrue();
    });

    it('short-circuits on a higher-priority wildcard before lower sources run', function () {
        $lower = new class implements GrantSource
        {
            public bool $ran = false;

            public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
            {
                $this->ran = true;

                return PermissionSet::empty();
            }

            public function priority(): int
            {
                return 80;
            }
        };

        $resolver = new EffectivePermissionResolver(
            catalog: makeCatalog(['app.a']),
            sources: [makeGrantSource(PermissionSet::wildcard(), GrantPriority::ClassRole), $lower],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        expect($set->isWildcard())->toBeTrue()
            ->and($lower->ran)->toBeFalse();
    });
});

describe('EffectivePermissionResolver: dynamic catalog definitions (F28)', function () {

    beforeEach(function () {
        // Exercise the wildcard-off branch by default; dynamic matching must work
        // regardless of the wildcard feature flag.
        config()->set('az-guard.features.wildcard_permission', false);
    });

    it('keeps a concrete grant matching a dynamic definition (app.team.{id}.admin ← app.team.42.admin)', function () {
        $source = makeGrantSource(PermissionSet::fromKeys(['app.team.42.admin']));

        $resolver = new EffectivePermissionResolver(
            catalog: makeDefinitionCatalog([dynamicDefinition('app.team.{id}.admin')]),
            sources: [$source],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        expect($set->grants('app.team.42.admin'))->toBeTrue();
    });

    it('still filters a bogus unknown key even when dynamic definitions exist', function () {
        $source = makeGrantSource(PermissionSet::fromKeys([
            'app.team.42.admin',      // matches the dynamic definition → kept
            'app.orphan.unknown',     // matches nothing → dropped
        ]));

        $resolver = new EffectivePermissionResolver(
            catalog: makeDefinitionCatalog([dynamicDefinition('app.team.{id}.admin')]),
            sources: [$source],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        expect($set->grants('app.team.42.admin'))->toBeTrue()
            ->and($set->has('app.orphan.unknown'))->toBeFalse()
            ->and($set->keys())->toBe(['app.team.42.admin']);
    });

    it('does NOT treat a non-dynamic definition as a wildcard pattern', function () {
        // A static definition with a literal placeholder-looking key must NOT
        // match a differently-shaped concrete key: only isDynamic() participates.
        $source = makeGrantSource(PermissionSet::fromKeys(['app.team.42.admin']));

        $resolver = new EffectivePermissionResolver(
            catalog: makeDefinitionCatalog([staticDefinition('app.team.{id}.admin')]),
            sources: [$source],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        // The static definition is known by exact key only; the concrete grant
        // is not that exact key, so it is dropped.
        expect($set->has('app.team.42.admin'))->toBeFalse()
            ->and($set->isEmpty())->toBeTrue();
    });

    it('requires the same segment count — a dynamic pattern does not match a longer key', function () {
        $source = makeGrantSource(PermissionSet::fromKeys([
            'app.team.42.admin.extra',   // one segment too many
        ]));

        $resolver = new EffectivePermissionResolver(
            catalog: makeDefinitionCatalog([dynamicDefinition('app.team.{id}.admin')]),
            sources: [$source],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        expect($set->has('app.team.42.admin.extra'))->toBeFalse();
    });

    it('honours literal segments around the placeholder', function () {
        $source = makeGrantSource(PermissionSet::fromKeys([
            'app.team.42.admin',    // matches
            'app.team.42.viewer',   // last literal segment differs → no match
        ]));

        $resolver = new EffectivePermissionResolver(
            catalog: makeDefinitionCatalog([dynamicDefinition('app.team.{id}.admin')]),
            sources: [$source],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        expect($set->grants('app.team.42.admin'))->toBeTrue()
            ->and($set->has('app.team.42.viewer'))->toBeFalse();
    });

    it('drops a literal wildcard key even when it shape-matches a dynamic definition (wildcard off)', function () {
        // '*' must not sneak past the dynamic-catalog check when the wildcard
        // feature is disabled — 'app.team.*' has the same segment count as
        // 'app.team.{id}' and would otherwise match the placeholder literally.
        $source = makeGrantSource(PermissionSet::fromKeys(['app.team.*']));

        $resolver = new EffectivePermissionResolver(
            catalog: makeDefinitionCatalog([dynamicDefinition('app.team.{id}')]),
            sources: [$source],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        expect($set->has('app.team.*'))->toBeFalse()
            ->and($set->isEmpty())->toBeTrue();
    });

    it('still keeps a concrete key matching a dynamic definition when wildcard is off (no regression)', function () {
        $source = makeGrantSource(PermissionSet::fromKeys(['app.team.42']));

        $resolver = new EffectivePermissionResolver(
            catalog: makeDefinitionCatalog([dynamicDefinition('app.team.{id}')]),
            sources: [$source],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        expect($set->grants('app.team.42'))->toBeTrue();
    });

    it('matches dynamic definitions in the wildcard-enabled branch as well', function () {
        config()->set('az-guard.features.wildcard_permission', true);

        $source = makeGrantSource(PermissionSet::fromKeys([
            'app.team.42.admin',    // concrete grant, no '*' → dynamic branch
            'app.orphan.unknown',   // dropped
        ]));

        $resolver = new EffectivePermissionResolver(
            catalog: makeDefinitionCatalog([
                dynamicDefinition('app.team.{id}.admin'),
                staticDefinition('app.posts.view'),
            ]),
            sources: [$source],
            cache: new PermissionCache,
        );

        $set = $resolver->forUser(makeUser(1), 'app');

        expect($set->grants('app.team.42.admin'))->toBeTrue()
            ->and($set->has('app.orphan.unknown'))->toBeFalse();
    });
});
