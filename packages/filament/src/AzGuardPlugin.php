<?php

declare(strict_types=1);

namespace AzGuard\Filament;

use AzGuard\Contracts\Panels\PanelRegistry;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Filament\Authorization\FilamentContext;
use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\Authorization\FilamentKeys;
use AzGuard\Filament\Authorization\FilamentSurface;
use AzGuard\Filament\Concerns\AuthorizesPage;
use AzGuard\Filament\Concerns\AuthorizesRelationManager;
use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Filament\Concerns\AuthorizesWidget;
use AzGuard\Filament\Contracts\FilamentFormExtension;
use AzGuard\Filament\Pages\DoctorPage;
use AzGuard\Filament\Pages\PanelsPage;
use AzGuard\Filament\Resources\PermissionGrantResource;
use AzGuard\Filament\Resources\PermissionResource;
use AzGuard\Filament\Resources\RoleGrantResource;
use AzGuard\Filament\Resources\RoleResource;
use AzGuard\Filament\Sources\FilamentSource;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Panels\Panel;
use Filament\Actions\AssociateAction;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkAction;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel as FilamentPanel;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionMethod;

/**
 * Connects a Filament panel to a panel of AzGuard.
 *
 * The permissions of the resources, pages and widgets of the Filament panel live in the AzGuard panel named by
 * `guardPanel()`, which is also the current panel of every request to the Filament panel. `manages()` names the
 * AzGuard panels whose roles and grants this admin edits.
 *
 * Every setting is a property of the plugin instance, one per Filament panel; the defaults are read once from
 * `config/azguard-filament.php` and the configuration is never written. Register the plugin after `tenant()` in the
 * panel provider, because the admission middleware of a panel with tenants runs after the tenant is chosen.
 *
 * The plugin does not change the AzGuard panels. The application attaches `FilamentSource` and `FilamentTenantResolver`
 * itself; `boot()` checks that it did and says what is missing.
 *
 * @api
 */
final class AzGuardPlugin implements Plugin
{
    public const string ID = 'azguard';

    /** Alias of the middleware that enters a panel. */
    private const string MIDDLEWARE = 'azguard.panel';

    /** @var list<string> */
    private const array RESOURCES = ['roles', 'role_grants', 'permission_grants', 'permissions', 'panels', 'doctor'];

    private ?string $guardPanel;

    /** @var list<string>|null */
    private ?array $manages;

    private bool $enforce;

    private FilamentDefinitions $definitions;

    private PermissionAuthority $authority;

    private bool $authorityDeclared = false;

    /** @var list<string> */
    private array $abilities;

    /** @var array{resources: list<string>, pages: list<string>, widgets: list<string>} */
    private array $exclude;

    /** @var array<string, bool> */
    private array $editors;

    /** @var list<class-string<FilamentFormExtension>|FilamentFormExtension> */
    private array $formExtensions = [];

    private ?bool $middlewareWithTenancy = null;

    /**
     * @throws InvalidConfigurationException when the configuration has a value the plugin cannot use
     */
    public function __construct(Repository $config)
    {
        /** @var array<string, mixed> $values */
        $values = (array) $config->get('azguard-filament', []);

        $this->guardPanel = self::optionalString($values['guard_panel'] ?? null, 'guard_panel');
        $this->manages = isset($values['manages']) ? self::strings($values['manages'], 'manages') : null;
        $this->enforce = (bool) ($values['enforce'] ?? true);
        $this->definitions = FilamentDefinitions::tryFrom(self::optionalString($values['definitions'] ?? null, 'definitions') ?? 'enums')
            ?? throw self::invalid('definitions must be "enums" or "resources".');
        $this->authority = PermissionAuthority::tryFrom(self::optionalString($values['authority'] ?? null, 'authority') ?? 'grants')
            ?? throw self::invalid('authority must be "grants" or "policy".');
        $this->abilities = self::abilityNames($values['abilities'] ?? []);

        $exclude = (array) ($values['exclude'] ?? []);
        $this->exclude = [
            'resources' => self::strings($exclude['resources'] ?? [], 'exclude.resources'),
            'pages' => self::strings($exclude['pages'] ?? [], 'exclude.pages'),
            'widgets' => self::strings($exclude['widgets'] ?? [], 'exclude.widgets'),
        ];
        $this->editors = array_fill_keys(self::RESOURCES, true);
    }

