<?php

declare(strict_types=1);

namespace AzGuard\Filament\Diagnostics;

use AzGuard\Filament\AzGuardPlugin;
use Filament\Facades\Filament;
use Packman\Attributes\Api;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext as PackmanDoctorContext;
use Packman\Doctor\Data\DoctorFinding as PackmanDoctorFinding;

/**
 * Reports registered AzGuard Filament panel plugins to Packman diagnostics without mutating panel state.
 */
#[Api(since: '1.0')]
final readonly class AzGuardFilamentHealthCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'azguard.filament.health';
    }

    public function title(): string
    {
        return 'AzGuard Filament integration diagnostics';
    }

    /** @return list<PackmanDoctorFinding> */
    public function run(PackmanDoctorContext $context): array
    {
        $configured = 0;

        foreach (Filament::getPanels() as $panel) {
            if ($panel->hasPlugin(AzGuardPlugin::ID) && $panel->getPlugin(AzGuardPlugin::ID) instanceof AzGuardPlugin) {
                $configured++;
            }
        }

        return [
            PackmanDoctorFinding::ok(
                code: $this->key(),
                message: sprintf('Verified AzGuard Filament plugin wiring across %d panel(s).', $configured),
                evidence: ['filament_panels' => $configured],
            ),
        ];
    }
}
