<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Exceptions\RegistryFrozenException;
use AzGuard\Roles\BaseRole;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * What a panel is built from: every builder call kept as a record of the setting, its value and its origin.
 *
 * Nothing is overwritten while the recipe is written. Layers are read in the order provider, plugins by attachment
 * order, `configure` for all panels; the compiler decides what a scalar setting resolves to, a list keeps every item.
 *
 * @phpstan-type Origin array{kind: 'provider'|'plugin'|'configure', plugin: ?string, order: int}
 * @phpstan-type Record array{setting: string, value: mixed, origin: Origin}
 * @phpstan-type Subject array{model: class-string<Model>, guard: ?string, directory: ?string}
 */
final class PanelRecipe
{
    public const string PROVIDER = 'provider';

    public const string PLUGIN = 'plugin';

    public const string CONFIGURE = 'configure';

    public const string ID = 'id';

    public const string LABEL = 'label';

    public const string DESCRIPTION = 'description';

    public const string DEFAULT = 'default';

    public const string RESOURCE_PREFIX = 'resource_prefix';

    public const string SUBJECTS = 'subjects';

    public const string MIDDLEWARE = 'middleware';

    public const string ENTRY = 'entry';

    public const string ON_DENIED = 'on_denied';

    public const string REQUIRE_ROUTE_CHECKS = 'require_route_checks';

    public const string PERMISSIONS = 'permissions';

    public const string ROLES = 'roles';

    public const string DISCOVER = 'discover';

    public const string POLICIES = 'policies';

    public const string PRESENTATION = 'presentation';

    public const string FIELDS = 'fields';

    public const string BEFORE = 'before';

    public const string RESTRICTIONS = 'restrictions';

    public const string AFTER = 'after';

    public const string CHANGING = 'changing';

    public const string GRANT_CONDITIONS = 'grant_conditions';

    public const string DOCTOR_CHECKS = 'doctor_checks';

    public const string TENANT_RESOLVERS = 'tenant_resolvers';

    public const string SCOPE_RESOLVERS = 'scope_resolvers';

    public const string RESOURCE_SCOPES = 'resource_scopes';

    public const string PLUGINS = 'plugins';

    public const string WITHOUT_PLUGINS = 'without_plugins';

    /** Hook and resolver lists whose items are a class name, an object or a closure. */
    public const array HOOK_LISTS = [
        self::BEFORE, self::RESTRICTIONS, self::AFTER, self::CHANGING, self::GRANT_CONDITIONS, self::DOCTOR_CHECKS,
        self::TENANT_RESOLVERS, self::SCOPE_RESOLVERS,
    ];

    private const array LAYERS = [self::PROVIDER => 0, self::PLUGIN => 1, self::CONFIGURE => 2];

    /** @var list<Record> */
    private array $records = [];

    /** @var Origin */
    private array $origin;

    /** @var list<string> */
    private array $pluginIds = [];

    private bool $sealed = false;

    /** @var class-string<PanelProvider>|null */
    private ?string $providerClass = null;

    public function __construct(private readonly string $panelId)
    {
        $this->origin = self::provider();
    }

    /**
     * @return Origin
     */
    public static function provider(): array
    {
        return ['kind' => self::PROVIDER, 'plugin' => null, 'order' => 0];
    }

    /**
     * @param  int  $order  position of the plugin among the plugins attached to the panel
     * @return Origin
     */
    public static function plugin(string $id, int $order): array
    {
        return ['kind' => self::PLUGIN, 'plugin' => $id, 'order' => $order];
    }

    /**
     * @return Origin
     */
    public static function configure(): array
    {
        return ['kind' => self::CONFIGURE, 'plugin' => null, 'order' => 0];
    }

    public function panelId(): string
    {
        return $this->panelId;
    }

    /**
     * @param  class-string<PanelProvider>  $provider
     */
    public function setProvider(string $provider): void
    {
        $this->providerClass = $provider;
    }

    /**
     * @return class-string<PanelProvider>|null
     */
    public function providerClass(): ?string
    {
        return $this->providerClass;
    }

    /**
     * @return Origin the origin a record written now would get
     */
    public function origin(): array
    {
        return $this->origin;
    }

    /**
     * Runs the callback with the given origin for every record it writes, then restores the previous origin.
     *
     * @param  Origin  $origin
     * @param  Closure(): mixed  $callback
     */
    public function during(array $origin, Closure $callback): void
    {
        $previous = $this->origin;
        $this->origin = $origin;

        try {
            $callback();
        } finally {
            $this->origin = $previous;
        }
    }

    /**
     * @throws RegistryFrozenException when the panel is already compiled
     */
    public function record(string $setting, mixed $value): void
    {
        $this->assertOpen($setting);

        $this->records[] = ['setting' => $setting, 'value' => $value, 'origin' => $this->origin];
    }

