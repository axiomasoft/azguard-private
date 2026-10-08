<?php

declare(strict_types=1);

namespace AzGuard\Filament\Authorization;

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel as FilamentPanel;
use Filament\Resources\Resource;
use Filament\Resources\ResourceConfiguration;
use Filament\Support\Contracts\HasLabel;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionProperty;
use UnitEnum;

/**
 * Permission keys of the resources, pages and widgets of one Filament panel.
 *
 * A resource key is `{slug}.{ability}`, a page key `pages.{slug}`, a widget key `widgets.{kebab(class name)}`. A slash
 * of a slug becomes a dot, so a cluster or a resource configuration is a nested segment. The model of a resource never
 * takes part in a key. `protected static ?string $azguardKey` on a class replaces the segment that is computed from
 * its slug or name.
 *
 * @internal
 */
final class FilamentKeys
{
    /** @var list<FilamentKey>|null */
    private ?array $keys = null;

    /**
     * @param  list<string>  $abilities  snake_case abilities of a resource
     * @param  array{resources?: list<string>, pages?: list<string>, widgets?: list<string>}  $exclude  classes without a permission
     */
    public function __construct(
        private readonly FilamentPanel $panel,
        private readonly array $abilities,
        private readonly array $exclude = [],
    ) {}

    /**
     * Every key of the panel, each once.
     *
     * @return list<FilamentKey>
     *
     * @throws InvalidConfigurationException when a segment breaks the grammar or two classes share a key
     */
    public function all(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        $keys = [];
        $owners = [];

        foreach ($this->entries() as [$key, $owner]) {
            if (isset($owners[$key->local])) {
                throw $this->failure('filament_key_duplicate', 'Filament panel "'.$this->panel->getId().'": '.$owners[$key->local].' and '.$owner
                    .' both map to the permission "'.$key->local.'". Set protected static ?string $azguardKey on one of the classes.');
            }
            $owners[$key->local] = $owner;
            $keys[] = $key;
        }

        return $this->keys = $keys;
    }

    /**
     * The key of an ability of a resource.
     *
     * @param  class-string<resource>  $resource
     *
     * @throws InvalidConfigurationException
     */
    public function resource(string $resource, string $ability, ?ResourceConfiguration $configuration = null): string
    {
        return $this->checked($this->resourceSegment($resource, $configuration).'.'.$ability, $resource);
    }

    /**
     * @param  class-string<Page>  $page
     *
     * @throws InvalidConfigurationException
     */
    public function page(string $page, ?string $configuration = null): string
    {
        return $this->checked('pages.'.$this->pageSegment($page, $configuration), $page);
    }

    /**
     * @param  class-string<Widget>  $widget
     *
     * @throws InvalidConfigurationException
     */
    public function widget(string $widget): string
    {
        $segment = $this->override($widget) ?? Str::kebab(class_basename($widget));

        return $this->checked('widgets.'.$segment, $widget);
    }

    /**
     * @return iterable<array{0: FilamentKey, 1: string}>
     */
    private function entries(): iterable
    {
        foreach ($this->resourceTargets() as [$resource, $configuration]) {
            $model = $resource::getModel();
            $group = $this->group($resource::getNavigationGroup());

            foreach ($this->abilities as $ability) {
                yield [
                    new FilamentKey(
                        local: $this->resource($resource, $ability, $configuration),
                        surface: FilamentSurface::Resource,
                        class: $resource,
                        label: Str::ucfirst(str_replace('_', ' ', $ability)).': '.$resource::getTitleCasePluralModelLabel(),
                        group: $group,
                        model: is_subclass_of($model, Model::class) ? $model : null,
                    ),
                    $resource.($configuration instanceof ResourceConfiguration ? ' (configuration "'.$configuration->getKey().'")' : ''),
                ];
            }
        }

        foreach ($this->pageTargets() as [$page, $configuration]) {
            yield [
                new FilamentKey(
                    local: $this->page($page, $configuration),
                    surface: FilamentSurface::Page,
                    class: $page,
                    label: $page::getNavigationLabel(),
                    group: $this->group($page::getNavigationGroup()),
                ),
                $page.($configuration === null ? '' : ' (configuration "'.$configuration.'")'),
            ];
        }

        foreach ($this->widgetTargets() as $widget) {
            yield [
                new FilamentKey(
                    local: $this->widget($widget),
                    surface: FilamentSurface::Widget,
                    class: $widget,
                    label: Str::headline(class_basename($widget)),
                ),
                $widget,
            ];
        }
    }

