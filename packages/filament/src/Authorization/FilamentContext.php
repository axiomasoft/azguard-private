<?php

declare(strict_types=1);

namespace AzGuard\Filament\Authorization;

use AzGuard\Contracts\AzGuardSubject;
use AzGuard\Contracts\PanelAccess;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Scopes\ModelIdentity;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel as FilamentPanel;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource as FilamentResource;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionProperty;
use UnitEnum;

/**
 * A Filament panel with the AzGuard plugin, as the authorization of its resources, pages and widgets sees it.
 *
 * @internal
 */
final readonly class FilamentContext
{
    private function __construct(public FilamentPanel $panel, public AzGuardPlugin $plugin) {}

    /**
     * The current Filament panel, or the default one outside a Filament request, when it has the plugin; null in an
     * application that has not booted Filament.
     */
    public static function current(): ?self
    {
        return self::filament() ? self::of(Filament::getCurrentOrDefaultPanel()) : null;
    }

    /**
     * The Filament panel that serves the request, when it has the plugin; never the default panel.
     */
    public static function serving(): ?self
    {
        return self::filament() ? self::of(Filament::getCurrentPanel()) : null;
    }

    public static function of(?FilamentPanel $panel): ?self
    {
        if (! $panel instanceof FilamentPanel || ! $panel->hasPlugin(AzGuardPlugin::ID)) {
            return null;
        }
        $plugin = $panel->getPlugin(AzGuardPlugin::ID);

        return $plugin instanceof AzGuardPlugin ? new self($panel, $plugin) : null;
    }

    public function enforced(): bool
    {
        return $this->plugin->isEnforced();
    }

    /**
     * @param  'resources'|'pages'|'widgets'  $surface
     */
    public function excludes(string $surface, string $class): bool
    {
        return in_array($class, $this->plugin->getExclude()[$surface], true);
    }

    /**
     * @param  class-string<FilamentResource>  $resource
     *
     * @throws InvalidConfigurationException
     */
    public function resourceKey(string $resource, string|UnitEnum $action): string
    {
        return $this->keys()->resource($resource, self::ability($action), $resource::getConfiguration($this->panel));
    }

    /**
     * @param  class-string<Page>  $page
     *
     * @throws InvalidConfigurationException
     */
    public function pageKey(string $page): string
    {
        $configuration = $page::getConfiguration($this->panel);

        return $this->keys()->page($page, $configuration === null ? null : ($configuration->getSlug() ?? $configuration->getKey()));
    }

    /**
     * @param  class-string<Widget>  $widget
     *
     * @throws InvalidConfigurationException
     */
    public function widgetKey(string $widget): string
    {
        return $this->keys()->widget($widget);
    }

    public function guardPanel(): string
    {
        return $this->plugin->getGuardPanel();
    }

    public function access(): PanelAccess
    {
        return AzGuard::panel($this->guardPanel());
    }

    /**
     * The user of the Filament panel as a subject of the guard panel; null when there is none, it holds no roles or the
     * guard panel does not accept it.
     */
    public function subject(PanelAccess $access): ?SubjectRef
    {
        $user = Filament::auth()->user();

        if (! $user instanceof Model || ! $user instanceof AzGuardSubject) {
            return null;
        }
        $subject = SubjectRef::of($user->getMorphClass(), ModelIdentity::key($user));

        return $access->definition()->accepts($subject) ? $subject : null;
    }

    /**
     * The snake_case ability of an action of Filament: `viewAny` is `view_any`.
     */
    public static function ability(string|UnitEnum $action): string
    {
        $name = match (true) {
            $action instanceof BackedEnum => (string) $action->value,
            $action instanceof UnitEnum => $action->name,
            default => $action,
        };

        return Str::snake($name);
    }

    /**
     * The resource that authorizes the records of a Livewire component: the resource of a resource page, or the resource
     * of a relation manager; null when it is not authorized by AzGuard.
     *
     * @return class-string<FilamentResource>|null
     */
    public static function resourceOf(mixed $component): ?string
    {
        $resource = match (true) {
            $component instanceof RelationManager => self::relationResource($component::class),
            $component instanceof ManageRelatedRecords => $component::getRelatedResource() ?? $component::getResource(),
            $component instanceof ResourcePage => $component::getResource(),
            default => null,
        };

        return $resource !== null && self::authorizes($resource) ? $resource : null;
    }

    /**
     * The resource whose permissions decide a relation manager: its related resource, or `$azguardResource`.
     *
     * @param  class-string<RelationManager>  $relationManager
     * @return class-string<FilamentResource>|null
     */
    public static function relationResource(string $relationManager): ?string
    {
        $resource = $relationManager::getRelatedResource();

        if ($resource === null && property_exists($relationManager, 'azguardResource')) {
            $resource = (new ReflectionProperty($relationManager, 'azguardResource'))->getValue();
        }

        return is_string($resource) && self::authorizes($resource) ? $resource : null;
    }

    /**
     * Whether a resource class decides through AzGuard.
     *
     * @phpstan-assert-if-true class-string<FilamentResource> $resource
     */
    public static function authorizes(string $resource): bool
    {
        return is_subclass_of($resource, FilamentResource::class) && in_array(AuthorizesResource::class, class_uses_recursive($resource), true);
    }

    private static function filament(): bool
    {
        return app()->bound('filament');
    }

    private function keys(): FilamentKeys
    {
        return $this->plugin->keys($this->panel);
    }
}
