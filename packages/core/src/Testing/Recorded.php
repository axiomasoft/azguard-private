<?php

declare(strict_types=1);

namespace AzGuard\Testing;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One grant/revoke/check observed by {@see AzGuardFake} while `AzGuard::fake()`
 * is active. Passed to the closure-predicate form of assertGranted()/
 * assertDenied()/assertChecked().
 *
 * @api
 */
final readonly class Recorded
{
    public function __construct(
        public Authenticatable $user,
        public string $key,
        public ?string $panelId = null,
        public ?bool $result = null,
    ) {}
}
