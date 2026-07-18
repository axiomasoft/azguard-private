<?php

declare(strict_types=1);

use AzGuard\Registry\Builders\CompositePermissionCatalog;
use AzGuard\Registry\Contracts\PermissionCatalogBuilder;
use AzGuard\Registry\Contracts\PermissionDefinition;
use AzGuard\Registry\Contracts\PermissionMeta;
use AzGuard\Registry\Definitions\SimplePermissionMeta;
use AzGuard\Registry\Exceptions\InvalidPermissionKeyException;

// ─── Helpers ──────────────────────────────────────────────────────────────

/**
 * Быстрая заглушка PermissionDefinition.
 */
function makeDefinition(string $key, ?string $group = 'General'): PermissionDefinition
{
    return new class($key, $group) implements PermissionDefinition
    {
        public function __construct(private string $k, private ?string $g) {}

        public function key(): string
        {
            return $this->k;
        }

        public function shortKey(): string
        {
            return $this->k;
        }

        public function panelId(): string
        {
            return explode('.', $this->k)[0] ?? '';
        }

        public function group(): ?string
        {
            return $this->g;
        }

        public function label(): ?string
        {
            return $this->meta()->label();
        }

        public function meta(): PermissionMeta
        {
            return new SimplePermissionMeta($this->k);
        }

        public function isDynamic(): bool
        {
            return false;
        }
    };
}

/**
 * Заглушка PermissionCatalogBuilder, возвращающая фиксированный набор.
 *
 * @param  list<PermissionDefinition>  $definitions
 */
function makeBuilder(array $definitions, bool $supports = true): PermissionCatalogBuilder
{
    return new class($definitions, $supports) implements PermissionCatalogBuilder
    {
        public function __construct(
            private array $defs,
            private bool $sup,
        ) {}

        public function build(string $panelId): array
        {
            return $this->defs;
        }

        public function supports(string $panelId): bool
        {
            return $this->sup;
        }
    };
}

// ─── Tests ────────────────────────────────────────────────────────────────

