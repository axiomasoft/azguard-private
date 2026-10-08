<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Contracts\Plugins\DependsOnPlugins;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Exceptions\PluginConflictException;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Panels\PanelRegistry;
use Illuminate\Contracts\Container\Container;

/**
 * `panels.plugins`: the plugins of each panel find the plugins they require and do not set one setting differently.
 *
 * @internal
 */
final readonly class PanelsPlugins implements DoctorCheck
{
    public function __construct(private PanelRegistry $registry, private Container $container) {}

    public function key(): string
    {
        return 'panels.plugins';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach ($context->panels() as $panel) {
            yield from self::check($this->registry->recipe($panel->id()), $panel->pluginIds(), $this->container);
        }
    }

    /**
     * @param  list<string>  $attached  ids of the plugins attached to the panel
     * @return list<DoctorFinding>
     */
    public static function check(PanelRecipe $recipe, array $attached, Container $container): array
    {
        $scope = 'panel:'.$recipe->panelId();
        $findings = [];

        try {
            (new PanelCompiler)->settings($recipe);
        } catch (PluginConflictException $error) {
            $findings[] = DoctorFinding::error('panels.plugins', $error->getMessage(), $scope, ['code' => $error->code()]);
        }

        foreach ([PanelRecipe::PROVIDER, PanelRecipe::PLUGIN, PanelRecipe::CONFIGURE] as $layer) {
            foreach ($recipe->plugins($layer) as $plugin) {
                $plugin = is_string($plugin) ? $container->make($plugin) : $plugin;

                if (! $plugin instanceof DependsOnPlugins || ! $plugin instanceof Plugin) {
                    continue;
                }
                $missing = array_values(array_diff(array_filter($plugin->requires(), is_string(...)), $attached));

                if ($missing !== []) {
                    $findings[] = DoctorFinding::error('panels.plugins', 'Plugin "'.$plugin->id().'" of panel '.$recipe->panelId().' requires '
                        .implode(', ', $missing).', which the panel does not attach.', $scope, ['plugin' => $plugin->id(), 'missing' => $missing]);
                }
            }
        }

        return $findings;
    }
}