    /**
     * @return list<array{0: class-string<resource>, 1: ResourceConfiguration|null}>
     */
    private function resourceTargets(): array
    {
        $targets = [];

        foreach ($this->panel->getResources() as $resource) {
            if (is_subclass_of($resource, Resource::class) && ! $this->excluded('resources', $resource)) {
                $targets[] = [$resource, null];
            }
        }

        foreach ($this->panel->getResourceConfigurations() as $configuration) {
            $resource = $configuration->getResource();

            if (is_subclass_of($resource, Resource::class) && ! $this->excluded('resources', $resource)) {
                $targets[] = [$resource, $configuration];
            }
        }

        return $targets;
    }

    /**
     * @return list<array{0: class-string<Page>, 1: string|null}>
     */
    private function pageTargets(): array
    {
        $targets = [];

        foreach ($this->panel->getPages() as $page) {
            if (is_subclass_of($page, Page::class) && ! $this->excluded('pages', $page)) {
                $targets[] = [$page, null];
            }
        }

        foreach ($this->panel->getPageConfigurations() as $configuration) {
            if (is_subclass_of($configuration->page, Page::class) && ! $this->excluded('pages', $configuration->page)) {
                $targets[] = [$configuration->page, $configuration->getSlug() ?? $configuration->getKey()];
            }
        }

        return $targets;
    }

    /**
     * @return list<class-string<Widget>>
     */
    private function widgetTargets(): array
    {
        $targets = [];

        foreach ($this->panel->getWidgets() as $widget) {
            $class = $widget instanceof WidgetConfiguration ? $widget->widget : $widget;

            if (! $this->excluded('widgets', $class) && ! in_array($class, $targets, true)) {
                $targets[] = $class;
            }
        }

        return $targets;
    }

    private function excluded(string $surface, string $class): bool
    {
        return in_array($class, $this->exclude[$surface] ?? [], true);
    }

    /**
     * @param  class-string<resource>  $resource
     */
    private function resourceSegment(string $resource, ?ResourceConfiguration $configuration): string
    {
        $override = $this->override($resource);

        if ($override !== null) {
            return $override;
        }

        $slug = $resource::getDefaultSlug();

        if ($configuration instanceof ResourceConfiguration) {
            $slug = filled($configuration->getSlug()) ? $configuration->getSlug() : $slug.'/'.$configuration->getKey();
        }

        return $this->segment($this->clustered($resource::getCluster(), $slug), $resource);
    }

    /**
     * @param  class-string<Page>  $page
     */
    private function pageSegment(string $page, ?string $configuration): string
    {
        $override = $this->override($page);

        if ($override !== null) {
            return $override;
        }

        $slug = $configuration === null ? $page::getDefaultSlug() : $page::getDefaultSlug().'/'.$configuration;

        return $this->segment($this->clustered($page::getCluster(), $slug), $page);
    }

    /**
     * @param  class-string|null  $cluster
     */
    private function clustered(?string $cluster, string $slug): string
    {
        return filled($cluster) ? $cluster::getSlug($this->panel).'/'.$slug : $slug;
    }

    private function segment(string $slug, string $class): string
    {
        $segment = str_replace('/', '.', trim($slug, '/'));

        if (! self::isSegments($segment)) {
            throw $this->failure('filament_key_segment', $class.' in the Filament panel "'.$this->panel->getId().'" has the slug "'.$slug
                .'", which is not a permission segment (lowercase letters, digits, "_" and "-"). Set protected static ?string $azguardKey on the class.');
        }

        return $segment;
    }

    /**
     * @param  class-string  $class
     */
    private function override(string $class): ?string
    {
        if (! property_exists($class, 'azguardKey')) {
            return null;
        }

        $key = (new ReflectionProperty($class, 'azguardKey'))->getValue();

        if ($key === null) {
            return null;
        }

        if (! is_string($key) || ! self::isSegments($key)) {
            throw $this->failure('filament_key_segment', $class.': $azguardKey must be a permission name (lowercase letters, digits, "_", "-" and "." between segments).');
        }

        return $key;
    }

    private static function isSegments(string $value): bool
    {
        foreach (explode('.', $value) as $segment) {
            if (! PermissionGrammar::isSegment($segment)) {
                return false;
            }
        }

        return true;
    }

    private function checked(string $key, string $class): string
    {
        if (! PermissionGrammar::isLocalKey($key)) {
            throw $this->failure('filament_key_segment', $class.' in the Filament panel "'.$this->panel->getId().'" gets the invalid permission "'.$key.'". Check $azguardKey and the abilities.');
        }

        return $key;
    }

    private function group(string|UnitEnum|null $group): ?string
    {
        return match (true) {
            $group instanceof HasLabel => self::text($group->getLabel()),
            $group instanceof BackedEnum => (string) $group->value,
            $group instanceof UnitEnum => $group->name,
            default => $group,
        };
    }

    private static function text(string|Htmlable|null $label): ?string
    {
        return $label instanceof Htmlable ? $label->toHtml() : $label;
    }

    private function failure(string $check, string $message): InvalidConfigurationException
    {
        return InvalidConfigurationException::failing($check, $message);
    }
}