describe('CompositePermissionCatalog', function () {

    it('returns empty list for panel with no builders', function () {
        $catalog = new CompositePermissionCatalog(
            builders: [],
            panelIds: ['app'],
        );

        expect($catalog->all('app'))->toBe([]);
    });

    it('aggregates definitions from multiple builders', function () {
        $defA = makeDefinition('app.posts.view');
        $defB = makeDefinition('app.posts.edit');

        $catalog = new CompositePermissionCatalog(
            builders: [makeBuilder([$defA]), makeBuilder([$defB])],
            panelIds: ['app'],
        );

        $all = $catalog->all('app');

        expect($all)->toHaveCount(2);
        expect(array_map(fn ($d) => $d->key(), $all))
            ->toContain('app.posts.view')
            ->toContain('app.posts.edit');
    });

    it('deduplicates same key from two builders when groups match', function () {
        $def1 = makeDefinition('app.posts.view', 'Posts');
        $def2 = makeDefinition('app.posts.view', 'Posts');

        $catalog = new CompositePermissionCatalog(
            builders: [makeBuilder([$def1]), makeBuilder([$def2])],
            panelIds: ['app'],
        );

        expect($catalog->all('app'))->toHaveCount(1);
    });

    it('deduplicates same key from two builders even when groups differ (key is identity)', function () {
        // Одинаковый ключ из двух источников (напр. Filament-дискавери ресурса +
        // permission-enum того же ресурса) — идемпотентный дедуп, не конфликт:
        // ключ есть идентичность пермишена, group()/label() — только отображение.
        $def1 = makeDefinition('app.posts.view', 'Posts');
        $def2 = makeDefinition('app.posts.view', 'Articles');

        $catalog = new CompositePermissionCatalog(
            builders: [makeBuilder([$def1]), makeBuilder([$def2])],
            panelIds: ['app'],
        );

        $all = $catalog->all('app');

        expect($all)->toHaveCount(1)
            ->and($all[0]->group())->toBe('Posts'); // первый выигрывает при обеих непустых
    });

    it('adopts a non-null group when the first source for a key had none', function () {
        $def1 = makeDefinition('app.posts.view', null);
        $def2 = makeDefinition('app.posts.view', 'Posts');

        $catalog = new CompositePermissionCatalog(
            builders: [makeBuilder([$def1]), makeBuilder([$def2])],
            panelIds: ['app'],
        );

        $all = $catalog->all('app');

        expect($all)->toHaveCount(1)
            ->and($all[0]->group())->toBe('Posts');
    });

    it('has() returns true for registered key', function () {
        $catalog = new CompositePermissionCatalog(
            builders: [makeBuilder([makeDefinition('app.posts.view')])],
            panelIds: ['app'],
        );

        expect($catalog->has('app', 'app.posts.view'))->toBeTrue()
            ->and($catalog->has('app', 'app.posts.delete'))->toBeFalse();
    });

    it('get() returns definition or null', function () {
        $def = makeDefinition('app.posts.view');
        $catalog = new CompositePermissionCatalog(
            builders: [makeBuilder([$def])],
            panelIds: ['app'],
        );

        expect($catalog->get('app', 'app.posts.view'))->toBe($def)
            ->and($catalog->get('app', 'missing'))->toBeNull();
    });

    it('assert() throws InvalidPermissionKeyException for unknown key', function () {
        $catalog = new CompositePermissionCatalog(
            builders: [makeBuilder([makeDefinition('app.posts.view')])],
            panelIds: ['app'],
        );

        expect(fn () => $catalog->assert('app', 'app.missing'))
            ->toThrow(InvalidPermissionKeyException::class);
    });

    it('groups() returns definitions sorted by group name', function () {
        $catalog = new CompositePermissionCatalog(
            builders: [makeBuilder([
                makeDefinition('app.posts.view', 'Posts'),
                makeDefinition('app.tags.view', 'Tags'),
                makeDefinition('app.posts.edit', 'Posts'),
            ])],
            panelIds: ['app'],
        );

        $groups = $catalog->groups('app');

        expect(array_keys($groups))->toBe(['Posts', 'Tags'])
            ->and($groups['Posts'])->toHaveCount(2)
            ->and($groups['Tags'])->toHaveCount(1);
    });

    it('groups() uses Other for null group', function () {
        $def = new class implements PermissionDefinition
        {
            public function key(): string
            {
                return 'app.x';
            }

            public function shortKey(): string
            {
                return 'x';
            }

            public function panelId(): string
            {
                return 'app';
            }

            public function group(): ?string
            {
                return null;
            }

            public function label(): ?string
            {
                return $this->meta()->label();
            }

            public function meta(): PermissionMeta
            {
                return new SimplePermissionMeta('x');
            }

            public function isDynamic(): bool
            {
                return false;
            }
        };

        $catalog = new CompositePermissionCatalog(
            builders: [makeBuilder([$def])],
            panelIds: ['app'],
        );

        expect(array_keys($catalog->groups('app')))->toBe(['Other']);
    });

    it('panels() returns registered panel IDs', function () {
        $catalog = new CompositePermissionCatalog(
            builders: [],
            panelIds: ['app', 'admin'],
        );

        expect($catalog->panels())->toBe(['app', 'admin']);
    });

    it('resolves a Closure panelIds lazily so a panel added after construction is visible', function () {
        $panels = ['app'];

        $catalog = new CompositePermissionCatalog(
            builders: [],
            panelIds: function () use (&$panels): array {
                return $panels;
            },
        );

        expect($catalog->panels())->toBe(['app']);

        $panels[] = 'admin'; // "registered after boot"

        expect($catalog->panels())->toBe(['app', 'admin']);
    });

    it('flush() resets built state and allows re-build', function () {
        $catalog = new CompositePermissionCatalog(
            builders: [makeBuilder([makeDefinition('app.posts.view')])],
            panelIds: ['app'],
        );

        // Первая сборка
        expect($catalog->has('app', 'app.posts.view'))->toBeTrue();

        // Flush — сброс кэша; следующий вызов перестраивает каталог заново
        $catalog->flush();

        // После flush каталог пересобирается при следующем обращении — ключ снова доступен
        expect($catalog->get('app', 'app.posts.view'))->not->toBeNull();
    });

    it('skips builder that does not support panel', function () {
        $supported = makeBuilder([makeDefinition('app.posts.view')], supports: true);
        $unsupported = makeBuilder([makeDefinition('app.posts.edit')], supports: false);

        $catalog = new CompositePermissionCatalog(
            builders: [$supported, $unsupported],
            panelIds: ['app'],
        );

        expect($catalog->all('app'))->toHaveCount(1)
            ->and($catalog->has('app', 'app.posts.edit'))->toBeFalse();
    });
});