    public static function make(): static
    {
        return app(self::class);
    }

    /**
     * The plugin of the current Filament panel, or of the named one.
     *
     * @throws InvalidConfigurationException when the panel does not have the plugin
     */
    public static function get(?string $filamentPanel = null): self
    {
        $panel = $filamentPanel === null ? Filament::getCurrentOrDefaultPanel() : Filament::getPanel($filamentPanel);

        if (! $panel instanceof FilamentPanel || ! $panel->hasPlugin(self::ID)) {
            throw self::invalid('The Filament panel "'.($panel?->getId() ?? $filamentPanel).'" does not have AzGuardPlugin: add ->plugin(AzGuardPlugin::make()->guardPanel(...)) to its provider.');
        }

        $plugin = $panel->getPlugin(self::ID);

        return $plugin instanceof self ? $plugin : throw self::invalid('The plugin "azguard" of the Filament panel "'.$panel->getId().'" is not '.self::class.'.');
    }

    public function getId(): string
    {
        return self::ID;
    }

    /**
     * The AzGuard panel that holds the permissions of this Filament panel.
     */
    public function guardPanel(string $panel): static
    {
        $this->guardPanel = $panel;

        return $this;
    }

    /**
     * The AzGuard panels whose roles and grants this admin edits; null means every panel with a writable schema.
     *
     * @param  list<string>|null  $panels
     */
    public function manages(?array $panels): static
    {
        $this->manages = $panels === null ? null : self::strings($panels, 'manages');

        return $this;
    }

    /**
     * Whether a resource, page or widget without a permission is closed.
     */
    public function enforce(bool $enforce = true): static
    {
        $this->enforce = $enforce;

        return $this;
    }

    public function definitions(FilamentDefinitions $definitions): static
    {
        $this->definitions = $definitions;

        return $this;
    }

    /**
     * Who decides the permissions that `FilamentSource` defines; it applies to `FilamentDefinitions::Resources` only.
     */
    public function authority(PermissionAuthority|string $authority): static
    {
        $this->authority = $authority instanceof PermissionAuthority ? $authority
            : (PermissionAuthority::tryFrom($authority) ?? throw self::invalid('authority must be "grants" or "policy".'));
        $this->authorityDeclared = true;

        return $this;
    }

    /**
     * Abilities of a resource in snake_case, such as `view_any`.
     *
     * @param  list<string>  $abilities
     */
    public function abilities(array $abilities): static
    {
        $this->abilities = self::abilityNames($abilities);

        return $this;
    }

    /**
     * Classes that get no permission.
     *
     * @param  list<string>  $resources
     * @param  list<string>  $pages
     * @param  list<string>  $widgets
     */
    public function exclude(array $resources = [], array $pages = [], array $widgets = []): static
    {
        $this->exclude = [
            'resources' => self::strings($resources, 'exclude.resources'),
            'pages' => self::strings($pages, 'exclude.pages'),
            'widgets' => self::strings($widgets, 'exclude.widgets'),
        ];

        return $this;
    }

    /**
     * Which editors of the package this panel shows; all of them by default.
     */
    public function resources(
        ?bool $roles = null,
        ?bool $roleGrants = null,
        ?bool $permissionGrants = null,
        ?bool $permissions = null,
        ?bool $panels = null,
        ?bool $doctor = null,
    ): static {
        $given = [
            'roles' => $roles,
            'role_grants' => $roleGrants,
            'permission_grants' => $permissionGrants,
            'permissions' => $permissions,
            'panels' => $panels,
            'doctor' => $doctor,
        ];

        foreach ($given as $editor => $enabled) {
            if ($enabled !== null) {
                $this->editors[$editor] = $enabled;
            }
        }

        return $this;
    }

