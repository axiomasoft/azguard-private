<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs;

use AzGuard\Guard\AzGuardDiagnostics;

/**
 * Test double that counts diagnose() invocations, used to prove DoctorPage
 * memoizes the diagnostics result for the whole request (one diagnose() across
 * the badge / badge-colour / view-data render hooks) instead of recomputing it
 * per hook.
 *
 * @property-read array{errors: list<string>, warnings: list<string>, abilities: list<array{panel: string, ability: string, handler: string}>} $payload
 */
final class CountingDiagnostics extends AzGuardDiagnostics
{
    public int $calls = 0;

    /**
     * @param  array{errors: list<string>, warnings: list<string>, abilities: list<array{panel: string, ability: string, handler: string}>}  $payload
     */
    public function __construct(
        private readonly array $payload = ['errors' => [], 'warnings' => [], 'abilities' => []],
    ) {}

    public function diagnose(?string $panelFilter = null): array
    {
        $this->calls++;

        return $this->payload;
    }
}
