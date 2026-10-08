<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Panels\GateMode;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelSettings;

/**
 * `gate.mode`: every panel answers Laravel Gate authoritatively, also with the defaults the configuration sets now.
 *
 * @internal
 */
final readonly class GateModeCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'gate.mode';
    }

    public function run(DoctorContext $context): iterable
    {
        $configured = $context->config()->defaults()[PanelSettings::GATE_MODE] ?? null;

        foreach ($context->panels() as $panel) {
            yield from self::check($panel, is_string($configured) ? $configured : null);
        }
    }

    /** @return list<DoctorFinding> */
    public static function check(Panel $panel, ?string $configured = null): array
    {
        $settings = $panel->settings();
        $mode = $settings->origin(PanelSettings::GATE_MODE) === 'default' && $configured !== null ? $configured : $settings->gateMode()->value;

        if ($mode === GateMode::Authoritative->value) {
            return [];
        }

        return [DoctorFinding::error('gate.mode', 'Panel '.$panel->id().' answers Laravel Gate in the mode "'.$mode.'"; only authoritative is supported.',
            'panel:'.$panel->id(), ['mode' => $mode])];
    }
}