    /**
     * Components of their own for the declared fields of the grant forms; for a field that several extensions give a
     * component, the first one registered wins. A class is resolved from the container every time a form is built.
     *
     * @param  class-string<FilamentFormExtension>|FilamentFormExtension  ...$extensions
     */
    public function formExtensions(string|FilamentFormExtension ...$extensions): static
    {
        foreach ($extensions as $extension) {
            if (! in_array($extension, $this->formExtensions, true)) {
                $this->formExtensions[] = $extension;
            }
        }

        return $this;
    }

    /**
     * @throws InvalidConfigurationException when no guard panel is set
     */
    public function getGuardPanel(): string
    {
        return $this->guardPanel ?? throw self::invalid('AzGuardPlugin needs the AzGuard panel that holds the permissions of the Filament panel: call guardPanel(\'id\') or set guard_panel in config/azguard-filament.php.');
    }

    /**
     * @return list<string>|null
     */
    public function getManages(): ?array
    {
        return $this->manages;
    }

    public function isEnforced(): bool
    {
        return $this->enforce;
    }

    public function getDefinitions(): FilamentDefinitions
    {
        return $this->definitions;
    }

    public function getAuthority(): PermissionAuthority
    {
        return $this->authority;
    }

    /**
     * @return list<string>
     */
    public function getAbilities(): array
    {
        return $this->abilities;
    }

    /**
     * @return array{resources: list<string>, pages: list<string>, widgets: list<string>}
     */
    public function getExclude(): array
    {
        return $this->exclude;
    }

    /**
     * Whether an editor of the package is shown: `roles`, `role_grants`, `permission_grants`, `permissions`, `panels` or `doctor`.
     */
    public function hasEditor(string $editor): bool
    {
        return $this->editors[$editor] ?? false;
    }

    /**
     * @return list<class-string<FilamentFormExtension>|FilamentFormExtension>
     */
    public function getFormExtensions(): array
    {
        return $this->formExtensions;
    }

    /**
     * The form extensions for one form: each class is resolved from the container again.
     *
     * @return list<FilamentFormExtension>
     *
     * @throws InvalidConfigurationException when a registered class is not a form extension
     */
    public function resolveFormExtensions(): array
    {
        $resolved = [];

        foreach ($this->formExtensions as $extension) {
            if (! is_string($extension)) {
                $resolved[] = $extension;

                continue;
            }

            if (! is_subclass_of($extension, FilamentFormExtension::class)) {
                throw self::invalid('The form extension '.$extension.' does not implement '.FilamentFormExtension::class.'.');
            }
            $instance = app($extension);
            $resolved[] = $instance instanceof FilamentFormExtension ? $instance
                : throw self::invalid('The container did not resolve the form extension '.$extension.' to '.FilamentFormExtension::class.'.');
        }

        return $resolved;
    }

    /**
     * Permission keys of the resources, pages and widgets of a Filament panel under the settings of this plugin.
     */
    public function keys(FilamentPanel $panel): FilamentKeys
    {
        return new FilamentKeys($panel, $this->abilities, $this->exclude);
    }

