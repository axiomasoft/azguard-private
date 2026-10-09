<?php

declare(strict_types=1);

namespace AzGuard\Filament\Diagnostics;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Attributes\ForFilament;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Kernel\Identity\TenantRef;
use Filament\Facades\Filament;
use Filament\Panel as FilamentPanel;
use ReflectionEnum;

/**
 * `filament.definitions`: for each Filament panel whose guard panel is the panel under check, a permission enum marked
 * `#[ForFilament(Class)]` whose class is not in that Filament panel any more is a warning (`filament.stale_definition`),
 * and a resource, page or widget of a panel that enforces and takes its definitions from enums, but has no definition
 * for a permission it checks, is an error (`filament.missing_definition`).
 *
 * Add it to the guard panel with `doctorChecks([FilamentDefinitionsCheck::class])`.
 *
 * @api
 */
final readonly class FilamentDefinitionsCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'filament.definitions';
    }

    public function run(DoctorContext $context): iterable
    {
        $guard = $context->panel();

        if ($guard === null) {
            return;
        }
        $scope = 'panel:'.$guard->id();
        $catalog = AzGuard::panel($guard->id())->inTenant(TenantRef::global())->catalog();
        $marked = [];

        foreach ($catalog->all() as $definition) {
            if ($definition->case !== null) {
                $marked[$definition->case::class] = true;
            }
        }

        $classes = [];

        foreach (Filament::getPanels() as $panel) {
            $plugin = self::pluginOf($panel);

            if ($plugin === null || $plugin->getGuardPanel() !== $guard->id()) {
                continue;
            }

            foreach ($plugin->keys($panel)->all() as $key) {
                $classes[$key->class] = true;

                if ($plugin->isEnforced() && $plugin->getDefinitions() === FilamentDefinitions::Enums && ! $catalog->has($key->local)) {
                    yield DoctorFinding::error('filament.missing_definition', 'The Filament panel "'.$panel->getId().'" checks the permission "'.$key->local.'" of '.$key->class
                        .', which the AzGuard panel "'.$guard->id().'" does not define: run azguard:filament:generate.', $scope, ['permission' => $key->local, 'class' => $key->class, 'filament_panel' => $panel->getId()]);
                }
            }
        }

        foreach (array_keys($marked) as $enum) {
            foreach ((new ReflectionEnum($enum))->getAttributes(ForFilament::class) as $attribute) {
                $target = $attribute->newInstance()->class;

                if (! isset($classes[$target])) {
                    yield DoctorFinding::warning('filament.stale_definition', 'The enum '.$enum.' defines the permissions of '.$target.', which no Filament panel of the AzGuard panel "'
                        .$guard->id().'" has any more: delete the enum or its marker.', $scope, ['enum' => $enum, 'class' => $target]);
                }
            }
        }
    }

    private static function pluginOf(FilamentPanel $panel): ?AzGuardPlugin
    {
        try {
            return $panel->hasPlugin(AzGuardPlugin::ID) ? AzGuardPlugin::get($panel->getId()) : null;
        } catch (InvalidConfigurationException) {
            return null;
        }
    }
}
