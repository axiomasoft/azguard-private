<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics;

use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Api;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext as PackmanDoctorContext;
use Packman\Doctor\Data\DoctorFinding as PackmanDoctorFinding;

/**
 * Bridges AzGuard core diagnostics into Packman's unified `DoctorChecks` registry.
 */
#[Api(since: '1.0')]
final readonly class AzGuardHealthCheck implements DoctorCheck
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return 'azguard.health';
    }

    public function title(): string
    {
        return 'AzGuard authorization diagnostics';
    }

    /** @return list<PackmanDoctorFinding> */
    public function run(PackmanDoctorContext $context): array
    {
        $doctor = $this->container->make(Doctor::class);
        $findings = $doctor->run($doctor->context(production: $context->production));

        if ($findings === []) {
            return [
                PackmanDoctorFinding::ok(
                    code: $this->key(),
                    message: 'All AzGuard core, storage, and panel health checks passed.',
                    remediation: 'No action required.',
                ),
            ];
        }

        $results = [];
        foreach ($findings as $finding) {
            $evidence = self::scalarEvidence($finding->details);
            $code = str_starts_with($finding->key, 'azguard.') ? $finding->key : 'azguard.'.$finding->key;
            $remediation = 'Run `php artisan azguard:doctor` for panel and storage remediation details.';

            $results[] = $finding->isError()
                ? PackmanDoctorFinding::error($code, $finding->message, $remediation, $evidence, $finding->scope)
                : PackmanDoctorFinding::warning($code, $finding->message, $remediation, $evidence, $finding->scope);
        }

        return $results;
    }

    /**
     * @param  array<string, scalar|list<scalar>>  $details
     * @return array<string, scalar|null>
     */
    private static function scalarEvidence(array $details): array
    {
        $normalized = [];
        foreach ($details as $key => $value) {
            if ($key === '') {
                continue;
            }
            $normalized[$key] = is_array($value)
                ? implode(', ', array_map(static fn (bool|float|int|string $item): string => (string) $item, $value))
                : $value;
        }

        return $normalized;
    }
}
