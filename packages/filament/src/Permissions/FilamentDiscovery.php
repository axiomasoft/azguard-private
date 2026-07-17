<?php

declare(strict_types=1);

namespace AzGuard\Filament\Permissions;

use AzGuard\Filament\AzGuardPlugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Str;
use Override;
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