    /**
     * Enters the guard panel on every request to the Filament panel; the service provider makes the middleware persistent,
     * so that a Livewire update enters it again. Registers the editors of the package that `resources()` keeps.
     */
    public function register(FilamentPanel $panel): void
    {
        $middleware = [self::MIDDLEWARE.':'.$this->getGuardPanel()];
        $this->middlewareWithTenancy = $panel->hasTenancy();

        if ($this->middlewareWithTenancy) {
            $panel->tenantMiddleware($middleware);
        } else {
            $panel->authMiddleware($middleware);
        }
        $editors = [];

        if ($this->hasEditor('roles')) {
            $editors[] = RoleResource::class;
        }

        if ($this->hasEditor('role_grants')) {
            $editors[] = RoleGrantResource::class;
        }

        if ($this->hasEditor('permission_grants')) {
            $editors[] = PermissionGrantResource::class;
        }

        if ($this->hasEditor('permissions')) {
            $editors[] = PermissionResource::class;
        }

        if ($editors !== []) {
            $panel->resources($editors);
        }
        $pages = [];

        if ($this->hasEditor('panels')) {
            $pages[] = PanelsPage::class;
        }

        if ($this->hasEditor('doctor')) {
            $pages[] = DoctorPage::class;
        }

        if ($pages !== []) {
            $panel->pages($pages);
        }
    }

    /**
     * @throws InvalidConfigurationException when the panels are connected in a way that cannot work
     */
    public function boot(FilamentPanel $panel): void
    {
        if ($this->middlewareWithTenancy !== $panel->hasTenancy()) {
            throw self::invalid('The Filament panel "'.$panel->getId().'" got tenancy after AzGuardPlugin was registered: call ->tenant(...) before ->plugin(AzGuardPlugin::make()), so that the guard panel is entered after the tenant is chosen.');
        }

        $registry = app(PanelRegistry::class);
        $guard = $registry->find($this->getGuardPanel())
            ?? throw self::invalid('The Filament panel "'.$panel->getId().'" names the AzGuard panel "'.$this->getGuardPanel().'", which is not registered.');

        foreach ($this->manages ?? [] as $managed) {
            if ($registry->find($managed) === null) {
                throw self::invalid('The Filament panel "'.$panel->getId().'" manages the AzGuard panel "'.$managed.'", which is not registered.');
            }
        }

        if ($this->definitions === FilamentDefinitions::Enums && $this->authorityDeclared) {
            throw self::invalid('authority() applies to FilamentDefinitions::Resources only; with FilamentDefinitions::Enums the authority is declared by the permission enums.');
        }

        $keys = $this->keys($panel)->all();

        if ($this->definitions === FilamentDefinitions::Resources && ! $this->hasFilamentSource($guard)) {
            throw self::invalid('The Filament panel "'.$panel->getId().'" defines permissions from its resources, but the AzGuard panel "'.$guard->id()
                .'" has no FilamentSource: add FilamentSource::make(\''.$panel->getId().'\') to permissions([...]) of that panel.');
        }

        if ($this->enforce) {
            $classes = [];

            foreach ($keys as $key) {
                $classes[$key->class] = $key->surface;
            }

            foreach ($classes as $class => $surface) {
                self::assertAuthorized($panel, $surface, $class);

                if ($surface === FilamentSurface::Resource && is_a($class, Resource::class, true)) {
                    self::assertRelations($class::getRelations());
                }
            }
        }

        self::authorizeRecordsOfActions();
    }

    /**
     * A Filament panel that enforces decides every resource, page and widget it has through AzGuard; one that Filament
     * would decide alone would be open.
     *
     * @throws InvalidConfigurationException
     */
    private static function assertAuthorized(FilamentPanel $panel, FilamentSurface $surface, string $class): void
    {
        [$trait, $methods] = match ($surface) {
            FilamentSurface::Resource => [AuthorizesResource::class, ['getAuthorizationResponse', 'getEloquentQuery']],
            FilamentSurface::Page => [AuthorizesPage::class, ['canAccess']],
            FilamentSurface::Widget => [AuthorizesWidget::class, ['canView']],
        };
        $file = (new ReflectionClass($trait))->getFileName();

        foreach ($methods as $method) {
            if (! in_array($trait, class_uses_recursive($class), true) || (new ReflectionMethod($class, $method))->getFileName() !== $file) {
                throw InvalidConfigurationException::failing('filament_enforce', $class.' in the Filament panel "'.$panel->getId().'", which enforces its permissions, is not decided by AzGuard: use '
                    .$trait.' and keep its '.implode('() and ', $methods).'()'.($surface === FilamentSurface::Resource ? ' (change the query in modifyEloquentQuery())' : '')
                    .', or exclude the class.');
            }
        }
    }

