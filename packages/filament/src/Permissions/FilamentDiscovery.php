<?php

declare(strict_types=1);

namespace AzGuard\Filament\Permissions;

use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\Concerns\HasAzGuardPage;
use AzGuard\Filament\Concerns\HasAzGuardWidget;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Widgets\Widget;
use Illuminate\Support\Str;
use Override;
use ReflectionMethod;
use Throwable;

/**
 * Reads the live Filament panel — the one whose {@see AzGuardPlugin} links to
 * the given AzGuard panel — and turns its resources and pages into
 * {@see PermissionSubject}s using the configured ability set.
 *
 * Degrades to an empty list when Filament is not booted or no matching panel
 * is registered, so it is always safe to tag as a catalog builder.
 */
final readonly class FilamentDiscovery implements PermissionDiscovery
{
    /**
     * @param  list<string>  $abilities  abilities granted to every resource
     * @param  array{resources?: list<string>, pages?: list<string>, widgets?: list<string>}  $exclude
     */
    public function __construct(
        private array $abilities,
        private string $pageAbility = 'view',
        private string $widgetAbility = 'view',
        private array $exclude = [],
    ) {}

    #[Override]
    public function subjects(string $panelId): array
    {
        $panel = $this->filamentPanelFor($panelId);

        if (! $panel instanceof Panel) {
            return [];
        }

        $subjects = [];

        foreach ($panel->getResources() as $resourceClass) {
            if ($this->excluded('resources', $resourceClass)) {
                continue;
            }

            $subject = $this->resourceSubject($resourceClass);

            if ($subject instanceof PermissionSubject) {
                $subjects[] = $subject;
            }
        }

        foreach ($panel->getPages() as $pageClass) {
            if ($this->excluded('pages', $pageClass)) {
                continue;
            }

            $name = class_basename($pageClass);
            $subjects[] = new PermissionSubject($name, Str::headline($name), [$this->pageAbility]);
        }

        foreach ($panel->getWidgets() as $widgetClass) {
            if ($this->excluded('widgets', $widgetClass)) {
                continue;
            }

            $name = class_basename($widgetClass);
            $subjects[] = new PermissionSubject($name, Str::headline($name), [$this->widgetAbility]);
        }

        return $subjects;
    }

    /**
     * The subset whose native authorization entry points reach AzGuard.
     * Discovery alone does not prove that a page/widget uses its permission.
     *
     * @internal
     *
     * @return list<PermissionSubject>
     */
    public function authorizationSubjects(string $panelId): array
    {
        $panel = $this->filamentPanelFor($panelId);

        if (! $panel instanceof Panel) {
            return [];
        }

        $subjects = [];

        foreach ($panel->getResources() as $resourceClass) {
            if ($this->excluded('resources', $resourceClass)
                || ! is_subclass_of($resourceClass, Resource::class)
                || $resourceClass::shouldSkipAuthorization()
                || ! $this->usesHook($resourceClass, Resource::class, 'getAuthorizationResponse')) {
                continue;
            }

            $subject = $this->resourceSubject($resourceClass);

            if ($subject instanceof PermissionSubject) {
                $subjects[] = new PermissionSubject(
                    $subject->name,
                    $subject->label,
                    array_values(array_filter(
                        array_intersect($subject->abilities, FilamentActions::MAP),
                        function (string $ability) use ($resourceClass): bool {
                            $action = $ability === 'update' ? 'Edit' : Str::studly($ability);

                            return $this->usesHook($resourceClass, Resource::class, 'can'.$action)
                                && $this->usesHook($resourceClass, Resource::class, 'get'.$action.'AuthorizationResponse');
                        },
                    )),
                    $subject->model,
                );
            }
        }

        foreach ([
            ['pages', $panel->getPages(), Page::class, HasAzGuardPage::class, 'canAccess', $this->pageAbility],
            ['widgets', $panel->getWidgets(), Widget::class, HasAzGuardWidget::class, 'canView', $this->widgetAbility],
        ] as [$type, $classes, $baseClass, $trait, $hook, $ability]) {
            foreach ($classes as $class) {
                if ($this->excluded($type, $class)
                    || ! is_subclass_of($class, $baseClass)
                    || ! $this->usesHook($class, $trait, $hook)) {
                    continue;
                }

                $name = class_basename($class);
                $subjects[] = new PermissionSubject($name, Str::headline($name), [$ability]);
            }
        }

        return $subjects;
    }

    private function usesHook(string $class, string $owner, string $method): bool
    {
        $actual = new ReflectionMethod($class, $method);
        $expected = new ReflectionMethod($owner, $method);

        return $actual->getFileName() === $expected->getFileName()
            && $actual->getStartLine() === $expected->getStartLine()
            && $actual->getEndLine() === $expected->getEndLine();
    }

    private function resourceSubject(string $resourceClass): ?PermissionSubject
    {
        try {
            /** @var class-string $model */
            $model = $resourceClass::getModel();
        } catch (Throwable) {
            return null;
        }

        $name = class_basename($model);

        return new PermissionSubject(
            name: $name,
            label: Str::headline(Str::pluralStudly($name)),
            abilities: $this->abilities,
            model: $model,
        );
    }

    private function filamentPanelFor(string $panelId): ?Panel
    {
        try {
            foreach (Filament::getPanels() as $panel) {
                $plugin = $this->azGuardPlugin($panel);

                if ($plugin instanceof AzGuardPlugin && $plugin->getPanelId() === $panelId) {
                    return $panel;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function azGuardPlugin(Panel $panel): ?AzGuardPlugin
    {
        try {
            $plugin = $panel->getPlugin('az-guard');

            return $plugin instanceof AzGuardPlugin ? $plugin : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  'resources'|'pages'|'widgets'  $type
     */
    private function excluded(string $type, string $class): bool
    {
        return in_array($class, $this->exclude[$type] ?? [], true);
    }
}