    /**
     * Adds a plugin to the plugins registered on the panel.
     *
     * @return Origin the origin of what the plugin writes
     *
     * @throws RegistryFrozenException when the panel is already compiled
     */
    public function attach(string $pluginId): array
    {
        $this->assertOpen(self::PLUGINS);
        $this->pluginIds[] = $pluginId;

        return self::plugin($pluginId, count($this->pluginIds));
    }

    /**
     * @return list<string> ids of the plugins registered on the panel, in the order they were attached
     */
    public function pluginIds(): array
    {
        return $this->pluginIds;
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    public function isSealed(): bool
    {
        return $this->sealed;
    }

    /**
     * @return list<Record> in the order the records were written
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * @return list<Record> records of one setting in layer order; records of one layer keep their written order
     */
    public function layered(string $setting): array
    {
        $records = array_values(array_filter(
            $this->records,
            static fn (array $record): bool => $record['setting'] === $setting,
        ));
        $positions = array_keys($records);

        usort($positions, static fn (int $a, int $b): int => [self::rank($records[$a]['origin']), $a]
            <=> [self::rank($records[$b]['origin']), $b]);

        return array_map(static fn (int $position): array => $records[$position], $positions);
    }

    /**
     * @param  Origin  $origin
     * @return array{0: int, 1: int} layer and position inside the layer; records of one layer share the rank
     */
    public static function rank(array $origin): array
    {
        return [self::LAYERS[$origin['kind']], $origin['kind'] === self::PLUGIN ? $origin['order'] : 0];
    }

    /**
     * Items of a list setting from every layer; a layer never replaces the items of another one.
     *
     * @return list<mixed>
     */
    public function items(string $setting): array
    {
        $items = [];

        foreach ($this->layered($setting) as $record) {
            foreach (is_array($record['value']) ? $record['value'] : [] as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return list<Subject>
     */
    public function subjects(): array
    {
        $subjects = [];

        foreach ($this->items(self::SUBJECTS) as $subject) {
            if (is_array($subject) && is_string($subject['model'] ?? null) && is_subclass_of($subject['model'], Model::class)) {
                $guard = $subject['guard'] ?? null;
                $directory = $subject['directory'] ?? null;

                $subjects[] = [
                    'model' => $subject['model'],
                    'guard' => is_string($guard) ? $guard : null,
                    'directory' => is_string($directory) ? $directory : null,
                ];
            }
        }

        return $subjects;
    }

    /**
     * Plugins one layer attaches, in the order they were written.
     *
     * @param  self::PROVIDER|self::PLUGIN|self::CONFIGURE  $layer
     * @return list<Plugin|class-string<Plugin>>
     */
    public function plugins(string $layer): array
    {
        $plugins = [];

        foreach ($this->records as $record) {
            if ($record['setting'] !== self::PLUGINS || $record['origin']['kind'] !== $layer) {
                continue;
            }

            foreach (is_array($record['value']) ? $record['value'] : [] as $plugin) {
                if ($plugin instanceof Plugin || (is_string($plugin) && is_subclass_of($plugin, Plugin::class))) {
                    $plugins[] = $plugin;
                }
            }
        }

        return $plugins;
    }

    /**
     * @return list<string> ids of the plugins the panel does not take from `configure` for all panels
     */
    public function withoutPlugins(): array
    {
        return array_values(array_unique(array_filter($this->items(self::WITHOUT_PLUGINS), is_string(...))));
    }

    /**
     * @return list<class-string<BackedEnum>> permission enums, each class once, in the order of first registration
     */
    public function enums(): array
    {
        $enums = [];

        foreach ($this->items(self::PERMISSIONS) as $definition) {
            if (is_string($definition) && is_subclass_of($definition, BackedEnum::class)) {
                $enums[$definition] = $definition;
            }
        }

        return array_values($enums);
    }

    /**
     * @return list<Source|string> source objects and registered source names in the order they were attached
     */
    public function sources(): array
    {
        $sources = [];

        foreach ($this->items(self::PERMISSIONS) as $definition) {
            if ($definition instanceof Source || (is_string($definition) && ! is_subclass_of($definition, BackedEnum::class))) {
                $sources[] = $definition;
            }
        }

        return $sources;
    }

    /**
     * @return list<class-string<BaseRole>> role classes, each once, in the order of first registration
     */
    public function roles(): array
    {
        $roles = [];

        foreach ($this->items(self::ROLES) as $role) {
            if (is_string($role) && is_subclass_of($role, BaseRole::class)) {
                $roles[$role] = $role;
            }
        }

        return array_values($roles);
    }

    /**
     * @throws RegistryFrozenException
     */
    private function assertOpen(string $setting): void
    {
        if ($this->sealed) {
            throw new RegistryFrozenException(
                'Panel "'.$this->panelId.'" is already compiled: "'.$setting.'" cannot be changed after the application has booted.',
            );
        }
    }
}