    /**
     * Inside a Filament panel with the plugin, a bulk action that deletes, force-deletes or restores decides every
     * selected record, and the records offered to attach or associate are the ones the user may view.
     */
    private static function authorizeRecordsOfActions(): void
    {
        BulkAction::configureUsing(static function (BulkAction $action): void {
            $ability = match (true) {
                $action instanceof DeleteBulkAction => 'delete',
                $action instanceof ForceDeleteBulkAction => 'force_delete',
                $action instanceof RestoreBulkAction => 'restore',
                default => null,
            };

            if ($ability === null || FilamentContext::serving() === null) {
                return;
            }
            $action->fetchSelectedRecords()->authorizeIndividualRecords(static function (Model $record) use ($action, $ability): Response {
                $resource = FilamentContext::resourceOf($action->getLivewire());

                if ($resource !== null) {
                    return FilamentGate::resource($resource, $ability, $record);
                }
                $livewire = $action->getLivewire();
                $filament = $livewire instanceof HasActions ? $livewire->getDefaultActionIndividualRecordAuthorizationResponseResolver($action) : null;

                $response = $filament === null ? null : $filament($record);

                return $response instanceof Response ? $response : Response::deny();
            });
        });

        $visible = static function (AttachAction|AssociateAction $action): void {
            if (FilamentContext::serving()?->enforced() !== true) {
                return;
            }
            $action->recordSelectOptionsQuery(static function (Builder $query) use ($action): Builder {
                $resource = FilamentContext::resourceOf($action->getLivewire());

                return $resource === null ? $query : FilamentGate::visible($resource, $query);
            });
        };
        AttachAction::configureUsing($visible);
        AssociateAction::configureUsing($visible);
    }

    /** @param array<class-string<RelationManager>|RelationGroup|RelationManagerConfiguration> $relations */
    private static function assertRelations(array $relations): void
    {
        $trait = AuthorizesRelationManager::class;
        $file = (new ReflectionClass($trait))->getFileName();

        foreach ($relations as $relation) {
            if ($relation instanceof RelationGroup) {
                self::assertRelations($relation->getManagers());

                continue;
            }
            $class = $relation instanceof RelationManagerConfiguration ? $relation->relationManager : $relation;

            foreach (['canViewForRecord', 'getAuthorizationResponse', 'makeTable'] as $method) {
                if (! in_array($trait, class_uses_recursive($class), true) || (new ReflectionMethod($class, $method))->getFileName() !== $file) {
                    throw self::invalid($class.' in an enforced resource must use '.$trait.' and keep its authorization and visibility methods.');
                }
            }

            if (FilamentContext::relationResource($class) === null) {
                throw self::invalid($class.' in an enforced resource must name a related resource with '.AuthorizesResource::class.'.');
            }
        }
    }

    private function hasFilamentSource(Panel $guard): bool
    {
        foreach ($guard->sources() as $source) {
            if ($source->class === FilamentSource::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function abilityNames(mixed $abilities): array
    {
        $names = self::strings($abilities, 'abilities');

        foreach ($names as $name) {
            if (preg_match('/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/', $name) !== 1) {
                throw self::invalid('The ability "'.$name.'" is not snake_case.');
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values, string $name): array
    {
        if (! is_array($values)) {
            throw self::invalid($name.' must be a list of strings.');
        }

        $strings = [];

        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                throw self::invalid($name.' must be a list of non-empty strings.');
            }
            $strings[] = $value;
        }

        return array_values(array_unique($strings));
    }

    private static function optionalString(mixed $value, string $name): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) && $value !== '' => $value,
            default => throw self::invalid($name.' must be a non-empty string or null.'),
        };
    }

    private static function invalid(string $message): InvalidConfigurationException
    {
        return InvalidConfigurationException::failing('filament', $message);
    }
}
