<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Exceptions\PluginConflictException;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Panels\PanelRegistry;

/**
 * `panels.plugins`: the plugins of each panel do not set one setting differently.
 *
 * @internal
 */
final readonly class PanelsPlugins implements DoctorCheck
{
    public function __construct(private PanelRegistry $registry) {}

    public function key(): string
    {
        return 'panels.plugins';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach ($context->panels() as $panel) {
            yield from self::check($this->registry->recipe($panel->id()));
        }
    }

    /**
     * @return list<DoctorFinding>
     */
    public static function check(PanelRecipe $recipe): array
    {
        $scope = 'panel:'.$recipe->panelId();
        $findings = [];

        try {
            (new PanelCompiler)->settings($recipe);
        } catch (PluginConflictException $error) {
            $findings[] = DoctorFinding::error('panels.plugins', $error->getMessage(), $scope, ['code' => $error->code()]);
        }

        return $findings;
    }
}
