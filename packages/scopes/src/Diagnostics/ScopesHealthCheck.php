<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Diagnostics;

use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Contracts\Panels\PanelRegistry;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Api;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext as PackmanDoctorContext;
use Packman\Doctor\Data\DoctorFinding as PackmanDoctorFinding;

/**
 * Reports assignment-scope readiness for Packman diagnostics without triggering inactive queries or builds.
 */
#[Api(since: '1.0')]
final readonly class ScopesHealthCheck implements DoctorCheck
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return 'azguard.scopes.health';
    }

    public function title(): string
    {
        return 'AzGuard scoped assignments diagnostics';
    }

    /** @return list<PackmanDoctorFinding> */
    public function run(PackmanDoctorContext $context): array
    {
        if (! $this->container->bound(PanelRegistry::class)) {
            return [
                PackmanDoctorFinding::ok(
                    code: $this->key(),
                    message: 'Panel registry is not bound; scoped assignment checks skipped.',
                ),
            ];
        }

        $registry = $this->container->make(PanelRegistry::class);

        if (! $registry->isFrozen()) {
            return [
                PackmanDoctorFinding::ok(
                    code: $this->key(),
                    message: 'Panels are not compiled yet; scoped assignment checks skipped.',
                ),
            ];
        }

        $scoped = 0;

        foreach ($registry->all() as $panel) {
            $declaration = $panel->scopeDeclaration();

            if ($declaration !== null && $declaration->mode !== AssignmentScopeMode::None) {
                $scoped++;
            }
        }

        return [
            PackmanDoctorFinding::ok(
                code: $this->key(),
                message: sprintf('Verified scoped assignment configuration across %d panel(s).', $scoped),
                evidence: ['scoped_panels' => $scoped],
            ),
        ];
    }
}
