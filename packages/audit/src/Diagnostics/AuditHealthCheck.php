<?php

declare(strict_types=1);

namespace AzGuard\Audit\Diagnostics;

use AzGuard\Audit\AuditOptions;
use AzGuard\Audit\Internal\AuditSchema;
use AzGuard\AzGuard;
use AzGuard\Contracts\Panels\PanelRegistry;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Api;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext as PackmanDoctorContext;
use Packman\Doctor\Data\DoctorFinding as PackmanDoctorFinding;

/**
 * Verifies the journal table schema for panels enabling `AuditPlugin` through Packman diagnostics.
 */
#[Api(since: '1.0')]
final readonly class AuditHealthCheck implements DoctorCheck
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return 'azguard.audit.health';
    }

    public function title(): string
    {
        return 'AzGuard audit journal diagnostics';
    }

    /** @return list<PackmanDoctorFinding> */
    public function run(PackmanDoctorContext $context): array
    {
        if (! $this->container->bound(PanelRegistry::class) || ! $this->container->bound(AzGuard::class)) {
            return [
                PackmanDoctorFinding::ok(
                    code: $this->key(),
                    message: 'Panel registry or AzGuard runtime is not bound; audit checks skipped.',
                ),
            ];
        }

        $registry = $this->container->make(PanelRegistry::class);

        if (! $registry->isFrozen()) {
            return [
                PackmanDoctorFinding::ok(
                    code: $this->key(),
                    message: 'Panels are not compiled yet; audit checks skipped.',
                ),
            ];
        }

        $azguard = $this->container->make(AzGuard::class);
        $findings = [];
        $audited = 0;

        foreach ($registry->all() as $panel) {
            if ($panel->options(AuditOptions::class) === null) {
                continue;
            }

            $audited++;
            $writer = $panel->writer();

            if ($writer === null) {
                $findings[] = PackmanDoctorFinding::error(
                    code: 'azguard.audit.table',
                    message: 'Panel '.$panel->id().' has the audit plugin but no database source that stores its grants.',
                    remediation: 'Attach DatabaseSource to the panel before enabling AuditPlugin.',
                    slice: 'panel:'.$panel->id(),
                );

                continue;
            }

            $storage = $azguard->storage($writer->storage);

            if (! AuditSchema::exists($storage)) {
                $findings[] = PackmanDoctorFinding::error(
                    code: 'azguard.audit.table',
                    message: 'The storage '.$storage->id.' of panel '.$panel->id().' has no audit_log table.',
                    remediation: $storage->id === 'default'
                        ? 'Run `php artisan vendor:publish --tag=azguard-audit-migrations && php artisan migrate`.'
                        : 'Run `php artisan azguard:audit:migration '.$storage->id.' && php artisan migrate`.',
                    evidence: ['storage' => $storage->id],
                    slice: 'panel:'.$panel->id(),
                );

                continue;
            }

            $differences = AuditSchema::differences($storage);

            if ($differences !== []) {
                $findings[] = PackmanDoctorFinding::error(
                    code: 'azguard.audit.table',
                    message: 'The audit_log table of storage '.$storage->id.' differs from the audit schema: '.implode('; ', $differences).'.',
                    remediation: 'Run `php artisan azguard:doctor` for schema remediation details.',
                    evidence: ['storage' => $storage->id, 'differences' => implode('; ', $differences)],
                    slice: 'panel:'.$panel->id(),
                );
            }
        }

        if ($findings !== []) {
            return $findings;
        }

        return [
            PackmanDoctorFinding::ok(
                code: $this->key(),
                message: sprintf('Verified audit journal readiness across %d audited panel(s).', $audited),
                evidence: ['audited_panels' => $audited],
            ),
        ];
    }
}
